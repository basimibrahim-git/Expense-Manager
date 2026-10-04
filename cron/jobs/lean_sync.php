<?php
/**
 * Daily open-banking sync (required by cron/reminder_emails.php with $pdo in scope).
 *
 * For every family with an active Lean connection: asks Lean for a fresh pull from the bank
 * (the result arrives later via lean_webhook.php), then syncs what Lean already holds —
 * balance snapshots for linked banks (only when changed) and new transactions for review.
 * Families whose connection needs to be re-authorised get one email per connection per month.
 * Idempotent: snapshots are only written on change, transactions are deduped, emails go
 * through Notifier::sendOnce.
 */
if (!defined('IS_CLI')) {
    http_response_code(403);
    exit;
}

/** @var PDO $pdo */

use App\Helpers\Html;
use App\Helpers\LeanClient;
use App\Helpers\LeanSync;
use App\Helpers\Notifier;

if (!LeanClient::isConfigured() || !LeanSync::tablesReady($pdo)) {
    logLine('LEAN skipped (not configured or tables missing)');
    return;
}

$jobLean_tenants = $pdo->query(
    "SELECT DISTINCT tenant_id FROM lean_entities WHERE status IN ('active', 'pending')"
)->fetchAll(PDO::FETCH_COLUMN);

foreach (array_map('intval', $jobLean_tenants) as $jobLean_tid) {
    try {
        $jobLean_sum = LeanSync::syncTenant($pdo, $jobLean_tid, null, true);
        logLine(sprintf(
            'LEAN tenant #%d — %d account(s), %d balance snapshot(s), %d new transaction(s)%s',
            $jobLean_tid,
            $jobLean_sum['accounts'],
            $jobLean_sum['snapshots'],
            $jobLean_sum['transactions'],
            $jobLean_sum['busy'] ? ' (busy, skipped)' : ($jobLean_sum['errors'] ? ' — ' . count($jobLean_sum['errors']) . ' issue(s)' : '')
        ));
    } catch (Throwable $e) {
        logLine("LEAN ERROR tenant #{$jobLean_tid} — " . $e->getMessage());
    }
}

// Ask families to reconnect banks whose consent expired / needs re-authorisation
$jobLean_broken = $pdo->query(
    "SELECT tenant_id, entity_id, COALESCE(bank_name, 'Your bank') AS bank_name
       FROM lean_entities
      WHERE status IN ('reconnect_required', 'consent_expired')"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($jobLean_broken as $jobLean_row) {
    try {
        $jobLean_body = '<p>The open-banking connection to <strong>' . Html::e($jobLean_row['bank_name']) . '</strong> '
            . 'has expired or needs to be re-authorised, so its balance and transactions are no longer syncing.</p>'
            . '<p>A family admin can reconnect it under <em>Open Banking</em>.</p>';
        $jobLean_sent = Notifier::sendOnce(
            $pdo,
            (int) $jobLean_row['tenant_id'],
            'lean_reconnect',
            substr($jobLean_row['entity_id'], 0, 64) . ':' . date('Y-m'),
            'Please reconnect ' . $jobLean_row['bank_name'],
            Notifier::wrap('Bank connection needs attention', $jobLean_body, Notifier::appUrl('lean_accounts.php'))
        );
        if ($jobLean_sent) {
            logLine("LEAN reconnect email sent to tenant #{$jobLean_row['tenant_id']}");
        }
    } catch (Throwable $e) {
        logLine("LEAN reconnect email ERROR tenant #{$jobLean_row['tenant_id']} — " . $e->getMessage());
    }
}
