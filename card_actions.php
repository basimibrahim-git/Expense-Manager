<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;
use App\Helpers\BalanceHelper;
use App\Helpers\Categories;
use App\Helpers\CardOfferHelper;
use App\Helpers\ExchangeRateHelper;
use App\Helpers\Flash;

Bootstrap::init();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    // Permission Check: Read-Only users cannot perform POST actions
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'dashboard.php');
        Flash::redirect($redirect, 'error', 'Unauthorized: Read-only access');
    }
}

$tenant_id = (int) $_SESSION['tenant_id'];

/**
 * A posted bank_id is only accepted when the bank belongs to this tenant.
 * Returns the id, null when none was chosen, or false when it is foreign/invalid.
 */
function cardActionsBankId(PDO $pdo, int $tenant_id)
{
    $bank_id = filter_input(INPUT_POST, 'bank_id', FILTER_VALIDATE_INT);
    if (!$bank_id) {
        return null;
    }
    return BalanceHelper::bank($pdo, $tenant_id, $bank_id) ? $bank_id : false;
}

/** A posted day of month (1–31), or null when empty/invalid (the column allows NULL). */
function cardActionsDay(string $field): ?int
{
    $day = filter_input(INPUT_POST, $field, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31]]);
    return $day ?: null;
}

/** Posted card type, normalised to 'Credit' or 'Debit'; null when it is neither. */
function cardActionsType(): ?string
{
    $t = strtolower(trim((string) ($_POST['card_type'] ?? '')));
    return $t === 'credit' ? 'Credit' : ($t === 'debit' ? 'Debit' : null);
}

/** Strict Y-m-d check (rejects 2026-02-31); returns the date or null. */
function cardActionsDate(string $raw): ?string
{
    $d = DateTime::createFromFormat('!Y-m-d', $raw);
    return ($d && $d->format('Y-m-d') === $raw) ? $raw : null;
}

/** JSON {"Category": percent} from the cb_<Category> inputs (only rates above zero). */
function cardActionsCashbackStruct(): string
{
    $cb_data = [];
    foreach (Categories::expenseKeys() as $cat) {
        $val = floatval($_POST['cb_' . $cat] ?? 0);
        if ($val > 0) {
            $cb_data[$cat] = min($val, 100);
        }
    }
    return json_encode((object) $cb_data);
}

if ($action == 'add_card' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $bank_name = trim($_POST['bank_name'] ?? '');
    $card_name = trim($_POST['card_name'] ?? '');
    $card_type = cardActionsType();
    if ($card_type === null) {
        Flash::redirect('add_card.php', 'error', 'Card type must be Credit or Debit.');
    }
    $network = trim($_POST['network'] ?? '');
    $tier = trim($_POST['tier'] ?? '');
    $fee_type = trim($_POST['fee_type'] ?? 'LTF');
    $card_image = filter_var($_POST['card_image'] ?? '', FILTER_SANITIZE_URL);

    $limit_amount = floatval($_POST['limit_amount'] ?? 0);
    $bill_day = cardActionsDay('bill_day');
    $statement_day = cardActionsDay('statement_day');
    $first_four = trim($_POST['first_four'] ?? '');
    $last_four = trim($_POST['last_four'] ?? '');

    $cashback_struct = cardActionsCashbackStruct();

    $bank_url = filter_var($_POST['bank_url'] ?? '', FILTER_SANITIZE_URL);
    $features = $_POST['features'] ?? '';
    $bank_id = cardActionsBankId($pdo, $tenant_id);
    if ($bank_id === false) {
        Flash::redirect('add_card.php', 'error', 'Invalid linked bank account.');
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO cards (user_id, tenant_id, bank_name, card_name, card_type, network, tier, limit_amount, bill_day, statement_day, cashback_struct, bank_url, features, bank_id, first_four, last_four, fee_type, card_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $tenant_id, $bank_name, $card_name, $card_type, $network, $tier, $limit_amount, $bill_day, $statement_day, $cashback_struct, $bank_url, $features, $bank_id, $first_four, $last_four, $fee_type, $card_image]);

        AuditHelper::log($pdo, 'add_card', "Added Card: $card_name ($bank_name)");
        Flash::redirect('my_cards.php', 'success', 'Card added successfully');
    } catch (PDOException $e) {
        error_log("Add Card Error: " . $e->getMessage());
        Flash::redirect('add_card.php', 'error', 'Failed to add card. Please try again.');
    }
} elseif ($action == 'update_card' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $card_id = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
    $user_id = $_SESSION['user_id'];

    if (!$card_id) {
        Flash::redirect('my_cards.php', 'error', 'Invalid request');
    }

    $bank_name = trim($_POST['bank_name'] ?? '');
    $card_name = trim($_POST['card_name'] ?? '');
    $card_type = cardActionsType();
    if ($card_type === null) {
        Flash::redirect("edit_card.php?id=$card_id", 'error', 'Card type must be Credit or Debit.');
    }
    $network = trim($_POST['network'] ?? '');
    $tier = trim($_POST['tier'] ?? '');
    $fee_type = trim($_POST['fee_type'] ?? 'LTF');
    $card_image = filter_var($_POST['card_image'] ?? '', FILTER_SANITIZE_URL);

    $limit_amount = floatval($_POST['limit_amount'] ?? 0);
    $bill_day = cardActionsDay('bill_day');
    $statement_day = cardActionsDay('statement_day');
    $first_four = trim($_POST['first_four'] ?? '');
    $last_four = trim($_POST['last_four'] ?? '');

    $cashback_struct = cardActionsCashbackStruct();

    $bank_url = filter_var($_POST['bank_url'] ?? '', FILTER_SANITIZE_URL);
    $features = $_POST['features'] ?? '';
    $is_default = isset($_POST['is_default']) && $_POST['is_default'] == '1' ? 1 : 0;
    $bank_id = cardActionsBankId($pdo, $tenant_id);
    if ($bank_id === false) {
        Flash::redirect("edit_card.php?id=$card_id", 'error', 'Invalid linked bank account.');
    }

    $own = $pdo->prepare("SELECT id FROM cards WHERE id = ? AND tenant_id = ?");
    $own->execute([$card_id, $tenant_id]);
    if (!$own->fetchColumn()) {
        Flash::redirect('my_cards.php', 'error', 'Card not found');
    }

    try {
        $pdo->beginTransaction();

        // If setting as default, clear other cards' default status first
        if ($is_default) {
            $pdo->prepare("UPDATE cards SET is_default = 0 WHERE tenant_id = ? AND id <> ?")->execute([$tenant_id, $card_id]);
        }

        $stmt = $pdo->prepare("UPDATE cards SET bank_name=?, card_name=?, card_type=?, network=?, tier=?, limit_amount=?, bill_day=?, statement_day=?, cashback_struct=?, bank_url=?, features=?, is_default=?, bank_id=?, first_four=?, last_four=?, fee_type=?, card_image=? WHERE id=? AND tenant_id=?");
        $stmt->execute([$bank_name, $card_name, $card_type, $network, $tier, $limit_amount, $bill_day, $statement_day, $cashback_struct, $bank_url, $features, $is_default, $bank_id, $first_four, $last_four, $fee_type, $card_image, $card_id, $tenant_id]);
        $pdo->commit();

        AuditHelper::log($pdo, 'update_card', "Updated Card: $card_name (ID: $card_id)");
        if ($stmt->rowCount() > 0) {
            Flash::redirect("edit_card.php?id=$card_id", 'success', 'Card updated successfully');
        }
        Flash::redirect("edit_card.php?id=$card_id", 'info', 'Card data saved (no changes detected)');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Card Update Error: " . $e->getMessage());
        Flash::redirect("edit_card.php?id=$card_id", 'error', 'Failed to update card details.');
    }
} elseif ($action == 'refresh_offers' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    // Re-read the card's bank page now: features text + the cashback rates the page states
    $card_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $stmt = $pdo->prepare("SELECT id, card_name, bank_url, cashback_struct, features FROM cards WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$card_id ?: 0, $tenant_id]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) {
        Flash::redirect('my_cards.php', 'error', 'Card not found');
    }
    $result = CardOfferHelper::refreshCard($pdo, $tenant_id, $card);
    if (!$result['ok']) {
        Flash::redirect('my_cards.php', 'error', 'Could not refresh offers: ' . $result['error']);
    }
    AuditHelper::log($pdo, 'refresh_card_offers', "Refreshed offers: {$card['card_name']} (ID: {$card['id']})");
    $found = count($result['rates']);
    Flash::redirect('my_cards.php', 'success', $found > 0
        ? "Offers refreshed: cashback rates found for $found " . ($found === 1 ? 'category' : 'categories') . '.'
        : 'Offers refreshed. The bank page lists no cashback rates the app can read, so the rates were kept.');
} elseif ($action == 'delete_card' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $card_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    if ($card_id) {
        try {
            $pdo->beginTransaction();
            // Detach the card's expenses (kept as Cash so the edit form stays valid; any bank deduction stays linked)
            // and drop its payments, so nothing is left pointing at a card that no longer exists
            $pdo->prepare("UPDATE expenses SET card_id = NULL, payment_method = 'Cash' WHERE card_id = ? AND tenant_id = ?")->execute([$card_id, $tenant_id]);
            $pdo->prepare("DELETE FROM card_payments WHERE card_id = ? AND tenant_id = ?")->execute([$card_id, $tenant_id]);
            $stmt = $pdo->prepare("DELETE FROM cards WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$card_id, $tenant_id]);
            $pdo->commit();
            AuditHelper::log($pdo, 'delete_card', "Deleted Card ID: $card_id");
            Flash::redirect('my_cards.php', 'success', 'Card deleted');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Delete Card Error: " . $e->getMessage());
            Flash::redirect('my_cards.php', 'error', 'Delete failed');
        }
    }
} elseif ($action == 'record_payment' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $card_id = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
    $back = 'pay_card.php' . ($card_id ? '?card_id=' . (int) $card_id : '');
    $bank_id = cardActionsBankId($pdo, $tenant_id); // NULL when no source bank was chosen
    if ($bank_id === false) {
        Flash::redirect($back, 'error', 'Invalid source bank account.');
    }
    $amount = round(floatval($_POST['amount'] ?? 0), 2);
    $date = cardActionsDate((string) ($_POST['payment_date'] ?? ''));
    if ($date === null) {
        Flash::redirect($back, 'error', 'Invalid date');
    }

    if (!$card_id || $amount <= 0) {
        Flash::redirect($back, 'error', 'Invalid input');
    }

    // Payments only make sense for this tenant's credit cards
    $own = $pdo->prepare("SELECT card_type FROM cards WHERE id = ? AND tenant_id = ?");
    $own->execute([$card_id, $tenant_id]);
    $ownType = $own->fetchColumn();
    if ($ownType === false) {
        Flash::redirect('pay_card.php', 'error', 'Card not found');
    }
    if (strtolower((string) $ownType) !== 'credit') {
        Flash::redirect('my_cards.php', 'error', 'Payments can only be recorded for credit cards');
    }

    // The payment is entered in AED; convert to the source account's currency.
    // (Rate lookup may call an external API, so do it before the transaction starts.)
    $bank_delta = 0.0;
    if ($bank_id !== null) {
        $source = BalanceHelper::bank($pdo, $tenant_id, $bank_id);
        $bank_cur = strtoupper($source['currency'] ?: 'AED');
        $bank_delta = -round($bank_cur === 'AED' ? $amount : $amount * ExchangeRateHelper::getRate('AED', $bank_cur, $pdo), 2);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO card_payments (user_id, tenant_id, card_id, bank_id, amount, payment_date) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $tenant_id, $card_id, $bank_id, $amount, $date]);

        // Deduct the payment from the source account, as promised on pay_card.php
        if ($bank_id !== null && !BalanceHelper::adjust($pdo, $tenant_id, (int) $user_id, $bank_id, $bank_delta, $date)) {
            throw new RuntimeException("Source bank $bank_id not owned by tenant $tenant_id");
        }

        $pdo->commit();

        AuditHelper::log($pdo, 'record_card_payment', "Recorded Card Payment: $amount (Card ID: $card_id)");
        Flash::redirect('my_cards.php', 'success', 'Payment recorded successfully');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Payment Record Error: " . $e->getMessage());
        Flash::redirect($back, 'error', 'Failed to record payment.');
    }
}

// Fallback
header("Location: my_cards.php");
exit();
