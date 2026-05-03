<?php
/**
 * Reminder Email Cron Script
 *
 * Sends email alerts to the configured recipients at 4 checkpoints
 * relative to each reminder's alert_date:
 *   -7 days  → "1 week before" warning
 *   -3 days  → "3 days before" warning
 *    0 days  → "due today" alert
 *   +3 days  → "3 days overdue" follow-up
 *
 * Cron setup (runs daily at 9 AM server time):
 *   0 9 * * * php /var/www/html/expenses/cron/reminder_emails.php >> /var/log/reminder_emails.log 2>&1
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

        if ($trigger_date !== $today) {
            continue;
        }

        // Already sent this offset for this reminder on this date?
        $check = $pdo->prepare(
            "SELECT id FROM reminder_email_log
             WHERE reminder_id = ? AND offset_days = ? AND sent_date = ?"
        );
        $check->execute([$rem['id'], $offset, $today]);
        if ($check->fetch()) {
            $skipped++;
            logLine("SKIP  reminder #{$rem['id']} ({$rem['title']}) offset={$offset}d — already sent");
            continue;
        }

        // ── Build email ───────────────────────────────────────────────────────
        [$subject, $html] = buildEmail($rem, $offset);

        $ok = MailHelper::send($RECIPIENTS, $subject, $html);

        if ($ok) {
            $log = $pdo->prepare(
                "INSERT INTO reminder_email_log (reminder_id, offset_days, sent_date)
                 VALUES (?, ?, ?)"
            );
            $log->execute([$rem['id'], $offset, $today]);
            $sent++;
            logLine("SENT  reminder #{$rem['id']} ({$rem['title']}) offset={$offset}d");
        } else {
            $errors++;
            logLine("ERROR reminder #{$rem['id']} ({$rem['title']}) offset={$offset}d — mail failed");
        }
    }
}

logLine("Done. Sent=$sent Skipped=$skipped Errors=$errors");

// ── Helpers ───────────────────────────────────────────────────────────────────

function logLine(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    $logFile = dirname(__DIR__) . '/logs/cronlog.txt';
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo IS_CLI ? $line : nl2br(htmlspecialchars($line));
}

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
