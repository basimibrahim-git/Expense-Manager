<?php

namespace App\Helpers;

/**
 * Server-side client for Lean Technologies (UAE open banking).
 *
 * Documentation this follows (read 2026-10-04):
 *   Authentication / OAuth client credentials  https://docs.leantech.me/docs/authentication
 *   Token endpoint reference                   https://docs.leantech.me/reference/postoauth2token
 *   Creating a customer                        https://docs.leantech.me/docs/creating-a-customer
 *   Create customer / get by app_user_id       https://docs.leantech.me/reference/createcustomer
 *                                              https://docs.leantech.me/reference/getcustomerbyuserid
 *   Entities for a customer / delete entity    https://docs.leantech.me/reference/getcustomerentities
 *                                              https://docs.leantech.me/reference/deleteentity
 *   Data API v2 accounts / balances / txns     https://docs.leantech.me/reference/fetchaccountsv2
 *                                              https://docs.leantech.me/reference/fetchbalancesv2
 *                                              https://docs.leantech.me/reference/fetchtransactionsv2
 *   Manual data refresh                        https://docs.leantech.me/reference/triggerdatarefresh
 *   Data workflow (refresh webhooks)           https://docs.leantech.me/docs/data-workflow
 *   Open Finance data integration guide        https://docs.leantech.me/docs/lean-open-finance-data-integration-guide
 *   Link SDK (web) + CSP requirements          https://docs.leantech.me/docs/web
 *   Webhooks (HMAC-SHA512 "lean-signature")    https://docs.leantech.me/docs/webhooks
 *   Webhook library (event payloads)           https://docs.leantech.me/docs/webhook-library
 *
 * Configuration (.env):
 *   LEAN_CLIENT_ID       application id (OAuth client_id)
 *   LEAN_CLIENT_SECRET   OAuth client secret (shown once in the Lean dashboard)
 *   LEAN_APP_TOKEN       app_token for the Link SDK (defaults to LEAN_CLIENT_ID)
 *   LEAN_ENV             sandbox | production (default sandbox)
 *   LEAN_WEBHOOK_SECRET  webhook secret from the dashboard's Integration section
 *   LEAN_API_BASE        optional override of the API host (see apiBase())
 *
 * Tokens: an api-scoped token (scope=api) is used for every backend call; a
 * customer-scoped token (scope=customer.<id>) is handed to the Link SDK only.
 * Tokens are cached in memory for the request and never logged.
 */
class LeanClient
{
    private const AUTH_URL = [
        'sandbox'    => 'https://auth.sandbox.leantech.me/oauth2/token',
        'production' => 'https://auth.leantech.me/oauth2/token',
    ];

    /**
     * API hosts as listed in the API reference "servers" (customers + data v2).
     * Some guides show https://api.leantech.me for production; set LEAN_API_BASE
     * if Lean tells you to use that host instead.
     */
    private const API_BASE = [
        'sandbox'    => 'https://sandbox.leantech.me',
        'production' => 'https://api2.leantech.me',
    ];

    /** Link SDK loader for the UAE region (docs: LinkSDK → Web). */
    public const SDK_URL = 'https://cdn.leantech.me/link/loader/prod/ae/latest/lean-link-loader.min.js';

    /** Data permissions requested in the Link flow (identity is not needed by this app). */
    public const PERMISSIONS = ['accounts', 'balance', 'transactions'];

    /** Data API "status" values that mean the data is not usable right now. */
    private const NOT_READY = ['PENDING', 'FAILED', 'CONSENT_EXPIRED', 'RECONNECT_REQUIRED', 'PROCESSING_STARTED'];

    /** @var array<string, array{token: string, expires: int}> */
    private static array $tokens = [];

    // ── Configuration ────────────────────────────────────────────────────────

    public static function isConfigured(): bool
    {
        return self::env('LEAN_CLIENT_ID') !== ''
            && self::env('LEAN_CLIENT_SECRET') !== ''
            && function_exists('curl_init');
    }

    public static function environment(): string
    {
        return strtolower(self::env('LEAN_ENV')) === 'production' ? 'production' : 'sandbox';
    }

    public static function isSandbox(): bool
    {
        return self::environment() === 'sandbox';
    }

    /** app_token passed to the Link SDK (public value, not a secret). */
    public static function appToken(): string
    {
        $t = self::env('LEAN_APP_TOKEN');
        return $t !== '' ? $t : self::env('LEAN_CLIENT_ID');
    }

    public static function apiBase(): string
    {
        $override = self::env('LEAN_API_BASE');
        if ($override !== '' && preg_match('#^https://[A-Za-z0-9.-]+\.leantech\.me$#', rtrim($override, '/'))) {
            return rtrim($override, '/');
        }
        return self::API_BASE[self::environment()];
    }

    /** Lean ids are UUIDs; anything else is refused before it reaches a URL. */
    public static function isId(?string $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $id) === 1;
    }

    private static function env(string $key): string
    {
        return trim((string) ($_ENV[$key] ?? ''));
    }

    // ── Tokens ───────────────────────────────────────────────────────────────

    /** OAuth2 client-credentials token for a scope ("api" or "customer.<id>"), cached until shortly before expiry. */
    public function token(string $scope = 'api'): string
    {
        $key = self::environment() . '|' . $scope;
        if (isset(self::$tokens[$key]) && self::$tokens[$key]['expires'] > time()) {
            return self::$tokens[$key]['token'];
        }
        if (!self::isConfigured()) {
            throw new LeanException('Open banking is not configured.');
        }

        $ch = curl_init(self::AUTH_URL[self::environment()]);
        curl_setopt_array($ch, self::curlDefaults() + [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id'     => self::env('LEAN_CLIENT_ID'),
                'client_secret' => self::env('LEAN_CLIENT_SECRET'),
                'grant_type'    => 'client_credentials',
                'scope'         => $scope,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $raw   = curl_exec($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new LeanException("Lean auth unreachable (curl error {$errno})");
        }
        $data = json_decode((string) $raw, true);
        if ($http !== 200 || !is_array($data) || empty($data['access_token'])) {
            // The response body is not logged: it could echo request details.
            throw new LeanException("Lean auth failed (HTTP {$http})", $http);
        }

        $ttl = max(60, (int) ($data['expires_in'] ?? 3599));
        self::$tokens[$key] = ['token' => (string) $data['access_token'], 'expires' => time() + $ttl - 120];
        return self::$tokens[$key]['token'];
    }

    /**
     * Customer-scoped token for the Link SDK. Lean requires at least 10 minutes of
     * validity when the SDK opens; a fresh token is minted per page view.
     */
    public function customerToken(string $customerId): string
    {
        if (!self::isId($customerId)) {
            throw new LeanException('Invalid Lean customer id');
        }
        unset(self::$tokens[self::environment() . '|customer.' . $customerId]);
        return $this->token('customer.' . $customerId);
    }

    // ── Customers & entities ─────────────────────────────────────────────────

    /** Existing customer for this app_user_id, or a new one. Returns the customer_id. */
    public function findOrCreateCustomer(string $appUserId): string
    {
        try {
            $found = $this->request('GET', '/customers/v1/app-user-id/' . rawurlencode($appUserId), [], null, 'GET customer by app_user_id');
            if (self::isId($found['customer_id'] ?? null)) {
                return $found['customer_id'];
            }
        } catch (LeanException $e) {
            if ($e->httpStatus !== 404 && $e->httpStatus !== 400) {
                throw $e;
            }
        }

        $created = $this->request('POST', '/customers/v1/', [], ['app_user_id' => $appUserId], 'POST customer');
        if (!self::isId($created['customer_id'] ?? null)) {
            throw new LeanException('Lean did not return a customer id');
        }
        return $created['customer_id'];
    }

    /** @return array<int, array> entity objects (id, bank_identifier, created_at, …) */
    public function entities(string $customerId): array
    {
        $this->assertId($customerId);
        $data = $this->request('GET', '/customers/v1/' . $customerId . '/entities', [], null, 'GET entities');
        $isList = $data === [] || array_keys($data) === range(0, count($data) - 1);
        $list = $isList ? $data : ($data['data'] ?? $data['entities'] ?? []);
        return array_values(array_filter((array) $list, 'is_array'));
    }

    public function deleteEntity(string $customerId, string $entityId): void
    {
        $this->assertId($customerId);
        $this->assertId($entityId);
        $this->request(
            'DELETE',
            '/customers/v1/' . $customerId . '/entities/' . $entityId,
            ['reason' => 'USER_REQUESTED'],
            ['reason' => 'USER_REQUESTED'],
            'DELETE entity'
        );
    }

    // ── Data API v2 ──────────────────────────────────────────────────────────

    /** @return array<int, array> */
    public function accounts(string $entityId): array
    {
        $this->assertId($entityId);
        return $this->paged('/data/v2/accounts', ['entity_id' => $entityId], 'accounts', 5, 'GET accounts');
    }

    /** @return array<int, array> */
    public function balances(string $entityId, string $accountId): array
    {
        $this->assertId($entityId);
        $this->assertId($accountId);
        return $this->paged('/data/v2/accounts/' . $accountId . '/balances', ['entity_id' => $entityId], 'balances', 2, 'GET balances');
    }

    /** @return array<int, array> */
    public function transactions(string $entityId, string $accountId, string $startDate, string $endDate): array
    {
        $this->assertId($entityId);
        $this->assertId($accountId);
        return $this->paged(
            '/data/v2/accounts/' . $accountId . '/transactions',
            ['entity_id' => $entityId, 'start_date' => $startDate, 'end_date' => $endDate],
            'transactions',
            30,
            'GET transactions'
        );
    }

    /**
     * Ask Lean to fetch fresh data from the bank (max 2 per entity per day, 10-minute cooldown).
     * Data arrives later; Lean then sends entity.data.refresh.updated (status FINISHED).
     */
    public function refresh(string $entityId): void
    {
        $this->assertId($entityId);
        $this->request('POST', '/data/v2/refreshes', [], ['entity_id' => $entityId], 'POST refresh');
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * lean-signature header = "sha512=" + HMAC-SHA512(raw body, LEAN_WEBHOOK_SECRET).
     * Hex is the documented form; base64 is accepted too in case the dashboard uses it.
     */
    public static function verifyWebhookSignature(string $rawBody, string $header): bool
    {
        $secret = self::env('LEAN_WEBHOOK_SECRET');
        $header = trim($header);
        if ($secret === '' || $header === '' || $rawBody === '') {
            return false;
        }
        if (stripos($header, 'sha512=') === 0) {
            $header = substr($header, 7);
        }
        $mac = hash_hmac('sha512', $rawBody, $secret, true);
        return hash_equals(bin2hex($mac), strtolower($header))
            || hash_equals(base64_encode($mac), $header);
    }

    // ── Transport ────────────────────────────────────────────────────────────

    private function assertId(string $id): void
    {
        if (!self::isId($id)) {
            throw new LeanException('Invalid Lean id');
        }
    }

    /** Collect every page of a Data API v2 list. */
    private function paged(string $path, array $query, string $key, int $maxPages, string $label): array
    {
        $items = [];
        for ($page = 0; $page < $maxPages; $page++) {
            $resp = $this->request('GET', $path, $query + ['page' => $page, 'size' => 100], null, $label);
            $status = strtoupper((string) ($resp['status'] ?? 'OK'));
            if (in_array($status, self::NOT_READY, true)) {
                throw new LeanException("Lean {$label}: {$status}", 200, $status);
            }
            foreach ((array) ($resp['data'][$key] ?? []) as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }
            $totalPages = (int) ($resp['data']['page']['total_pages'] ?? 1);
            if ($page + 1 >= $totalPages) {
                break;
            }
        }
        return $items;
    }

    /**
     * JSON request with the api-scoped token. Throws LeanException on any non-2xx
     * answer; the exception carries Lean's "status" field when there is one.
     */
    private function request(string $method, string $path, array $query, ?array $body, string $label): array
    {
        $url = self::apiBase() . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->token('api')];

        $ch = curl_init($url);
        $opts = self::curlDefaults() + [CURLOPT_CUSTOMREQUEST => $method];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw   = curl_exec($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new LeanException("Lean {$label}: unreachable (curl error {$errno})");
        }
        $data = json_decode((string) $raw, true);
        if ($http < 200 || $http >= 300) {
            $leanStatus = is_array($data) && isset($data['status']) && is_string($data['status']) ? strtoupper($data['status']) : null;
            $detail = is_array($data) && isset($data['message']) && is_string($data['message'])
                ? ': ' . mb_substr(preg_replace('/\s+/', ' ', $data['message']), 0, 160)
                : '';
            throw new LeanException("Lean {$label} failed (HTTP {$http}){$detail}", $http, $leanStatus);
        }
        return is_array($data) ? $data : [];
    }

    private static function curlDefaults(): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'ExpenseManager/1.0',
        ];
    }
}
