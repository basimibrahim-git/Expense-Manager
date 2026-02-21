<?php
// v3_archive_engine.php - Maintenance script for cleaning up old data.
require_once 'config.php';

if (php_sapi_name() !== 'cli' && !isset($_SESSION['user_id'])) {
    die("Unauthorized. Run via CLI or log in.");
}

$retention_years = 2;
$cutoff_date = date('Y-m-d', strtotime("-$retention_years years"));

echo "🚀 Starting Archiving Engine (Retention: $retention_years years, Cutoff: $cutoff_date)\n";

try {
    // 1. Archive Tables (Handled by install.php)
    echo "✔ Archive tables verification skipped (Assumed exists via installer).\n";

    // 2. Move Expenses
    $stmt = $pdo->prepare("INSERT INTO expenses_archive SELECT * FROM expenses WHERE expense_date < ?");
    $stmt->execute([$cutoff_date]);
    $moved_expenses = $stmt->rowCount();

    if ($moved_expenses > 0) {
        $pdo->prepare("DELETE FROM expenses WHERE expense_date < ?")->execute([$cutoff_date]);
        echo "✔ Moved $moved_expenses expenses to archive.\n";
    } else {
        echo "ℹ No old expenses to archive.\n";
    }

    // 3. Move Income
    $stmt = $pdo->prepare("INSERT INTO income_archive SELECT * FROM income WHERE income_date < ?");
    $stmt->execute([$cutoff_date]);
    $moved_income = $stmt->rowCount();

    if ($moved_income > 0) {
        $pdo->prepare("DELETE FROM income WHERE income_date < ?")->execute([$cutoff_date]);
        echo "✔ Moved $moved_income income records to archive.\n";
    } else {
        echo "ℹ No old income to archive.\n";
    }

    // 4. Optimize Tables
    $pdo->exec("OPTIMIZE TABLE expenses, income");
    echo "✔ Tables optimized.\n";

    log_audit('data_archive', "Archived data older than $cutoff_date. Expenses: $moved_expenses, Income: $moved_income");
    echo "\n✅ Archiving complete.\n";

} catch (Exception $e) {
    die("❌ Archiving failed: " . $e->getMessage());
}
