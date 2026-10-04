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
use App\Helpers\Flash;

Bootstrap::init();

$back = BASE_URL . 'monthly_balances.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: $back");
    exit();
}

SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
    Flash::redirect($back, 'error', 'Unauthorized: Read-only access');
}

$tenant_id = (int) $_SESSION['tenant_id'];
$user_id   = (int) $_SESSION['user_id'];
$action    = $_POST['action'] ?? '';

$dateRaw = $_POST['date'] ?? '';
$dt = DateTime::createFromFormat('!Y-m-d', $dateRaw);
if (!$dt || $dt->format('Y-m-d') !== $dateRaw) {
    Flash::redirect($back, 'error', 'Invalid date');
}
$date = $dateRaw;
$back .= '?month=' . (int) $dt->format('n') . '&year=' . (int) $dt->format('Y');

$diffRaw = $_POST['difference'] ?? '';
if (!is_numeric($diffRaw) || !is_finite((float) $diffRaw) || abs((float) $diffRaw) >= 1e12) {
    Flash::redirect($back, 'error', 'Invalid difference');
}
$diff = round((float) $diffRaw, 2);

if ($action !== 'auto_fix') {
    Flash::redirect($back, 'error', 'Unknown action');
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
    Flash::redirect($back, 'error', 'Failed to record the adjustment.');
}

Flash::redirect($back, 'success', 'Adjustment recorded');
