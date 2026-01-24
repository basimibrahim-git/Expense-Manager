<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
}

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
            $pdo->prepare("UPDATE banks SET is_default = 0 WHERE user_id = ?")->execute([$user_id]);
        }

        $stmt = $pdo->prepare("INSERT INTO banks (user_id, bank_name, account_type, account_number, iban, currency, notes, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $bank_name, $account_type, $account_number, $iban, $currency, $notes, $is_default]);

        header("Location: my_banks.php?success=Bank added successfully");
        exit();

    } catch (PDOException $e) {
        header("Location: add_bank.php?error=Failed to add bank");
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
            $pdo->prepare("UPDATE banks SET is_default = 0 WHERE user_id = ?")->execute([$user_id]);
        }

        $stmt = $pdo->prepare("UPDATE banks SET bank_name = ?, account_type = ?, account_number = ?, iban = ?, currency = ?, notes = ?, is_default = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$bank_name, $account_type, $account_number, $iban, $currency, $notes, $is_default, $bank_id, $user_id]);

        header("Location: edit_bank.php?id=$bank_id&success=Bank updated successfully");
        exit();

    } catch (PDOException $e) {
        header("Location: edit_bank.php?id=$bank_id&error=Failed to update");
        exit();
    }
}

// DELETE BANK
elseif ($action == 'delete' && isset($_GET['id'])) {
    $bank_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if ($bank_id) {
        // Unlink cards first
        $pdo->prepare("UPDATE cards SET bank_id = NULL WHERE bank_id = ? AND user_id = ?")->execute([$bank_id, $user_id]);

        // Delete bank
        $stmt = $pdo->prepare("DELETE FROM banks WHERE id = ? AND user_id = ?");
        $stmt->execute([$bank_id, $user_id]);
    }

    header("Location: my_banks.php?success=Bank deleted");
    exit();
}

header("Location: my_banks.php");
exit();
?>