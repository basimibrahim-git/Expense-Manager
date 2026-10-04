<?php
// budget_actions.php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;
use App\Helpers\Flash;
use App\Helpers\Categories;
use App\Helpers\BudgetAlertHelper;

Bootstrap::init();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    // Permission Check: Read-Only users cannot perform POST actions
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        Flash::redirect('budget.php', 'error', 'Unauthorized: Read-only access');
    }
}

$tenant_id = (int) $_SESSION['tenant_id'];

if ($action == 'save_budgets' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $month = filter_input(INPUT_POST, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
    $year = filter_input(INPUT_POST, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
    if (!$month || !$year) {
        Flash::redirect('manage_budgets.php', 'error', 'Invalid month or year');
    }
    $budgets = $_POST['budgets'] ?? [];
    if (!is_array($budgets)) {
        $budgets = [];
    }

    try {
        $pdo->beginTransaction();

        // The unique key is per user (user_id, category, month, year), so replace the tenant's row
        // explicitly instead of ON DUPLICATE KEY UPDATE, which left one row per family member.
        $delStmt = $pdo->prepare("DELETE FROM budgets WHERE tenant_id = ? AND category = ? AND month = ? AND year = ?");
        $insStmt = $pdo->prepare("INSERT INTO budgets (user_id, tenant_id, category, amount, month, year) VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($budgets as $category => $amount) {
            $category = (string) $category;
            if (!Categories::isExpense($category)) {
                continue; // only known categories (they are displayed on the budget pages)
            }
            $amount = is_scalar($amount) ? floatval($amount) : 0;

            // Amount 0/empty removes the target
            $delStmt->execute([$tenant_id, $category, $month, $year]);
            if ($amount > 0) {
                $insStmt->execute([$user_id, $tenant_id, $category, $amount, $month, $year]);
            }
        }

        $pdo->commit();
        AuditHelper::log($pdo, 'save_budgets', "Updated Budgets for $month/$year. Categories: " . count($budgets));

        // A lowered target may already be exceeded this month: alert now (never throws).
        if ($month === (int) date('n') && $year === (int) date('Y')) {
            BudgetAlertHelper::checkTenant($pdo, $tenant_id);
        }

        $month_name = date('F', mktime(0, 0, 0, $month, 1, $year));
        Flash::redirect("manage_budgets.php?month=$month&year=$year", 'success', "Budgets saved for $month_name $year");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Save Budgets Error: " . $e->getMessage());
        Flash::redirect("manage_budgets.php?month=$month&year=$year", 'error', 'Failed to save budgets');
    }
}

if ($action == 'save_alert_settings' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!in_array($_SESSION['role'] ?? '', ['family_admin', 'root_admin'], true)) {
        Flash::redirect('manage_budgets.php#alerts', 'error', 'Only the family admin can change budget alerts.');
    }

    $enabled  = !empty($_POST['enabled']);
    $instant  = !empty($_POST['instant']);
    $warn_pct = filter_input(INPUT_POST, 'warn_pct', FILTER_VALIDATE_INT);
    $over_pct = filter_input(INPUT_POST, 'over_pct', FILTER_VALIDATE_INT);

    if ($warn_pct === false || $warn_pct === null || $over_pct === false || $over_pct === null) {
        Flash::redirect('manage_budgets.php#alerts', 'error', 'Please enter whole-number percentages for both thresholds.');
    }
    $error = BudgetAlertHelper::validate($warn_pct, $over_pct);
    if ($error !== null) {
        Flash::redirect('manage_budgets.php#alerts', 'error', $error);
    }

    try {
        BudgetAlertHelper::saveSettings($pdo, $tenant_id, $enabled, $warn_pct, $over_pct, $instant);
        AuditHelper::log($pdo, 'save_budget_alerts', 'Budget alerts ' . ($enabled ? 'on' : 'off')
            . ", warn $warn_pct%, over $over_pct%, instant " . ($instant ? 'on' : 'off'));
    } catch (Exception $e) {
        error_log("Save Budget Alerts Error: " . $e->getMessage());
        Flash::redirect('manage_budgets.php#alerts', 'error', 'Failed to save alert settings');
    }

    Flash::redirect('manage_budgets.php#alerts', 'success', 'Budget alert settings saved');
}

header("Location: budget.php");
exit();
