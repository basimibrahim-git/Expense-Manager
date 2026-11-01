<?php
// v3_archive_engine.php - Maintenance script for cleaning up old data.
// CLI only:  php v3_archive_engine.php
// Moves expenses/income older than the retention period (all tenants) into *_archive tables.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;

Bootstrap::init();

$retention_years = 2;
$cutoff_date = date('Y-m-d', strtotime("-$retention_years years"));

echo "Starting Archiving Engine (Retention: $retention_years years, Cutoff: $cutoff_date)\n";

/**
 * Moves rows older than the cutoff from $table to $archive inside one transaction.
 * Only columns present in both tables are copied, so a later ALTER on the live table
 * cannot break the INSERT.
 */
function archiveTable(PDO $pdo, string $table, string $archive, string $dateColumn, string $cutoff): int
{
    // DDL commits implicitly, so it runs before the transaction. LIKE copies columns and
    // indexes but not foreign keys, which is what an archive needs.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `$archive` LIKE `$table`");

    $liveCols    = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $archiveCols = $pdo->query("SHOW COLUMNS FROM `$archive`")->fetchAll(PDO::FETCH_COLUMN);
    $cols = array_values(array_intersect($liveCols, $archiveCols));
    if (!in_array('id', $cols, true)) {
        throw new RuntimeException("Archive table $archive has no id column");
    }
    $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO `$archive` ($colList) SELECT $colList FROM `$table` WHERE `$dateColumn` < ?");
        $stmt->execute([$cutoff]);
        $moved = $stmt->rowCount();

        if ($moved > 0) {
            // Delete exactly the rows that are now in the archive
            $del = $pdo->prepare("DELETE t FROM `$table` t JOIN `$archive` a ON a.id = t.id WHERE t.`$dateColumn` < ?");
            $del->execute([$cutoff]);
        }

        $pdo->commit();
        return $moved;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Copies the split rows of expenses older than the cutoff into expense_splits_archive. Must run
 * BEFORE the expenses are archived: expense_splits.expense_id has ON DELETE CASCADE, so deleting an
 * expense would otherwise destroy its splits. Re-runnable: rows already archived are skipped.
 */
function archiveSplits(PDO $pdo, string $cutoff): int
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `expense_splits_archive` LIKE `expense_splits`");

    $liveCols    = $pdo->query("SHOW COLUMNS FROM `expense_splits`")->fetchAll(PDO::FETCH_COLUMN);
    $archiveCols = $pdo->query("SHOW COLUMNS FROM `expense_splits_archive`")->fetchAll(PDO::FETCH_COLUMN);
    $cols = array_values(array_intersect($liveCols, $archiveCols));
    if (!in_array('id', $cols, true)) {
        throw new RuntimeException('Archive table expense_splits_archive has no id column');
    }
    $colList = implode(', ', array_map(fn($c) => "s.`$c`", $cols));
    $insList = implode(', ', array_map(fn($c) => "`$c`", $cols));

    $stmt = $pdo->prepare(
        "INSERT INTO `expense_splits_archive` ($insList)
         SELECT $colList FROM `expense_splits` s
           JOIN `expenses` e ON e.id = s.expense_id
          WHERE e.expense_date < ?
            AND s.id NOT IN (SELECT id FROM `expense_splits_archive`)"
    );
    $stmt->execute([$cutoff]);
    return $stmt->rowCount();
}

try {
    // Splits first (see archiveSplits); card payments and settlements use the same cutoff as the
    // expenses they pay for / settle, so card outstanding and split balances stay consistent.
    $moved_splits = archiveSplits($pdo, $cutoff_date);
    echo $moved_splits > 0 ? "Copied $moved_splits expense splits to archive.\n" : "No old expense splits to archive.\n";

    $moved_expenses = archiveTable($pdo, 'expenses', 'expenses_archive', 'expense_date', $cutoff_date);
    echo $moved_expenses > 0 ? "Moved $moved_expenses expenses to archive.\n" : "No old expenses to archive.\n";

    $moved_income = archiveTable($pdo, 'income', 'income_archive', 'income_date', $cutoff_date);
    echo $moved_income > 0 ? "Moved $moved_income income records to archive.\n" : "No old income to archive.\n";

    $moved_payments = archiveTable($pdo, 'card_payments', 'card_payments_archive', 'payment_date', $cutoff_date);
    echo $moved_payments > 0 ? "Moved $moved_payments card payments to archive.\n" : "No old card payments to archive.\n";

    $moved_settlements = archiveTable($pdo, 'settlements', 'settlements_archive', 'settled_on', $cutoff_date);
    echo $moved_settlements > 0 ? "Moved $moved_settlements settlements to archive.\n" : "No old settlements to archive.\n";

    if ($moved_expenses > 0 || $moved_income > 0) {
        $pdo->query("OPTIMIZE TABLE expenses, income")->fetchAll();
        echo "Tables optimized.\n";
    }

    error_log("[archive] Archived data older than $cutoff_date. Expenses: $moved_expenses, Income: $moved_income");
    echo "\nArchiving complete.\n";
} catch (Throwable $e) {
    error_log('[archive] ' . $e->getMessage());
    fwrite(STDERR, "A system error occurred during archiving: " . $e->getMessage() . "\n");
    exit(1);
}
