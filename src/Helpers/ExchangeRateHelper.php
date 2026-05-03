<?php

namespace App\Helpers;

use PDO;
use Exception;

class ExchangeRateHelper
{
    /** Hardcoded fallback rates when no cached rate exists and API is unreachable */
    private const FALLBACK_RATES = [
        'AED' => ['INR' => 22.5],
        'INR' => ['AED' => 0.044],
    ];

    /**
     * Returns the exchange rate from $from currency to $to currency.
     * Checks a DB cache first (6-hour TTL), then fetches from frankfurter.app.
     * Falls back to last known cached rate, then to hardcoded constants.
     */
    public static function getRate(string $from, string $to, PDO $pdo): float
    {
        if ($from === $to) {
            return 1.0;
        }

        // Ensure the cache table exists
        self::ensureTable($pdo);

        // 1. Check cache (fresh within 6 hours)
        $cached = self::fetchCached($pdo, $from, $to, true);
        if ($cached !== null) {
            return $cached;
        }

        // 2. Fetch live rate from API
        $rate = self::fetchFromApi($from, $to);

        if ($rate !== null) {
            // 3. Upsert into cache
            self::upsertCache($pdo, $from, $to, $rate);
            return $rate;
        }

        // 4. API failed — fall back to any cached rate (ignore expiry)
        error_log("ExchangeRateHelper: API fetch failed for {$from}→{$to}. Trying stale cache.");
        $stale = self::fetchCached($pdo, $from, $to, false);
        if ($stale !== null) {
            return $stale;
        }

        // 5. No cache at all — use hardcoded fallback
        error_log("ExchangeRateHelper: No cached rate for {$from}→{$to}. Using hardcoded fallback.");
        return self::FALLBACK_RATES[$from][$to] ?? 1.0;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function ensureTable(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS exchange_rate_cache (
            from_currency CHAR(3)        NOT NULL,
            to_currency   CHAR(3)        NOT NULL,
            rate          DECIMAL(15,6)  NOT NULL,
            fetched_at    DATETIME       NOT NULL,
            PRIMARY KEY (from_currency, to_currency)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Returns a cached rate or null.
     * When $requireFresh is true, only rows fetched within the last 6 hours qualify.
     */
    private static function fetchCached(PDO $pdo, string $from, string $to, bool $requireFresh): ?float
    {
        if ($requireFresh) {
            $sql = "SELECT rate FROM exchange_rate_cache
                    WHERE from_currency = ? AND to_currency = ?
                      AND fetched_at > NOW() - INTERVAL 6 HOUR
                    LIMIT 1";
        } else {
            $sql = "SELECT rate FROM exchange_rate_cache
                    WHERE from_currency = ? AND to_currency = ?
                    LIMIT 1";
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$from, $to]);
            $row = $stmt->fetchColumn();
            return ($row !== false) ? (float) $row : null;
        } catch (Exception $e) {
            error_log("ExchangeRateHelper: DB read error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetches a live rate from frankfurter.app with a 5-second timeout.
     * Returns null on any failure.
     */
    private static function fetchFromApi(string $from, string $to): ?float
    {
        $url = "https://api.frankfurter.app/latest?base={$from}&symbols={$to}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'ExpenseManager/1.0',
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode !== 200) {
            error_log("ExchangeRateHelper: curl failed for {$url} (HTTP {$httpCode}) {$curlErr}");
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['rates'][$to])) {
            error_log("ExchangeRateHelper: Unexpected API response: {$raw}");
            return null;
        }

        return (float) $data['rates'][$to];
    }

    /**
     * Inserts or updates the cached rate for a currency pair.
     */
    private static function upsertCache(PDO $pdo, string $from, string $to, float $rate): void
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO exchange_rate_cache (from_currency, to_currency, rate, fetched_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE rate = VALUES(rate), fetched_at = NOW()"
            );
            $stmt->execute([$from, $to, $rate]);
        } catch (Exception $e) {
            error_log("ExchangeRateHelper: DB write error: " . $e->getMessage());
        }
    }
}
