<?php

namespace App\Helpers;

/**
 * Family split ("who owes whom") maths. Pure functions, no database access.
 *
 * Money is handled in integer fils/cents internally so shares always add up
 * exactly to the expense amount and balances never drift by float rounding.
 */
final class SplitHelper
{
    /** Tolerance when checking that custom split amounts add up to the expense amount. */
    public const TOLERANCE = 0.01;

    private static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private static function fromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /**
     * Split $amount equally between $userIds. Any rounding remainder goes to the payer
     * when the payer is a participant, otherwise to the first participant.
     *
     * @param int[] $userIds
     * @return array<int, float> user id => share
     */
    public static function equalShares(float $amount, array $userIds, int $payerId): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        sort($userIds);
        $n = count($userIds);
        if ($n === 0) {
            return [];
        }
        $total = self::toCents($amount);
        $base  = intdiv($total, $n);
        $rem   = $total - $base * $n;

        $cents = array_fill_keys($userIds, $base);
        $cents[in_array($payerId, $userIds, true) ? $payerId : $userIds[0]] += $rem;

        return array_map([self::class, 'fromCents'], $cents);
    }

    /**
     * Rescale shares (e.g. custom amounts typed in a foreign currency) so they add up exactly
     * to $total, keeping their proportions. The rounding remainder goes to the payer when the
     * payer is a participant, otherwise to the largest share. Zero/negative weights are dropped.
     *
     * @param array<int, float> $weights user id => amount
     * @return array<int, float> user id => share
     */
    public static function scaleShares(array $weights, float $total, int $payerId): array
    {
        $weights = array_filter($weights, fn($w) => (float) $w > 0);
        if (empty($weights)) {
            return [];
        }
        $sum        = array_sum(array_map('floatval', $weights));
        $totalCents = self::toCents($total);

        $cents = [];
        foreach ($weights as $uid => $w) {
            $cents[(int) $uid] = (int) floor($totalCents * ((float) $w / $sum));
        }
        $rem = $totalCents - array_sum($cents);
        if ($rem !== 0) {
            if (isset($cents[$payerId])) {
                $target = $payerId;
            } else {
                $target = array_keys($cents, max($cents), true)[0];
            }
            $cents[$target] += $rem;
        }
        return array_map([self::class, 'fromCents'], $cents);
    }

    /**
     * True when the amounts add up to $total within the tolerance and none is negative.
     *
     * @param array<int, float> $amounts
     */
    public static function customSumMatches(array $amounts, float $total, float $tolerance = self::TOLERANCE): bool
    {
        foreach ($amounts as $a) {
            if ((float) $a < 0) {
                return false;
            }
        }
        $diff = abs(self::toCents(array_sum(array_map('floatval', $amounts))) - self::toCents($total));
        return $diff <= self::toCents($tolerance);
    }

    /**
     * Net balance per member. Positive = the family owes them, negative = they owe.
     *
     * @param array<int, array{payer_id:int|string, user_id:int|string, share:float|string}> $splitRows
     *        one row per (expense, participant); the payer's own share is ignored
     * @param array<int, array{from_user_id:int|string, to_user_id:int|string, amount:float|string}> $settlements
     *        "from paid to" — reduces what from owes to
     * @return array<int, float> user id => net (only members that appear)
     */
    public static function netBalances(array $splitRows, array $settlements): array
    {
        $net = [];
        foreach ($splitRows as $r) {
            $payer = (int) $r['payer_id'];
            $user  = (int) $r['user_id'];
            $share = self::toCents((float) $r['share']);
            $net[$payer] ??= 0;
            $net[$user]  ??= 0;
            if ($payer === $user || $share === 0) {
                continue;
            }
            $net[$payer] += $share;
            $net[$user]  -= $share;
        }
        foreach ($settlements as $s) {
            $from = (int) $s['from_user_id'];
            $to   = (int) $s['to_user_id'];
            $amt  = self::toCents((float) $s['amount']);
            $net[$from] ??= 0;
            $net[$to]   ??= 0;
            if ($from === $to) {
                continue;
            }
            $net[$from] += $amt;
            $net[$to]   -= $amt;
        }
        ksort($net);
        return array_map([self::class, 'fromCents'], $net);
    }

    /**
     * Turn net balances into a small set of transfers: repeatedly the largest debtor pays the
     * largest creditor (ties broken by lower user id, so the result is deterministic).
     *
     * @param array<int, float> $net user id => net balance (positive = is owed)
     * @return array<int, array{from:int, to:int, amount:float}>
     */
    public static function simplify(array $net): array
    {
        $credit = [];
        $debit  = [];
        foreach ($net as $uid => $amount) {
            $c = self::toCents((float) $amount);
            if ($c > 0) {
                $credit[(int) $uid] = $c;
            } elseif ($c < 0) {
                $debit[(int) $uid] = -$c;
            }
        }

        $pickMax = static function (array $m): int {
            $best = null;
            foreach ($m as $uid => $c) {
                if ($best === null || $c > $m[$best] || ($c === $m[$best] && $uid < $best)) {
                    $best = $uid;
                }
            }
            return $best;
        };

        $transfers = [];
        while (!empty($credit) && !empty($debit)) {
            $to   = $pickMax($credit);
            $from = $pickMax($debit);
            $amt  = min($credit[$to], $debit[$from]);
            $transfers[] = ['from' => $from, 'to' => $to, 'amount' => self::fromCents($amt)];
            $credit[$to]   -= $amt;
            $debit[$from]  -= $amt;
            if ($credit[$to] === 0) {
                unset($credit[$to]);
            }
            if ($debit[$from] === 0) {
                unset($debit[$from]);
            }
        }
        return $transfers;
    }

    /**
     * True when $shares is exactly what equalShares() would produce for the same people
     * (used to prefill the edit form in "Equal" mode).
     *
     * @param array<int, float> $shares
     */
    public static function isEqualSplit(array $shares, float $amount, int $payerId): bool
    {
        if (empty($shares)) {
            return false;
        }
        $expected = self::equalShares($amount, array_keys($shares), $payerId);
        foreach ($expected as $uid => $v) {
            if (!isset($shares[$uid]) || self::toCents((float) $shares[$uid]) !== self::toCents($v)) {
                return false;
            }
        }
        return true;
    }
}
