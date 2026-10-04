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

if ($action == 'add_balance' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $bank_id = filter_input(INPUT_POST, 'bank_id', FILTER_VALIDATE_INT);
    $amountRaw = $_POST['amount'] ?? '';
    if (!is_numeric($amountRaw)) {
        header("Location: add_balance.php?error=" . urlencode("Enter a valid amount"));
        exit();
    }
    $amount = round((float) $amountRaw, 2);
    $currency = strtoupper(trim($_POST['currency'] ?? ''));
    $dateRaw = $_POST['balance_date'] ?? '';
    $dt = DateTime::createFromFormat('!Y-m-d', $dateRaw);
    if (!$dt || $dt->format('Y-m-d') !== $dateRaw) {
        header("Location: add_balance.php?error=" . urlencode("Invalid date format"));
        exit();
    }
    $date = $dateRaw;

    if (!$bank_id) {
        header("Location: add_balance.php?error=" . urlencode("Select a bank"));
        exit();
    }

    // The bank must belong to this tenant
    $bstmt = $pdo->prepare("SELECT bank_name, currency FROM banks WHERE id = ? AND tenant_id = ?");
    $bstmt->execute([$bank_id, $tenant_id]);
    $bank = $bstmt->fetch(PDO::FETCH_ASSOC);
    if (!$bank) {
        header("Location: add_balance.php?error=" . urlencode("Bank not found"));
        exit();
    }
    $bank_name = $bank['bank_name'];
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $currency = $bank['currency'] ?: 'AED';
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO bank_balances (user_id, tenant_id, bank_id, bank_name, amount, balance_date, currency) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $tenant_id, $bank_id, $bank_name, $amount, $date, $currency]);

        AuditHelper::log($pdo, 'manual_balance_update', "Updated Balance for $bank_name: $amount $currency");
        $month = date('n', strtotime($date));
        $year = date('Y', strtotime($date));
        header("Location: monthly_balances.php?month=$month&year=$year&success=" . urlencode("Balance Added"));
        exit();
    } catch (PDOException $e) {
        error_log("Add Balance Error: " . $e->getMessage());
        header("Location: add_balance.php?error=" . urlencode("Failed to add balance"));
        exit();
    }

} elseif ($action == 'delete_balance' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM bank_balances WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenant_id]);
        AuditHelper::log($pdo, 'delete_balance_snapshot', "Deleted Balance Snapshot ID: $id");
    }
    $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'bank_balances.php');
    header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "success=Deleted");
    exit();
} elseif ($action == 'bulk_delete' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    if (!empty($ids)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM bank_balances WHERE id IN ($placeholders) AND tenant_id = ?");
        $stmt->execute(array_merge($ids, [$tenant_id]));
        AuditHelper::log($pdo, 'bulk_delete_balances', "Bulk Deleted " . count($ids) . " Balance snapshots. IDs: " . implode(',', $ids));
    }
    $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'bank_balances.php');
    header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "success=Bulk deleted");
    exit();
}

header("Location: bank_balances.php");
exit();
