<?php
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;

Bootstrap::init();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

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

        if (!empty($target_month_year)) {
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

            AuditHelper::log($pdo, 'interest_payment', "Recorded Interest Payment: " . abs($amount) . " for " . ($target_month_year ?: $interest_date));
            header("Location: interest_tracker.php?year=" . date('Y', strtotime($interest_date)) . "&success=Payment Recorded");
            exit;
        }
    }
}

// Redirect back if something went wrong or no action
header("Location: interest_tracker.php");
exit;
