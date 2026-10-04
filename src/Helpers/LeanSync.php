<?php

namespace App\Helpers;

use PDO;
use PDOException;
use DomainException;

/**
 * Open-banking sync between Lean (see LeanClient) and the app's tables:
 * lean_customers / lean_entities / lean_accounts / lean_transactions (migrations/2026_10_05_feature_release.sql).
 *
 * - Balances: for every Lean account linked to a row in `banks`, an absolute snapshot is
 *   inserted into bank_balances (dated today) only when it differs from the latest one.
 * - Transactions: booked transactions are stored in lean_transactions for review, then
 *   imported as expenses/income with balance_bank_id = NULL (the synced balance already
 *   includes them, so they must not move the balance a second time).
 *
 * Every method is tenant-scoped; ids coming from a browser are re-checked here.
 * DomainException = a refusal whose message can be shown to the user;
 * LeanException = an API failure (log it, show a generic message).
 */
class LeanSync
{
    public const ADMIN_ROLES = ['family_admin', 'root_admin'];

    /** Balance types in order of preference (booked balances match the booked transactions we import). */
    private const BALANCE_PREFERENCE = [
        'INTERIM_BOOKED', 'CLOSING_BOOKED', 'INTERIM_AVAILABLE', 'CLOSING_AVAILABLE',
        'OPENING_BOOKED', 'OPENING_AVAILABLE', 'EXPECTED', 'INTERIM_CLEARED', 'FORWARD_AVAILABLE',
    ];

    private const FIRST_SYNC_DAYS = 90;
    private const OVERLAP_DAYS    = 7;

    // ── Status / lookups ─────────────────────────────────────────────────────

    /** True when the Lean tables exist (the migration has been run). */
    public static function tablesReady(PDO $pdo): bool
    {
        try {
            $pdo->query("SELECT 1 FROM lean_transactions LIMIT 0");
            $pdo->query("SELECT 1 FROM lean_accounts LIMIT 0");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public static function isAdmin(): bool
    {
        return in_array($_SESSION['role'] ?? '', self::ADMIN_ROLES, true);
    }

    /** The tenant's Lean customer id for the current environment, or null. */
    public static function customerId(PDO $pdo, int $tenantId): ?string
    {
        $stmt = $pdo->prepare("SELECT customer_id FROM lean_customers WHERE tenant_id = ? AND environment = ?");
        $stmt->execute([$tenantId, LeanClient::environment()]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string) $id;
    }

    /** Reuse the tenant's Lean customer or create one. */
    public static function ensureCustomer(PDO $pdo, LeanClient $client, int $tenantId): string
    {
        $existing = self::customerId($pdo, $tenantId);
        if ($existing !== null) {
            return $existing;
        }

        $appUserId  = 'family-' . $tenantId;
        $customerId = $client->findOrCreateCustomer($appUserId);
        $env        = LeanClient::environment();

        $pdo->prepare(
            "INSERT INTO lean_customers (tenant_id, customer_id, app_user_id, environment) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id), app_user_id = VALUES(app_user_id), environment = VALUES(environment)"
        )->execute([$tenantId, $customerId, $appUserId, $env]);

        // Connections made with a customer from the other environment can no longer be used.
        $pdo->prepare("UPDATE lean_entities SET status = 'disconnected' WHERE tenant_id = ? AND customer_id <> ?")
            ->execute([$tenantId, $customerId]);

        return $customerId;
    }

    /**
     * User the sync writes snapshots as: $preferred if they belong to the tenant,
     * else the tenant's family admin, else any member. Null if the tenant has no users.
     */
    public static function actingUserId(PDO $pdo, int $tenantId, ?int $preferred = null): ?int
    {
        if ($preferred) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$preferred, $tenantId]);
            if ($stmt->fetchColumn()) {
                return $preferred;
            }
        }
        $stmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id = ? ORDER BY (role = 'family_admin') DESC, id ASC LIMIT 1");
        $stmt->execute([$tenantId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    // ── Connections ──────────────────────────────────────────────────────────

    /**
     * Pull the customer's entities from Lean and store new ones. Called after the Link
     * flow succeeds; the browser only gives a hint, Lean's list is the source of truth.
     *
     * @return array{added: int, total: int, hint_found: bool}
     */
    public static function registerEntities(PDO $pdo, LeanClient $client, int $tenantId, ?string $hintEntityId = null): array
    {
        $customerId = self::customerId($pdo, $tenantId);
        if ($customerId === null) {
            throw new DomainException('No open-banking customer for this family yet.');
        }

        $added = 0;
        $total = 0;
        $hintFound = false;
        $insert = $pdo->prepare(
            "INSERT INTO lean_entities (tenant_id, entity_id, customer_id, bank_identifier, bank_name, status)
             VALUES (?, ?, ?, ?, ?, 'active')
             ON DUPLICATE KEY UPDATE
                bank_identifier = COALESCE(VALUES(bank_identifier), bank_identifier),
                bank_name       = COALESCE(bank_name, VALUES(bank_name)),
                status          = IF(status = 'pending', 'active', status)"
        );
        foreach ($client->entities($customerId) as $e) {
            $entityId = (string) ($e['id'] ?? $e['entity_id'] ?? '');
            if (!LeanClient::isId($entityId)) {
                continue;
            }
            $identifier = self::str($e['bank_identifier'] ?? ($e['bank_details']['identifier'] ?? null), 64);
            $name = self::str($e['bank_details']['name'] ?? ($e['bank']['name'] ?? null), 100) ?? self::prettyBank($identifier);
            $insert->execute([$tenantId, $entityId, $customerId, $identifier, $name]);
            if ($insert->rowCount() >= 1) { // 1 = new row, 2 = webhook-created row activated
                $added++;
            }
            $total++;
            if ($hintEntityId !== null && hash_equals($entityId, $hintEntityId)) {
                $hintFound = true;
            }
        }
        return ['added' => $added, 'total' => $total, 'hint_found' => $hintFound];
    }

    /**
     * Disconnect one connection (tenant-checked row id): delete it at Lean, then forget its
     * accounts and un-reviewed transactions. Imported/ignored rows are kept for dedupe.
     * Returns false when Lean could not be reached (the local disconnect still happens).
     */
    public static function disconnect(PDO $pdo, LeanClient $client, int $tenantId, int $entityRowId): bool
    {
        $stmt = $pdo->prepare("SELECT entity_id, customer_id FROM lean_entities WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$entityRowId, $tenantId]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$entity) {
            throw new DomainException('Connection not found');
        }

        $remoteOk = true;
        try {
            $client->deleteEntity($entity['customer_id'], $entity['entity_id']);
        } catch (LeanException $e) {
            if ($e->httpStatus !== 404) {
                $remoteOk = false;
                error_log('Lean disconnect: ' . $e->getMessage());
            }
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "DELETE t FROM lean_transactions t
                   JOIN lean_accounts a ON a.tenant_id = t.tenant_id AND a.account_id = t.account_id
                  WHERE t.tenant_id = ? AND a.entity_id = ? AND t.status = 'new'"
            )->execute([$tenantId, $entity['entity_id']]);
            $pdo->prepare("DELETE FROM lean_accounts WHERE tenant_id = ? AND entity_id = ?")
                ->execute([$tenantId, $entity['entity_id']]);
            $pdo->prepare("UPDATE lean_entities SET status = 'disconnected', last_error = NULL WHERE id = ? AND tenant_id = ?")
                ->execute([$entityRowId, $tenantId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $remoteOk;
    }

    // ── Account ↔ bank mapping ───────────────────────────────────────────────

    /**
     * Link a Lean account (row id) to a tenant bank, create a new bank from it ($target = 'new'),
     * or unlink it ($target = ''). Returns a user-facing message; throws LeanException on refusal.
     */
    public static function mapAccount(PDO $pdo, int $tenantId, int $userId, int $accountRowId, string $target): string
    {
        $stmt = $pdo->prepare(
            "SELECT a.*, e.bank_name AS entity_bank
               FROM lean_accounts a
               LEFT JOIN lean_entities e ON e.entity_id = a.entity_id AND e.tenant_id = a.tenant_id
              WHERE a.id = ? AND a.tenant_id = ?"
        );
        $stmt->execute([$accountRowId, $tenantId]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acc) {
            throw new DomainException('Account not found');
        }

        if ($target === '') {
            $pdo->prepare("UPDATE lean_accounts SET bank_id = NULL WHERE id = ? AND tenant_id = ?")->execute([$accountRowId, $tenantId]);
            return 'Account unlinked. Its balance will no longer be synced.';
        }

        $pdo->beginTransaction();
        try {
            if ($target === 'new') {
                $name = trim(($acc['entity_bank'] ?: 'Bank') . ' ' . ($acc['display_name'] ?: ''));
                $digits = preg_replace('/\D/', '', (string) $acc['masked_number']);
                $type = stripos((string) $acc['account_type'], 'SAVING') !== false ? 'Savings' : 'Current';
                $pdo->prepare(
                    "INSERT INTO banks (user_id, tenant_id, bank_name, account_type, account_number, iban, currency, notes, is_default)
                     VALUES (?, ?, ?, ?, ?, NULL, ?, ?, 0)"
                )->execute([
                    $userId, $tenantId, mb_substr($name, 0, 100), $type,
                    $digits !== '' ? substr($digits, -4) : null,
                    $acc['currency'] ?: 'AED',
                    'Linked via open banking (Lean)',
                ]);
                $bankId = (int) $pdo->lastInsertId();
            } else {
                $bankId = (int) $target;
                $bank = $bankId > 0 ? BalanceHelper::bank($pdo, $tenantId, $bankId) : null;
                if (!$bank) {
                    throw new DomainException('Bank not found');
                }
                if (strtoupper($bank['currency'] ?: 'AED') !== strtoupper($acc['currency'])) {
                    throw new DomainException("Currency mismatch: the bank is in {$bank['currency']} but the account is in {$acc['currency']}.");
                }
                $taken = $pdo->prepare("SELECT 1 FROM lean_accounts WHERE tenant_id = ? AND bank_id = ? AND id <> ?");
                $taken->execute([$tenantId, $bankId, $accountRowId]);
                if ($taken->fetchColumn()) {
                    throw new DomainException('That bank is already linked to another open-banking account.');
                }
            }

            $pdo->prepare("UPDATE lean_accounts SET bank_id = ? WHERE id = ? AND tenant_id = ?")
                ->execute([$bankId, $accountRowId, $tenantId]);

            $snap = false;
            if ($acc['last_balance'] !== null) {
                $snap = self::snapshotIfChanged($pdo, $tenantId, $userId, $bankId, (float) $acc['last_balance'], (string) $acc['currency']);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ($target === 'new' ? 'New bank created and linked.' : 'Account linked.')
            . ($snap ? ' Current balance recorded.' : '');
    }

    // ── Sync ─────────────────────────────────────────────────────────────────

    /**
     * Sync every active connection of a tenant (or just $onlyEntityId).
     * $requestRefresh also asks Lean to fetch fresh data from the bank (rate-limited by Lean).
     *
     * @return array{entities: int, accounts: int, snapshots: int, transactions: int, errors: string[], busy: bool, refreshed: int}
     */
    public static function syncTenant(PDO $pdo, int $tenantId, ?int $userId = null, bool $requestRefresh = false, ?string $onlyEntityId = null): array
    {
        $sum = ['entities' => 0, 'accounts' => 0, 'snapshots' => 0, 'transactions' => 0, 'errors' => [], 'busy' => false, 'refreshed' => 0];
        if (!LeanClient::isConfigured()) {
            $sum['errors'][] = 'Open banking is not configured.';
            return $sum;
        }

        $lockName = 'lean_sync_' . $tenantId;
        $lock = $pdo->prepare("SELECT GET_LOCK(?, 0)");
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            $sum['busy'] = true;
            return $sum;
        }

        try {
            $client = new LeanClient();
            $actor  = self::actingUserId($pdo, $tenantId, $userId);

            $sql = "SELECT * FROM lean_entities WHERE tenant_id = ? AND status IN ('active', 'pending')";
            $params = [$tenantId];
            if ($onlyEntityId !== null) {
                $sql .= " AND entity_id = ?";
                $params[] = $onlyEntityId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entity) {
                $label = $entity['bank_name'] ?: 'Bank';
                if ($requestRefresh) {
                    try {
                        $client->refresh($entity['entity_id']);
                        $sum['refreshed']++;
                    } catch (LeanException $e) {
                        if ($e->needsReconnect() || $e->httpStatus === 403) {
                            self::markEntity($pdo, $tenantId, $entity['entity_id'], 'reconnect_required', 'The bank needs to be reconnected.');
                            $sum['errors'][] = "{$label}: please reconnect this bank.";
                            continue;
                        }
                        // 429 (daily limit / cooldown) and other refresh errors: the stored data is still synced below.
                    }
                }

                try {
                    self::syncEntity($pdo, $client, $tenantId, $entity, $actor, $sum);
                    $sum['entities']++;
                } catch (LeanException $e) {
                    if ($e->needsReconnect()) {
                        $status = $e->leanStatus === 'CONSENT_EXPIRED' ? 'consent_expired' : 'reconnect_required';
                        self::markEntity($pdo, $tenantId, $entity['entity_id'], $status, 'The bank needs to be reconnected.');
                        $sum['errors'][] = "{$label}: please reconnect this bank.";
                    } elseif (in_array($e->leanStatus, ['PENDING', 'PROCESSING_STARTED'], true)) {
                        self::markEntity($pdo, $tenantId, $entity['entity_id'], 'pending', 'The bank data is still being prepared.');
                        $sum['errors'][] = "{$label}: data is still being prepared by the bank, try again in a few minutes.";
                    } else {
                        self::markEntity($pdo, $tenantId, $entity['entity_id'], null, mb_substr($e->getMessage(), 0, 250));
                        $sum['errors'][] = "{$label}: sync failed.";
                        error_log('Lean sync tenant ' . $tenantId . ': ' . $e->getMessage());
                    }
                }
            }
        } finally {
            $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$lockName]);
        }
        return $sum;
    }

    private static function syncEntity(PDO $pdo, LeanClient $client, int $tenantId, array $entity, ?int $actor, array &$sum): void
    {
        $entityId = $entity['entity_id'];
        $today    = date('Y-m-d');

        $upsert = $pdo->prepare(
            "INSERT INTO lean_accounts (tenant_id, entity_id, account_id, display_name, account_type, currency, masked_number)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE entity_id = VALUES(entity_id), display_name = VALUES(display_name),
                account_type = VALUES(account_type), currency = VALUES(currency), masked_number = VALUES(masked_number)"
        );
        $load = $pdo->prepare("SELECT id, bank_id, last_synced_at FROM lean_accounts WHERE tenant_id = ? AND account_id = ?");
        $saveBalance = $pdo->prepare("UPDATE lean_accounts SET last_balance = ? WHERE id = ? AND tenant_id = ?");
        $touch = $pdo->prepare("UPDATE lean_accounts SET last_synced_at = NOW() WHERE id = ? AND tenant_id = ?");
        $insertTx = $pdo->prepare(
            "INSERT INTO lean_transactions (tenant_id, account_id, transaction_id, booking_date, amount, currency, description, merchant, card_last4)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id"
        );

        foreach ($client->accounts($entityId) as $a) {
            $accountId = (string) ($a['account_id'] ?? '');
            if (!LeanClient::isId($accountId) || strtoupper((string) ($a['status'] ?? '')) === 'DELETED') {
                continue;
            }
            $currency = strtoupper((string) ($a['currency'] ?? 'AED'));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $currency = 'AED';
            }
            $ident = $a['account'][0] ?? [];
            $name = self::str($a['nickname'] ?? null, 150)
                ?? self::str($ident['name'] ?? null, 150)
                ?? self::str($a['description'] ?? null, 150)
                ?? ucfirst(strtolower((string) ($a['account_sub_type'] ?? 'Account')));
            $upsert->execute([
                $tenantId, $entityId, $accountId, $name,
                self::str($a['account_sub_type'] ?? null, 30),
                $currency,
                self::mask($ident['identification'] ?? null),
            ]);
            $load->execute([$tenantId, $accountId]);
            $row = $load->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                continue;
            }
            $sum['accounts']++;

            // Balance → absolute snapshot for the linked bank (only when it changed)
            $balance = self::pickBalance($client->balances($entityId, $accountId));
            if ($balance !== null) {
                $saveBalance->execute([$balance, $row['id'], $tenantId]);
                if ($row['bank_id'] && $actor) {
                    if (self::snapshotIfChanged($pdo, $tenantId, $actor, (int) $row['bank_id'], $balance, $currency)) {
                        $sum['snapshots']++;
                    }
                }
            }

            // Transactions since the last sync (with overlap for late bookings), or the last 90 days
            $from = $row['last_synced_at']
                ? date('Y-m-d', strtotime($row['last_synced_at'] . ' -' . self::OVERLAP_DAYS . ' days'))
                : date('Y-m-d', strtotime('-' . self::FIRST_SYNC_DAYS . ' days'));
            foreach ($client->transactions($entityId, $accountId, $from, $today) as $t) {
                $tx = self::normaliseTransaction($t, $accountId, $currency);
                if ($tx === null) {
                    continue;
                }
                $insertTx->execute([
                    $tenantId, $accountId, $tx['id'], $tx['date'], $tx['amount'], $tx['currency'],
                    $tx['description'], $tx['merchant'], $tx['card_last4'],
                ]);
                if ($insertTx->rowCount() === 1) {
                    $sum['transactions']++;
                }
            }
            $touch->execute([$row['id'], $tenantId]);
        }

        $pdo->prepare("UPDATE lean_entities SET status = 'active', last_error = NULL, last_synced_at = NOW() WHERE tenant_id = ? AND entity_id = ?")
            ->execute([$tenantId, $entityId]);
    }

    /**
     * Insert an absolute balance snapshot (today) unless the bank's latest snapshot already
     * has this amount. Not BalanceHelper::adjust(): this is a balance, not a delta.
     */
    private static function snapshotIfChanged(PDO $pdo, int $tenantId, int $userId, int $bankId, float $balance, string $currency): bool
    {
        $bank = BalanceHelper::bank($pdo, $tenantId, $bankId);
        if (!$bank || strtoupper($bank['currency'] ?: 'AED') !== strtoupper($currency)) {
            return false;
        }
        $latest = BalanceHelper::latest($pdo, $tenantId, $bank);
        if ($latest && abs((float) $latest['amount'] - $balance) < 0.005) {
            return false;
        }
        $pdo->prepare(
            "INSERT INTO bank_balances (tenant_id, user_id, bank_id, bank_name, amount, balance_date, currency)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$tenantId, $userId, $bank['id'], $bank['bank_name'], round($balance, 2), date('Y-m-d'), $bank['currency'] ?: 'AED']);
        return true;
    }

    private static function pickBalance(array $balances): ?float
    {
        $best = null;
        $bestRank = PHP_INT_MAX;
        $bestTime = '';
        foreach ($balances as $b) {
            $raw = $b['amount']['amount'] ?? ($b['amount'] ?? null);
            if (!is_numeric($raw)) {
                continue;
            }
            $rank = array_search(strtoupper((string) ($b['type'] ?? '')), self::BALANCE_PREFERENCE, true);
            $rank = $rank === false ? 100 : $rank;
            $time = (string) ($b['date_time'] ?? '');
            if ($rank < $bestRank || ($rank === $bestRank && $time > $bestTime)) {
                $value = abs((float) $raw);
                $indicator = strtoupper((string) ($b['credit_debit_indicator'] ?? ''));
                if ($indicator === 'DEBIT' || ($indicator !== 'CREDIT' && (float) $raw < 0)) {
                    $value = -$value;
                }
                $best = round($value, 2);
                $bestRank = $rank;
                $bestTime = $time;
            }
        }
        return $best;
    }

    /** @return array{id: string, date: string, amount: float, currency: string, description: string, merchant: ?string, card_last4: ?string}|null */
    private static function normaliseTransaction(array $t, string $accountId, string $accountCurrency): ?array
    {
        if (strtoupper((string) ($t['status'] ?? 'BOOKED')) === 'PENDING') {
            return null; // pending items often come back later with a different id
        }
        $raw = $t['amount']['amount'] ?? ($t['amount'] ?? null);
        if (!is_numeric($raw) || (float) $raw == 0.0) {
            return null;
        }
        $date = substr((string) ($t['booking_date_time'] ?? ($t['value_date_time'] ?? '')), 0, 10);
        $dt = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            return null;
        }
        $amount = abs((float) $raw);
        $indicator = strtoupper((string) ($t['credit_debit_indicator'] ?? ''));
        if ($indicator === 'DEBIT' || ($indicator !== 'CREDIT' && (float) $raw < 0)) {
            $amount = -$amount;
        }
        $currency = strtoupper((string) ($t['amount']['currency'] ?? $accountCurrency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = $accountCurrency;
        }
        $description = self::str($t['transaction_information'] ?? null, 255) ?? '';
        $merchant = self::str($t['merchant_details']['merchant_name'] ?? null, 150);

        $id = self::str($t['transaction_id'] ?? null, 128);
        if ($id === null) {
            $id = 'h:' . sha1($accountId . '|' . $date . '|' . $amount . '|' . $description . '|' . ($t['transaction_reference'] ?? ''));
        }

        $card = null;
        if (preg_match('/(\d{4})\D*$/', (string) ($t['card_instrument']['identification'] ?? ''), $m)) {
            $card = $m[1];
        }

        return [
            'id' => $id, 'date' => $date, 'amount' => round($amount, 2), 'currency' => $currency,
            'description' => $description, 'merchant' => $merchant, 'card_last4' => $card,
        ];
    }

    private static function markEntity(PDO $pdo, int $tenantId, string $entityId, ?string $status, ?string $error): void
    {
        if ($status !== null) {
            $pdo->prepare("UPDATE lean_entities SET status = ?, last_error = ? WHERE tenant_id = ? AND entity_id = ? AND status <> 'disconnected'")
                ->execute([$status, $error, $tenantId, $entityId]);
        } else {
            $pdo->prepare("UPDATE lean_entities SET last_error = ? WHERE tenant_id = ? AND entity_id = ?")
                ->execute([$error, $tenantId, $entityId]);
        }
    }

    // ── Review & import ──────────────────────────────────────────────────────

    /**
     * Import 'new' transactions (tenant-checked ids) as expenses or income in one DB transaction.
     * $categories maps transaction id → chosen category; invalid/missing ones fall back to a suggestion.
     *
     * @return array{imported: int, skipped: int}
     */
    public static function import(PDO $pdo, int $tenantId, int $userId, array $ids, string $kind, array $categories): array
    {
        if (!in_array($kind, ['expense', 'income'], true)) {
            throw new DomainException('Unknown import type');
        }
        $ids = self::ids($ids);
        if (!$ids) {
            return ['imported' => 0, 'skipped' => 0];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM lean_transactions WHERE tenant_id = ? AND status = 'new' AND id IN ($in)");
        $stmt->execute(array_merge([$tenantId], $ids));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // FX rates before the transaction starts (a slow rate API must not hold row locks)
        $rates = ['AED' => 1.0];
        foreach ($rows as $r) {
            $cur = strtoupper($r['currency'] ?: 'AED');
            if (!isset($rates[$cur])) {
                $rate = ExchangeRateHelper::getRate($cur, 'AED', $pdo);
                $rates[$cur] = $rate > 0 ? $rate : 1.0;
            }
        }

        // A card can be matched only when exactly one tenant card has those last four digits
        $cardByLast4 = [];
        if ($kind === 'expense') {
            $cs = $pdo->prepare("SELECT id, last_four FROM cards WHERE tenant_id = ? AND last_four IS NOT NULL AND last_four <> ''");
            $cs->execute([$tenantId]);
            foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cardByLast4[$c['last_four']][] = (int) $c['id'];
            }
        }

        $lockRow = $pdo->prepare("SELECT status FROM lean_transactions WHERE id = ? AND tenant_id = ? FOR UPDATE");
        $insExpense = $pdo->prepare(
            "INSERT INTO expenses (user_id, tenant_id, spent_by_user_id, amount, description, category, payment_method, card_id,
                                   balance_bank_id, expense_date, is_subscription, currency, original_amount, tags, cashback_earned, is_fixed)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, 0, ?, ?, 'open-banking', 0, 0)"
        );
        $insIncome = $pdo->prepare(
            "INSERT INTO income (user_id, tenant_id, amount, description, category, income_date, is_recurring, recurrence_day, currency, balance_bank_id)
             VALUES (?, ?, ?, ?, ?, ?, 0, NULL, 'AED', NULL)"
        );
        $mark = $pdo->prepare(
            "UPDATE lean_transactions SET status = 'imported', imported_expense_id = ?, imported_income_id = ? WHERE id = ? AND tenant_id = ?"
        );

        $imported = 0;
        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $lockRow->execute([$r['id'], $tenantId]);
                if ($lockRow->fetchColumn() !== 'new') {
                    continue; // imported/ignored by someone else meanwhile
                }
                $cur  = strtoupper($r['currency'] ?: 'AED');
                $orig = round(abs((float) $r['amount']), 2);
                $aed  = round($orig * $rates[$cur], 2);
                if ($aed <= 0) {
                    continue;
                }
                $text = trim(($r['merchant'] ?? '') . ' ' . $r['description']);
                $desc = trim((string) ($r['merchant'] ?: $r['description'])) ?: 'Bank transaction';

                $category = (string) ($categories[$r['id']] ?? '');
                if ($kind === 'expense') {
                    if (!Categories::isExpense($category)) {
                        $category = self::suggestCategory($text, 'expense');
                    }
                    $cardIds = $r['card_last4'] ? ($cardByLast4[$r['card_last4']] ?? []) : [];
                    $cardId  = count($cardIds) === 1 ? $cardIds[0] : null;
                    $insExpense->execute([
                        $userId, $tenantId, $userId, $aed, mb_substr($desc, 0, 255), $category,
                        $cardId ? 'Card' : 'Cash', $cardId, $r['booking_date'],
                        $cur, $cur !== 'AED' ? $orig : null,
                    ]);
                    $mark->execute([(int) $pdo->lastInsertId(), null, $r['id'], $tenantId]);
                } else {
                    if (!Categories::isIncome($category)) {
                        $category = self::suggestCategory($text, 'income');
                    }
                    if ($cur !== 'AED') {
                        $desc .= ' (' . number_format($orig, 2, '.', '') . ' ' . $cur . ')';
                    }
                    $insIncome->execute([$userId, $tenantId, $aed, mb_substr($desc, 0, 255), $category, $r['booking_date']]);
                    $mark->execute([null, (int) $pdo->lastInsertId(), $r['id'], $tenantId]);
                }
                $imported++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        // Imported expenses count towards budgets like any other (never throws).
        if ($kind === 'expense' && $imported > 0) {
            BudgetAlertHelper::checkTenant($pdo, $tenantId);
        }
        return ['imported' => $imported, 'skipped' => count($ids) - $imported];
    }

    /** Mark tenant transactions as ignored ($ignore) or back to new. Returns the number changed. */
    public static function setIgnored(PDO $pdo, int $tenantId, array $ids, bool $ignore): int
    {
        $ids = self::ids($ids);
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "UPDATE lean_transactions SET status = ? WHERE tenant_id = ? AND status = ? AND id IN ($in)"
        );
        $stmt->execute(array_merge([$ignore ? 'ignored' : 'new', $tenantId, $ignore ? 'new' : 'ignored'], $ids));
        return $stmt->rowCount();
    }

    /** Best-guess category from merchant/description keywords. */
    public static function suggestCategory(string $text, string $kind): string
    {
        $map = $kind === 'income' ? [
            'Salary'     => ['salary', 'payroll', 'wps', 'sal'],
            'Bonus'      => ['bonus'],
            'Incentives' => ['incentive', 'commission'],
            'Investment' => ['dividend', 'profit', 'interest', 'investment', 'sarwa', 'stake'],
            'Freelance'  => ['freelance', 'upwork', 'fiverr'],
            'Gift'       => ['gift'],
        ] : [
            'Grocery'       => ['carrefour', 'lulu', 'spinneys', 'waitrose', 'choithrams', 'union coop', 'grandiose', 'nesto',
                                'viva', 'kibsons', 'west zone', 'al maya', 'supermarket', 'hypermarket', 'grocery', 'instashop'],
            'Food'          => ['restaurant', 'cafe', 'coffee', 'starbucks', 'costa', 'tim hortons', 'mcdonald', 'kfc', 'burger',
                                'pizza', 'talabat', 'deliveroo', 'zomato', 'noon food', 'careem food', 'shawarma', 'bakery', 'eat'],
            'Transport'     => ['enoc', 'adnoc', 'eppco', 'emarat', 'petrol', 'fuel', 'salik', 'darb', 'rta', 'nol', 'uber',
                                'careem', 'taxi', 'parking', 'metro', 'mawaqif'],
            'Utilities'     => ['dewa', 'sewa', 'fewa', 'addc', 'aadc', 'etisalat', 'e&', 'du', 'virgin mobile', 'empower',
                                'tabreed', 'lootah', 'emirates gas', 'internet', 'telecom', 'utility', 'chiller'],
            'Medical'       => ['pharmacy', 'aster', 'boots', 'hospital', 'clinic', 'medical', 'dental', 'mediclinic', 'nmc',
                                'healthcare', 'lab'],
            'Shopping'      => ['amazon', 'noon', 'namshi', 'ikea', 'centrepoint', 'max fashion', 'zara', 'h&m', 'sharaf dg',
                                'emax', 'jumbo', 'ace', 'mall', 'apple', 'shein', 'decathlon'],
            'Entertainment' => ['netflix', 'spotify', 'vox', 'reel cinema', 'cinema', 'osn', 'shahid', 'playstation', 'steam',
                                'anghami', 'youtube', 'disney', 'xbox'],
            'Travel'        => ['emirates airline', 'flydubai', 'etihad', 'air arabia', 'airline', 'airways', 'hotel', 'booking.com',
                                'airbnb', 'agoda', 'expedia', 'musafir'],
            'Education'     => ['school', 'university', 'college', 'academy', 'tuition', 'course', 'udemy', 'coursera', 'gems',
                                'taaleem', 'nursery'],
        ];
        $text = mb_strtolower($text);
        foreach ($map as $category => $words) {
            foreach ($words as $w) {
                if (preg_match('/(^|[^a-z])' . preg_quote($w, '/') . '($|[^a-z])/u', $text)) {
                    return $category;
                }
            }
        }
        return 'Other';
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * Apply a verified webhook event. Returns [tenantId, entityId] when the event means fresh
     * data is ready to sync, else null. Unknown events are ignored.
     *
     * @return array{0: int, 1: string}|null
     */
    public static function handleWebhook(PDO $pdo, array $event): ?array
    {
        $type = (string) ($event['type'] ?? '');
        $p = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        switch ($type) {
            case 'entity.created':
                $entityId = (string) ($p['id'] ?? '');
                $tenantId = self::tenantForCustomer($pdo, (string) ($p['customer_id'] ?? ''));
                if (!$tenantId || !LeanClient::isId($entityId)) {
                    return null;
                }
                $identifier = self::str($p['bank_details']['identifier'] ?? null, 64);
                $name = self::str($p['bank_details']['name'] ?? null, 100) ?? self::prettyBank($identifier);
                $pdo->prepare(
                    "INSERT INTO lean_entities (tenant_id, entity_id, customer_id, bank_identifier, bank_name, status)
                     VALUES (?, ?, ?, ?, ?, 'pending')
                     ON DUPLICATE KEY UPDATE bank_identifier = COALESCE(bank_identifier, VALUES(bank_identifier)),
                                             bank_name = COALESCE(VALUES(bank_name), bank_name)"
                )->execute([$tenantId, $entityId, (string) $p['customer_id'], $identifier, $name]);
                return null; // data is populated asynchronously; wait for entity.data.refresh.updated

            case 'entity.reconnected':
                $entityId = (string) ($p['id'] ?? '');
                $tenantId = self::tenantForEntity($pdo, $entityId);
                if (!$tenantId) {
                    return null;
                }
                self::markEntity($pdo, $tenantId, $entityId, 'active', null);
                return [$tenantId, $entityId];

            case 'entity.data.refresh.updated':
                $entityId = (string) ($p['entity_id'] ?? '');
                $tenantId = self::tenantForEntity($pdo, $entityId);
                if (!$tenantId) {
                    return null;
                }
                $statuses = array_map('strtoupper', array_filter(array_values((array) ($p['data_status'] ?? [])), 'is_string'));
                if (array_intersect($statuses, ['CONSENT_EXPIRED', 'RECONNECT_REQUIRED'])) {
                    $status = in_array('CONSENT_EXPIRED', $statuses, true) ? 'consent_expired' : 'reconnect_required';
                    self::markEntity($pdo, $tenantId, $entityId, $status, 'The bank needs to be reconnected.');
                    return null;
                }
                if (strtoupper((string) ($p['status'] ?? '')) === 'FINISHED') {
                    $pdo->prepare("UPDATE lean_entities SET status = 'active', last_error = NULL WHERE tenant_id = ? AND entity_id = ? AND status IN ('pending', 'active')")
                        ->execute([$tenantId, $entityId]);
                    return [$tenantId, $entityId];
                }
                return null;

            case 'consent.status.updated':
                if (strtoupper((string) ($p['consent_type'] ?? '')) === 'PAYMENT') {
                    return null;
                }
                $status = strtoupper((string) ($p['status'] ?? ''));
                if (!in_array($status, ['REVOKED', 'EXPIRED', 'SUSPENDED', 'REJECTED', 'CANCELLED', 'CONSUMED'], true)) {
                    return null;
                }
                $tenantId = self::tenantForCustomer($pdo, (string) ($p['customer_id'] ?? ''));
                $accountId = (string) ($p['account_id'] ?? '');
                if (!$tenantId || !LeanClient::isId($accountId)) {
                    return null;
                }
                $stmt = $pdo->prepare("SELECT entity_id FROM lean_accounts WHERE tenant_id = ? AND account_id = ?");
                $stmt->execute([$tenantId, $accountId]);
                $entityId = $stmt->fetchColumn();
                if ($entityId) {
                    self::markEntity($pdo, $tenantId, (string) $entityId, 'consent_expired', 'Bank consent ' . strtolower($status) . '. Please reconnect.');
                }
                return null;
        }
        return null;
    }

    private static function tenantForCustomer(PDO $pdo, string $customerId): ?int
    {
        if (!LeanClient::isId($customerId)) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT tenant_id FROM lean_customers WHERE customer_id = ? LIMIT 1");
        $stmt->execute([$customerId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    private static function tenantForEntity(PDO $pdo, string $entityId): ?int
    {
        if (!LeanClient::isId($entityId)) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT tenant_id FROM lean_entities WHERE entity_id = ?");
        $stmt->execute([$entityId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    // ── Small helpers ────────────────────────────────────────────────────────

    /** @return int[] unique positive ints, at most 500 */
    private static function ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id) {
                $out[$id] = $id;
            }
        }
        return array_slice(array_values($out), 0, 500);
    }

    /** Trimmed string clipped to $max chars, or null when empty / not a string. */
    private static function str($value, int $max): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** "AE070331234567890123456" → "•••• 3456". */
    private static function mask($identification): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $identification);
        return $digits === '' ? null : '•••• ' . substr($digits, -4);
    }

    private static function prettyBank(?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }
        return mb_substr(ucwords(strtolower(str_replace(['_', '-'], ' ', $identifier))), 0, 100);
    }
}
