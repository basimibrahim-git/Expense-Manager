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
     * The new snapshot is dated max($date, latest snapshot date) so a back-dated
     * transaction still changes the *current* balance instead of hiding behind a
     * newer snapshot.
     *
     * Returns false when the bank does not belong to the tenant.
     */
    public static function adjust(PDO $pdo, int $tenantId, int $userId, int $bankId, float $delta, string $date): bool
    {
        $bank = self::bank($pdo, $tenantId, $bankId);
        if (!$bank) {
            return false;
        }

        $latest     = self::latest($pdo, $tenantId, $bank);
        $current    = $latest ? (float) $latest['amount'] : 0.0;
        $snapDate   = ($latest && $latest['balance_date'] > $date) ? $latest['balance_date'] : $date;

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
        return true;
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
