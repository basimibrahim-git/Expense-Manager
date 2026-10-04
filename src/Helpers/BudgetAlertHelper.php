<?php

namespace App\Helpers;

use PDO;
use Throwable;

/**
 * Budget alerts: per-family thresholds and the "category crossed its budget" emails.
 *
 * - Daily: cron/jobs/budget_alerts.php calls runTenant() for every family with budgets this month.
 * - Instant: after an expense is saved (and committed), call
 *       BudgetAlertHelper::checkTenant($pdo, (int) $_SESSION['tenant_id'], $category);
 *   It never throws, so it can't break saving an expense.
 *
 * Each (category, month) is alerted at most once per level via notification_log:
 *   kind 'budget_warn' / 'budget_over', ref_key "YYYY-MM:<category>".
 * All categories that newly crossed in one run go out in a single email.
 */
class BudgetAlertHelper
{
    public const DEFAULTS = ['enabled' => 1, 'warn_pct' => 80, 'over_pct' => 100, 'instant' => 1];

    public const WARN_MIN = 50;
    public const WARN_MAX = 100;
    public const OVER_MIN = 100;
    public const OVER_MAX = 200;

    public const KIND_WARN = 'budget_warn';
    public const KIND_OVER = 'budget_over';

    /**
     * The family's alert settings (defaults when none were saved yet or the table is missing).
     *
     * @return array{enabled:int, warn_pct:int, over_pct:int, instant:int}
     */
    public static function settings(PDO $pdo, int $tenantId): array
    {
        try {
            $stmt = $pdo->prepare("SELECT enabled, warn_pct, over_pct, instant FROM budget_alert_settings WHERE tenant_id = ?");
            $stmt->execute([$tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $row = false; // migration not run yet
        }
        return self::normalize($row ?: []);
    }

    /**
     * Validation for the settings form. Returns an error message or null.
     */
    public static function validate(int $warnPct, int $overPct): ?string
    {
        if ($warnPct < self::WARN_MIN || $warnPct > self::WARN_MAX) {
            return 'Warning threshold must be between ' . self::WARN_MIN . '% and ' . self::WARN_MAX . '%.';
        }
        if ($overPct < self::OVER_MIN || $overPct > self::OVER_MAX) {
            return 'Over-budget threshold must be between ' . self::OVER_MIN . '% and ' . self::OVER_MAX . '%.';
        }
        if ($warnPct >= $overPct) {
            return 'The warning threshold must be lower than the over-budget threshold.';
        }
        return null;
    }

    public static function saveSettings(PDO $pdo, int $tenantId, bool $enabled, int $warnPct, int $overPct, bool $instant): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO budget_alert_settings (tenant_id, enabled, warn_pct, over_pct, instant)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), warn_pct = VALUES(warn_pct),
                                     over_pct = VALUES(over_pct), instant = VALUES(instant)"
        );
        $stmt->execute([$tenantId, $enabled ? 1 : 0, $warnPct, $overPct, $instant ? 1 : 0]);
    }

    /**
     * Instant check after an expense was saved. Only acts when alerts and instant alerts are on,
     * for the current month. Safe to call from any request: it never throws.
     * Call it AFTER commit (claims must not be rolled back after the email went out).
     */
    public static function checkTenant(PDO $pdo, int $tenantId, ?string $category = null): void
    {
        try {
            if ($tenantId <= 0) {
                return;
            }
            if ($pdo->inTransaction()) {
                error_log('[BudgetAlert] checkTenant() called inside a transaction; call it after commit. Skipped.');
                return;
            }
            self::runTenant($pdo, $tenantId, true, $category);
        } catch (Throwable $e) {
            error_log('[BudgetAlert] instant check failed for tenant #' . $tenantId . ': ' . $e->getMessage());
        }
    }

    /**
     * Check the tenant's budgets for the current month and email the categories that newly
     * crossed a threshold. Returns the number of categories included in the email (0 = none sent).
     *
     * @param bool        $instantOnly Respect the "instant" setting (used by checkTenant)
     * @param string|null $category    Only consider this category (the total is still reported)
     */
    public static function runTenant(PDO $pdo, int $tenantId, bool $instantOnly = false, ?string $category = null): int
    {
        $monthStart = date('Y-m-01');
        $nextMonth  = date('Y-m-01', strtotime($monthStart . ' +1 month'));
        $month      = (int) date('n');
        $year       = (int) date('Y');

        // One query: settings, each budget, spend per budgeted category and total spend this month.
        $stmt = $pdo->prepare(
            "SELECT b.id, b.category, b.amount AS budget,
                    COALESCE(s.enabled, 1)    AS enabled,
                    COALESCE(s.warn_pct, 80)  AS warn_pct,
                    COALESCE(s.over_pct, 100) AS over_pct,
                    COALESCE(s.instant, 1)    AS instant,
                    COALESCE((SELECT SUM(e.amount) FROM expenses e
                               WHERE e.tenant_id = b.tenant_id AND e.category = b.category
                                 AND e.expense_date >= ? AND e.expense_date < ?), 0) AS spent,
                    COALESCE((SELECT SUM(e2.amount) FROM expenses e2
                               WHERE e2.tenant_id = b.tenant_id
                                 AND e2.expense_date >= ? AND e2.expense_date < ?), 0) AS month_spent
               FROM budgets b
               LEFT JOIN budget_alert_settings s ON s.tenant_id = b.tenant_id
              WHERE b.tenant_id = ? AND b.month = ? AND b.year = ? AND b.amount > 0
              ORDER BY b.id"
        );
        $stmt->execute([$monthStart, $nextMonth, $monthStart, $nextMonth, $tenantId, $month, $year]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return 0;
        }

        $settings = self::normalize($rows[0]);
        if (!$settings['enabled'] || ($instantOnly && !$settings['instant'])) {
            return 0;
        }

        // Legacy data can hold several rows per category (one per family member); latest id wins,
        // matching budget.php.
        $budgets = [];
        foreach ($rows as $r) {
            $budgets[$r['category']] = ['budget' => (float) $r['budget'], 'spent' => (float) $r['spent']];
        }
        $monthSpent  = (float) $rows[0]['month_spent'];
        $totalBudget = 0.0;
        $totalSpent  = 0.0;
        foreach ($budgets as $b) {
            $totalBudget += $b['budget'];
            $totalSpent  += $b['spent'];
        }

        $period  = sprintf('%04d-%02d', $year, $month);
        $claimed = [];   // [kind, ref_key] rows this run inserted (released if the email fails)
        $alerts  = [];   // categories for the email

        $claim = $pdo->prepare("INSERT IGNORE INTO notification_log (tenant_id, kind, ref_key) VALUES (?, ?, ?)");
        $doClaim = function (string $kind, string $ref) use ($claim, $tenantId, &$claimed): bool {
            $claim->execute([$tenantId, $kind, $ref]);
            if ($claim->rowCount() > 0) {
                $claimed[] = [$kind, $ref];
                return true;
            }
            return false;
        };

        foreach ($budgets as $cat => $b) {
            if ($category !== null && $cat !== $category) {
                continue;
            }
            $pct = $b['budget'] > 0 ? ($b['spent'] / $b['budget']) * 100 : 0;
            $ref = mb_substr($period . ':' . $cat, 0, 120);

            if ($pct >= $settings['over_pct']) {
                if ($doClaim(self::KIND_OVER, $ref)) {
                    // Jumped straight past "over": consume the warning too so it isn't sent later
                    // (e.g. after an expense is deleted and the category drops back into the warn band).
                    $doClaim(self::KIND_WARN, $ref);
                    $alerts[] = ['category' => $cat, 'level' => 'over', 'pct' => $pct] + $b;
                }
            } elseif ($pct >= $settings['warn_pct']) {
                if ($doClaim(self::KIND_WARN, $ref)) {
                    $alerts[] = ['category' => $cat, 'level' => 'warn', 'pct' => $pct] + $b;
                }
            }
        }

        if (empty($alerts)) {
            return 0;
        }

        $release = function () use ($pdo, $tenantId, &$claimed): void {
            $del = $pdo->prepare("DELETE FROM notification_log WHERE tenant_id = ? AND kind = ? AND ref_key = ?");
            foreach ($claimed as [$kind, $ref]) {
                $del->execute([$tenantId, $kind, $ref]);
            }
        };

        try {
            $to = Notifier::recipients($pdo, $tenantId);
            [$subject, $html] = self::buildEmail($alerts, $settings, $totalBudget, $totalSpent, $monthSpent, $year, $month);
            $ok = !empty($to) && MailHelper::send($to, $subject, $html);
        } catch (Throwable $e) {
            error_log('[BudgetAlert] mail failed for tenant #' . $tenantId . ': ' . $e->getMessage());
            $ok = false;
        }

        if (!$ok) {
            $release(); // next run (or next expense) retries
            return 0;
        }
        return count($alerts);
    }

    /**
     * @return array{0:string, 1:string} [subject, html]
     */
    private static function buildEmail(array $alerts, array $settings, float $totalBudget, float $totalSpent, float $monthSpent, int $year, int $month): array
    {
        $monthLabel = date('F Y', mktime(0, 0, 0, $month, 1, $year));
        $overCount  = count(array_filter($alerts, fn($a) => $a['level'] === 'over'));

        if (count($alerts) === 1) {
            $a = $alerts[0];
            $subject = $a['level'] === 'over'
                ? "Budget alert: {$a['category']} is over budget ({$monthLabel})"
                : "Budget alert: {$a['category']} reached " . (int) floor($a['pct']) . "% of budget ({$monthLabel})";
        } else {
            $subject = 'Budget alert: ' . count($alerts) . ' categories need attention (' . $monthLabel . ')';
        }
        $title = $overCount > 0 ? 'Budget exceeded' : 'Budget warning';

        $money = fn(float $v) => 'AED ' . number_format($v, 2);
        $cell  = 'padding:8px 10px;border-bottom:1px solid #eef0f3;';

        $rowsHtml = '';
        foreach ($alerts as $a) {
            $isOver = $a['level'] === 'over';
            $color  = $isOver ? '#dc2626' : '#d97706';
            $label  = Categories::EXPENSE[$a['category']] ?? $a['category'];
            $status = $isOver ? 'Over budget' : 'Warning';
            $rowsHtml .= '<tr>'
                . '<td style="' . $cell . '"><strong>' . Html::e($label) . '</strong></td>'
                . '<td style="' . $cell . 'text-align:right;">' . Html::e($money($a['budget'])) . '</td>'
                . '<td style="' . $cell . 'text-align:right;">' . Html::e($money($a['spent'])) . '</td>'
                . '<td style="' . $cell . 'text-align:right;color:' . $color . ';font-weight:700;">' . Html::e(number_format($a['pct'], 0)) . '%</td>'
                . '<td style="' . $cell . 'color:' . $color . ';">' . Html::e($status) . '</td>'
                . '</tr>';
        }

        $th = 'padding:8px 10px;border-bottom:2px solid #e5e7eb;font-size:12px;text-transform:uppercase;color:#6b7280;';
        $body = '<p style="margin:0 0 16px;">The following categories crossed your budget alert thresholds for <strong>'
            . Html::e($monthLabel) . '</strong> (warning at ' . (int) $settings['warn_pct'] . '%, over budget at '
            . (int) $settings['over_pct'] . '%):</p>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">'
            . '<tr><th align="left" style="' . $th . '">Category</th><th align="right" style="' . $th . '">Budget</th>'
            . '<th align="right" style="' . $th . '">Spent</th><th align="right" style="' . $th . '">Used</th>'
            . '<th align="left" style="' . $th . '">Status</th></tr>'
            . $rowsHtml . '</table>';

        $totalPct = $totalBudget > 0 ? ($totalSpent / $totalBudget) * 100 : 0;
        $body .= '<p style="margin:20px 0 0;padding:12px 14px;background:#f8f9fa;border-radius:8px;">'
            . '<strong>Month total:</strong> ' . Html::e($money($totalSpent)) . ' spent of '
            . Html::e($money($totalBudget)) . ' budgeted (' . Html::e(number_format($totalPct, 0)) . '%).';
        if (abs($monthSpent - $totalSpent) >= 0.01) {
            $body .= '<br><span style="color:#6b7280;">All spending this month, including categories without a budget: '
                . Html::e($money($monthSpent)) . '.</span>';
        }
        $body .= '</p>';

        return [$subject, Notifier::wrap($title, $body, Notifier::appUrl('budget.php'), 'View budget')];
    }

    /**
     * @return array{enabled:int, warn_pct:int, over_pct:int, instant:int}
     */
    private static function normalize(array $row): array
    {
        $s = [];
        foreach (self::DEFAULTS as $k => $default) {
            $s[$k] = isset($row[$k]) && $row[$k] !== null ? (int) $row[$k] : $default;
        }
        // Guard against out-of-range values written outside the form.
        if (self::validate($s['warn_pct'], $s['over_pct']) !== null) {
            $s['warn_pct'] = self::DEFAULTS['warn_pct'];
            $s['over_pct'] = self::DEFAULTS['over_pct'];
        }
        return $s;
    }
}
