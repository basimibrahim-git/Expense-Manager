<?php
/**
 * Zakath reminders — emails each family 30 days before, 7 days before and on the
 * day its Zakath falls due (hawl start date + 354 days, repeating).
 *
 * Required by cron/reminder_emails.php once a day with $pdo in scope.
 * Idempotent: Notifier::sendOnce() dedupes on ref_key "<due-date>:<30|7|0>".
 * Windows (8–30 days, 1–7 days, 0–2 days overdue) let a missed cron run catch up.
 */
if (!defined('IS_CLI')) { http_response_code(403); exit; }

use App\Helpers\Html;
use App\Helpers\Notifier;
use App\Helpers\ZakathHelper;

/** @var PDO $pdo */

// Keep the shared metal price cache warm (at most one fetch per 12 hours).
try {
    ZakathHelper::marketPrices($pdo);
} catch (Throwable $e) {
    logLine('ZAKATH price refresh failed: ' . $e->getMessage());
}

$zakathRows = $pdo->query(
    "SELECT zs.tenant_id, zs.hawl_start_date
       FROM zakath_settings zs
       JOIN tenants t ON t.id = zs.tenant_id
      WHERE zs.hawl_start_date IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);

$zakathToday = new DateTimeImmutable('today');
$zakathSent = 0;

foreach ($zakathRows as $zakathRow) {
    $tenantId = (int) $zakathRow['tenant_id'];
    $hawl = ZakathHelper::hawl($zakathRow['hawl_start_date'], $zakathToday);
    if (!$hawl) {
        continue;
    }

    // Which reminder applies today?
    $dueDate = $hawl['due'];
    $daysLeft = (int) $hawl['days_left'];
    $stage = null;
    if ($daysLeft === 0) {
        $stage = '0';
    } elseif ($daysLeft >= 1 && $daysLeft <= 7) {
        $stage = '7';
    } elseif ($daysLeft >= 8 && $daysLeft <= 30) {
        $stage = '30';
    } elseif ($hawl['prev_due'] !== null && $daysLeft >= ZakathHelper::HAWL_DAYS - 2) {
        // The due date was 1–2 days ago (cron missed it) — still send the "due" email for it.
        $dueDate = $hawl['prev_due'];
        $stage = '0';
        $daysLeft = (int) $zakathToday->diff(new DateTimeImmutable($dueDate))->format('%r%a');
    }
    if ($stage === null) {
        continue;
    }

    $calc = ZakathHelper::calculationForDue($pdo, $tenantId, $dueDate);
    if ($calc && $calc['status'] === 'Paid') {
        logLine("ZAKATH tenant #{$tenantId} due {$dueDate}: already marked paid — no reminder");
        continue;
    }

    $settings = ZakathHelper::settings($pdo, $tenantId);
    $nisab = ZakathHelper::nisab($settings, ZakathHelper::prices($pdo, $settings));

    $dueLabel = date('l, j F Y', strtotime($dueDate));
    $hijri = ZakathHelper::hijri(new DateTimeImmutable($dueDate));

    if ($stage === '0') {
        $subject = $daysLeft < 0 ? 'Zakath fell due on ' . date('j F', strtotime($dueDate)) : 'Zakath is due today';
        $lead = $daysLeft < 0 ? 'Your family\'s Zakath fell due on' : 'Your family\'s Zakath is due today,';
    } else {
        $subject = "Zakath due in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's');
        $lead = "Your family's Zakath is due in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . ', on';
    }

    $body = '<p>' . Html::e($lead) . ' <strong>' . Html::e($dueLabel) . '</strong>'
        . ($hijri ? ' (' . Html::e($hijri) . ')' : '') . '.</p>';

    if ($calc) {
        $body .= '<p>Your latest calculation for this year, <strong>' . Html::e($calc['cycle_name']) . '</strong>, came to '
            . '<strong>AED ' . Html::e(number_format((float) $calc['total_zakath'], 2)) . '</strong>.</p>';
    } else {
        $body .= '<p>No calculation has been saved for this year yet. Open the Zakath calculator to work out the amount — '
            . 'it pulls in your bank balances, gold, investments and money owed to you.</p>';
    }

    if ($nisab['value'] !== null) {
        $body .= '<p style="color:#6b7280;font-size:13px;">Current nisab (' . Html::e($nisab['basis']) . ', '
            . Html::e(number_format($nisab['grams'], 2)) . ' g): AED ' . Html::e(number_format($nisab['value'], 2))
            . '. Zakath is 2.5% of net zakatable wealth when it is at or above the nisab.</p>';
    }

    $html = Notifier::wrap('Zakath reminder', $body, Notifier::appUrl('zakath_calculator.php'), 'Open Zakath calculator');

    try {
        if (Notifier::sendOnce($pdo, $tenantId, 'zakath_due', $dueDate . ':' . $stage, $subject, $html)) {
            $zakathSent++;
            logLine("ZAKATH sent tenant #{$tenantId} due {$dueDate} stage {$stage}");
        }
    } catch (Throwable $e) {
        logLine("ZAKATH failed tenant #{$tenantId}: " . $e->getMessage());
    }
}

logLine("ZAKATH reminders: families with hawl=" . count($zakathRows) . " sent={$zakathSent}");
