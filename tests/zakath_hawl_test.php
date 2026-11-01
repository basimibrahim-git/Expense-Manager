<?php
// CLI checks for App\Helpers\ZakathHelper::hawl (Hijri-anniversary due dates; no database needed).
// Run: php tests/zakath_hawl_test.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../autoload.php';

use App\Helpers\ZakathHelper;

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
function day(string $ymd): DateTimeImmutable
{
    return new DateTimeImmutable($ymd);
}

check(ZakathHelper::hawl(null) === null, 'no start date -> null');
check(ZakathHelper::hawl('garbage') === null, 'bad start date -> null');

// Same Hijri month/day each year (needs ext-intl; otherwise the 354.367-day fallback applies)
if (extension_loaded('intl') && class_exists('IntlCalendar')) {
    $h = ZakathHelper::hawl('2025-03-01', day('2026-11-01'));  // 1 Ramadan 1446
    check($h['start_hijri'] === '1 Ramadan 1446 AH', 'start is 1 Ramadan 1446');
    check($h['due_hijri'] === '1 Ramadan 1448 AH', 'due is the 1 Ramadan anniversary');
    check($h['due'] === '2027-02-08', 'due 2027-02-08');
    check($h['prev_due'] === '2026-02-18' && $h['cycle_start'] === '2026-02-18', 'previous due = 1 Ramadan 1447');
    check($h['cycle_number'] === 2 && $h['days_left'] === 99, 'cycle 2, 99 days left');

    // Due today counts as the current cycle (days_left 0); the day after rolls to the next cycle
    $h = ZakathHelper::hawl('2025-03-01', day('2026-02-18'));
    check($h['due'] === '2026-02-18' && $h['days_left'] === 0 && $h['cycle_number'] === 1, 'due today -> days_left 0');
    $h = ZakathHelper::hawl('2025-03-01', day('2026-02-19'));
    check($h['due'] === '2027-02-08' && $h['prev_due'] === '2026-02-18', 'day after due -> next cycle, prev_due set');

    // Not a fixed 354 days: consecutive due dates differ by 354 or 355 days
    $s = day('2020-06-15');
    $gap = (int) ZakathHelper::dueForCycle($s, 3)->diff(ZakathHelper::dueForCycle($s, 2))->days;
    check($gap === 354 || $gap === 355, 'consecutive Hijri years are 354/355 days apart');

    // Day 30 start clamps to the month length in a 29-day target month
    $d = ZakathHelper::dueForCycle(day('2025-04-30'), 1);
    check(ZakathHelper::hijri($d) !== null, 'clamped due date converts back to a Hijri date');
}

// Shape and invariants hold for any start date
foreach (['2019-01-31', '2024-02-29', '2026-11-01', '2026-10-31'] as $start) {
    foreach (['2026-11-01', '2027-06-15', '2030-01-01'] as $today) {
        $h = ZakathHelper::hawl($start, day($today));
        check($h !== null && $h['due'] >= $today, "$start/$today: due is today or later");
        check($h['days_left'] >= 0 && $h['days_left'] <= 355, "$start/$today: days_left within one lunar year");
        check($h['prev_due'] === null || $h['prev_due'] < $today, "$start/$today: prev_due is in the past");
        check($h['cycle_number'] >= 1 && ($h['cycle_number'] > 1) === ($h['prev_due'] !== null), "$start/$today: prev_due only after cycle 1");
        check(array_keys($h) === ['start', 'cycle_start', 'due', 'cycle_number', 'days_left', 'due_hijri', 'start_hijri', 'prev_due'], "$start/$today: return shape");
    }
}

echo $failures === 0 ? "OK: $checks checks passed\n" : "$failures of $checks checks FAILED\n";
exit($failures === 0 ? 0 : 1);
