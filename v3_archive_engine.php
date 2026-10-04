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

try {
    $moved_expenses = archiveTable($pdo, 'expenses', 'expenses_archive', 'expense_date', $cutoff_date);
    echo $moved_expenses > 0 ? "Moved $moved_expenses expenses to archive.\n" : "No old expenses to archive.\n";

    $moved_income = archiveTable($pdo, 'income', 'income_archive', 'income_date', $cutoff_date);
    echo $moved_income > 0 ? "Moved $moved_income income records to archive.\n" : "No old income to archive.\n";

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
