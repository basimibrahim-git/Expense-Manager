<?php
/**
 * Reconciliation "Auto-Fix" (posted from monthly_balances.php).
 *
 * Recorded bank balances (BalanceHelper) are the truth. When they differ from
 * opening + income - expenses for a month, this records a balancing Income or
 * Expense entry dated at month end. The entry does NOT move any bank balance
 * (balance_bank_id stays NULL), it only explains the gap.
 */
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;

Bootstrap::init();

$back = BASE_URL . 'monthly_balances.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: $back");
    exit();
}

SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
    header("Location: $back?error=" . urlencode("Unauthorized: Read-only access"));
    exit();
}

$tenant_id = (int) $_SESSION['tenant_id'];
$user_id   = (int) $_SESSION['user_id'];
$action    = $_POST['action'] ?? '';

$dateRaw = $_POST['date'] ?? '';
$dt = DateTime::createFromFormat('!Y-m-d', $dateRaw);
if (!$dt || $dt->format('Y-m-d') !== $dateRaw) {
    header("Location: $back?error=" . urlencode("Invalid date"));
    exit();
}
$date = $dateRaw;
$back .= '?month=' . (int) $dt->format('n') . '&year=' . (int) $dt->format('Y');

$diffRaw = $_POST['difference'] ?? '';
if (!is_numeric($diffRaw) || !is_finite((float) $diffRaw) || abs((float) $diffRaw) >= 1e12) {
    header("Location: $back&error=" . urlencode("Invalid difference"));
    exit();
}
$diff = round((float) $diffRaw, 2);

if ($action !== 'auto_fix') {
    header("Location: $back&error=" . urlencode("Unknown action"));
    exit();
}

if (abs($diff) < 0.01) {
    header("Location: $back");
    exit();
}

$desc = 'Reconciliation Adjustment (' . $dt->format('F Y') . ')';

try {
    if ($diff > 0) {
        // Surplus -> Income
        $stmt = $pdo->prepare("INSERT INTO income (user_id, tenant_id, amount, description, category, income_date, currency) VALUES (?, ?, ?, ?, 'Adjustment', ?, 'AED')");
        $stmt->execute([$user_id, $tenant_id, $diff, $desc, $date]);
    } else {
        // Missing -> Expense
        $stmt = $pdo->prepare("INSERT INTO expenses (user_id, tenant_id, spent_by_user_id, amount, description, category, payment_method, expense_date, currency) VALUES (?, ?, ?, ?, ?, 'Adjustment', 'Cash', ?, 'AED')");
        $stmt->execute([$user_id, $tenant_id, $user_id, abs($diff), $desc, $date]);
    }
    AuditHelper::log($pdo, 'reconcile_auto_fix', "$desc: $diff AED");
} catch (PDOException $e) {
    error_log("Reconcile Fix Error: " . $e->getMessage());
    header("Location: $back&error=" . urlencode("Failed to record the adjustment."));
    exit();
}

header("Location: $back&success=" . urlencode("Adjustment recorded"));
exit();
