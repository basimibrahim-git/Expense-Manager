<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // Permission Check: Read-Only users cannot perform POST actions
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        $redirect = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
        header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "error=Unauthorized: Read-only access");
        exit();
    }
}

$tenant_id = $_SESSION['tenant_id'];

// ADD BANK
if ($action == 'add_bank' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $bank_name = htmlspecialchars($_POST['bank_name']);
    $account_type = htmlspecialchars($_POST['account_type'] ?? 'Current');
    $account_number = htmlspecialchars($_POST['account_number'] ?? '');
    $iban = htmlspecialchars($_POST['iban'] ?? '');
    $currency = htmlspecialchars($_POST['currency'] ?? 'AED');
    $notes = htmlspecialchars($_POST['notes'] ?? '');
    $is_default = isset($_POST['is_default']) && $_POST['is_default'] == '1' ? 1 : 0;

    if (empty($bank_name)) {
        header("Location: add_bank.php?error=Bank name is required");
        exit();
    }

    try {
        // If setting as default, clear other banks' default status
        if ($is_default) {
            $pdo->prepare("UPDATE banks SET is_default = 0 WHERE tenant_id = ?")->execute([$tenant_id]);
        }

        $stmt = $pdo->prepare("INSERT INTO banks (user_id, tenant_id, bank_name, account_type, account_number, iban, currency, notes, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $tenant_id, $bank_name, $account_type, $account_number, $iban, $currency, $notes, $is_default]);

        log_audit('add_bank', "Added Bank: $bank_name ($currency)");
        header("Location: my_banks.php?success=Bank added successfully");
        exit();

    } catch (PDOException $e) {
        error_log("Add Bank Error: " . $e->getMessage());
        header("Location: add_bank.php?error=" . urlencode("Failed to add bank."));
        exit();
    }
}

// UPDATE BANK
elseif ($action == 'update_bank' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $bank_id = filter_input(INPUT_POST, 'bank_id', FILTER_VALIDATE_INT);

    if (!$bank_id) {
        header("Location: my_banks.php?error=Invalid bank");
        exit();
    }

    $bank_name = htmlspecialchars($_POST['bank_name']);
    $account_type = htmlspecialchars($_POST['account_type'] ?? 'Current');
    $account_number = htmlspecialchars($_POST['account_number'] ?? '');
    $iban = htmlspecialchars($_POST['iban'] ?? '');
    $currency = htmlspecialchars($_POST['currency'] ?? 'AED');
    $notes = htmlspecialchars($_POST['notes'] ?? '');
    $is_default = isset($_POST['is_default']) && $_POST['is_default'] == '1' ? 1 : 0;

    try {
        // If setting as default, clear other banks' default status
        if ($is_default) {
            $pdo->prepare("UPDATE banks SET is_default = 0 WHERE tenant_id = ?")->execute([$tenant_id]);
        }

        $stmt = $pdo->prepare("UPDATE banks SET bank_name = ?, account_type = ?, account_number = ?, iban = ?, currency = ?, notes = ?, is_default = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$bank_name, $account_type, $account_number, $iban, $currency, $notes, $is_default, $bank_id, $tenant_id]);

        log_audit('update_bank', "Updated Bank: $bank_name (ID: $bank_id)");
        header("Location: edit_bank.php?id=$bank_id&success=Bank updated successfully");
        exit();

    } catch (PDOException $e) {
        error_log("Update Bank Error: " . $e->getMessage());
        header("Location: edit_bank.php?id=$bank_id&error=" . urlencode("Failed to update bank."));
        exit();
    }
}

// DELETE BANK
elseif ($action == 'delete' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $bank_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    if ($bank_id) {
        // Unlink cards first
        $pdo->prepare("UPDATE cards SET bank_id = NULL WHERE bank_id = ? AND tenant_id = ?")->execute([$bank_id, $tenant_id]);

        // Delete bank
        $stmt = $pdo->prepare("DELETE FROM banks WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$bank_id, $tenant_id]);
        log_audit('delete_bank', "Deleted Bank ID: $bank_id");
    }

    header("Location: my_banks.php?success=Bank deleted");
    exit();
}

header("Location: my_banks.php");
exit();
?>