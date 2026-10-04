<?php
// budget_actions.php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;

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
        $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'dashboard.php');

        header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "error=Unauthorized: Read-only access");
        exit();
    }
}

$tenant_id = $_SESSION['tenant_id'];

// Budget categories = the expense categories offered in add_expense.php / manage_budgets.php
const BUDGET_CATEGORIES = ['Grocery', 'Food', 'Medical', 'Shopping', 'Utilities', 'Transport', 'Travel', 'Entertainment', 'Education', 'Other'];

if ($action == 'save_budgets' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $month = filter_input(INPUT_POST, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
    $year = filter_input(INPUT_POST, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
    if (!$month || !$year) {
        header("Location: manage_budgets.php?error=Invalid month or year");
        exit();
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
            if (!in_array($category, BUDGET_CATEGORIES, true)) {
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
        $month_name = date('F', mktime(0, 0, 0, $month, 1, $year));
        header("Location: manage_budgets.php?month=$month&year=$year&success=Budgets saved for $month_name $year");
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Save Budgets Error: " . $e->getMessage());
        header("Location: manage_budgets.php?month=$month&year=$year&error=Failed to save budgets");
        exit();
    }
}

header("Location: budget.php");
exit();
