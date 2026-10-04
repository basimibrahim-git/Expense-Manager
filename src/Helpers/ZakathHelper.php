<?php

namespace App\Helpers;

use DateTimeImmutable;
use DateTimeInterface;
use PDO;
use Throwable;

/**
 * Zakath rules, settings, metal prices and hawl (lunar year) tracking.
 *
 * Rules implemented (also shown to the user in the calculator's help box):
 *  - Nisab = nisab grams of the chosen metal x that metal's price per gram (AED).
 *    Basis is per family: silver (default; lower threshold, so more wealth qualifies,
 *    which is the cautious choice in favour of the poor) or gold.
 *    Gold 85 g (contemporary) or 87.48 g (7.5 tola); silver 612.36 g (52.5 tola) or 595 g.
 *  - Net zakatable wealth = cash + gold/silver + investments + receivables
 *    − debts due within the coming year (never below zero).
 *  - If net wealth >= nisab, Zakath = 2.5% of the WHOLE net wealth (not just the excess).
 *    Below nisab, nothing is due.
 *  - Hawl: one lunar year (354 days) from the date wealth first reached nisab.
 *    The due date repeats every 354 days from the family's hawl start date.
 *
 * Metal prices: spot XAU/XAG in USD per troy ounce, converted with
 * 1 troy oz = 31.1034768 g and the AED peg 1 USD = 3.6725 AED. Cached in
 * zakath_metal_prices for 12 hours; on failure the last cached price is used, then
 * the family's manual price. Provider is pluggable via .env:
 *   ZAKATH_METALS_PROVIDER = gold-api (default, keyless) | metalpriceapi | none
 *   ZAKATH_METALS_API_KEY  = key for providers that need one (metalpriceapi)
 */
class ZakathHelper
{
    public const RATE                 = 0.025;
    public const HAWL_DAYS            = 354;
    public const GRAMS_PER_TROY_OUNCE = 31.1034768;
    public const AED_PER_USD          = 3.6725;
    public const CACHE_HOURS          = 12;
    public const METALS               = ['gold', 'silver'];

    /** Allowed range for custom nisab weights (grams). */
    public const GOLD_GRAMS_RANGE   = [50.0, 100.0];
    public const SILVER_GRAMS_RANGE = [400.0, 700.0];

    public const GOLD_GRAM_PRESETS = [
        '85'    => '85 g — contemporary figure (most fatwa councils)',
        '87.48' => '87.48 g — 7.5 tola (classical)',
    ];
    public const SILVER_GRAM_PRESETS = [
        '612.36' => '612.36 g — 52.5 tola (classical)',
        '595'    => '595 g — contemporary figure',
    ];

    public const DEFAULTS = [
        'nisab_basis'              => 'silver',
        'gold_grams'               => 85.0,
        'silver_grams'             => 612.36,
        'hawl_start_date'          => null,
        'use_manual_prices'        => 0,
        'manual_gold_price'        => null,
        'manual_silver_price'      => null,
        'manual_prices_updated_at' => null,
        'updated_at'               => null,
    ];

    /** Don't retry a failed price fetch more often than this (keeps pages fast when the API is down). */
    private const RETRY_MINUTES = 30;
    /** Per-request timeouts; both metals are fetched in parallel, so a page waits at most ~4 s. */
    private const CONNECT_TIMEOUT = 2;
    private const TOTAL_TIMEOUT   = 4;

    /** Sanity bounds for spot prices (USD per troy ounce) — rejects garbage responses. */
    private const USD_OZ_BOUNDS = ['gold' => [200.0, 50000.0], 'silver' => [2.0, 2000.0]];

    /** Resolved within this request. */
    private static ?array $marketMemo = null;

    // ── Settings ──────────────────────────────────────────────────────────────

    /**
     * The family's Zakath settings merged over the defaults.
     * Adds '_missing' => true when the table doesn't exist yet (migration not run).
     */
    public static function settings(PDO $pdo, int $tenantId): array
    {
        $settings = self::DEFAULTS;
        try {
            $stmt = $pdo->prepare("SELECT * FROM zakath_settings WHERE tenant_id = ?");
            $stmt->execute([$tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                foreach (self::DEFAULTS as $key => $default) {
                    if (array_key_exists($key, $row)) {
                        $settings[$key] = $row[$key];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('ZakathHelper: settings read failed (run migrations/2026_10_05_zakath.sql?): ' . $e->getMessage());
            $settings['_missing'] = true;
        }

        $settings['nisab_basis']       = $settings['nisab_basis'] === 'gold' ? 'gold' : 'silver';
        $settings['gold_grams']        = (float) $settings['gold_grams'];
        $settings['silver_grams']      = (float) $settings['silver_grams'];
        $settings['use_manual_prices'] = (int) $settings['use_manual_prices'];
        foreach (['manual_gold_price', 'manual_silver_price'] as $k) {
            $settings[$k] = ($settings[$k] === null || $settings[$k] === '') ? null : (float) $settings[$k];
        }
        return $settings;
    }

    /**
     * Insert or update the family's settings. Values must already be validated.
     * manual_prices_updated_at only moves when a manual price actually changes.
     */
    public static function saveSettings(PDO $pdo, int $tenantId, array $s): void
    {
        $manualStamp = ($s['manual_gold_price'] !== null || $s['manual_silver_price'] !== null) ? date('Y-m-d H:i:s') : null;

        // Note: in ON DUPLICATE KEY UPDATE, later assignments see earlier ones, so the
        // timestamp is compared against the OLD prices before they are overwritten.
        $stmt = $pdo->prepare(
            "INSERT INTO zakath_settings
                (tenant_id, nisab_basis, gold_grams, silver_grams, hawl_start_date,
                 use_manual_prices, manual_gold_price, manual_silver_price, manual_prices_updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                manual_prices_updated_at = IF(manual_gold_price <=> VALUES(manual_gold_price)
                                              AND manual_silver_price <=> VALUES(manual_silver_price),
                                              manual_prices_updated_at, VALUES(manual_prices_updated_at)),
                nisab_basis         = VALUES(nisab_basis),
                gold_grams          = VALUES(gold_grams),
                silver_grams        = VALUES(silver_grams),
                hawl_start_date     = VALUES(hawl_start_date),
                use_manual_prices   = VALUES(use_manual_prices),
                manual_gold_price   = VALUES(manual_gold_price),
                manual_silver_price = VALUES(manual_silver_price)"
        );
        $stmt->execute([
            $tenantId,
            $s['nisab_basis'],
            $s['gold_grams'],
            $s['silver_grams'],
            $s['hawl_start_date'],
            (int) $s['use_manual_prices'],
            $s['manual_gold_price'],
            $s['manual_silver_price'],
            $manualStamp,
        ]);
    }

    // ── Metal prices ──────────────────────────────────────────────────────────

    /** Configured price provider: gold-api | metalpriceapi | none. */
    public static function provider(): string
    {
        $p = strtolower(trim((string) ($_ENV['ZAKATH_METALS_PROVIDER'] ?? 'gold-api')));
        return in_array($p, ['gold-api', 'metalpriceapi', 'none'], true) ? $p : 'gold-api';
    }

    /** Human-readable name of the configured provider (for "source" hints). */
    public static function providerLabel(?string $provider = null): string
    {
        $labels = ['gold-api' => 'gold-api.com', 'metalpriceapi' => 'metalpriceapi.com', 'none' => 'manual only'];
        return $labels[$provider ?? self::provider()] ?? (string) $provider;
    }

    public static function usdOunceToAedGram(float $usdPerOunce): float
    {
        return $usdPerOunce * self::AED_PER_USD / self::GRAMS_PER_TROY_OUNCE;
    }

    /**
     * Market prices from the cache, refreshing them when older than 12 hours.
     * Returns per metal: usd_per_ounce, aed_per_gram, source, fetched_at, fresh (bool) — or null.
     *
     * @return array{gold: ?array, silver: ?array}
     */
    public static function marketPrices(PDO $pdo, bool $forceRefresh = false): array
    {
        if (self::$marketMemo !== null && !$forceRefresh) {
            return self::$marketMemo;
        }

        $rows = self::cachedPrices($pdo);
        if ($rows === null) {
            // Table missing (migration not run): don't hit the network on every page load.
            return self::$marketMemo = ['gold' => null, 'silver' => null];
        }
        $needsRefresh = $forceRefresh;
        $recentAttempt = false;
        foreach (self::METALS as $m) {
            $r = $rows[$m] ?? null;
            if (!$r || $r['aed_per_gram'] === null || !$r['fresh']) {
                $needsRefresh = true;
            }
            if ($r && $r['attempted_at'] && strtotime($r['attempted_at']) > time() - self::RETRY_MINUTES * 60) {
                $recentAttempt = true;
            }
        }

        if ($needsRefresh && ($forceRefresh || !$recentAttempt) && self::provider() !== 'none') {
            self::markAttempt($pdo);
            $live = self::fetchLive();
            foreach ($live as $metal => $usdPerOunce) {
                if ($usdPerOunce !== null) {
                    self::storePrice($pdo, $metal, $usdPerOunce);
                }
            }
            $rows = self::cachedPrices($pdo) ?? [];
        }

        $out = ['gold' => null, 'silver' => null];
        foreach (self::METALS as $m) {
            $r = $rows[$m] ?? null;
            if ($r && $r['aed_per_gram'] !== null) {
                $out[$m] = [
                    'usd_per_ounce' => $r['usd_per_ounce'],
                    'aed_per_gram'  => $r['aed_per_gram'],
                    'source'        => $r['source'],
                    'fetched_at'    => $r['fetched_at'],
                    'fresh'         => $r['fresh'],
                ];
            }
        }
        self::$marketMemo = $out;
        return $out;
    }

    /**
     * Effective price per gram (AED) for each metal for this family:
     * manual (when the family chose manual prices) → market (fresh or last cached) → manual → none.
     *
     * Per metal: ['aed_per_gram' => ?float, 'kind' => 'market'|'manual'|'none',
     *             'stale' => bool, 'as_of' => ?string, 'source' => string]
     */
    public static function prices(PDO $pdo, array $settings, bool $forceRefresh = false): array
    {
        $market = [];
        try {
            $market = self::marketPrices($pdo, $forceRefresh);
        } catch (Throwable $e) {
            error_log('ZakathHelper: market price lookup failed: ' . $e->getMessage());
        }

        $out = [];
        foreach (self::METALS as $m) {
            $manual = $settings['manual_' . $m . '_price'] ?? null;
            $mk     = $market[$m] ?? null;
            $manualEntry = [
                'aed_per_gram' => $manual,
                'kind'         => 'manual',
                'stale'        => false,
                'as_of'        => $settings['manual_prices_updated_at'] ?? null,
                'source'       => 'your manual price',
            ];
            if (!empty($settings['use_manual_prices']) && $manual !== null) {
                $out[$m] = $manualEntry;
            } elseif ($mk !== null) {
                $out[$m] = [
                    'aed_per_gram' => (float) $mk['aed_per_gram'],
                    'kind'         => 'market',
                    'stale'        => !$mk['fresh'],
                    'as_of'        => $mk['fetched_at'],
                    'source'       => self::providerLabel($mk['source'] ?: null) . ($mk['fresh'] ? '' : ' (last known price)'),
                ];
            } elseif ($manual !== null) {
                $manualEntry['source'] = 'your manual price (market price unavailable)';
                $out[$m] = $manualEntry;
            } else {
                $out[$m] = ['aed_per_gram' => null, 'kind' => 'none', 'stale' => false, 'as_of' => null, 'source' => 'unavailable'];
            }
        }
        return $out;
    }

    /** @return array<string, array>|null cached rows keyed by metal, with a 'fresh' flag; null when unreadable */
    private static function cachedPrices(PDO $pdo): ?array
    {
        $rows = [];
        try {
            $stmt = $pdo->query(
                "SELECT metal, usd_per_ounce, aed_per_gram, source, fetched_at, attempted_at,
                        (fetched_at IS NOT NULL AND fetched_at > NOW() - INTERVAL " . (int) self::CACHE_HOURS . " HOUR) AS fresh
                   FROM zakath_metal_prices"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[$r['metal']] = [
                    'usd_per_ounce' => $r['usd_per_ounce'] !== null ? (float) $r['usd_per_ounce'] : null,
                    'aed_per_gram'  => $r['aed_per_gram'] !== null ? (float) $r['aed_per_gram'] : null,
                    'source'        => $r['source'],
                    'fetched_at'    => $r['fetched_at'],
                    'attempted_at'  => $r['attempted_at'],
                    'fresh'         => (bool) $r['fresh'],
                ];
            }
        } catch (Throwable $e) {
            error_log('ZakathHelper: price cache read failed: ' . $e->getMessage());
            return null;
        }
        return $rows;
    }

    private static function markAttempt(PDO $pdo): void
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO zakath_metal_prices (metal, attempted_at) VALUES (?, NOW())
                 ON DUPLICATE KEY UPDATE attempted_at = NOW()"
            );
            foreach (self::METALS as $m) {
                $stmt->execute([$m]);
            }
        } catch (Throwable $e) {
            error_log('ZakathHelper: price cache write failed: ' . $e->getMessage());
        }
    }

    private static function storePrice(PDO $pdo, string $metal, float $usdPerOunce): void
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO zakath_metal_prices (metal, usd_per_ounce, aed_per_gram, source, fetched_at, attempted_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE usd_per_ounce = VALUES(usd_per_ounce), aed_per_gram = VALUES(aed_per_gram),
                                         source = VALUES(source), fetched_at = NOW(), attempted_at = NOW()"
            );
            $stmt->execute([$metal, round($usdPerOunce, 4), round(self::usdOunceToAedGram($usdPerOunce), 4), self::provider()]);
        } catch (Throwable $e) {
            error_log('ZakathHelper: price cache write failed: ' . $e->getMessage());
        }
    }

    /**
     * Spot prices in USD per troy ounce from the configured provider.
     *
     * @return array{gold: ?float, silver: ?float}
     */
    private static function fetchLive(): array
    {
        $out = ['gold' => null, 'silver' => null];
        if (!function_exists('curl_multi_init')) {
            error_log('ZakathHelper: curl extension not available; metal prices cannot be fetched.');
            return $out;
        }

        switch (self::provider()) {
            case 'gold-api':
                // Keyless public API: {"name":"Gold","price":<USD per troy oz>,"symbol":"XAU",...}
                $res = self::httpGetJsonMulti([
                    'gold'   => 'https://api.gold-api.com/price/XAU',
                    'silver' => 'https://api.gold-api.com/price/XAG',
                ]);
                foreach (self::METALS as $m) {
                    if (isset($res[$m]['price']) && is_numeric($res[$m]['price'])) {
                        $out[$m] = (float) $res[$m]['price'];
                    }
                }
                break;

            case 'metalpriceapi':
                // Needs ZAKATH_METALS_API_KEY. rates.USDXAU = USD per oz (or 1 / rates.XAU).
                $key = trim((string) ($_ENV['ZAKATH_METALS_API_KEY'] ?? ''));
                if ($key === '') {
                    error_log('ZakathHelper: ZAKATH_METALS_PROVIDER=metalpriceapi but ZAKATH_METALS_API_KEY is empty.');
                    break;
                }
                $res = self::httpGetJsonMulti([
                    'all' => 'https://api.metalpriceapi.com/v1/latest?api_key=' . rawurlencode($key) . '&base=USD&currencies=XAU,XAG',
                ]);
                $rates = $res['all']['rates'] ?? [];
                foreach (['gold' => 'XAU', 'silver' => 'XAG'] as $m => $sym) {
                    if (isset($rates['USD' . $sym]) && is_numeric($rates['USD' . $sym])) {
                        $out[$m] = (float) $rates['USD' . $sym];
                    } elseif (isset($rates[$sym]) && is_numeric($rates[$sym]) && (float) $rates[$sym] > 0) {
                        $out[$m] = 1 / (float) $rates[$sym];
                    }
                }
                break;
        }

        foreach ($out as $m => $v) {
            [$lo, $hi] = self::USD_OZ_BOUNDS[$m];
            if ($v !== null && ($v < $lo || $v > $hi)) {
                error_log("ZakathHelper: rejected implausible {$m} price {$v} USD/oz");
                $out[$m] = null;
            }
        }
        return $out;
    }

    /**
     * GET several JSON URLs in parallel (HTTPS only, TLS verified, short timeouts).
     *
     * @param array<string,string> $urls
     * @return array<string,?array>
     */
    private static function httpGetJsonMulti(array $urls): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $key => $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_USERAGENT      => 'ExpenseManager/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $deadline = microtime(true) + self::TOTAL_TIMEOUT + 1;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running && curl_multi_select($mh, 0.5) === -1) {
                usleep(50000); // select unsupported on this platform: avoid a busy loop
            }
        } while ($running && $status === CURLM_OK && microtime(true) < $deadline);

        $out = [];
        foreach ($handles as $key => $ch) {
            $raw  = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (!is_string($raw) || $raw === '' || $code !== 200) {
                // Never log the URL: it may carry an API key.
                error_log("ZakathHelper: metal price request '{$key}' failed (HTTP {$code}) " . curl_error($ch));
                $out[$key] = null;
            } else {
                $data = json_decode($raw, true);
                $out[$key] = is_array($data) ? $data : null;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    // ── Nisab & calculation ───────────────────────────────────────────────────

    /**
     * Nisab threshold for the family: ['basis', 'grams', 'price_per_gram', 'value' (AED or null)].
     */
    public static function nisab(array $settings, array $prices): array
    {
        $basis = $settings['nisab_basis'] === 'gold' ? 'gold' : 'silver';
        $grams = (float) $settings[$basis . '_grams'];
        $ppg   = $prices[$basis]['aed_per_gram'] ?? null;
        return [
            'basis'          => $basis,
            'grams'          => $grams,
            'price_per_gram' => $ppg,
            'value'          => $ppg !== null ? round($grams * $ppg, 2) : null,
        ];
    }

    /**
     * Zakath on the given amounts (AED).
     * meets_nisab is null when the nisab couldn't be priced; Zakath is then computed
     * anyway (2.5%) and the UI warns that the threshold wasn't checked.
     */
    public static function calculate(float $cash, float $goldSilver, float $investments, float $receivables, float $liabilities, ?float $nisabValue): array
    {
        $gross = $cash + $goldSilver + $investments + $receivables;
        $net   = max(0.0, $gross - $liabilities);
        $meets = $nisabValue === null ? null : ($net > 0 && $net >= $nisabValue);
        $zakat = ($meets === false) ? 0.0 : round($net * self::RATE, 2);
        return ['gross' => round($gross, 2), 'net' => round($net, 2), 'meets_nisab' => $meets, 'zakat' => $zakat];
    }

    // ── Hawl (lunar year) ─────────────────────────────────────────────────────

    /**
     * Current hawl cycle from the family's start date, or null when not set.
     * Due dates are start + 354 days x n (the first one on or after today).
     *
     * @return array{start: string, cycle_start: string, due: string, cycle_number: int, days_left: int,
     *               due_hijri: ?string, start_hijri: ?string, prev_due: ?string}|null
     */
    public static function hawl(?string $startDate, ?DateTimeImmutable $today = null): ?array
    {
        if (!$startDate) {
            return null;
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', substr($startDate, 0, 10));
        if (!$start) {
            return null;
        }
        $today   = $today ?? new DateTimeImmutable('today');
        $elapsed = (int) $start->diff($today)->format('%r%a');
        $n       = max(1, intdiv(max(0, $elapsed) + self::HAWL_DAYS - 1, self::HAWL_DAYS));
        $due     = $start->modify('+' . ($n * self::HAWL_DAYS) . ' days');
        $cycleStart = $due->modify('-' . self::HAWL_DAYS . ' days');

        return [
            'start'        => $start->format('Y-m-d'),
            'cycle_start'  => $cycleStart->format('Y-m-d'),
            'due'          => $due->format('Y-m-d'),
            'cycle_number' => $n,
            'days_left'    => (int) $today->diff($due)->format('%r%a'),
            'due_hijri'    => self::hijri($due),
            'start_hijri'  => self::hijri($start),
            'prev_due'     => $n > 1 ? $cycleStart->format('Y-m-d') : null,
        ];
    }

    /** Hijri (Umm al-Qura) date like "15 Ramadan 1448 AH", or null without ext-intl. */
    public static function hijri(DateTimeInterface $date): ?string
    {
        if (!extension_loaded('intl') || !class_exists('IntlDateFormatter')) {
            return null;
        }
        try {
            $fmt = new \IntlDateFormatter(
                'en_US@calendar=islamic-umalqura',
                \IntlDateFormatter::LONG,
                \IntlDateFormatter::NONE,
                date_default_timezone_get(),
                \IntlDateFormatter::TRADITIONAL,
                'd MMMM y G'
            );
            $s = $fmt->format($date);
            return ($s === false || $s === '') ? null : $s;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ── Auto-pulled assets ────────────────────────────────────────────────────

    /**
     * Zakatable amounts (AED) pulled from the app's data, with a short "source" note each.
     * Keys: cash, gold_silver, investments, receivables, liabilities → ['amount' => float, 'source' => string];
     * plus 'excluded' => list of net-worth items that were not counted (for review).
     */
    public static function autoAssets(PDO $pdo, int $tenantId): array
    {
        $out = [
            'cash'        => ['amount' => 0.0, 'parts' => []],
            'gold_silver' => ['amount' => 0.0, 'parts' => []],
            'investments' => ['amount' => 0.0, 'parts' => []],
            'receivables' => ['amount' => 0.0, 'parts' => []],
            'liabilities' => ['amount' => 0.0, 'parts' => []],
            'excluded'    => [],
        ];

        // Bank balances (latest snapshot per bank, INR converted)
        try {
            $bank = BalanceHelper::totalAed($pdo, $tenantId);
            $out['cash']['amount'] += $bank;
            $out['cash']['parts'][] = 'bank balances ' . self::money($bank);
        } catch (Throwable $e) {
            error_log('ZakathHelper: bank total failed: ' . $e->getMessage());
        }

        // Manual Net Worth items (stored in AED)
        try {
            $stmt = $pdo->prepare("SELECT name, type, category, amount FROM net_worth_items WHERE tenant_id = ? ORDER BY type, sort_order, id");
            $stmt->execute([$tenantId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $amount = (float) $item['amount'];
                if ($amount <= 0) {
                    continue;
                }
                $bucket = self::bucketFor($item);
                $label  = $item['name'] . ' ' . self::money($amount);
                if ($bucket === null) {
                    $out['excluded'][] = $item;
                    continue;
                }
                $out[$bucket]['amount'] += $amount;
                $out[$bucket]['parts'][] = $label;
            }
        } catch (Throwable $e) {
            error_log('ZakathHelper: net worth items failed: ' . $e->getMessage());
        }

        // Money lent and not yet fully repaid
        try {
            $stmt = $pdo->prepare("SELECT amount, currency, status FROM lending_tracker WHERE tenant_id = ? AND status IN ('Pending', 'Partially Paid')");
            $stmt->execute([$tenantId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rate = null;
            $count = 0;
            $partial = 0;
            foreach ($rows as $r) {
                $amt = (float) $r['amount'];
                if (strtoupper((string) $r['currency']) === 'INR') {
                    $rate = $rate ?? ExchangeRateHelper::getRate('INR', 'AED', $pdo);
                    $amt *= $rate;
                }
                $out['receivables']['amount'] += $amt;
                $count++;
                if ($r['status'] === 'Partially Paid') {
                    $partial++;
                }
            }
            if ($count > 0) {
                $out['receivables']['parts'][] = $count . ' open loan' . ($count === 1 ? '' : 's') . ' in Lending Tracker';
                if ($partial > 0) {
                    $out['receivables']['parts'][] = $partial . ' partially repaid — full amount counted, reduce by what was repaid';
                }
            }
        } catch (Throwable $e) {
            error_log('ZakathHelper: lending lookup failed: ' . $e->getMessage());
        }

        foreach (['cash', 'gold_silver', 'investments', 'receivables', 'liabilities'] as $k) {
            $out[$k]['amount'] = round($out[$k]['amount'], 2);
            $out[$k]['source'] = empty($out[$k]['parts']) ? 'nothing found — enter manually' : implode(' + ', $out[$k]['parts']);
        }
        return $out;
    }

    /**
     * Which zakatable bucket a Net Worth item belongs to, or null when it isn't counted
     * (home, car and other personal-use property; long-term mortgage principal).
     */
    private static function bucketFor(array $item): ?string
    {
        $cat  = strtolower(trim((string) $item['category']));
        $text = $cat . ' ' . strtolower((string) $item['name']);

        if ($item['type'] === 'liability') {
            // Only debts due within the coming year are deductible. A mortgage's full
            // principal is not; the user can add the next 12 months' instalments by hand.
            return $cat === 'mortgage' ? null : 'liabilities';
        }
        if ($cat === 'investment') {
            return 'investments';
        }
        if (preg_match('/\b(gold|silver|jewel|jewellery|jewelry|bullion|ornament)/', $text)) {
            return 'gold_silver';
        }
        if (in_array($cat, ['cash', 'bank account', 'savings goal'], true)) {
            return 'cash';
        }
        if (preg_match('/\b(invest|stock|share|equit|crypto|bitcoin|fund|etf|bond|sukuk|reit)/', $text)) {
            return 'investments';
        }
        return null;
    }

    /** Latest saved calculation for a hawl due date, or null. */
    public static function calculationForDue(PDO $pdo, int $tenantId, string $dueDate): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM zakath_calculations WHERE tenant_id = ? AND due_date = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$tenantId, $dueDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('ZakathHelper: calculation lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function money(float $amount, int $decimals = 2): string
    {
        return 'AED ' . number_format($amount, $decimals);
    }
}
