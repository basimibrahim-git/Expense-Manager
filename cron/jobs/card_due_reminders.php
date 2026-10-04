<?php
/**
 * Credit-card payment due reminders (feature job, run daily by cron/reminder_emails.php).
 *
 * Emails the family 5 days before, 1 day before and on the due date of each credit card's last
 * statement while part of it is still unpaid (CardCycleHelper::due_remaining > 0).
 * Each reminder is sent at most once: Notifier::sendOnce with kind 'card_due' and
 * ref_key "<card_id>:<due_date>:<offset>".
 *
 * A missed run is caught up: 2–4 days before the due date counts as the 5-day reminder
 * (still sent only once), so a card added or a statement entered late still gets a heads-up.
 *
 * Runs inside a closure with $pdo in scope; logLine() is provided by the runner.
 */
if (!defined('IS_CLI')) {
    http_response_code(403);
    exit;
}

$cardDueOffset = function (int $daysToDue): ?int {
    if ($daysToDue === 0 || $daysToDue === 1) {
        return $daysToDue;
    }
    if ($daysToDue >= 2 && $daysToDue <= 5) {
        return 5;
    }
    return null;
};

$cardDueSent = 0;
$tenantIds = $pdo->query("SELECT id FROM tenants ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tenantIds as $tenantId) {
    $tenantId = (int) $tenantId;
    try {
        foreach (\App\Helpers\CardCycleHelper::upcoming($pdo, $tenantId, 5) as $row) {
            $offset = $cardDueOffset((int) $row['days_to_due']);
            if ($offset === null) {
                continue; // overdue: no email (the cards page shows it as overdue)
            }

            $cardLabel = trim($row['bank_name'] . ' ' . $row['card_name'])
                . ($row['last_four'] !== '' ? ' (•••• ' . $row['last_four'] . ')' : '');
            $dueDate   = date('d M Y', strtotime($row['last_statement_due_date']));
            $amount    = 'AED ' . number_format($row['due_remaining'], 2);
            $when      = $row['days_to_due'] === 0 ? 'today' : ($row['days_to_due'] === 1 ? 'tomorrow' : 'in ' . $row['days_to_due'] . ' days');

            $subject = $row['days_to_due'] === 0
                ? "Card payment due today: {$cardLabel}"
                : "Card payment due {$when}: {$cardLabel}";

            $e = [\App\Helpers\Html::class, 'e'];
            $body = '<p>The payment for <strong>' . $e($cardLabel) . '</strong> is due <strong>' . $e($when) . '</strong> (' . $e($dueDate) . ').</p>'
                . '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:12px 0;">'
                . '<tr><td style="color:#6b7280;">Statement (' . $e(date('d M Y', strtotime($row['last_statement_date']))) . ')</td><td style="text-align:right;">AED ' . $e(number_format($row['last_statement_amount'], 2)) . '</td></tr>'
                . '<tr><td style="color:#6b7280;">Paid since statement</td><td style="text-align:right;">AED ' . $e(number_format($row['paid_since_statement'], 2)) . '</td></tr>'
                . '<tr><td><strong>Still due</strong></td><td style="text-align:right;"><strong>' . $e($amount) . '</strong></td></tr>'
                . '</table>'
                . '<p style="color:#6b7280;font-size:13px;">Amounts are based on the card expenses and payments recorded in Expense Manager. '
                . 'Already paid? Record the payment so the reminders stop.</p>';

            $sent = \App\Helpers\Notifier::sendOnce(
                $pdo,
                $tenantId,
                'card_due',
                $row['card_id'] . ':' . $row['last_statement_due_date'] . ':' . $offset,
                $subject,
                \App\Helpers\Notifier::wrap(
                    'Card payment reminder',
                    $body,
                    \App\Helpers\Notifier::appUrl('pay_card.php?card_id=' . (int) $row['card_id']),
                    'Record payment'
                )
            );
            if ($sent) {
                $cardDueSent++;
                logLine("CARD_DUE SENT tenant #{$tenantId} card #{$row['card_id']} due={$row['last_statement_due_date']} offset={$offset}");
            }
        }
    } catch (Throwable $ex) {
        logLine("CARD_DUE ERROR tenant #{$tenantId}: " . $ex->getMessage());
    }
}

logLine("CARD_DUE Done. Sent={$cardDueSent}");
