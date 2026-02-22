<?php
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;

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
        $redirect = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
        header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "error=Unauthorized: Read-only access");
        exit();
    }
}

$tenant_id = $_SESSION['tenant_id'];

if ($action == 'add_card' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $bank_name = htmlspecialchars($_POST['bank_name']);
    $card_name = htmlspecialchars($_POST['card_name']);
    $card_type = htmlspecialchars($_POST['card_type']);
    $network = htmlspecialchars($_POST['network']);
    $tier = htmlspecialchars($_POST['tier']);
    $fee_type = htmlspecialchars($_POST['fee_type'] ?? 'LTF');
    $card_image = filter_var($_POST['card_image'] ?? '', FILTER_SANITIZE_URL);

    $limit_amount = floatval($_POST['limit_amount']);
    $bill_day = filter_input(INPUT_POST, 'bill_day', FILTER_VALIDATE_INT) ?: 1;
    $statement_day = filter_input(INPUT_POST, 'statement_day', FILTER_VALIDATE_INT) ?: 1;
    $first_four = htmlspecialchars($_POST['first_four'] ?? '');
    $last_four = htmlspecialchars($_POST['last_four'] ?? '');

    // Process Category-Specific Cashback percentages
    $cb_categories = ['Grocery', 'Food', 'Transport', 'Shopping', 'Utilities', 'Travel', 'Medical', 'Entertainment', 'Education', 'Other'];
    $cb_data = [];
    foreach ($cb_categories as $cat) {
        $val = floatval($_POST['cb_' . $cat] ?? 0);
        if ($val > 0) {
            $cb_data[$cat] = $val;
        }
    }
    $cashback_struct = json_encode($cb_data);

    $bank_url = filter_var($_POST['bank_url'], FILTER_SANITIZE_URL);
    $features = $_POST['features'] ?? '';
    $bank_id = filter_input(INPUT_POST, 'bank_id', FILTER_VALIDATE_INT) ?: null;

    try {
        $stmt = $pdo->prepare("INSERT INTO cards (user_id, tenant_id, bank_name, card_name, card_type, network, tier, limit_amount, bill_day, statement_day, cashback_struct, bank_url, features, bank_id, first_four, last_four, fee_type, card_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $tenant_id, $bank_name, $card_name, $card_type, $network, $tier, $limit_amount, $bill_day, $statement_day, $cashback_struct, $bank_url, $features, $bank_id, $first_four, $last_four, $fee_type, $card_image]);

        AuditHelper::log($pdo, 'add_card', "Added Card: $card_name ($bank_name)");
        header("Location: my_cards.php?success=Card added successfully");
        exit();

    } catch (PDOException $e) {
        error_log("Add Card Error: " . $e->getMessage());
        header("Location: add_card.php?error=" . urlencode("Failed to add card. Please try again."));
        exit();
    }
} elseif ($action == 'update_card' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $card_id = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
    $user_id = $_SESSION['user_id'];

    if (!$card_id) {
        die("Invalid ID");
    }

    $bank_name = htmlspecialchars($_POST['bank_name']);
    $card_name = htmlspecialchars($_POST['card_name']);
    $card_type = htmlspecialchars($_POST['card_type']);
    $network = htmlspecialchars($_POST['network']);
    $tier = htmlspecialchars($_POST['tier']);
    $fee_type = htmlspecialchars($_POST['fee_type'] ?? 'LTF');
    $card_image = filter_var($_POST['card_image'] ?? '', FILTER_SANITIZE_URL);

    $limit_amount = floatval($_POST['limit_amount']);
    $bill_day = filter_input(INPUT_POST, 'bill_day', FILTER_VALIDATE_INT) ?: 1;
    $statement_day = filter_input(INPUT_POST, 'statement_day', FILTER_VALIDATE_INT) ?: 1;
    $first_four = htmlspecialchars($_POST['first_four'] ?? '');
    $last_four = htmlspecialchars($_POST['last_four'] ?? '');

    // Process Category-Specific Cashback percentages
    $cb_categories = ['Grocery', 'Food', 'Transport', 'Shopping', 'Utilities', 'Travel', 'Medical', 'Entertainment', 'Education', 'Other'];
    $cb_data = [];
    foreach ($cb_categories as $cat) {
        $val = floatval($_POST['cb_' . $cat] ?? 0);
        if ($val > 0) {
            $cb_data[$cat] = $val;
        }
    }
    $cashback_struct = json_encode($cb_data);

    $bank_url = filter_var($_POST['bank_url'], FILTER_SANITIZE_URL);
    $features = $_POST['features'] ?? '';
    $is_default = isset($_POST['is_default']) && $_POST['is_default'] == '1' ? 1 : 0;
    $bank_id = filter_input(INPUT_POST, 'bank_id', FILTER_VALIDATE_INT) ?: null;

    try {
        // If setting as default, clear other cards' default status first
        if ($is_default) {
            $pdo->prepare("UPDATE cards SET is_default = 0 WHERE tenant_id = ?")->execute([$tenant_id]);
        }

        // Ensure user owns the card
        $stmt = $pdo->prepare("UPDATE cards SET bank_name=?, card_name=?, card_type=?, network=?, tier=?, limit_amount=?, bill_day=?, statement_day=?, cashback_struct=?, bank_url=?, features=?, is_default=?, bank_id=?, first_four=?, last_four=?, fee_type=?, card_image=? WHERE id=? AND tenant_id=?");
        $stmt->execute([$bank_name, $card_name, $card_type, $network, $tier, $limit_amount, $bill_day, $statement_day, $cashback_struct, $bank_url, $features, $is_default, $bank_id, $first_four, $last_four, $fee_type, $card_image, $card_id, $tenant_id]);

        AuditHelper::log($pdo, 'update_card', "Updated Card: $card_name (ID: $card_id)");
        if ($stmt->rowCount() > 0) {
            header("Location: edit_card.php?id=$card_id&success=Card updated successfully");
        } else {
            // If no rows changed, it might mean data was identical OR ID not found.
            // We'll log it and show a precise message.
            error_log("Card Update: 0 rows affected for ID $card_id. Values: Fee=$fee_type, Img=" . substr($card_image, 0, 20));
            header("Location: edit_card.php?id=$card_id&success=Card data saved (No changes detected)");
        }
        exit();

    } catch (PDOException $e) {
        error_log("Card Update Error: " . $e->getMessage());
        header("Location: edit_card.php?id=$card_id&error=" . urlencode("Failed to update card details."));
        exit();
    }
} elseif ($action == 'delete_card' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $card_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $user_id = $_SESSION['user_id'];

    if ($card_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM cards WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$card_id, $tenant_id]);
            AuditHelper::log($pdo, 'delete_card', "Deleted Card ID: $card_id");
            header("Location: my_cards.php?success=Card deleted");
            exit();
        } catch (PDOException $e) {
            error_log("Delete Card Error: " . $e->getMessage());
            header("Location: my_cards.php?error=Delete failed");
            exit();
        }
    }
} elseif ($action == 'record_payment' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $card_id = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
    $bank_id = filter_input(INPUT_POST, 'bank_id', FILTER_VALIDATE_INT);
    if (!$bank_id) {
        $bank_id = null; // Ensure NULL if empty/false to pass FK constraint
    }
    $amount = floatval($_POST['amount']);
    $date = htmlspecialchars($_POST['payment_date']);

    if (!$card_id || $amount <= 0) {
        header("Location: pay_card.php?error=Invalid input");
        exit();
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO card_payments (user_id, tenant_id, card_id, bank_id, amount, payment_date) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $tenant_id, $card_id, $bank_id, $amount, $date]);

        AuditHelper::log($pdo, 'record_card_payment', "Recorded Card Payment: $amount (Card ID: $card_id)");
        header("Location: my_cards.php?success=Payment recorded successfully");
        exit();
    } catch (PDOException $e) {
        error_log("Payment Record Error: " . $e->getMessage());
        header("Location: pay_card.php?error=" . urlencode("Failed to record payment."));
        exit();
    }
}

// Fallback
header("Location: my_cards.php");
exit();
