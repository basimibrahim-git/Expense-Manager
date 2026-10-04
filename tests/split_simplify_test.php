<?php
// CLI checks for App\Helpers\SplitHelper (no database needed).
// Run: php tests/split_simplify_test.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../autoload.php';

use App\Helpers\SplitHelper;

$failures = 0;
$checks   = 0;
function check(bool $ok, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        echo "FAIL: $label\n";
    }
}
function sumCents(array $a): int
{
    return (int) round(array_sum($a) * 100);
}

// --- equalShares: remainder to the payer ---
$s = SplitHelper::equalShares(100.00, [1, 2, 3], 2);
check($s === [1 => 33.33, 2 => 33.34, 3 => 33.33], 'equal 100/3 remainder to payer 2');
check(sumCents($s) === 10000, 'equal 100/3 sums exactly');

$s = SplitHelper::equalShares(10.00, [3, 1, 2], 1);
check($s[1] === 3.34 && $s[2] === 3.33 && $s[3] === 3.33, 'equal 10/3 payer 1 gets extra cent');

// payer not among participants -> remainder to lowest id participant
$s = SplitHelper::equalShares(0.05, [5, 4], 9);
check($s === [4 => 0.03, 5 => 0.02], 'equal remainder to first participant when payer absent');

$s = SplitHelper::equalShares(0.01, [1, 2, 3], 3);
check($s === [1 => 0.0, 2 => 0.0, 3 => 0.01], 'equal 0.01/3 all to payer');

check(SplitHelper::equalShares(50, [], 1) === [], 'equal with no participants');
check(SplitHelper::equalShares(50, [2, 2], 2) === [2 => 50.0], 'equal dedupes ids');

// --- scaleShares: foreign-currency custom amounts rescaled to the AED total ---
$s = SplitHelper::scaleShares([1 => 100, 2 => 200], 30.00, 1);
check($s === [1 => 10.0, 2 => 20.0], 'scale 1:2 to 30');
$s = SplitHelper::scaleShares([1 => 1, 2 => 1, 3 => 1], 100.00, 3);
check($s === [1 => 33.33, 2 => 33.33, 3 => 33.34], 'scale thirds, remainder to payer');
$s = SplitHelper::scaleShares([1 => 0, 2 => 5], 7.5, 1);
check($s === [2 => 7.5], 'scale drops zero weights, payer absent');
$s = SplitHelper::scaleShares([1 => 33.33, 2 => 33.33, 3 => 33.33], 100.00, 9);
check(sumCents($s) === 10000, 'scale sums exactly even when payer absent');

// --- customSumMatches ---
check(SplitHelper::customSumMatches([1 => 40, 2 => 60], 100), 'custom exact');
check(SplitHelper::customSumMatches([1 => 33.33, 2 => 33.33, 3 => 33.33], 100), 'custom within 0.01');
check(!SplitHelper::customSumMatches([1 => 33.33, 2 => 33.33, 3 => 33.32], 100), 'custom off by 0.02 rejected');
check(!SplitHelper::customSumMatches([1 => -10, 2 => 110], 100), 'custom negative rejected');

// --- netBalances ---
// A(1) paid 90 split equally with B(2), C(3); B paid 30 split with A
$rows = [
    ['payer_id' => 1, 'user_id' => 1, 'share' => 30],
    ['payer_id' => 1, 'user_id' => 2, 'share' => 30],
    ['payer_id' => 1, 'user_id' => 3, 'share' => 30],
    ['payer_id' => 2, 'user_id' => 1, 'share' => '15.00'],
    ['payer_id' => 2, 'user_id' => 2, 'share' => '15.00'],
];
$net = SplitHelper::netBalances($rows, []);
check($net === [1 => 45.0, 2 => -15.0, 3 => -30.0], 'net balances');
check(sumCents($net) === 0, 'net balances sum to zero');

// C paid A 10 -> C owes 20, A owed 35
$net = SplitHelper::netBalances($rows, [['from_user_id' => 3, 'to_user_id' => 1, 'amount' => '10.00']]);
check($net === [1 => 35.0, 2 => -15.0, 3 => -20.0], 'settlement reduces debt');

// fully settled
$net = SplitHelper::netBalances($rows, [
    ['from_user_id' => 3, 'to_user_id' => 1, 'amount' => 30],
    ['from_user_id' => 2, 'to_user_id' => 1, 'amount' => 15],
]);
check(SplitHelper::simplify($net) === [], 'fully settled -> no transfers');

// --- simplify ---
$t = SplitHelper::simplify([1 => 45.0, 2 => -15.0, 3 => -30.0]);
check($t === [
    ['from' => 3, 'to' => 1, 'amount' => 30.0],
    ['from' => 2, 'to' => 1, 'amount' => 15.0],
], 'simplify two debtors one creditor');

// chain A->B->C collapses to A->C
$net = SplitHelper::netBalances([
    ['payer_id' => 2, 'user_id' => 1, 'share' => 10], // A owes B 10
    ['payer_id' => 3, 'user_id' => 2, 'share' => 10], // B owes C 10
], []);
check(SplitHelper::simplify($net) === [['from' => 1, 'to' => 3, 'amount' => 10.0]], 'chain collapses to one transfer');

// two creditors two debtors
$t = SplitHelper::simplify([1 => 50.0, 2 => 20.0, 3 => -40.0, 4 => -30.0]);
check(count($t) <= 3, 'at most n-1 transfers');
$check = [1 => 50.0, 2 => 20.0, 3 => -40.0, 4 => -30.0];
foreach ($t as $x) {
    $check[$x['from']] += $x['amount'];
    $check[$x['to']]   -= $x['amount'];
}
check(array_sum(array_map(fn($v) => abs((int) round($v * 100)), $check)) === 0, 'transfers clear every balance');

// float noise is handled in cents
$t = SplitHelper::simplify([1 => 0.1 + 0.2, 2 => -0.3]);
check($t === [['from' => 2, 'to' => 1, 'amount' => 0.3]], 'float noise');

// tie-break by lower id
$t = SplitHelper::simplify([5 => 10.0, 2 => 10.0, 7 => -20.0]);
check($t[0]['to'] === 2, 'ties broken by lower id');

// --- isEqualSplit ---
check(SplitHelper::isEqualSplit([1 => 33.33, 2 => 33.34, 3 => 33.33], 100, 2), 'isEqualSplit true');
check(!SplitHelper::isEqualSplit([1 => 40, 2 => 60], 100, 2), 'isEqualSplit false');

echo $failures === 0 ? "OK ($checks checks)\n" : "$failures of $checks checks FAILED\n";
exit($failures === 0 ? 0 : 1);
