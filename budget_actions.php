<?php
// budget_actions.php
require_once 'config.php'; // NOSONAR
use App\Helpers\AuditHelper;

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

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

if ($action == 'save_budgets' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $month = intval($_POST['month']);
    $year = intval($_POST['year']);
    $budgets = $_POST['budgets'] ?? [];

    try {
        $pdo->beginTransaction();

        foreach ($budgets as $category => $amount) {
            $amount = floatval($amount);

            // If amount is 0/empty, we can either keep it or delete it.
            // Let's use INSERT ... ON DUPLICATE KEY UPDATE
            if ($amount > 0) {
                $stmt = $pdo->prepare("INSERT INTO budgets (user_id, tenant_id, category, amount, month, year)
                                     VALUES (?, ?, ?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE amount = VALUES(amount)");
                $stmt->execute([$user_id, $tenant_id, htmlspecialchars($category), $amount, $month, $year]);
            } else {
                // Delete if entry exists and new amount is 0
                $stmt = $pdo->prepare("DELETE FROM budgets WHERE tenant_id = ? AND category = ? AND month = ? AND year = ?");
                $stmt->execute([$tenant_id, $category, $month, $year]);
            }
        }

        $pdo->commit();
        AuditHelper::log($pdo, 'save_budgets', "Updated Budgets for $month/$year. Categories: " . count($budgets));
        header("Location: manage_budgets.php?month=$month&year=$year&success=Budgets saved successfully");
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

