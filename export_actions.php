<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Flash;
use App\Helpers\SecurityHelper;

Bootstrap::init();

const CONTENT_TYPE_CSV = 'Content-Type: text/csv';
const CSV_EXTENSION = '.csv';
const PHP_OUTPUT = 'php://output';
const SYSTEM_ERROR_MSG = "A system error occurred during export. Please try again or check the logs.";

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

/**
 * fputcsv() that neutralises spreadsheet formulas (CSV injection): text cells starting
 * with = + - @ or a tab / carriage return get a leading apostrophe. Numbers are left as-is.
 */
function csv_row($handle, array $fields): void
{
    foreach ($fields as $i => $value) {
        if (is_string($value) && $value !== '' && !is_numeric($value) && strpbrk($value[0], "=+-@\t\r") !== false) {
            $fields[$i] = "'" . $value;
        }
    }
    fputcsv($handle, $fields);
}

/**
 * Log an export failure and send the user back to the page they came from with a flash message.
 * Falls back to a plain message when part of the CSV has already been sent.
 */
function export_fail(Throwable $e): void
{
    error_log('[Export] ' . $e->getMessage());
    if (headers_sent()) {
        die(SYSTEM_ERROR_MSG);
    }
    header_remove('Content-Type');
    header_remove('Content-Disposition');
    Flash::redirect(SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'dashboard.php'), 'error', SYSTEM_ERROR_MSG);
}

/** Validated month / year from the query string (convention: invalid values fall back to now). */
function export_month(): int
{
    return filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
}

function export_year(): int
{
    return filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');
}

$action = $_GET['action'] ?? '';

if ($action == 'export_expenses') {
    $month = export_month();
    $year = export_year();
    $category_filter = filter_input(INPUT_GET, 'category');
    $payment_filter = filter_input(INPUT_GET, 'payment_method');
    $card_filter = filter_input(INPUT_GET, 'card_id', FILTER_VALIDATE_INT);
    $start_date = filter_input(INPUT_GET, 'start');
    $end_date = filter_input(INPUT_GET, 'end');

    $query = "SELECT e.*, c.bank_name, c.card_name
FROM expenses e
LEFT JOIN cards c ON e.card_id = c.id AND c.tenant_id = e.tenant_id
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
    $query .= " ORDER BY e.expense_date DESC LIMIT 50000";
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($expenses) > 10000) {
            error_log("[Export] Large export: " . count($expenses) . " rows for tenant " . $_SESSION['tenant_id']);
        }

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="expenses_' . $month . '_' . $year . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        csv_row($output, [
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

            csv_row($output, [
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
        export_fail($e);
    }

} elseif ($action == 'export_income') {
    $month = export_month();
    $year = export_year();
    $category_filter = filter_input(INPUT_GET, 'category');
    $start_date = filter_input(INPUT_GET, 'start');
    $end_date = filter_input(INPUT_GET, 'end');

    $query = "SELECT income_date, description, category, amount, currency, is_recurring FROM income WHERE tenant_id = :tenant_id AND MONTH(income_date) = :month AND YEAR(income_date) = :year";
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
    $query .= " ORDER BY income_date DESC LIMIT 50000";
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $income = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($income) > 10000) {
            error_log("[Export] Large export: " . count($income) . " rows for tenant " . $_SESSION['tenant_id']);
        }

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="income_' . $month . '_' . $year . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        csv_row($output, ['Date', 'Description', 'Category', 'Amount', 'Currency', 'Recurring']);

        foreach ($income as $i) {
            $recurring = 'N/A';
            if (isset($i['is_recurring'])) {
                $recurring = $i['is_recurring'] ? 'Yes' : 'No';
            }

            csv_row($output, [
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
        export_fail($e);
    }
} elseif ($action == 'export_sadaqa') {
    $month = export_month();
    $year = export_year();

    $query = "SELECT * FROM sadaqa_tracker WHERE tenant_id = :tenant_id AND MONTH(sadaqa_date) = :month AND
        YEAR(sadaqa_date) = :year ORDER BY sadaqa_date DESC LIMIT 50000";
    $params = ['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year];

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($records) > 10000) {
            error_log("[Export] Large export: " . count($records) . " rows for tenant " . $_SESSION['tenant_id']);
        }

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="sadaqa_' . $month . '_' . $year . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        csv_row($output, ['Date', 'Title', 'Category', 'Amount']);

        foreach ($records as $r) {
            csv_row($output, [$r['sadaqa_date'], $r['title'], $r['category'] ?? 'General', $r['amount']]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        export_fail($e);
    }
} elseif ($action == 'export_incentives') {
    $month = export_month();
    $year = export_year();

    $query = "SELECT * FROM company_incentives WHERE tenant_id = :tenant_id AND MONTH(incentive_date) = :month AND
        YEAR(incentive_date) = :year ORDER BY incentive_date DESC LIMIT 50000";
    $params = ['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year];

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($records) > 10000) {
            error_log("[Export] Large export: " . count($records) . " rows for tenant " . $_SESSION['tenant_id']);
        }

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="incentives_' . $month . '_' . $year . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        csv_row($output, ['Date', 'Title', 'Amount']);

        foreach ($records as $r) {
            csv_row($output, [$r['incentive_date'], $r['title'], $r['amount']]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        export_fail($e);
    }
} elseif ($action == 'export_interest') {
    $month = export_month();
    $year = export_year();

    $query = "SELECT * FROM interest_tracker WHERE tenant_id = :tenant_id AND MONTH(interest_date) = :month AND
        YEAR(interest_date) = :year ORDER BY interest_date DESC LIMIT 50000";
    $params = ['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year];

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($records) > 10000) {
            error_log("[Export] Large export: " . count($records) . " rows for tenant " . $_SESSION['tenant_id']);
        }

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="interest_' . $month . '_' . $year . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        csv_row($output, ['Date', 'Title', 'Amount', 'Type']);

        foreach ($records as $r) {
            csv_row($output, [
                $r['interest_date'],
                $r['title'],
                abs($r['amount']),
                $r['amount'] < 0 ? 'Payment' : 'Interest'
            ]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        export_fail($e);
    }
} elseif ($action == 'export_zakath') {
    $query = "SELECT * FROM zakath_calculations WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT 50000";
    $params = ['tenant_id' => $_SESSION['tenant_id']];

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($records) > 10000) {
            error_log("[Export] Large export: " . count($records) . " rows for tenant " . $_SESSION['tenant_id']);
        }

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="zakath_calculations' . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        csv_row($output, [
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
            csv_row($output, [
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
        export_fail($e);
    }
} elseif ($action == 'export_reminders') {
    $query = "SELECT alert_date, title, recurrence_type FROM reminders WHERE tenant_id = :tenant_id ORDER BY alert_date ASC LIMIT 50000";
    $params = ['tenant_id' => $_SESSION['tenant_id']];

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($records) > 10000) {
            error_log("[Export] Large export: " . count($records) . " rows for tenant " . $_SESSION['tenant_id']);
        }

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="reminders' . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        csv_row($output, ['Alert Date', 'Title', 'Recurrence']);

        foreach ($records as $r) {
            csv_row($output, [$r['alert_date'], $r['title'], $r['recurrence_type']]);
        }
        fclose($output);
        exit();
    } catch (Exception $e) {
        export_fail($e);
    }
} elseif ($action == 'export_lending') {
    $query = "SELECT * FROM lending_tracker WHERE tenant_id = :tenant_id ORDER BY lent_date DESC LIMIT 50000";
    $params = ['tenant_id' => $_SESSION['tenant_id']];

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($records) > 10000) {
            error_log("[Export] Large export: " . count($records) . " rows for tenant " . $_SESSION['tenant_id']);
        }

        header(CONTENT_TYPE_CSV);
        header('Content-Disposition: attachment; filename="lending_records' . CSV_EXTENSION . '"');

        $output = fopen(PHP_OUTPUT, 'w');
        csv_row($output, ['Lent Date', 'Due Date', 'Borrower', 'Amount', 'Currency', 'Status', 'Notes']);

        foreach ($records as $r) {
            csv_row($output, [
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
        export_fail($e);
    }
}

// Unknown action
header("Location: dashboard.php");
exit();
