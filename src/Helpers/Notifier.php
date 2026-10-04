<?php

namespace App\Helpers;

use PDO;

/**
 * Email notifications for a family (tenant), sent at most once per (kind, ref_key).
 *
 * Used by the cron jobs in cron/jobs/: budget alerts, card due dates, Zakath reminders…
 *   Notifier::sendOnce($pdo, $tenantId, 'budget_80', "2026-10:Food", $subject, Notifier::wrap($title, $body));
 * The ref_key decides what "once" means (e.g. per category per month).
 */
class Notifier
{
    /**
     * Email addresses of the tenant's members who have notifications enabled.
     *
     * @return string[]
     */
    public static function recipients(PDO $pdo, int $tenantId): array
    {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT u.email
               FROM users u
               LEFT JOIN user_preferences p ON p.user_id = u.id
              WHERE u.tenant_id = ?
                AND u.role IN ('family_admin', 'user')
                AND COALESCE(p.notifications_enabled, 1) = 1
                AND u.email <> ''"
        );
        $stmt->execute([$tenantId]);
        return array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_COLUMN),
            function ($e) { return filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }
        ));
    }

    /**
     * Send unless this (kind, ref_key) was already sent for the tenant.
     * Returns true when an email went out.
     *
     * @param string[]|null $to Override recipients (default: the tenant's members)
     */
    public static function sendOnce(PDO $pdo, int $tenantId, string $kind, string $refKey, string $subject, string $html, ?array $to = null): bool
    {
        // Claim the slot first so two overlapping cron runs can't both send.
        $claim = $pdo->prepare("INSERT IGNORE INTO notification_log (tenant_id, kind, ref_key) VALUES (?, ?, ?)");
        $claim->execute([$tenantId, $kind, $refKey]);
        if ($claim->rowCount() === 0) {
            return false;
        }

        $to = $to ?? self::recipients($pdo, $tenantId);
        if (empty($to) || !MailHelper::send($to, $subject, $html)) {
            // Release the claim so the next run retries.
            $pdo->prepare("DELETE FROM notification_log WHERE tenant_id = ? AND kind = ? AND ref_key = ?")
                ->execute([$tenantId, $kind, $refKey]);
            return false;
        }
        return true;
    }

    /**
     * Minimal branded HTML email. $bodyHtml must already be escaped by the caller
     * (use Html::e() for any data).
     */
    public static function wrap(string $title, string $bodyHtml, ?string $buttonUrl = null, string $buttonText = 'Open Expense Manager'): string
    {
        $button = '';
        if ($buttonUrl !== null) {
            $button = '<p style="margin:24px 0 0;"><a href="' . Html::e($buttonUrl) . '" style="display:inline-block;padding:10px 18px;background:#4f46e5;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;">'
                . Html::e($buttonText) . '</a></p>';
        }
        return '<!DOCTYPE html><html><body style="margin:0;background:#f4f5f7;font-family:Segoe UI,Arial,sans-serif;">'
            . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">'
            . '<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">'
            . '<tr><td style="background:#4f46e5;color:#ffffff;padding:18px 28px;font-size:18px;font-weight:700;">' . Html::e($title) . '</td></tr>'
            . '<tr><td style="padding:24px 28px;color:#1f2937;font-size:15px;line-height:1.6;">' . $bodyHtml . $button . '</td></tr>'
            . '<tr><td style="padding:14px 28px;background:#f8f9fa;color:#9ca3af;font-size:12px;">Sent by Expense Manager. You can turn off email notifications under My Profile.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /**
     * Absolute URL to a page of the app (for email buttons). Uses APP_URL when set.
     */
    public static function appUrl(string $page = 'dashboard.php'): string
    {
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        if ($appUrl === '') {
            $appUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(BASE_URL, '/');
        }
        return $appUrl . '/' . ltrim($page, '/');
    }
}
