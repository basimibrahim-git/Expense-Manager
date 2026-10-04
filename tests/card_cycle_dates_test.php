<?php
/**
 * Self-checks for the pure date math in App\Helpers\CardCycleHelper (no database needed).
 *
 *   php tests/card_cycle_dates_test.php
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

echo "clampedDate\n";
check('Jan 31 stays 31',                 '2026-01-31', C::clampedDate(2026, 1, 31));
check('Feb 31 → Feb 28 (non-leap)',      '2026-02-28', C::clampedDate(2026, 2, 31));
check('Feb 30 → Feb 29 (leap 2028)',     '2028-02-29', C::clampedDate(2028, 2, 30));
check('Apr 31 → Apr 30',                 '2026-04-30', C::clampedDate(2026, 4, 31));
check('month 13 → Jan next year',        '2027-01-15', C::clampedDate(2026, 13, 15));
check('month 0 → Dec previous year',     '2025-12-31', C::clampedDate(2026, 0, 31));
check('month -1 → Nov previous year',    '2025-11-30', C::clampedDate(2026, -1, 31));

echo "nextStatementOnOrAfter\n";
check('before day in month',             '2026-10-15', C::nextStatementOnOrAfter('2026-10-04', 15));
check('on the statement day itself',     '2026-10-15', C::nextStatementOnOrAfter('2026-10-15', 15));
check('after day → next month',          '2026-11-15', C::nextStatementOnOrAfter('2026-10-16', 15));
check('day 31 in February (non-leap)',   '2026-02-28', C::nextStatementOnOrAfter('2026-02-10', 31));
check('day 31 on Feb 28 itself',         '2026-02-28', C::nextStatementOnOrAfter('2026-02-28', 31));
check('day 30, Feb 29 leap year',        '2028-02-29', C::nextStatementOnOrAfter('2028-02-01', 30));
check('year rollover Dec 20 day 10',     '2027-01-10', C::nextStatementOnOrAfter('2026-12-20', 10));
check('Dec 31 day 31',                   '2026-12-31', C::nextStatementOnOrAfter('2026-12-31', 31));

echo "previousStatement\n";
check('Mar 31 → Feb 28 (day 31)',        '2026-02-28', C::previousStatement('2026-03-31', 31));
check('Feb 28 → Jan 31 (day 31)',        '2026-01-31', C::previousStatement('2026-02-28', 31));
check('Mar 29 → Feb 29 leap (day 29)',   '2028-02-29', C::previousStatement('2028-03-29', 29));
check('Jan 10 → Dec 10 prev year',       '2025-12-10', C::previousStatement('2026-01-10', 10));

echo "dueDateFor\n";
check('bill > statement → same month',            '2026-10-25', C::dueDateFor('2026-10-05', 5, 25));
check('bill < statement → next month',            '2026-11-05', C::dueDateFor('2026-10-15', 15, 5));
check('bill == statement → next month',           '2026-11-15', C::dueDateFor('2026-10-15', 15, 15));
check('Jan 31 statement, due 25 → Feb 25',        '2026-02-25', C::dueDateFor('2026-01-31', 31, 25));
check('Jan 31 statement, due 31 → Feb 28',        '2026-02-28', C::dueDateFor('2026-01-31', 31, 31));
check('Jan 31 statement, due 30 → Feb 29 leap',   '2028-02-29', C::dueDateFor('2028-01-31', 31, 30));
check('Feb 28 stmt (day 31), due 30 → Mar 30',    '2026-03-30', C::dueDateFor('2026-02-28', 31, 30));
check('Feb 28 stmt (day 29), due 30 → Mar 30',    '2026-03-30', C::dueDateFor('2026-02-28', 29, 30));
check('Feb 29 stmt leap (day 29), due 30 → Mar 30', '2028-03-30', C::dueDateFor('2028-02-29', 29, 30));
check('Leap: Feb 29 stmt (day 29), due 31 → Mar 31', '2028-03-31', C::dueDateFor('2028-02-29', 29, 31));
check('Dec 20 statement, due 10 → Jan 10 next yr', '2027-01-10', C::dueDateFor('2026-12-20', 20, 10));
check('Dec 1 statement, due 25 → Dec 25',         '2026-12-25', C::dueDateFor('2026-12-01', 1, 25));

echo "cycleDates\n";
$c = C::cycleDates('2026-10-04', 15, 5);
check('mid-cycle: end',                 '2026-10-15', $c['current_cycle_end']);
check('mid-cycle: last statement',      '2026-09-15', $c['last_statement_date']);
check('mid-cycle: start',               '2026-09-16', $c['current_cycle_start']);
check('mid-cycle: prev cycle after',    '2026-08-15', $c['previous_cycle_start_exclusive']);
check('mid-cycle: due (bill < stmt)',   '2026-10-05', $c['last_statement_due_date']);

$c = C::cycleDates('2026-10-15', 15, 5);
check('on statement day: cycle ends today',   '2026-10-15', $c['current_cycle_end']);
check('on statement day: last = month before', '2026-09-15', $c['last_statement_date']);

$c = C::cycleDates('2026-10-16', 15, 5);
check('day after statement: new cycle start', '2026-10-16', $c['current_cycle_start']);
check('day after statement: due next month',  '2026-11-05', $c['last_statement_due_date']);

$c = C::cycleDates('2026-03-10', 31, 25);
check('stmt 31, March 10: end Mar 31',        '2026-03-31', $c['current_cycle_end']);
check('stmt 31, March 10: last Feb 28',       '2026-02-28', $c['last_statement_date']);
check('stmt 31, March 10: start Mar 1',       '2026-03-01', $c['current_cycle_start']);
check('stmt 31, March 10: prev after Jan 31', '2026-01-31', $c['previous_cycle_start_exclusive']);
check('stmt 31, March 10: due Mar 25',        '2026-03-25', $c['last_statement_due_date']);

$c = C::cycleDates('2028-02-15', 30, 10);
check('leap Feb: end Feb 29',                 '2028-02-29', $c['current_cycle_end']);
check('leap Feb: last Jan 30',                '2028-01-30', $c['last_statement_date']);
check('leap Feb: due Feb 10',                 '2028-02-10', $c['last_statement_due_date']);

$c = C::cycleDates('2027-01-05', 20, 10);
check('year rollover: end Jan 20',            '2027-01-20', $c['current_cycle_end']);
check('year rollover: last Dec 20',           '2026-12-20', $c['last_statement_date']);
check('year rollover: start Dec 21',          '2026-12-21', $c['current_cycle_start']);
check('year rollover: due Jan 10',            '2027-01-10', $c['last_statement_due_date']);

$c = C::cycleDates('2026-10-04', 15, null);
check('no bill_day → due null',               null, $c['last_statement_due_date']);

echo "daysBetween / addDays\n";
check('same day',                       0,  C::daysBetween('2026-10-04', '2026-10-04'));
check('forward over Feb (non-leap)',    2,  C::daysBetween('2026-02-27', '2026-03-01'));
check('forward over Feb (leap)',        3,  C::daysBetween('2028-02-27', '2028-03-01'));
check('negative (overdue)',             -3, C::daysBetween('2026-10-08', '2026-10-05'));
check('year rollover',                  2,  C::daysBetween('2026-12-31', '2027-01-02'));
check('addDays over year end',          '2027-01-01', C::addDays('2026-12-31', 1));
check('addDays negative',               '2026-02-28', C::addDays('2026-03-01', -1));

echo "statusFor\n";
check('nothing billed → ok',            'ok',       C::statusFor(0.0, 0.0, 10));
check('fully paid → paid',              'paid',     C::statusFor(0.0, 500.0, 2));
check('due in 5 → due_soon',            'due_soon', C::statusFor(100.0, 500.0, 5));
check('due today → due_soon',           'due_soon', C::statusFor(100.0, 500.0, 0));
check('due in 6 → ok',                  'ok',       C::statusFor(100.0, 500.0, 6));
check('past due → overdue',             'overdue',  C::statusFor(100.0, 500.0, -1));
check('no due day → ok',                'ok',       C::statusFor(100.0, 500.0, null));

echo "cashback\n";
$r = C::cashbackRates('{"Grocery":2,"Other":0.5,"Food":0}');
check('rates drop zero entries',        ['Grocery' => 2.0, 'Other' => 0.5], $r);
check('category rate',                  2.0, C::rateFor($r, 'Grocery'));
check('fallback to Other',              0.5, C::rateFor($r, 'Travel'));
check('no Other → 0',                   0.0, C::rateFor(['Grocery' => 2.0], 'Travel'));
check('legacy list → no rates',         [], C::cashbackRates('["Grocery","Food"]'));
check('invalid JSON → no rates',        [], C::cashbackRates('not json'));
check('null → no rates',                [], C::cashbackRates(null));

echo "utilizationColor\n";
check('29.9 → success',                 'success', C::utilizationColor(29.9));
check('30 → warning',                   'warning', C::utilizationColor(30.0));
check('69.9 → warning',                 'warning', C::utilizationColor(69.9));
check('70 → danger',                    'danger',  C::utilizationColor(70.0));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
