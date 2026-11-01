<?php
/**
 * Card offers refresh (feature job, run daily by cron/reminder_emails.php).
 *
 * Re-reads the bank page (cards.bank_url) of every card whose last sync is older than
 * CardOfferHelper::STALE_DAYS, updating its features text and the cashback rates the page states.
 * At most 20 pages per run, oldest first, so one run stays short; the rest follow on later days.
 *
 * Runs inside a closure with $pdo in scope; logLine() is provided by the runner.
 */
if (!defined('IS_CLI')) {
    http_response_code(403);
    exit;
}

$offerCards = $pdo->query("SELECT id, tenant_id, card_name, bank_url, cashback_struct, features
                             FROM cards
                            WHERE bank_url LIKE 'http%'
                            ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$due = [];
foreach ($offerCards as $card) {
    $synced = \App\Helpers\CardOfferHelper::lastSynced($pdo, trim((string) $card['bank_url']));
    if ($synced === null || strtotime($synced) < strtotime('-' . \App\Helpers\CardOfferHelper::STALE_DAYS . ' days')) {
        $card['synced'] = $synced ?? '';
        $due[] = $card;
    }
}
usort($due, fn($a, $b) => strcmp($a['synced'], $b['synced']));

$offersRefreshed = 0;
$offersFailed = 0;
foreach (array_slice($due, 0, 20) as $card) {
    try {
        $result = \App\Helpers\CardOfferHelper::refreshCard($pdo, (int) $card['tenant_id'], $card);
        if ($result['ok']) {
            $offersRefreshed++;
        } else {
            $offersFailed++;
            logLine("Card offers: card {$card['id']} not refreshed: " . $result['error']);
        }
    } catch (Throwable $e) {
        $offersFailed++;
        logLine("Card offers: card {$card['id']} failed: " . $e->getMessage());
    }
}
logLine("Card offers: refreshed $offersRefreshed, failed $offersFailed, waiting " . max(0, count($due) - 20));
