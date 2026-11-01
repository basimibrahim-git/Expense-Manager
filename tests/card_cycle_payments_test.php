<?php
/**
 * Self-checks for how CardCycleHelper::cycles() credits payments, on an in-memory SQLite database
 * (needs pdo_sqlite):
 *
 *   php tests/card_cycle_payments_test.php
 *
 * Exits with status 1 when any check fails.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../src/Helpers/CardCycleHelper.php';

use App\Helpers\CardCycleHelper as C;

$passed = 0;
$failed = 0;

function check(string $label, $expected, $actual): void
{
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "  ok    {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n        expected " . var_export($expected, true) . "\n        got      " . var_export($actual, true) . "\n";
    }
}

/** Fresh database with one credit card: statement on the 25th, due on the 15th. */
function db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE banks (id INTEGER PRIMARY KEY, tenant_id INT, bank_name TEXT)");
    $pdo->exec("CREATE TABLE cards (id INTEGER PRIMARY KEY, tenant_id INT, card_name TEXT, bank_name TEXT, bank_id INT,
                last_four TEXT, limit_amount REAL, statement_day INT, bill_day INT, cashback_struct TEXT,
                card_type TEXT, is_default INT)");
    $pdo->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, tenant_id INT, card_id INT, category TEXT,
                amount REAL, cashback_earned REAL DEFAULT 0, expense_date TEXT)");
    $pdo->exec("CREATE TABLE card_payments (id INTEGER PRIMARY KEY, tenant_id INT, card_id INT, amount REAL, payment_date TEXT)");
    $pdo->exec("INSERT INTO cards VALUES (1, 1, 'Test Card', 'Bank', NULL, '1234', 10000, 25, 15, '{}', 'Credit', 1)");
    return $pdo;
}

function spend(PDO $pdo, string $date, float $amount): void
{
    $pdo->prepare("INSERT INTO expenses (tenant_id, card_id, category, amount, expense_date) VALUES (1, 1, 'Food', ?, ?)")
        ->execute([$amount, $date]);
}

function pay(PDO $pdo, string $date, float $amount): void
{
    $pdo->prepare("INSERT INTO card_payments (tenant_id, card_id, amount, payment_date) VALUES (1, 1, ?, ?)")
        ->execute([$amount, $date]);
}

function row(PDO $pdo, string $today): array
{
    return C::cycles($pdo, 1, $today)[0];
}

// Today 1 Nov: last statement 25 Oct (cycle 26 Sep – 25 Oct), due 15 Nov.

echo "payment after the statement\n";
$pdo = db();
spend($pdo, '2026-10-10', 500);
pay($pdo, '2026-10-28', 500);
$r = row($pdo, '2026-11-01');
check('due settled', 0.0, $r['due_remaining']);
check('status paid', 'paid', $r['status']);

echo "payment on the statement day\n";
$pdo = db();
spend($pdo, '2026-10-10', 500);
pay($pdo, '2026-10-25', 500);
$r = row($pdo, '2026-11-01');
check('due settled', 0.0, $r['due_remaining']);
check('status paid', 'paid', $r['status']);
check('paid toward statement', 500.0, $r['paid_since_statement']);

echo "early payment before the statement closed\n";
$pdo = db();
spend($pdo, '2026-10-10', 500);
pay($pdo, '2026-10-20', 500);
$r = row($pdo, '2026-11-01');
check('due settled', 0.0, $r['due_remaining']);
check('status paid', 'paid', $r['status']);

echo "partial payment\n";
$pdo = db();
spend($pdo, '2026-10-10', 500);
pay($pdo, '2026-10-20', 200);
$r = row($pdo, '2026-11-01');
check('300 still due', 300.0, $r['due_remaining']);
check('paid toward statement', 200.0, $r['paid_since_statement']);
check('due soon is not paid', true, $r['status'] !== 'paid');

echo "unpaid earlier statement carries forward\n";
$pdo = db();
spend($pdo, '2026-09-10', 1000); // September statement, never paid
spend($pdo, '2026-10-10', 500);
$r = row($pdo, '2026-11-01');
check('1500 due', 1500.0, $r['due_remaining']);
check('statement amount is the latest cycle', 500.0, $r['last_statement_amount']);
check('carried over from the earlier statement', 1000.0, $r['carried_over']);
check('statement - paid + carried = due', $r['due_remaining'], round($r['last_statement_amount'] - $r['paid_since_statement'] + $r['carried_over'], 2));

echo "spend in the open cycle is not due yet\n";
$pdo = db();
spend($pdo, '2026-10-28', 300);
$r = row($pdo, '2026-11-01');
check('nothing due', 0.0, $r['due_remaining']);
check('status ok', 'ok', $r['status']);
check('counted in this cycle', 300.0, $r['current_cycle_spend']);

echo "a future-dated payment does not count yet\n";
$pdo = db();
spend($pdo, '2026-10-10', 500);
pay($pdo, '2026-11-10', 500);
$r = row($pdo, '2026-11-01');
check('still due', 500.0, $r['due_remaining']);
check('outstanding ignores the future payment', 500.0, $r['outstanding_estimate']);

echo "\n";
if ($failed > 0) {
    echo "{$failed} failed, {$passed} passed\n";
    exit(1);
}
echo "OK ({$passed} checks)\n";
