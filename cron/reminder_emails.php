<?php
/**
 * Unified Daily Cron Script
 *
 * Runs daily at 9 AM. Does two jobs:
 *
 * 1. Reminder emails — sends alerts at 4 checkpoints relative to each reminder's alert_date:
 *      -7 days  → "1 week before" warning
 *      -3 days  → "3 days before" warning
 *       0 days  → "due today" alert
 *      +3 days  → "3 days overdue" follow-up
 *
 * 2. Monthly digest — on the 1st of each month, sends each tenant's family_admin
 *    a summary of the previous month (income, expenses, top categories, budget performance).
 *
 * Cron setup (runs daily at 9 AM server time):
 *   0 9 * * * php /var/www/html/expenses/cron/reminder_emails.php >> /var/log/cron.log 2>&1
 *
 * HTTP trigger (for testing, requires CRON_SECRET in .env):
 *   https://yourdomain.com/expenses/cron/reminder_emails.php?key=YOUR_SECRET
 */

define('IS_CLI', php_sapi_name() === 'cli');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!IS_CLI) {
    require_once dirname(__DIR__) . '/autoload.php';
    // Load .env manually just for the secret check
    $envLines = @file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $_ENV[trim($k)] = trim(trim($v), "\"'");
    }
    $secret = $_ENV['CRON_SECRET'] ?? '';
    if ($secret === '' || ($_GET['key'] ?? '') !== $secret) {
        http_response_code(403);
        die('Forbidden');
    }
}

require_once dirname(__DIR__) . '/autoload.php';

use App\Core\Bootstrap;
use App\Helpers\MailHelper;

Bootstrap::init();

global $pdo;

// ── Ensure log table exists ───────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS reminder_email_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    reminder_id INT NOT NULL,
    offset_days INT NOT NULL,
    sent_date   DATE NOT NULL,
    sent_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_send (reminder_id, offset_days, sent_date),
    FOREIGN KEY (reminder_id) REFERENCES reminders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Config ────────────────────────────────────────────────────────────────────
$RECIPIENTS = array_filter(array_map('trim', explode(',', $_ENV['MAIL_RECIPIENTS'] ?? '')));

if (empty($RECIPIENTS)) {
    logLine('ERROR No recipients configured — set MAIL_RECIPIENTS in .env');
    exit(1);
}

$OFFSETS = [-7, -3, 0, 3];  // days relative to alert_date
$today      = date('Y-m-d');

// ── Load all active reminders ─────────────────────────────────────────────────
$stmt = $pdo->query(
    "SELECT r.*, t.family_name
     FROM reminders r
     LEFT JOIN tenants t ON r.tenant_id = t.id
     ORDER BY r.alert_date ASC"
);
$reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent    = 0;
$skipped = 0;
$errors  = 0;

logLine("INFO  Today=$today | Reminders found=" . count($reminders) . " | Recipients=" . implode(', ', $RECIPIENTS));

foreach ($reminders as $rem) {
    $alert_date = date('Y-m-d', strtotime($rem['alert_date']));
    $triggers   = array_map(fn($o) => date('Y-m-d', strtotime("$alert_date $o days")) . "({$o}d)", $OFFSETS);
    logLine("CHECK reminder #{$rem['id']} \"{$rem['title']}\" alert={$alert_date} | triggers=" . implode(', ', $triggers));

    foreach ($OFFSETS as $offset) {
        // What calendar date does this offset correspond to?
        $trigger_date = date('Y-m-d', strtotime("$alert_date $offset days"));
        // Allow 1-day retry grace: if yesterday's cron run failed, retry today
        $yesterday    = date('Y-m-d', strtotime('-1 day'));

        if ($trigger_date !== $today && $trigger_date !== $yesterday) {
            continue;
        }

        // Already sent successfully for this offset on any date?
        $check = $pdo->prepare(
            "SELECT id FROM reminder_email_log WHERE reminder_id = ? AND offset_days = ?"
        );
        $check->execute([$rem['id'], $offset]);
        if ($check->fetch()) {
            $skipped++;
            logLine("SKIP  reminder #{$rem['id']} ({$rem['title']}) offset={$offset}d — already sent");
            continue;
        }

        // ── Build email ───────────────────────────────────────────────────────
        [$subject, $html] = buildEmail($rem, $offset);

        $ok = MailHelper::send($RECIPIENTS, $subject, $html);

        if ($ok) {
            // INSERT IGNORE guards against a rare simultaneous double-run
            $log = $pdo->prepare(
                "INSERT IGNORE INTO reminder_email_log (reminder_id, offset_days, sent_date)
                 VALUES (?, ?, ?)"
            );
            $log->execute([$rem['id'], $offset, $today]);
            $sent++;
            logLine("SENT  reminder #{$rem['id']} ({$rem['title']}) offset={$offset}d");

            // Advance recurring reminder after the due-date email
            if ($offset === 0 && ($rem['recurrence_type'] ?? 'none') !== 'none') {
                advanceReminder($pdo, $rem);
            }
        } else {
            $errors++;
            logLine("ERROR reminder #{$rem['id']} ({$rem['title']}) offset={$offset}d — mail failed, will retry tomorrow");
        }
    }
}

logLine("Done. Sent=$sent Skipped=$skipped Errors=$errors");

// Purge audit logs older than 90 days
try {
    $purged = \App\Helpers\AuditHelper::purgeOldLogs($pdo);
    if ($purged > 0) logLine("PURGE Deleted $purged old audit log entries");
} catch (Exception $e) {
    error_log("Audit purge error: " . $e->getMessage());
}

// ── Monthly digest (1st of the month only) ────────────────────────────────────
if ((int) date('j') === 1) {
    runMonthlyDigest($pdo);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function logLine(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    $logFile = dirname(__DIR__) . '/logs/cronlog.txt';
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo IS_CLI ? $line : nl2br(htmlspecialchars($line));
}

function advanceReminder(PDO $pdo, array $rem): void
{
    $map = [
        'daily'   => '+1 day',
        'weekly'  => '+1 week',
        'monthly' => '+1 month',
        'yearly'  => '+1 year',
    ];
    $interval = $map[$rem['recurrence_type']] ?? null;
    if ($interval === null) return;

    $next = date('Y-m-d H:i:s', strtotime($rem['alert_date'] . ' ' . $interval));
    $pdo->prepare("UPDATE reminders SET alert_date = ? WHERE id = ?")
        ->execute([$next, $rem['id']]);
    logLine("ADVANCE reminder #{$rem['id']} ({$rem['title']}) → next alert_date={$next}");
}

// ═════════════════════════════════════════════════════════════════════════════
// Monthly Digest Helpers
// ═════════════════════════════════════════════════════════════════════════════

function runMonthlyDigest(PDO $pdo): void
{
    $prevMonth  = (int) date('n', strtotime('first day of last month'));
    $prevYear   = (int) date('Y', strtotime('first day of last month'));
    $monthLabel = date('F Y', mktime(0, 0, 0, $prevMonth, 1, $prevYear));

    $tenants = $pdo->query("SELECT id, family_name FROM tenants ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $total   = count($tenants);
    $sent    = 0;

    logLine("DIGEST Starting monthly digest for {$monthLabel} | Tenants={$total}");

    foreach ($tenants as $tenant) {
        $tid  = (int) $tenant['id'];
        $name = $tenant['family_name'];

        try {
            $adminStmt = $pdo->prepare(
                "SELECT name, email FROM users WHERE tenant_id = ? AND role = 'family_admin'
                  AND email IS NOT NULL AND email <> '' LIMIT 1"
            );
            $adminStmt->execute([$tid]);
            $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin) {
                logLine("DIGEST SKIP Tenant #{$tid} ({$name}) — no family_admin email");
                continue;
            }

            // Income
            $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM income WHERE tenant_id=? AND MONTH(income_date)=? AND YEAR(income_date)=?");
            $s->execute([$tid, $prevMonth, $prevYear]);
            $totalIncome = (float) $s->fetchColumn();

            // Expenses
            $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE tenant_id=? AND MONTH(expense_date)=? AND YEAR(expense_date)=?");
            $s->execute([$tid, $prevMonth, $prevYear]);
            $totalExpenses = (float) $s->fetchColumn();

            $netSavings  = $totalIncome - $totalExpenses;
            $savingsRate = ($totalIncome > 0) ? round(($netSavings / $totalIncome) * 100, 1) : 0.0;

            // Top 5 categories
            $s = $pdo->prepare("SELECT category, SUM(amount) AS total FROM expenses WHERE tenant_id=? AND MONTH(expense_date)=? AND YEAR(expense_date)=? GROUP BY category ORDER BY total DESC LIMIT 5");
            $s->execute([$tid, $prevMonth, $prevYear]);
            $topCategories = $s->fetchAll(PDO::FETCH_ASSOC);

            // Budget performance
            $s = $pdo->prepare(
                "SELECT b.category, b.amount AS budget_amount, COALESCE(SUM(e.amount),0) AS actual_spent
                   FROM budgets b
                   LEFT JOIN expenses e ON e.tenant_id=b.tenant_id AND e.category=b.category
                        AND MONTH(e.expense_date)=? AND YEAR(e.expense_date)=?
                  WHERE b.tenant_id=? AND b.month=? AND b.year=?
                  GROUP BY b.category, b.amount ORDER BY b.category ASC"
            );
            $s->execute([$prevMonth, $prevYear, $tid, $prevMonth, $prevYear]);
            $budgets = $s->fetchAll(PDO::FETCH_ASSOC);

            // Sinking funds
            $s = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(current_saved),0) AS total FROM sinking_funds WHERE tenant_id=?");
            $s->execute([$tid]);
            $funds      = $s->fetch(PDO::FETCH_ASSOC);
            $goalCount  = (int)   $funds['cnt'];
            $totalSaved = (float) $funds['total'];

            $html    = buildDigestEmail($name, $admin['name'], $monthLabel, $totalIncome, $totalExpenses, $netSavings, $savingsRate, $topCategories, $totalExpenses, $budgets, $goalCount, $totalSaved);
            $subject = 'Your ' . $monthLabel . ' Financial Digest — ' . $name;
            $ok      = \App\Helpers\MailHelper::send([$admin['email']], $subject, $html);

            digestAuditLog($pdo, $tid, 'monthly_digest_sent', "Month={$monthLabel} | To={$admin['email']} | Income={$totalIncome} | Expenses={$totalExpenses}");

            if ($ok) {
                $sent++;
                logLine("DIGEST SENT Tenant #{$tid} ({$name}) → {$admin['email']}");
            } else {
                logLine("DIGEST ERROR Tenant #{$tid} ({$name}) → mail() failed");
            }
        } catch (Throwable $e) {
            error_log("[monthly_digest] Tenant #{$tid}: " . $e->getMessage());
            logLine("DIGEST ERROR Tenant #{$tid} ({$name}) — " . $e->getMessage());
        }
    }

    logLine("DIGEST Done. Sent={$sent}/{$total}");
}

function digestAuditLog(PDO $pdo, int $tenantId, string $action, string $context): void
{
    try {
        $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_id, action, context, ip_address, user_agent) VALUES (?, NULL, ?, ?, 'cli', 'cron/reminder_emails.php')")
            ->execute([$tenantId, $action, $context]);
    } catch (Throwable $e) {
        error_log("[monthly_digest] auditLog failed: " . $e->getMessage());
    }
}

function buildDigestEmail(string $familyName, string $adminName, string $monthLabel, float $totalIncome, float $totalExpenses, float $netSavings, float $savingsRate, array $topCategories, float $expenseTotal, array $budgets, int $goalCount, float $totalSaved): string
{
    $appUrl    = rtrim($_ENV['APP_URL'] ?? '#', '/');
    $dashUrl   = ($appUrl !== '#') ? $appUrl . '/dashboard.php' : '#';

    $fam       = htmlspecialchars($familyName, ENT_QUOTES, 'UTF-8');
    $adm       = htmlspecialchars($adminName,  ENT_QUOTES, 'UTF-8');
    $mon       = htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8');

    $incFmt    = 'AED ' . number_format($totalIncome,   2);
    $expFmt    = 'AED ' . number_format($totalExpenses, 2);
    $netFmt    = 'AED ' . number_format(abs($netSavings), 2);
    $netLabel  = ($netSavings >= 0) ? 'Saved' : 'Deficit';
    $netColor  = ($netSavings >= 0) ? '#198754' : '#dc3545';
    $rateLabel = number_format($savingsRate, 1) . '%';
    $rateColor = ($savingsRate >= 20) ? '#198754' : (($savingsRate >= 0) ? '#ffc107' : '#dc3545');
    $rateWidth = max(0, min(100, (int) abs($savingsRate)));

    // Category rows
    $catRows = empty($topCategories)
        ? '<tr><td colspan="3" style="padding:10px 12px;color:#9ca3af;font-size:13px;">No expense data for this period.</td></tr>'
        : '';
    foreach ($topCategories as $i => $cat) {
        $bg      = ($i % 2 === 0) ? '#fff' : '#f9fafb';
        $pct     = ($expenseTotal > 0) ? number_format(((float)$cat['total'] / $expenseTotal) * 100, 1) . '%' : '0%';
        $catRows .= '<tr style="background:' . $bg . ';">'
            . '<td style="padding:10px 12px;font-size:13px;color:#374151;">' . htmlspecialchars($cat['category'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:10px 12px;font-size:13px;text-align:right;">AED ' . number_format((float)$cat['total'], 2) . '</td>'
            . '<td style="padding:10px 12px;font-size:13px;color:#6c757d;text-align:right;">' . $pct . '</td>'
            . '</tr>';
    }

    // Budget rows
    $budgetRows = empty($budgets)
        ? '<tr><td colspan="4" style="padding:10px 12px;color:#9ca3af;font-size:13px;">No budgets set for this period.</td></tr>'
        : '';
    foreach ($budgets as $i => $b) {
        $pctUsed    = ($b['budget_amount'] > 0) ? ($b['actual_spent'] / $b['budget_amount']) * 100 : 0;
        $sc         = ($pctUsed >= 100) ? '#dc3545' : (($pctUsed >= 80) ? '#fd7e14' : '#198754');
        $st         = ($pctUsed >= 100) ? 'Over Budget' : (($pctUsed >= 80) ? 'Near Limit' : 'On Track');
        $bg         = ($i % 2 === 0) ? '#fff' : '#f9fafb';
        $budgetRows .= '<tr style="background:' . $bg . ';">'
            . '<td style="padding:10px 12px;font-size:13px;">' . htmlspecialchars($b['category'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:10px 12px;font-size:13px;text-align:right;">AED ' . number_format((float)$b['budget_amount'], 2) . '</td>'
            . '<td style="padding:10px 12px;font-size:13px;text-align:right;">AED ' . number_format((float)$b['actual_spent'], 2) . '</td>'
            . '<td style="padding:10px 12px;text-align:center;"><span style="background:' . $sc . ';color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;">' . number_format($pctUsed, 1) . '% &mdash; ' . $st . '</span></td>'
            . '</tr>';
    }

    $goalsLine = ($goalCount > 0)
        ? 'You have <strong>' . $goalCount . '</strong> active saving ' . ($goalCount === 1 ? 'goal' : 'goals') . ' with <strong>AED ' . number_format($totalSaved, 2) . '</strong> saved across all funds.'
        : 'No saving goals set up yet.';

    $h  = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
    $h .= '<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">';
    $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:30px 0;"><tr><td align="center">';
    $h .= '<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,.08);max-width:100%;">';

    // Header
    $h .= '<tr><td style="background:#0d6efd;padding:32px 40px;text-align:center;">'
        . '<p style="margin:0;font-size:12px;color:rgba(255,255,255,.7);letter-spacing:1.5px;text-transform:uppercase;">Expense Manager</p>'
        . '<h1 style="margin:10px 0 4px;font-size:24px;color:#fff;font-weight:700;">' . $fam . '</h1>'
        . '<p style="margin:0;font-size:15px;color:rgba(255,255,255,.85);">' . $mon . ' Monthly Digest</p>'
        . '</td></tr>';

    // Greeting
    $h .= '<tr><td style="padding:28px 40px 0;">'
        . '<p style="margin:0;font-size:15px;color:#374151;line-height:1.6;">Hi <strong>' . $adm . '</strong>, here\'s your family\'s financial summary for <strong>' . $mon . '</strong>.</p>'
        . '</td></tr>';

    // Stat cards
    $h .= '<tr><td style="padding:24px 40px 0;"><table width="100%" cellpadding="0" cellspacing="0"><tr>';
    $h .= '<td width="33%" style="padding:0 6px 0 0;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border-radius:10px;"><tr><td style="padding:16px;">'
        . '<p style="margin:0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#198754;">Income</p>'
        . '<p style="margin:6px 0 0;font-size:20px;font-weight:700;color:#111827;">' . $incFmt . '</p>'
        . '</td></tr></table></td>';
    $h .= '<td width="33%" style="padding:0 3px;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#fff5f5;border-radius:10px;"><tr><td style="padding:16px;">'
        . '<p style="margin:0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#dc3545;">Expenses</p>'
        . '<p style="margin:6px 0 0;font-size:20px;font-weight:700;color:#111827;">' . $expFmt . '</p>'
        . '</td></tr></table></td>';
    $h .= '<td width="33%" style="padding:0 0 0 6px;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border-radius:10px;"><tr><td style="padding:16px;">'
        . '<p style="margin:0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:' . $netColor . ';">' . $netLabel . '</p>'
        . '<p style="margin:6px 0 0;font-size:20px;font-weight:700;color:' . $netColor . ';">' . $netFmt . '</p>'
        . '</td></tr></table></td>';
    $h .= '</tr></table></td></tr>';

    // Savings rate bar
    $h .= '<tr><td style="padding:20px 40px 0;">'
        . '<p style="margin:0 0 6px;font-size:13px;color:#6c757d;">Savings Rate: <strong style="color:#111827;">' . $rateLabel . '</strong></p>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#e9ecef;border-radius:6px;height:10px;overflow:hidden;"><tr>'
        . '<td width="' . $rateWidth . '%" style="background:' . $rateColor . ';height:10px;border-radius:6px;"></td><td></td>'
        . '</tr></table></td></tr>';

    // Top categories
    $h .= '<tr><td style="padding:28px 40px 0;">'
        . '<h2 style="margin:0 0 12px;font-size:16px;color:#111827;font-weight:700;border-bottom:2px solid #e5e7eb;padding-bottom:8px;">Top Spending Categories</h2>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;">'
        . '<thead><tr style="background:#f8f9fa;">'
        . '<th style="padding:10px 12px;font-size:12px;font-weight:700;text-transform:uppercase;color:#6c757d;text-align:left;">Category</th>'
        . '<th style="padding:10px 12px;font-size:12px;font-weight:700;text-transform:uppercase;color:#6c757d;text-align:right;">Amount</th>'
        . '<th style="padding:10px 12px;font-size:12px;font-weight:700;text-transform:uppercase;color:#6c757d;text-align:right;">% of Total</th>'
        . '</tr></thead><tbody>' . $catRows . '</tbody></table></td></tr>';

    // Budget performance
    $h .= '<tr><td style="padding:28px 40px 0;">'
        . '<h2 style="margin:0 0 12px;font-size:16px;color:#111827;font-weight:700;border-bottom:2px solid #e5e7eb;padding-bottom:8px;">Budget Performance</h2>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;">'
        . '<thead><tr style="background:#f8f9fa;">'
        . '<th style="padding:10px 12px;font-size:12px;font-weight:700;text-transform:uppercase;color:#6c757d;text-align:left;">Category</th>'
        . '<th style="padding:10px 12px;font-size:12px;font-weight:700;text-transform:uppercase;color:#6c757d;text-align:right;">Budget</th>'
        . '<th style="padding:10px 12px;font-size:12px;font-weight:700;text-transform:uppercase;color:#6c757d;text-align:right;">Actual</th>'
        . '<th style="padding:10px 12px;font-size:12px;font-weight:700;text-transform:uppercase;color:#6c757d;text-align:center;">Status</th>'
        . '</tr></thead><tbody>' . $budgetRows . '</tbody></table></td></tr>';

    // Goals callout
    $h .= '<tr><td style="padding:24px 40px 0;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#fffbeb;border-left:4px solid #f59e0b;border-radius:6px;">'
        . '<tr><td style="padding:16px 20px;">'
        . '<p style="margin:0;font-size:13px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.5px;">Saving Goals</p>'
        . '<p style="margin:6px 0 0;font-size:14px;color:#374151;line-height:1.6;">' . $goalsLine . '</p>'
        . '</td></tr></table></td></tr>';

    // CTA
    $h .= '<tr><td style="padding:32px 40px;text-align:center;">'
        . '<a href="' . htmlspecialchars($dashUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#0d6efd;color:#fff;font-size:15px;font-weight:700;text-decoration:none;padding:13px 36px;border-radius:8px;">View Full Dashboard &rarr;</a>'
        . '</td></tr>';

    // Footer
    $h .= '<tr><td style="background:#f8f9fa;padding:20px 40px;text-align:center;border-top:1px solid #e5e7eb;">'
        . '<p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.8;">Expense Manager &mdash; Automated Monthly Digest<br>'
        . 'Sent to the family administrator of <strong>' . $fam . '</strong>.</p>'
        . '</td></tr>';

    $h .= '</table></td></tr></table></body></html>';
    return $h;
}

// ═════════════════════════════════════════════════════════════════════════════
// Reminder Email Helpers
// ═════════════════════════════════════════════════════════════════════════════

function buildEmail(array $rem, int $offset): array
{
    $title         = htmlspecialchars($rem['title']);
    $family        = htmlspecialchars($rem['family_name'] ?? 'Your Family');
    $alert_pretty  = date('l, d F Y \a\t h:i A', strtotime($rem['alert_date']));
    $days_abs      = abs($offset);

    if ($offset < 0) {
        $timing_label  = $days_abs === 7 ? '1 Week'  : "$days_abs Days";
        $subject       = "⏰ Reminder: {$rem['title']} — {$timing_label} to go";
        $headline      = "{$timing_label} Until Reminder Date";
        $badge_color   = $offset === -7 ? '#198754' : '#ffc107';
        $badge_text    = $offset === -7 ? '#fff'    : '#212529';
        $message       = "This is a heads-up that the following reminder is coming up in <strong>{$timing_label}</strong>.";
    } elseif ($offset === 0) {
        $subject       = "🔔 Reminder Due Today: {$rem['title']}";
        $headline      = "Today Is the Reminder Date";
        $badge_color   = '#dc3545';
        $badge_text    = '#fff';
        $message       = "Today is the date set for the following reminder. Please take any necessary action.";
    } else {
        $subject       = "⚠️ Overdue Reminder: {$rem['title']} ({$days_abs}d ago)";
        $headline      = "Reminder Was {$days_abs} Day" . ($days_abs === 1 ? '' : 's') . " Ago";
        $badge_color   = '#6c757d';
        $badge_text    = '#fff';
        $message       = "The following reminder date has passed <strong>{$days_abs} day" . ($days_abs === 1 ? '' : 's') . " ago</strong>. This is a follow-up notification.";
    }

    $recurrence = $rem['recurrence_type'] !== 'none'
        ? '<tr><td style="padding:6px 0;color:#6c757d;font-size:13px;">Recurrence</td><td style="padding:6px 0;font-size:13px;">' . ucfirst(htmlspecialchars($rem['recurrence_type'])) . '</td></tr>'
        : '';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:30px 0;">
    <tr><td align="center">
      <table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);">

        <!-- Header -->
        <tr>
          <td style="background:#0d6efd;padding:28px 36px;text-align:center;">
            <p style="margin:0;font-size:13px;color:rgba(255,255,255,.7);letter-spacing:1px;text-transform:uppercase;">Expense Manager</p>
            <h1 style="margin:8px 0 0;font-size:22px;color:#ffffff;font-weight:700;">{$headline}</h1>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:32px 36px;">
            <p style="margin:0 0 20px;font-size:15px;color:#374151;line-height:1.6;">{$message}</p>

            <!-- Reminder Card -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border-left:4px solid {$badge_color};border-radius:6px;padding:20px;margin-bottom:24px;">
              <tr>
                <td style="padding:0 20px;">
                  <span style="display:inline-block;background:{$badge_color};color:{$badge_text};font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:3px 10px;border-radius:20px;margin-bottom:10px;">{$headline}</span>
                  <h2 style="margin:0 0 8px;font-size:18px;color:#111827;font-weight:700;">{$title}</h2>
                  <table cellpadding="0" cellspacing="0" style="width:100%;">
                    <tr>
                      <td style="padding:6px 0;color:#6c757d;font-size:13px;width:110px;">Due Date</td>
                      <td style="padding:6px 0;font-size:13px;font-weight:600;color:#374151;">{$alert_pretty}</td>
                    </tr>
                    <tr>
                      <td style="padding:6px 0;color:#6c757d;font-size:13px;">Family</td>
                      <td style="padding:6px 0;font-size:13px;">{$family}</td>
                    </tr>
                    {$recurrence}
                  </table>
                </td>
              </tr>
            </table>

            <p style="margin:0;font-size:13px;color:#9ca3af;">
              This notification was sent automatically by Expense Manager.<br>
              You are receiving this because you are a registered notification recipient.
            </p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f8f9fa;padding:16px 36px;text-align:center;border-top:1px solid #e5e7eb;">
            <p style="margin:0;font-size:12px;color:#9ca3af;">Expense Manager &mdash; Automated Reminder System</p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    return [$subject, $html];
}
