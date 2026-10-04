<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;

Bootstrap::init();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: interest_tracker.php?error=" . urlencode('Unauthorized: Read-only access'));
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action == 'add_payment') {
        // Handle Payment (stored as a negative interest_tracker amount)
        $title  = trim((string) ($_POST['title'] ?? ''));
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $date   = (string) ($_POST['payment_date'] ?? '');
        $target_month_year = (string) ($_POST['target_month_year'] ?? ''); // Format YYYY-MM

        $pay = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$pay || $pay->format('Y-m-d') !== $date || (int) $pay->format('Y') < 2000 || (int) $pay->format('Y') > 2100) {
            header("Location: interest_tracker.php?error=" . urlencode('Please enter a valid payment date.'));
            exit();
        }

        // The payment is booked in the month it pays for, so it offsets that month's interest.
        // Use the actual payment date when it falls in that month, otherwise the payment day
        // clamped to the target month's last day.
        $interest_date = $date;
        if ($target_month_year !== '') {
            $target = DateTime::createFromFormat('!Y-m', $target_month_year);
            if (!$target || $target->format('Y-m') !== $target_month_year || (int) $target->format('Y') < 2000 || (int) $target->format('Y') > 2100) {
                header("Location: interest_tracker.php?error=" . urlencode('Please choose a valid month to pay for.'));
                exit();
            }
            if ($pay->format('Y-m') !== $target_month_year) {
                $day = min((int) $pay->format('j'), (int) $target->format('t'));
                $interest_date = $target->format('Y-m-') . sprintf('%02d', $day);
            }
        }

        if ($amount !== false && $amount > 0 && $amount <= 99999999.99 && $title !== '' && mb_strlen($title) <= 255) {
            $final_amount = -1 * abs($amount);

            $stmt = $pdo->prepare("INSERT INTO interest_tracker (user_id, tenant_id, title, amount, interest_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], $title, $final_amount, $interest_date]);

            AuditHelper::log($pdo, 'interest_payment', "Recorded Interest Payment: " . abs($amount) . " on $date for " . ($target_month_year ?: $interest_date));
            header("Location: interest_tracker.php?year=" . (int) substr($interest_date, 0, 4) . "&success=" . urlencode('Payment Recorded'));
            exit;
        }

        header("Location: interest_tracker.php?error=" . urlencode('Please enter a description and an amount greater than zero.'));
        exit;
    }
}

// Redirect back if something went wrong or no action
header("Location: interest_tracker.php");
exit;
