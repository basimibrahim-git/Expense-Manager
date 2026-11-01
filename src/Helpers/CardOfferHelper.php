<?php

namespace App\Helpers;

use DOMDocument;
use DOMXPath;
use PDO;
use Throwable;

/**
 * Card offers from the bank's card page (cards.bank_url): title, description, image and the
 * cashback percentages the page states per spending category.
 *
 * Used by fetch_url_data.php (the "sync" button on Add/Edit Card), the "Refresh offers" button on
 * My Cards and the weekly cron job (cron/jobs/card_offers_sync.php).
 *
 * Rates are read from the page's HTML text. Pages that build their content with JavaScript, or
 * show rates only in images or PDFs, yield no rates; the card's existing rates are then kept.
 */
class CardOfferHelper
{
    /** A refresh older than this is due again (cron + My Cards "synced" hint). */
    public const STALE_DAYS = 7;

    /** Words that place a percentage in an expense category (see Categories::EXPENSE). */
    private const CATEGORY_WORDS = [
        'Food'          => ['dining', 'restaurant', 'food delivery', 'food', 'cafe', 'café', 'eatery'],
        'Grocery'       => ['grocery', 'groceries', 'supermarket', 'hypermarket'],
        'Utilities'     => ['utilities', 'utility', 'telecom', 'mobile bill', 'bill payment', 'dewa', 'etisalat', 'du bill'],
        'Transport'     => ['fuel', 'petrol', 'transport', 'taxi', 'ride hailing', 'careem', 'uber', 'salik', 'metro'],
        'Shopping'      => ['shopping', 'retail', 'apparel', 'fashion', 'department store', 'electronics'],
        'Entertainment' => ['entertainment', 'cinema', 'movie', 'streaming'],
        'Travel'        => ['travel', 'airline', 'hotel', 'flight'],
        'Education'     => ['education', 'school fee', 'tuition'],
        'Medical'       => ['medical', 'pharmacy', 'pharmacies', 'healthcare', 'hospital', 'clinic'],
        'Other'         => ['all other', 'other spend', 'other categories', 'all spends', 'everything else', 'all purchases', 'other retail'],
    ];

    /**
     * Fetch and parse a card page. Same SSRF protections as before: http(s) only, the host must
     * resolve to a public IPv4 address, the connection is pinned to that address, no redirects.
     *
     * @return array{ok: bool, error?: string, title?: string, description?: string, image?: string, rates?: array<string, float>, offers?: string[]}
     */
    public static function fetch(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'error' => 'Invalid URL'];
        }
        $parsed = parse_url($url);
        if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return ['ok' => false, 'error' => 'Only HTTP/HTTPS URLs are allowed'];
        }
        $host = $parsed['host'] ?? '';
        if ($host === '') {
            return ['ok' => false, 'error' => 'Invalid URL: missing host'];
        }
        // Hostname must be a plain DNS name or IPv4 literal (also keeps the CURLOPT_RESOLVE entry well-formed)
        if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            return ['ok' => false, 'error' => 'Invalid URL: unsupported host'];
        }
        // Resolve BEFORE the request to prevent DNS rebinding
        $resolved_ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $host : gethostbyname($host);
        if (!filter_var($resolved_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ok' => false, 'error' => 'Unable to resolve hostname'];
        }
        if (self::isInternalIpAddress($resolved_ip)) {
            return ['ok' => false, 'error' => 'Access to internal networks is not allowed'];
        }
        $validated_ip = filter_var($resolved_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($validated_ip === false) {
            return ['ok' => false, 'error' => 'Invalid or blocked IP address'];
        }

        // Rebuild the URL from validated components only
        $scheme = strtolower(trim($parsed['scheme'])) === 'http' ? 'http' : 'https';
        $port = '';
        $connect_port = $scheme === 'https' ? 443 : 80;
        if (isset($parsed['port']) && is_numeric($parsed['port'])) {
            $port_num = (int) $parsed['port'];
            if ($port_num > 0 && $port_num < 65536) {
                $port = ':' . $port_num;
                $connect_port = $port_num;
            }
        }
        $path = '/';
        if (isset($parsed['path']) && strlen($parsed['path']) > 0) {
            // Allow only alphanumeric, dash, underscore, dot, forward slash
            $sanitized_path = preg_replace('/[^a-zA-Z0-9\-_\.\/]/', '', $parsed['path']);
            if (strlen($sanitized_path) > 0) {
                $path = '/' . ltrim($sanitized_path, '/');
            }
        }
        $query = '';
        if (isset($parsed['query']) && strlen($parsed['query']) > 0) {
            $query_params = [];
            parse_str($parsed['query'], $query_params);
            if (!empty($query_params)) {
                $query = '?' . http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);
            }
        }
        // The host name stays in the URL (TLS SNI / certificate checks, virtual hosting) and
        // CURLOPT_RESOLVE pins the connection to the IP validated above.
        $safe_url = $scheme . '://' . $host . $port . $path . $query;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $safe_url);
        curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . $connect_port . ':' . $validated_ip]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // no redirects: prevents SSRF via redirect
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache'
        ]);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // gzip
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $cookie_file = sys_get_temp_dir() . '/cookie_' . hash('sha256', $url) . '.txt';
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($html === false || $httpCode >= 400) {
            error_log("Fetch URL Error: $url - " . $error . " (HTTP $httpCode)");
            return ['ok' => false, 'error' => 'Failed to load page content.'];
        }

        return ['ok' => true] + self::parse((string) $html);
    }

    /**
     * Title, description, image (Open Graph / meta tags) and cashback rates from page HTML.
     *
     * @return array{title: string, description: string, image: string, rates: array<string, float>, offers: string[]}
     */
    public static function parse(string $html): array
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html); // tolerate malformed HTML
        $xpath = new DOMXPath($doc);

        $first = function (string $query) use ($xpath): string {
            $nodes = $xpath->query($query);
            return ($nodes !== false && $nodes->length > 0) ? trim((string) $nodes->item(0)->nodeValue) : '';
        };
        $title = $first('//meta[@property="og:title"]/@content') ?: $first('//title');
        $description = $first('//meta[@property="og:description"]/@content') ?: $first('//meta[@name="description"]/@content');
        $image = $first('//meta[@property="og:image"]/@content');

        // Visible text only
        foreach (['script', 'style', 'noscript', 'svg'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $node->parentNode->removeChild($node);
            }
        }
        // Line break after each block element so list items and cells stay separate sentences
        foreach (['li', 'p', 'div', 'td', 'th', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'section', 'article'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $node->appendChild($doc->createTextNode("\n"));
            }
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        $text = $body ? $body->textContent : $doc->textContent;

        $found = self::extractRates($description . "\n" . $text);

        return [
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'rates' => $found['rates'],
            'offers' => $found['offers'],
        ];
    }

    /**
     * Cashback percentages per category from free text, e.g. "5% cashback on dining" or
     * "Earn 3% back at supermarkets". A percentage only counts when its sentence talks about
     * cashback/rewards and not about fees or interest. Highest rate per category wins.
     *
     * @return array{rates: array<string, float>, offers: string[]}
     */
    public static function extractRates(string $text): array
    {
        $rates = [];
        $offers = [];
        // Lines, then sentences: keeps a percentage with the words that describe it
        $chunks = preg_split('/\R|(?<=[.!?;•|])\s+/u', $text) ?: [];
        foreach ($chunks as $chunk) {
            $chunk = trim(preg_replace('/\s+/u', ' ', $chunk) ?? '');
            if ($chunk === '' || mb_strlen($chunk) > 300 || strpos($chunk, '%') === false) {
                continue;
            }
            $lower = mb_strtolower($chunk);
            if (!preg_match('/cash ?back|back on|rewards?|earn|savings? of|discount/u', $lower)
                || preg_match('/\b(fee|fees|interest|apr|profit rate|charge|charges|foreign|fx|late payment|processing)\b/u', $lower)) {
                continue;
            }
            if (!preg_match_all('/(\d{1,2}(?:\.\d{1,2})?)\s?%/u', $chunk, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $matchedHere = false;
            foreach ($m[1] as $i => [$num, $offset]) {
                $rate = (float) $num;
                if ($rate <= 0 || $rate > 20) {
                    continue; // not a realistic cashback rate
                }
                // Words after this percentage, up to the next percentage
                $end = isset($m[0][$i + 1]) ? $m[0][$i + 1][1] : strlen($chunk);
                $after = mb_strtolower(substr($chunk, $offset, $end - $offset));
                foreach (self::CATEGORY_WORDS as $category => $words) {
                    foreach ($words as $word) {
                        if (preg_match('/\b' . preg_quote($word, '/') . '/u', $after)) {
                            $rates[$category] = max($rates[$category] ?? 0, $rate);
                            $matchedHere = true;
                            break;
                        }
                    }
                }
            }
            if ($matchedHere && count($offers) < 8 && !in_array($chunk, $offers, true)) {
                $offers[] = $chunk;
            }
        }
        return ['rates' => $rates, 'offers' => $offers];
    }

    /**
     * Re-read one card's bank page, update its features text and the cashback rates the page
     * states (categories the page does not mention keep their current rate), and record the sync
     * time in url_cache.
     *
     * @param array $card row with id, bank_url, cashback_struct, features
     * @return array{ok: bool, error?: string, rates?: array<string, float>, changed?: bool}
     */
    public static function refreshCard(PDO $pdo, int $tenantId, array $card): array
    {
        $url = trim((string) ($card['bank_url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'error' => 'This card has no bank page link.'];
        }
        $page = self::fetch($url);
        self::saveCache($pdo, $url, $page);
        if (!$page['ok']) {
            return ['ok' => false, 'error' => $page['error'] ?? 'Failed to load page content.'];
        }

        $current = CardCycleHelper::cashbackRates($card['cashback_struct'] ?? null);
        $merged = array_merge($current, $page['rates']);
        $features = (string) ($card['features'] ?? '');
        if ($page['description'] !== '' || $page['offers']) {
            $lines = array_filter(array_merge([$page['description']], $page['offers']));
            $features = "✅ Synced from bank website " . date('d M Y') . ":\n" . implode("\n", array_unique($lines));
        }

        $changed = $merged != $current || $features !== (string) ($card['features'] ?? '');
        if ($changed) {
            $stmt = $pdo->prepare("UPDATE cards SET cashback_struct = ?, features = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([json_encode((object) $merged), $features, (int) $card['id'], $tenantId]);
        }
        return ['ok' => true, 'rates' => $page['rates'], 'changed' => $changed];
    }

    /** When the card page was last fetched (Y-m-d H:i:s), or null. */
    public static function lastSynced(PDO $pdo, string $url): ?string
    {
        try {
            $stmt = $pdo->prepare("SELECT updated_at FROM url_cache WHERE url_hash = ?");
            $stmt->execute([hash('sha256', $url)]);
            $at = $stmt->fetchColumn();
            return $at ? (string) $at : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Store a successful fetch in url_cache (also marks the sync time). Failures are not cached. */
    public static function saveCache(PDO $pdo, string $url, array $page): void
    {
        if (!$page['ok']) {
            return;
        }
        try {
            $json = json_encode([
                'title' => $page['title'], 'description' => $page['description'], 'image' => $page['image'],
                'rates' => $page['rates'], 'source' => 'Live Website',
            ]);
            $stmt = $pdo->prepare("INSERT INTO url_cache (url_hash, url_data) VALUES (?, ?) ON DUPLICATE KEY UPDATE url_data = ?, updated_at = NOW()");
            $stmt->execute([hash('sha256', $url), $json, $json]);
        } catch (Throwable $e) {
            // cache is best effort
        }
    }

    /** Private, loopback, link-local, carrier-grade NAT, test, multicast and reserved IPv4 ranges. */
    public static function isInternalIpAddress(string $ip): bool
    {
        $blocked_ranges = [
            '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '0.0.0.0/8',
            '169.254.0.0/16', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.51.100.0/24',
            '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        ];
        $ip_long = ip2long($ip);
        if ($ip_long === false) {
            return true; // invalid IP: block it
        }
        foreach ($blocked_ranges as $range) {
            [$subnet, $mask] = explode('/', $range);
            $mask_long = ~((1 << (32 - (int) $mask)) - 1);
            if (($ip_long & $mask_long) === (ip2long($subnet) & $mask_long)) {
                return true;
            }
        }
        return false;
    }
}
