<?php

namespace App\Helpers;

use PDO;

/**
 * Single source of truth for bank balance snapshots.
 *
 * A bank's balance is its most recent row in bank_balances (by balance_date, then id).
 * Transactions move a balance by inserting a new snapshot = latest + delta.
 */
class BalanceHelper
{
    /**
     * Fetch a bank that belongs to the tenant, or null.
     */
    public static function bank(PDO $pdo, int $tenantId, int $bankId): ?array
    {
        $stmt = $pdo->prepare("SELECT id, bank_name, currency FROM banks WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$bankId, $tenantId]);
        $bank = $stmt->fetch(PDO::FETCH_ASSOC);
        return $bank ?: null;
    }

    /**
     * Latest snapshot for a tenant's bank (optionally as of a date), or null if none.
     * Legacy rows without bank_id are matched by bank name.
     */
    public static function latest(PDO $pdo, int $tenantId, array $bank, ?string $asOfDate = null): ?array
    {
        $sql = "SELECT amount, balance_date FROM bank_balances
                WHERE tenant_id = ?
                  AND (bank_id = ? OR (bank_id IS NULL AND bank_name = ?))";
        $params = [$tenantId, $bank['id'], $bank['bank_name']];
        if ($asOfDate !== null) {
            $sql .= " AND balance_date <= ?";
            $params[] = $asOfDate;
        }
        $sql .= " ORDER BY balance_date DESC, id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Move a bank's balance by $delta (negative = money out).
     *
     * The new snapshot is dated min($date, today), then max'd with the latest snapshot
     * date so a back-dated transaction still changes the *current* balance, and a
     * future-dated one never creates a future snapshot (the balance changes now).
     * The bank row is locked while reading + inserting so concurrent adjustments
     * cannot both start from the same balance.
     *
     * Returns false when the bank does not belong to the tenant.
     */
    public static function adjust(PDO $pdo, int $tenantId, int $userId, int $bankId, float $delta, string $date): bool
    {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // Lock the bank row (serialises adjustments of the same bank)
            $stmt = $pdo->prepare("SELECT id, bank_name, currency FROM banks WHERE id = ? AND tenant_id = ? FOR UPDATE");
            $stmt->execute([$bankId, $tenantId]);
            $bank = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$bank) {
                if ($ownTx) {
                    $pdo->rollBack();
                }
                return false;
            }

            $today      = date('Y-m-d');
            $latest     = self::latest($pdo, $tenantId, $bank, $today);
            $current    = $latest ? (float) $latest['amount'] : 0.0;
            $snapDate   = min($date, $today);
            if ($latest && $latest['balance_date'] > $snapDate) {
                $snapDate = $latest['balance_date'];
            }

            $stmt = $pdo->prepare("INSERT INTO bank_balances (user_id, tenant_id, bank_id, bank_name, amount, balance_date, currency)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId,
                $tenantId,
                $bank['id'],
                $bank['bank_name'],
                round($current + $delta, 2),
                $snapDate,
                $bank['currency'] ?: 'AED',
            ]);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return true;
    }

    /**
     * Month reconciliation inputs, all AED: opening balance, income, and money that left
     * the banks (non-credit-card expenses + card bill payments made from a bank).
     * Banks first recorded during the month contribute their first snapshot as opening.
     */
    public static function monthlyReconciliation(PDO $pdo, int $tenantId, int $month, int $year): array
    {
        $monthEnd     = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
        $prevMonthEnd = date('Y-m-t', mktime(0, 0, 0, $month - 1, 1, $year));

        $opening = self::totalAed($pdo, $tenantId, $prevMonthEnd);

        // Banks with no snapshot before this month: their first snapshot is the opening figure,
        // otherwise the whole balance would show up as a surplus
        $inrAed = null;
        $stmt = $pdo->prepare("
            SELECT COALESCE(b.currency, 'AED') AS currency, bb.amount
            FROM banks b
            JOIN bank_balances bb ON bb.id = (
                SELECT x.id FROM bank_balances x
                WHERE x.tenant_id = b.tenant_id
                  AND (x.bank_id = b.id OR (x.bank_id IS NULL AND x.bank_name = b.bank_name))
                ORDER BY x.balance_date ASC, x.id ASC
                LIMIT 1
            )
            WHERE b.tenant_id = ? AND bb.balance_date > ? AND bb.balance_date <= ?");
        $stmt->execute([$tenantId, $prevMonthEnd, $monthEnd]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $nb) {
            if (strtoupper($nb['currency']) === 'INR') {
                $inrAed = $inrAed ?? ExchangeRateHelper::getRate('INR', 'AED', $pdo);
                $opening += (float) $nb['amount'] * $inrAed;
            } else {
                $opening += (float) $nb['amount'];
            }
        }

        $stmt = $pdo->prepare("SELECT SUM(" . ExchangeRateHelper::aedSql($pdo) . ") FROM income WHERE tenant_id = ? AND MONTH(income_date) = ? AND YEAR(income_date) = ?");
        $stmt->execute([$tenantId, $month, $year]);
        $income = (float) ($stmt->fetchColumn() ?: 0);

        // Credit-card spending does not leave a bank account; the card bill payment does
        $stmt = $pdo->prepare("SELECT SUM(e.amount) FROM expenses e
            LEFT JOIN cards c ON c.id = e.card_id AND c.tenant_id = e.tenant_id
            WHERE e.tenant_id = ? AND MONTH(e.expense_date) = ? AND YEAR(e.expense_date) = ?
              AND NOT (e.payment_method = 'Card' AND c.card_type = 'Credit')");
        $stmt->execute([$tenantId, $month, $year]);
        $expense = (float) ($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->prepare("SELECT SUM(amount) FROM card_payments WHERE tenant_id = ? AND bank_id IS NOT NULL AND MONTH(payment_date) = ? AND YEAR(payment_date) = ?");
        $stmt->execute([$tenantId, $month, $year]);
        $expense += (float) ($stmt->fetchColumn() ?: 0);

        return ['opening' => $opening, 'income' => $income, 'expense' => $expense, 'actual' => self::totalAed($pdo, $tenantId, $monthEnd)];
    }

    /**
     * Latest snapshot per bank for the tenant, as of a date (default: today).
     * Returns rows: bank_id, bank_name, currency, amount, balance_date.
     * Banks without any snapshot are omitted.
     */
    public static function latestPerBank(PDO $pdo, int $tenantId, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?? date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT b.id AS bank_id, b.bank_name, COALESCE(b.currency, 'AED') AS currency,
                   bb.amount, bb.balance_date
            FROM banks b
            JOIN bank_balances bb ON bb.id = (
                SELECT x.id FROM bank_balances x
                WHERE x.tenant_id = b.tenant_id
                  AND (x.bank_id = b.id OR (x.bank_id IS NULL AND x.bank_name = b.bank_name))
                  AND x.balance_date <= ?
                ORDER BY x.balance_date DESC, x.id DESC
                LIMIT 1
            )
            WHERE b.tenant_id = ?
            ORDER BY b.bank_name
        ");
        $stmt->execute([$asOfDate, $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sum of latestPerBank() converted to AED.
     */
    public static function totalAed(PDO $pdo, int $tenantId, ?string $asOfDate = null): float
    {
        $rows  = self::latestPerBank($pdo, $tenantId, $asOfDate);
        $rate  = null;
        $total = 0.0;
        foreach ($rows as $r) {
            if (strtoupper($r['currency']) === 'INR') {
                $rate = $rate ?? ExchangeRateHelper::getRate('INR', 'AED', $pdo);
                $total += (float) $r['amount'] * $rate;
            } else {
                $total += (float) $r['amount'];
            }
        }
        return $total;
    }
}
