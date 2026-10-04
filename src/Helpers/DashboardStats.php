<?php

namespace App\Helpers;

use PDO;

/**
 * Batched read-only statistics for the dashboard.
 *
 * Everything here returns the same numbers as the per-call helpers it replaces,
 * just with fewer round trips to the database.
 */
class DashboardStats
{
    /**
     * Total bank balance in AED as of each of several dates, in ONE query.
     *
     * Equivalent to calling BalanceHelper::totalAed($pdo, $tenantId, $date) once per date:
     *  - the inner correlated subquery is the one BalanceHelper::latestPerBank() uses
     *    (tenant-scoped; matches bank_id, or legacy rows with NULL bank_id by bank_name;
     *    latest by balance_date DESC, id DESC; only snapshots dated on/before the as-of date),
     *    evaluated once per (as-of date, bank) pair instead of once per bank per query;
     *  - banks without a snapshot on/before a date are dropped by the inner JOIN, as before;
     *  - rows come back ordered by bank_name within each date, the same order totalAed() sums in;
     *  - INR banks are converted with ExchangeRateHelper::getRate('INR','AED') (memoised per
     *    request, so the same rate totalAed() would use); every other currency is added as-is.
     *
     * @param string[] $asOfDates 'Y-m-d' dates (duplicates are fine)
     * @return array<string, float> date => total in AED (0.0 when no bank has a snapshot yet)
     */
    public static function balanceTotalsAed(PDO $pdo, int $tenantId, array $asOfDates): array
    {
        $dates = [];
        foreach ($asOfDates as $d) {
            $d = (string) $d;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $dates[$d] = true;
            }
        }
        $dates = array_keys($dates);
        if (!$dates) {
            return [];
        }

        $totals = array_fill_keys($dates, 0.0);
        $asOf   = implode(' UNION ALL ', array_fill(0, count($dates), 'SELECT CAST(? AS DATE) AS as_of'));

        $stmt = $pdo->prepare("
            SELECT d.as_of, COALESCE(b.currency, 'AED') AS currency, bb.amount
            FROM banks b
            CROSS JOIN ($asOf) d
            JOIN bank_balances bb ON bb.id = (
                SELECT x.id FROM bank_balances x
                WHERE x.tenant_id = b.tenant_id
                  AND (x.bank_id = b.id OR (x.bank_id IS NULL AND x.bank_name = b.bank_name))
                  AND x.balance_date <= d.as_of
                ORDER BY x.balance_date DESC, x.id DESC
                LIMIT 1
            )
            WHERE b.tenant_id = ?
            ORDER BY d.as_of, b.bank_name
        ");
        $stmt->execute(array_merge($dates, [$tenantId]));

        $rate = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $date = substr((string) $r['as_of'], 0, 10);
            if (!isset($totals[$date])) {
                continue;
            }
            if (strtoupper($r['currency']) === 'INR') {
                $rate = $rate ?? ExchangeRateHelper::getRate('INR', 'AED', $pdo);
                $totals[$date] += (float) $r['amount'] * $rate;
            } else {
                $totals[$date] += (float) $r['amount'];
            }
        }
        return $totals;
    }

    /**
     * 'YYYY-MM' key for a timestamp, matching the y/m columns of a GROUP BY YEAR(), MONTH() query.
     */
    public static function monthKey(int $ts): string
    {
        return date('Y-m', $ts);
    }

    /**
     * Index rows from a "GROUP BY YEAR(col) AS y, MONTH(col) AS m" query by 'YYYY-MM'.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function byMonth(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[sprintf('%04d-%02d', (int) $r['y'], (int) $r['m'])] = $r;
        }
        return $out;
    }

    /**
     * Exact sum of money values with 2 decimals (DECIMAL(15,2) columns): adds whole cents so
     * the result equals the database SUM() instead of accumulating float error.
     *
     * @param iterable<mixed> $values numeric strings / numbers / nulls (nulls are skipped, like SUM())
     */
    public static function sumMoney(iterable $values): float
    {
        $cents = 0;
        foreach ($values as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $cents += (int) round((float) $v * 100);
        }
        return $cents / 100;
    }
}
