<?php
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;

Bootstrap::init();

const CONTENT_TYPE_CSV = 'Content-Type: text/csv';
const CSV_EXTENSION = '.csv';
const PHP_OUTPUT = 'php://output';
const SYSTEM_ERROR_MSG = "❌ A system error occurred during export. Please try again or check the logs.";

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$action = $_GET['action'] ?? '';

if ($action == 'export_expenses') {
    $month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
    $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');
    $category_filter = filter_input(INPUT_GET, 'category');
    $payment_filter = filter_input(INPUT_GET, 'payment_method');
    $card_filter = filter_input(INPUT_GET, 'card_id', FILTER_VALIDATE_INT);
    $start_date = filter_input(INPUT_GET, 'start');
    $end_date = filter_input(INPUT_GET, 'end');

    $query = "SELECT e.*, c.bank_name, c.card_name
FROM expenses e
LEFT JOIN cards c ON e.card_id = c.id
WHERE e.tenant_id = :tenant_id
AND MONTH(e.expense_date) = :month
AND YEAR(e.expense_date) = :year";

    $params = ['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year];

    if ($category_filter) {
        $query .= " AND e.category = :cat";
        $params['cat'] = $category_filter;
    }
    if ($payment_filter) {
        $query .= " AND e.payment_method = :pm";
        $params['pm'] = $payment_filter;
    }
    if ($card_filter) {
        $query .= " AND e.card_id = :card_id";
        $params['card_id'] = $card_filter;
    }
    if ($start_date) {
        $query .= " AND e.expense_date >= :start";
        $params['start'] = $start_date;
    }
    if ($end_date) {
        $query .= " AND e.expense_date <= :end";
        $params['end'] = $end_date;
    }
    $query .= " ORDER BY e.expense_date DESC";
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="expenses_' . $month . '_' . $year . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        fputcsv($output, [
            'Date',
            'Description',
            'Category',
            'Payment Method',
            'Card',
            'Currency',
            'Original Amount',
            'Amount (AED)',
            'Tags',
            'Type'
        ]);

        foreach ($expenses as $e) {
            $cardName = 'N/A';
            if ($e['card_name']) {
                $cardName = $e['bank_name'] . ' - ' . $e['card_name'];
            } elseif ($e['payment_method'] == 'Card') {
                $cardName = 'Unknown Card';
            }

            $expenseType = 'N/A';
            if (isset($e['is_fixed'])) {
                $expenseType = $e['is_fixed'] ? 'Fixed' : 'Variable';
            }

            fputcsv($output, [
                $e['expense_date'],
                $e['description'],
                $e['category'],
                $e['payment_method'],
                $cardName,
                $e['currency'] ?: 'AED',
                $e['original_amount'] ?: $e['amount'],
                $e['amount'],
                $e['tags'],
                $expenseType
            ]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        error_log($e->getMessage());
        die(SYSTEM_ERROR_MSG);
    }

} elseif ($action == 'export_income') {
    $month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
    $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');
    $category_filter = filter_input(INPUT_GET, 'category');
    $start_date = filter_input(INPUT_GET, 'start');
    $end_date = filter_input(INPUT_GET, 'end');

    $query = "SELECT * FROM income WHERE tenant_id = :tenant_id AND MONTH(income_date) = :month AND YEAR(income_date) =
    :year";
    $params = ['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year];

    if ($category_filter) {
        $query .= " AND category = :cat";
        $params['cat'] = $category_filter;
    }
    if ($start_date) {
        $query .= " AND income_date >= :start";
        $params['start'] = $start_date;
    }
    if ($end_date) {
        $query .= " AND income_date <= :end";
        $params['end'] = $end_date;
    }
    $query .= " ORDER BY income_date DESC";
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $income = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="income_' . $month . '_' . $year . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        fputcsv($output, ['Date', 'Description', 'Category', 'Amount', 'Currency', 'Recurring']);

        foreach ($income as $i) {
            $recurring = 'N/A';
            if (isset($i['is_recurring'])) {
                $recurring = $i['is_recurring'] ? 'Yes' : 'No';
            }

            fputcsv($output, [
                $i['income_date'],
                $i['description'],
                $i['category'],
                $i['amount'],
                $i['currency'] ?: 'AED',
                $recurring
            ]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        error_log($e->getMessage());
        die(SYSTEM_ERROR_MSG);
    }
} elseif ($action == 'export_sadaqa') {
    $month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
    $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');

    $query = "SELECT * FROM sadaqa_tracker WHERE tenant_id = :tenant_id AND MONTH(sadaqa_date) = :month AND
        YEAR(sadaqa_date) = :year ORDER BY sadaqa_date DESC";
    $params = ['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year];

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="sadaqa_' . $month . '_' . $year . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        fputcsv($output, ['Date', 'Title', 'Amount']);

        foreach ($records as $r) {
            fputcsv($output, [$r['sadaqa_date'], $r['title'], $r['amount']]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        error_log($e->getMessage());
        die(SYSTEM_ERROR_MSG);
    }
} elseif ($action == 'export_incentives') {
    $month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
    $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');

    $query = "SELECT * FROM company_incentives WHERE tenant_id = :tenant_id AND MONTH(incentive_date) = :month AND
        YEAR(incentive_date) = :year ORDER BY incentive_date DESC";
    $params = ['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year];

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="incentives_' . $month . '_' . $year . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        fputcsv($output, ['Date', 'Title', 'Amount']);

        foreach ($records as $r) {
            fputcsv($output, [$r['incentive_date'], $r['title'], $r['amount']]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        error_log($e->getMessage());
        die(SYSTEM_ERROR_MSG);
    }
} elseif ($action == 'export_interest') {
    $month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
    $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');

    $query = "SELECT * FROM interest_tracker WHERE tenant_id = :tenant_id AND MONTH(interest_date) = :month AND
        YEAR(interest_date) = :year ORDER BY interest_date DESC";
    $params = ['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year];

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="interest_' . $month . '_' . $year . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        fputcsv($output, ['Date', 'Title', 'Amount', 'Type']);

        foreach ($records as $r) {
            fputcsv($output, [
                $r['interest_date'],
                $r['title'],
                abs($r['amount']),
                $r['amount'] < 0 ? 'Payment' : 'Interest'
            ]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        error_log($e->
            getMessage());
        die(SYSTEM_ERROR_MSG);
    }
} elseif ($action == 'export_zakath') {
    $query = "SELECT * FROM zakath_calculations WHERE tenant_id = :tenant_id ORDER BY created_at DESC";
    $params = ['tenant_id' => $_SESSION['tenant_id']];

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="zakath_calculations' . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        fputcsv($output, [
            'Date',
            'Cycle Name',
            'Cash',
            'Gold/Silver',
            'Investments',
            'Liabilities',
            'Total Zakath',
            'Status'
        ]);

        foreach ($records as $r) {
            fputcsv($output, [
                $r['created_at'],
                $r['cycle_name'],
                $r['cash_balance'],
                $r['gold_silver'],
                $r['investments'],
                $r['liabilities'],
                $r['total_zakath'],
                $r['status']
            ]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        error_log($e->getMessage());
        die(SYSTEM_ERROR_MSG);
    }
} elseif ($action == 'export_reminders') {
    $query = "SELECT * FROM reminders WHERE tenant_id = :tenant_id ORDER BY alert_date ASC";
    $params = ['tenant_id' => $_SESSION['tenant_id']];

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="reminders' . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        fputcsv($output, ['Alert Date', 'Title', 'Recurrence']);

        foreach ($records as $r) {
            fputcsv($output, [$r['alert_date'], $r['title'], $r['recurrence_type']]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        error_log($e->getMessage());
        die(SYSTEM_ERROR_MSG);
    }
} elseif ($action == 'export_lending') {
    $query = "SELECT * FROM lending_tracker WHERE tenant_id = :tenant_id ORDER BY lent_date DESC";
    $params = ['tenant_id' => $_SESSION['tenant_id']];

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="lending_records' . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        fputcsv($output, ['Lent Date', 'Due Date', 'Borrower', 'Amount', 'Currency', 'Status', 'Notes']);

        foreach ($records as $r) {
            fputcsv($output, [
                $r['lent_date'],
                $r['due_date'] ?? 'N/A',
                $r['borrower_name'],
                $r['amount'],
                $r['currency'],
                $r['status'],
                $r['notes']
            ]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        error_log($e->getMessage());
        die(SYSTEM_ERROR_MSG);
    }
    header("Location: dashboard.php");
    exit();
}
