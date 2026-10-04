<?php
/**
 * Daily budget alerts (required by cron/reminder_emails.php with $pdo in scope).
 *
 * For every family with alerts enabled and budgets for the current month, emails the members
 * once per category/month when spending reaches the warning or over-budget threshold
 * (settings on manage_budgets.php#alerts). Idempotent: dedupe via notification_log.
 * The same check also runs instantly after an expense is saved (BudgetAlertHelper::checkTenant).
 */
if (!defined('IS_CLI')) {
    http_response_code(403);
    exit;
}

/** @var PDO $pdo */

$jobBudget_tenants = $pdo->prepare(
    "SELECT DISTINCT b.tenant_id
       FROM budgets b
       LEFT JOIN budget_alert_settings s ON s.tenant_id = b.tenant_id
      WHERE b.month = ? AND b.year = ? AND b.amount > 0 AND b.tenant_id IS NOT NULL
        AND COALESCE(s.enabled, 1) = 1"
);
$jobBudget_tenants->execute([(int) date('n'), (int) date('Y')]);
$jobBudget_ids = array_map('intval', $jobBudget_tenants->fetchAll(PDO::FETCH_COLUMN));

$jobBudget_emails = 0;
$jobBudget_categories = 0;
foreach ($jobBudget_ids as $jobBudget_tid) {
    try {
        $jobBudget_n = \App\Helpers\BudgetAlertHelper::runTenant($pdo, $jobBudget_tid);
        if ($jobBudget_n > 0) {
            $jobBudget_emails++;
            $jobBudget_categories += $jobBudget_n;
            logLine("BUDGET SENT tenant #{$jobBudget_tid} — {$jobBudget_n} categor" . ($jobBudget_n === 1 ? 'y' : 'ies'));
        }
    } catch (Throwable $e) {
        logLine("BUDGET ERROR tenant #{$jobBudget_tid} — " . $e->getMessage());
    }
}

logLine('BUDGET Done. Families checked=' . count($jobBudget_ids) . " Emails={$jobBudget_emails} Categories={$jobBudget_categories}");
