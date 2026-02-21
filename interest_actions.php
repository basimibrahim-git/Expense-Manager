<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: interest_tracker.php?error=Unauthorized: Read-only access");
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action == 'add_payment') {
        // Handle Payment (Negative Interest)
        $title = trim($_POST['title']);
        $amount = floatval($_POST['amount']);
        $date = $_POST['payment_date'];
        $target_month_year = $_POST['target_month_year']; // Format YYYY-MM

        // If user selects a target month, we might want to record the payment date
        // OR the date of the target month.
        // Requirement: "select im paying for which moth as well"
        // Let's stick the payment in the selected month/year so it reduces that month's total.
        // But the "recorded date" might be today.
        // Actually, to make it "show reduced in the dashboard" for that month,
        // the record needs to be associated with that month.
        // So we will use the target_month_year for the record's date (e.g. 1st of that month, or today's day if possible?
        // Let's use the provided payment date, but strictly speaking if I pay in Feb for Jan,
        // looking at Jan's data should show it paid?
        // IF the dashboard shows "Total Interest of [Year]", it sums all records.
        // IF the dashboard shows Month Cards, it sums records with date in that month.
        // So if I pay for Jan, the record must have a Jan date to reduce Jan's card amount.
        // Let's use the target month/year and set day to 28 or current day.

        // RE-EVALUATING BASED ON USER REQUEST: "select im paying for ehich moth as well"
        // If I pay today (Feb) for Jan, I want Jan's balance to go down.
        // So the entry MUST have a Jan date in the DB.
        // We can append the real payment date in the title/description.

        if (!empty($target_month_year)) {
            $date = $target_month_year . '-' . date('d'); // Default to current day of that month? Or 01?
            // To be safe against "Feb 30", let's just use 01 or 28.
            // Let's use the 1st of the month to be safe and consistent, or user picked date?
            // User picked "when i did a intrest payment".
            // If I physically paid on Feb 5th for Jan, I want Jan to be clear.
            // So we insert a record with Date = Jan XX (to affect Jan total)
            // AND maybe a note "Paid on Feb 5th".

            // Let's just trust the "target_month_year" for the database date field
            // to ensure the math works for that month.
            $parts = explode('-', $target_month_year);
            $year = $parts[0];
            $month = $parts[1];

            // We'll use the 28th of the month to ensure it sits at the end or just current day if valid
            $day = min(date('d'), 28);
            $interest_date = "$year-$month-$day";
        } else {
            $interest_date = $date;
        }

        if ($amount > 0 && !empty($title) && !empty($interest_date)) {
            // Store as NEGATIVE amount for payment
            $final_amount = -1 * abs($amount);

            $stmt = $pdo->prepare("INSERT INTO interest_tracker (user_id, tenant_id, title, amount, interest_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], $title, $final_amount, $interest_date]);

            log_audit('interest_payment', "Recorded Interest Payment: " . abs($amount) . " for " . ($target_month_year ?: $interest_date));
            header("Location: interest_tracker.php?year=" . date('Y', strtotime($interest_date)) . "&success=Payment Recorded");
            exit;
        }
    }
}

// Redirect back if something went wrong or no action
header("Location: interest_tracker.php");
exit;

