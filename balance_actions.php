<?php
require_once 'config.php'; // NOSONAR
use App\Helpers\AuditHelper;

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

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

if ($action == 'add_balance' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $bank_id = filter_input(INPUT_POST, 'bank_id', FILTER_VALIDATE_INT);
    $amount = floatval($_POST['amount']);
    $currency = $_POST['currency'] ?? 'AED';
    $date = htmlspecialchars($_POST['balance_date']);

    if (!$bank_id) {
        header("Location: add_balance.php?error=Select a bank");
        exit();
    }

    // Fetch bank name
    $bstmt = $pdo->prepare("SELECT bank_name FROM banks WHERE id = ? AND tenant_id = ?");
    $bstmt->execute([$bank_id, $tenant_id]);
    $bank_name = $bstmt->fetchColumn();
    try {
        $stmt = $pdo->prepare("INSERT INTO bank_balances (user_id, tenant_id, bank_id, bank_name, amount, balance_date, currency) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $tenant_id, $bank_id, $bank_name, $amount, $date, $currency]);

        AuditHelper::log($pdo, 'manual_balance_update', "Updated Balance for $bank_name: $amount $currency");
        $month = date('n', strtotime($date));
        $year = date('Y', strtotime($date));
        header("Location: monthly_balances.php?month=$month&year=$year&success=Balance Added");
        exit();
    } catch (PDOException $e) {
        header("Location: add_balance.php?error=Failed to add balance");
        exit();
    }

} elseif ($action == 'delete_balance' && (isset($_POST['id']) || isset($_GET['id']))) {
    $id = filter_input(isset($_POST['id']) ? INPUT_POST : INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM bank_balances WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenant_id]);
        AuditHelper::log($pdo, 'delete_balance_snapshot', "Deleted Balance Snapshot ID: $id");
    }
    header("Location: bank_balances.php?success=Deleted");
    exit();
} elseif ($action == 'bulk_delete' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    if (!empty($ids)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM bank_balances WHERE id IN ($placeholders) AND tenant_id = ?");
        $stmt->execute(array_merge($ids, [$tenant_id]));
        AuditHelper::log($pdo, 'bulk_delete_balances', "Bulk Deleted " . count($ids) . " Balance snapshots. IDs: " . implode(',', $ids));
    }
    $redirect = $_SERVER['HTTP_REFERER'] ?? 'bank_balances.php';
    header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "success=Bulk deleted");
    exit();
}

header("Location: bank_balances.php");
exit();
