<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;
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
        Flash::redirect(SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'bank_balances.php'), 'error', 'Unauthorized: Read-only access');
    }
}

$tenant_id = (int) $_SESSION['tenant_id'];

if ($action == 'add_balance' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $bank_id = filter_input(INPUT_POST, 'bank_id', FILTER_VALIDATE_INT);
    $amountRaw = $_POST['amount'] ?? '';
    if (!is_numeric($amountRaw)) {
        Flash::redirect('add_balance.php', 'error', 'Enter a valid amount');
    }
    $amount = round((float) $amountRaw, 2);
    $currency = strtoupper(trim($_POST['currency'] ?? ''));
    $dateRaw = $_POST['balance_date'] ?? '';
    $dt = DateTime::createFromFormat('!Y-m-d', $dateRaw);
    if (!$dt || $dt->format('Y-m-d') !== $dateRaw) {
        Flash::redirect('add_balance.php', 'error', 'Invalid date format');
    }
    $date = $dateRaw;

    if (!$bank_id) {
        Flash::redirect('add_balance.php', 'error', 'Select a bank');
    }

    // The bank must belong to this tenant
    $bstmt = $pdo->prepare("SELECT bank_name, currency FROM banks WHERE id = ? AND tenant_id = ?");
    $bstmt->execute([$bank_id, $tenant_id]);
    $bank = $bstmt->fetch(PDO::FETCH_ASSOC);
    if (!$bank) {
        Flash::redirect('add_balance.php', 'error', 'Bank not found');
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
        Flash::redirect("monthly_balances.php?month=$month&year=$year", 'success', 'Balance Added');
    } catch (PDOException $e) {
        error_log("Add Balance Error: " . $e->getMessage());
        Flash::redirect('add_balance.php', 'error', 'Failed to add balance');
    }

} elseif ($action == 'delete_balance' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM bank_balances WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenant_id]);
        AuditHelper::log($pdo, 'delete_balance_snapshot', "Deleted Balance Snapshot ID: $id");
    }
    Flash::redirect(SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'bank_balances.php'), 'success', 'Deleted');
} elseif ($action == 'bulk_delete' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    if (!empty($ids)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM bank_balances WHERE id IN ($placeholders) AND tenant_id = ?");
        $stmt->execute(array_merge($ids, [$tenant_id]));
        AuditHelper::log($pdo, 'bulk_delete_balances', "Bulk Deleted " . count($ids) . " Balance snapshots. IDs: " . implode(',', $ids));
    }
    Flash::redirect(SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'bank_balances.php'), 'success', 'Bulk deleted');
}

header("Location: bank_balances.php");
exit();
