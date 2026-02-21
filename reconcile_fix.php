<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? 'auto_fix';
    $diff = floatval($_POST['difference']);
    $desc = $_POST['desc'];
    $date = $_POST['date']; // Current month end
    $user_id = $_SESSION['user_id'];

    if ($action == 'update_opening') {
        // Option 2: Update Opening Balance (Backdate correction)
        // New Opening = Old Opening + Diff.
        // Since Opening is SUM(prev_month), adding an entry with amount=Diff effectively updates the sum.

        $prev_month_end = date('Y-m-t', strtotime("$date -1 month"));

        // Insert a balance adjustment for the previous month
        $stmt = $pdo->prepare("INSERT INTO bank_balances (user_id, bank_name, amount, balance_date) VALUES (?, 'Opening Balance Adjustment', ?, ?)");
        $stmt->execute([$user_id, $diff, $prev_month_end]);

    } else {
        // Option 1: Auto-Fix (Current Month Adj)
        if ($diff > 0) {
            // Surplus -> Income
            $stmt = $pdo->prepare("INSERT INTO income (user_id, amount, source, income_date, description) VALUES (?, ?, 'Adjustment', ?, ?)");
            $stmt->execute([$user_id, $diff, $date, $desc]);
        } elseif ($diff < 0) {
            // Missing -> Expense
            $amount = abs($diff);
            $stmt = $pdo->prepare("INSERT INTO expenses (user_id, amount, category, expense_date, description, payment_method) VALUES (?, ?, 'Adjustment', ?, ?, 'Cash')");
            $stmt->execute([$user_id, $amount, $date, $desc]);
        }
    }

    // Redirect back
    $m = date('n', strtotime($date));
    $y = date('Y', strtotime($date));
    header("Location: monthly_balances.php?month=$m&year=$y");
    exit;
}
?>
