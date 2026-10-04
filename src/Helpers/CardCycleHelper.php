<?php

namespace App\Helpers;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Credit-card billing cycles: statement dates, payment due dates, what is still due,
 * utilization and cashback for the current cycle.
 *
 * Terms (per card):
 *   statement_day  day of month the statement closes (1–31; clamped to the month's last day)
 *   bill_day       day of month the payment is due (1–31; clamped). When bill_day <= statement_day
 *                  the due date falls in the month AFTER the statement, otherwise in the same month.
 *
 * A cycle is the half-open range (previous statement date, statement date]: a purchase made ON the
 * statement day belongs to that statement. "Today" is always inside the current cycle, so on the
 * statement day itself the current cycle ends today and the last statement is the month before.
 *
 * All amounts are AED (expenses.amount, card_payments.amount and the limit are stored in AED).
 *
 * The date math lives in pure static functions (no DB) so it can be tested from the CLI:
 * see tests/card_cycle_dates_test.php.
 */
class CardCycleHelper
{
    /** Days before the due date at which a card counts as "due soon". */
    public const DUE_SOON_DAYS = 5;

    // ─────────────────────────────────────────────────────────────────────────
    // Data
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * One row per credit card (of the tenant) that has a statement_day.
     *
     * Row keys:
     *   card_id                 int
     *   card_name               string
     *   bank_name               string   linked bank's name, else the card's own bank_name
     *   last_four               string   '' when unknown
     *   limit_amount            float
     *   statement_day           int
     *   bill_day                int|null null when the card has no due day configured
     *   current_cycle_start     string   Y-m-d (day after the last statement)
     *   current_cycle_end       string   Y-m-d (next statement date, >= today)
     *   last_statement_date     string   Y-m-d (< today)
     *   last_statement_due_date string|null Y-m-d, null without bill_day
     *   last_statement_amount   float    card spend in the previous cycle
     *   paid_since_statement    float    card payments after the last statement date up to today
     *   due_remaining           float    max(0, last_statement_amount − paid_since_statement)
     *   days_to_due             int|null negative when the due date has passed; null without bill_day
     *   current_cycle_spend     float    card spend in the current cycle
     *   outstanding_estimate    float    see below
     *   utilization_pct         float|null outstanding_estimate / limit × 100 (1 decimal); null without a limit
     *   cashback_expected       float    Σ current-cycle spend × the card's cashback rate for the category
     *   cashback_recorded       float    Σ expenses.cashback_earned in the current cycle
     *   status                  string   'overdue' | 'due_soon' | 'paid' | 'ok'
     *
     * outstanding_estimate = all spend ever recorded on the card − all payments ever recorded,
     * floored at 0. There is no opening-balance field, so this assumes the card was at zero when the
     * family started tracking it. A payment that cleared a balance from before tracking started would
     * push the figure below zero, which is why it is floored; spend never entered in the app makes it
     * an underestimate. It is a planning number, not the bank's figure.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cycles(PDO $pdo, int $tenantId, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');

        $stmt = $pdo->prepare(
            "SELECT c.id, c.card_name, COALESCE(b.bank_name, c.bank_name) AS bank_name, c.last_four,
                    c.limit_amount, c.statement_day, c.bill_day, c.cashback_struct
               FROM cards c
               LEFT JOIN banks b ON b.id = c.bank_id AND b.tenant_id = c.tenant_id
              WHERE c.tenant_id = ?
                AND c.card_type = 'Credit'
                AND c.statement_day BETWEEN 1 AND 31
              ORDER BY c.is_default DESC, c.card_name ASC"
        );
        $stmt->execute([$tenantId]);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$cards) {
            return [];
        }

        // Per category so expected cashback can apply the card's rate per category.
        $expStmt = $pdo->prepare(
            "SELECT category,
                    SUM(CASE WHEN expense_date > ? AND expense_date <= ? THEN amount ELSE 0 END)          AS prev_spend,
                    SUM(CASE WHEN expense_date > ? AND expense_date <= ? THEN amount ELSE 0 END)          AS cur_spend,
                    SUM(CASE WHEN expense_date > ? AND expense_date <= ? THEN cashback_earned ELSE 0 END) AS cur_cashback,
                    SUM(amount) AS total_spend
               FROM expenses
              WHERE tenant_id = ? AND card_id = ?
              GROUP BY category"
        );
        $payStmt = $pdo->prepare(
            "SELECT SUM(CASE WHEN payment_date > ? AND payment_date <= ? THEN amount ELSE 0 END) AS since_statement,
                    SUM(amount) AS total_paid
               FROM card_payments
              WHERE tenant_id = ? AND card_id = ?"
        );

        $rows = [];
        foreach ($cards as $card) {
            $cardId       = (int) $card['id'];
            $statementDay = (int) $card['statement_day'];
            $billDay      = self::validDay($card['bill_day']);
            $d            = self::cycleDates($today, $statementDay, $billDay);
            $rates        = self::cashbackRates($card['cashback_struct']);

            $expStmt->execute([
                $d['previous_cycle_start_exclusive'], $d['last_statement_date'],
                $d['last_statement_date'], $d['current_cycle_end'],
                $d['last_statement_date'], $d['current_cycle_end'],
                $tenantId, $cardId,
            ]);
            $prevSpend = $curSpend = $cbRecorded = $totalSpend = $cbExpected = 0.0;
            foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
                $prevSpend  += (float) $e['prev_spend'];
                $curSpend   += (float) $e['cur_spend'];
                $cbRecorded += (float) $e['cur_cashback'];
                $totalSpend += (float) $e['total_spend'];
                $cbExpected += (float) $e['cur_spend'] * self::rateFor($rates, (string) $e['category']) / 100;
            }

            $payStmt->execute([$d['last_statement_date'], $today, $tenantId, $cardId]);
            $pay        = $payStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $paidSince  = (float) ($pay['since_statement'] ?? 0);
            $totalPaid  = (float) ($pay['total_paid'] ?? 0);

            $dueRemaining = max(0.0, round($prevSpend - $paidSince, 2));
            $daysToDue    = $d['last_statement_due_date'] !== null ? self::daysBetween($today, $d['last_statement_due_date']) : null;
            $outstanding  = max(0.0, round($totalSpend - $totalPaid, 2));
            $limit        = (float) $card['limit_amount'];

            $rows[] = [
                'card_id'                 => $cardId,
                'card_name'               => (string) $card['card_name'],
                'bank_name'               => (string) $card['bank_name'],
                'last_four'               => (string) ($card['last_four'] ?? ''),
                'limit_amount'            => $limit,
                'statement_day'           => $statementDay,
                'bill_day'                => $billDay,
                'current_cycle_start'     => $d['current_cycle_start'],
                'current_cycle_end'       => $d['current_cycle_end'],
                'last_statement_date'     => $d['last_statement_date'],
                'last_statement_due_date' => $d['last_statement_due_date'],
                'last_statement_amount'   => round($prevSpend, 2),
                'paid_since_statement'    => round($paidSince, 2),
                'due_remaining'           => $dueRemaining,
                'days_to_due'             => $daysToDue,
                'current_cycle_spend'     => round($curSpend, 2),
                'outstanding_estimate'    => $outstanding,
                'utilization_pct'         => $limit > 0 ? round($outstanding / $limit * 100, 1) : null,
                'cashback_expected'       => round($cbExpected, 2),
                'cashback_recorded'       => round($cbRecorded, 2),
                'status'                  => self::statusFor($dueRemaining, round($prevSpend, 2), $daysToDue),
            ];
        }
        return $rows;
    }

    /**
     * Cards with an unpaid statement that is overdue or due within $days, soonest first.
     * Same row shape as cycles(); every returned row has a non-null last_statement_due_date
     * and days_to_due, and due_remaining > 0.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function upcoming(PDO $pdo, int $tenantId, int $days = 14, ?string $today = null): array
    {
        $rows = array_values(array_filter(
            self::cycles($pdo, $tenantId, $today),
            function (array $r) use ($days): bool {
                return $r['due_remaining'] > 0 && $r['days_to_due'] !== null && $r['days_to_due'] <= $days;
            }
        ));
        usort($rows, function (array $a, array $b): int {
            return [$a['last_statement_due_date'], $a['card_name']] <=> [$b['last_statement_due_date'], $b['card_name']];
        });
        return $rows;
    }

    /** Cycle row for one card, or null (not a credit card / no statement day / other tenant). */
    public static function forCard(PDO $pdo, int $tenantId, int $cardId, ?string $today = null): ?array
    {
        foreach (self::cycles($pdo, $tenantId, $today) as $row) {
            if ($row['card_id'] === $cardId) {
                return $row;
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure date math (no DB)
    // ─────────────────────────────────────────────────────────────────────────

    /** Y-m-d for $day in the given month, clamped to the month's last day (31 → 28/29/30). */
    public static function clampedDate(int $year, int $month, int $day): string
    {
        // Normalise month overflow/underflow (month 0 → December of the previous year, 13 → January).
        $index = $year * 12 + ($month - 1);
        $year  = intdiv($index, 12);
        $month = $index % 12 + 1;
        $last  = (int) self::date(sprintf('%04d-%02d-01', $year, $month))->format('t');
        return sprintf('%04d-%02d-%02d', $year, $month, max(1, min($day, $last)));
    }

    /** Earliest statement date on or after $date. */
    public static function nextStatementOnOrAfter(string $date, int $statementDay): string
    {
        $d    = self::date($date);
        $y    = (int) $d->format('Y');
        $m    = (int) $d->format('n');
        $sameMonth = self::clampedDate($y, $m, $statementDay);
        return $sameMonth >= $date ? $sameMonth : self::clampedDate($y, $m + 1, $statementDay);
    }

    /** The statement date one month before the given statement date. */
    public static function previousStatement(string $statementDate, int $statementDay): string
    {
        $d = self::date($statementDate);
        return self::clampedDate((int) $d->format('Y'), (int) $d->format('n') - 1, $statementDay);
    }

    /**
     * Payment due date for a statement. bill_day <= statement_day (as configured, before clamping)
     * means the following month; otherwise the same month. A clamped due date that does not fall
     * after the statement date (e.g. statement 29 / due 30 in a 28-day February) moves to the next month.
     */
    public static function dueDateFor(string $statementDate, int $statementDay, int $billDay): string
    {
        $d = self::date($statementDate);
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n');
        if ($billDay > $statementDay) {
            $due = self::clampedDate($y, $m, $billDay);
            if ($due > $statementDate) {
                return $due;
            }
        }
        return self::clampedDate($y, $m + 1, $billDay);
    }

    /**
     * All cycle boundaries for a card on $today.
     *
     * @return array{current_cycle_start: string, current_cycle_end: string, last_statement_date: string,
     *               previous_cycle_start_exclusive: string, last_statement_due_date: string|null}
     */
    public static function cycleDates(string $today, int $statementDay, ?int $billDay): array
    {
        $end      = self::nextStatementOnOrAfter($today, $statementDay);
        $last     = self::previousStatement($end, $statementDay);
        $prevLast = self::previousStatement($last, $statementDay);
        return [
            'current_cycle_start'            => self::addDays($last, 1),
            'current_cycle_end'              => $end,
            'last_statement_date'            => $last,
            'previous_cycle_start_exclusive' => $prevLast,
            'last_statement_due_date'        => $billDay !== null ? self::dueDateFor($last, $statementDay, $billDay) : null,
        ];
    }

    /** Whole days from $from to $to (negative when $to is earlier). */
    public static function daysBetween(string $from, string $to): int
    {
        return (int) self::date($from)->diff(self::date($to))->format('%r%a');
    }

    public static function addDays(string $date, int $days): string
    {
        return self::date($date)->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** 'overdue' | 'due_soon' | 'paid' | 'ok'. */
    public static function statusFor(float $dueRemaining, float $lastStatementAmount, ?int $daysToDue): string
    {
        if ($dueRemaining <= 0) {
            return $lastStatementAmount > 0 ? 'paid' : 'ok';
        }
        if ($daysToDue === null) {
            return 'ok';
        }
        if ($daysToDue < 0) {
            return 'overdue';
        }
        return $daysToDue <= self::DUE_SOON_DAYS ? 'due_soon' : 'ok';
    }

    /**
     * Category → percent from cards.cashback_struct. Older rows may hold a plain list of
     * categories (no rates); those yield no rates.
     *
     * @return array<string, float>
     */
    public static function cashbackRates(?string $json): array
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            return [];
        }
        $rates = [];
        foreach ($data as $cat => $pct) {
            if (is_string($cat) && is_numeric($pct) && (float) $pct > 0) {
                $rates[$cat] = (float) $pct;
            }
        }
        return $rates;
    }

    /** Rate for a category, falling back to the card's 'Other' rate. */
    public static function rateFor(array $rates, string $category): float
    {
        return (float) ($rates[$category] ?? $rates['Other'] ?? 0);
    }

    /** Bootstrap colour for a utilization percentage: <30 green, <70 amber, else red. */
    public static function utilizationColor(?float $pct): string
    {
        if ($pct === null || $pct < 30) {
            return 'success';
        }
        return $pct < 70 ? 'warning' : 'danger';
    }

    /** Bootstrap colour for a status from statusFor(). */
    public static function statusColor(string $status): string
    {
        $map = ['overdue' => 'danger', 'due_soon' => 'warning', 'paid' => 'success', 'ok' => 'secondary'];
        return $map[$status] ?? 'secondary';
    }

    /** Short human label for days_to_due ("Due today", "3 days left", "2 days overdue"). */
    public static function dueLabel(?int $daysToDue): string
    {
        if ($daysToDue === null) {
            return 'No due day set';
        }
        if ($daysToDue === 0) {
            return 'Due today';
        }
        if ($daysToDue > 0) {
            return $daysToDue === 1 ? '1 day left' : $daysToDue . ' days left';
        }
        $n = -$daysToDue;
        return $n === 1 ? '1 day overdue' : $n . ' days overdue';
    }

    private static function validDay($value): ?int
    {
        $v = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31]]);
        return $v === false ? null : $v;
    }

    private static function date(string $ymd): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
        if ($d === false) {
            throw new \InvalidArgumentException('Invalid date: ' . $ymd);
        }
        return $d;
    }
}
