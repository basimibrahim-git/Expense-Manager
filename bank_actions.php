<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;

Bootstrap::init();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    // Permission Check: Read-Only users cannot perform POST actions
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'dashboard.php');

        header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "error=Unauthorized: Read-only access");
        exit();
    }
}

$tenant_id = (int) $_SESSION['tenant_id'];
$allowed_account_types = ['Savings', 'Current', 'Salary'];

// ADD BANK
if ($action == 'add_bank' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_type = trim($_POST['account_type'] ?? 'Current');
    $account_number = trim($_POST['account_number'] ?? '');
    $iban = trim($_POST['iban'] ?? '');
    $currency = trim($_POST['currency'] ?? 'AED');
    $notes = trim($_POST['notes'] ?? '');
    $is_default = isset($_POST['is_default']) && $_POST['is_default'] == '1' ? 1 : 0;
    if (!in_array($account_type, $allowed_account_types, true)) {
        $account_type = 'Current';
    }

    if (empty($bank_name)) {
        header("Location: add_bank.php?error=" . urlencode("Bank name is required"));
        exit();
    }

    try {
        $pdo->beginTransaction();

        // If setting as default, clear other banks' default status
        if ($is_default) {
            $pdo->prepare("UPDATE banks SET is_default = 0 WHERE tenant_id = ?")->execute([$tenant_id]);
        }

        $stmt = $pdo->prepare("INSERT INTO banks (user_id, tenant_id, bank_name, account_type, account_number, iban, currency, notes, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $tenant_id, $bank_name, $account_type, $account_number, $iban, $currency, $notes, $is_default]);

        $pdo->commit();
        AuditHelper::log($pdo, 'add_bank', "Added Bank: $bank_name ($currency)");
        header("Location: my_banks.php?success=Bank added successfully");
        exit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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

    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_type = trim($_POST['account_type'] ?? 'Current');
    $account_number = trim($_POST['account_number'] ?? '');
    $iban = trim($_POST['iban'] ?? '');
    $currency = trim($_POST['currency'] ?? 'AED');
    $notes = trim($_POST['notes'] ?? '');
    $is_default = isset($_POST['is_default']) && $_POST['is_default'] == '1' ? 1 : 0;
    if (!in_array($account_type, $allowed_account_types, true)) {
        $account_type = 'Current';
    }

    if ($bank_name === '') {
        header("Location: edit_bank.php?id=$bank_id&error=" . urlencode("Bank name is required"));
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Any family member with edit permission may manage the tenant's banks
        $own = $pdo->prepare("SELECT bank_name FROM banks WHERE id = ? AND tenant_id = ? FOR UPDATE");
        $own->execute([$bank_id, $tenant_id]);
        $old_name = $own->fetchColumn();
        if ($old_name === false) {
            $pdo->rollBack();
            header("Location: my_banks.php?error=" . urlencode("Bank not found"));
            exit();
        }

        // If setting as default, clear other banks' default status
        if ($is_default) {
            $pdo->prepare("UPDATE banks SET is_default = 0 WHERE tenant_id = ? AND id <> ?")->execute([$tenant_id, $bank_id]);
        }

        $stmt = $pdo->prepare("UPDATE banks SET bank_name = ?, account_type = ?, account_number = ?, iban = ?, currency = ?, notes = ?, is_default = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$bank_name, $account_type, $account_number, $iban, $currency, $notes, $is_default, $bank_id, $tenant_id]);

        // Legacy snapshots without bank_id are matched by name: link them before a rename orphans them
        if ($old_name !== $bank_name) {
            $pdo->prepare("UPDATE bank_balances SET bank_id = ? WHERE tenant_id = ? AND bank_id IS NULL AND bank_name = ?")
                ->execute([$bank_id, $tenant_id, $old_name]);
        }

        $pdo->commit();
        AuditHelper::log($pdo, 'update_bank', "Updated Bank: $bank_name (ID: $bank_id)");
        header("Location: edit_bank.php?id=$bank_id&success=" . urlencode("Bank updated successfully"));
        exit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Update Bank Error: " . $e->getMessage());
        header("Location: edit_bank.php?id=$bank_id&error=" . urlencode("Failed to update bank."));
        exit();
    }
}

// DELETE BANK
elseif ($action == 'delete' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $bank_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    $deleted = false;
    if ($bank_id) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id FROM banks WHERE id = ? AND tenant_id = ? FOR UPDATE");
            $stmt->execute([$bank_id, $tenant_id]);
            if ($stmt->fetchColumn()) {
                // Unlink cards first
                $pdo->prepare("UPDATE cards SET bank_id = NULL WHERE bank_id = ? AND tenant_id = ?")->execute([$bank_id, $tenant_id]);

                $stmt = $pdo->prepare("DELETE FROM banks WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$bank_id, $tenant_id]);
                $deleted = $stmt->rowCount() > 0;
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Delete Bank Error: " . $e->getMessage());
            header("Location: my_banks.php?error=" . urlencode("Failed to delete bank."));
            exit();
        }
    }

    if ($deleted) {
        AuditHelper::log($pdo, 'delete_bank', "Deleted Bank ID: $bank_id");
        header("Location: my_banks.php?success=" . urlencode("Bank deleted"));
    } else {
        header("Location: my_banks.php?error=" . urlencode("Bank not found"));
    }
    exit();
}

header("Location: my_banks.php");
exit();
