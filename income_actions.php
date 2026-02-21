<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
}

if ($action == 'add_income' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $amount = floatval($_POST['amount']);
    $currency = $_POST['currency'] ?? 'AED';
    $date = htmlspecialchars($_POST['income_date']);
    $desc = htmlspecialchars($_POST['description']);
    $category = htmlspecialchars($_POST['category']);

    // Foresight Fields
    $is_recurring = isset($_POST['is_recurring']) && $_POST['is_recurring'] == '1' ? 1 : 0;
    $recurrence_day = filter_input(INPUT_POST, 'recurrence_day', FILTER_VALIDATE_INT);
    if ($is_recurring && !$recurrence_day) {
        $recurrence_day = date('j', strtotime($date)); // Default to the selected date's day if not specified
    }

    if ($amount <= 0 || empty($desc)) {
        header("Location: add_income.php?error=Invalid input");
        exit();
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO income (user_id, amount, description, category, income_date, is_recurring, recurrence_day, currency) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $amount, $desc, $category, $date, $is_recurring, $recurrence_day, $currency]);

        // Added: Increment Bank Balance if requested
        $add_to_balance = isset($_POST['add_to_balance']) && $_POST['add_to_balance'] == '1' ? 1 : 0;
        $bank_id = filter_input(INPUT_POST, 'bank_id', FILTER_VALIDATE_INT);

        if ($add_to_balance && $bank_id) {
            // 1. Get bank name from managed banks
            $bstmt = $pdo->prepare("SELECT bank_name FROM banks WHERE id = ? AND user_id = ?");
            $bstmt->execute([$bank_id, $user_id]);
            $bank_info = $bstmt->fetch();

            if ($bank_info) {
                $bank_name = $bank_info['bank_name'];

                // 2. Get latest balance recorded for this specific bank (using name-based matching for compatibility)
                $lstmt = $pdo->prepare("SELECT amount FROM bank_balances WHERE (bank_id = ? OR bank_name = ?) AND user_id = ? ORDER BY balance_date DESC, id DESC LIMIT 1");
                $lstmt->execute([$bank_id, $bank_name, $user_id]);
                $last_balance = $lstmt->fetchColumn() ?: 0;

                // 3. Auto-record new balance snapshot
                $new_balance = $last_balance + $amount;
                $istmt = $pdo->prepare("INSERT INTO bank_balances (user_id, bank_name, amount, balance_date, bank_id) VALUES (?, ?, ?, ?, ?)");
                $istmt->execute([$user_id, $bank_name, $new_balance, $date, $bank_id]);
            }
        }

        $month = date('n', strtotime($date));
        $year = date('Y', strtotime($date));
        log_audit('add_income', "Added Income: $desc ($amount $currency)");
        header("Location: monthly_income.php?month=$month&year=$year&success=Income recorded and balance updated");
        exit();
    } catch (PDOException $e) {
        // Auto-fix for missing currency column (Error 1054 / 42S22)
        if ($e->getCode() == '42S22' || strpos($e->getMessage(), "Unknown column 'currency'") !== false) {
            try {
                // Add the missing column
                $pdo->exec("ALTER TABLE income ADD COLUMN currency VARCHAR(3) DEFAULT 'AED'");

                // Retry the insert
                $stmt = $pdo->prepare("INSERT INTO income (user_id, amount, description, category, income_date, is_recurring, recurrence_day, currency) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $amount, $desc, $category, $date, $is_recurring, $recurrence_day, $currency]);

                // Success redirect
                $month = date('n', strtotime($date));
                $year = date('Y', strtotime($date));
                header("Location: monthly_income.php?month=$month&year=$year&success=Income recorded (Database schema updated seamlessly)");
                exit();

            } catch (Exception $ex) {
                // If auto-fix fails, show original error
                header("Location: add_income.php?error=Failed to add income: " . urlencode($e->getMessage()));
                exit();
            }
        }

        header("Location: add_income.php?error=Failed to add income: " . urlencode($e->getMessage()));
        exit();
    }

} elseif ($action == 'delete_income' && (isset($_POST['id']) || isset($_GET['id']))) {
    $id = filter_input(isset($_POST['id']) ? INPUT_POST : INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM income WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
        log_audit('delete_income', "Deleted Income ID: $id");
    }
    // Try to return to where they were
    header("Location: income.php?success=Deleted");
    exit();
} elseif ($action == 'bulk_delete' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    if (!empty($ids)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM income WHERE id IN ($placeholders) AND user_id = ?");
        $stmt->execute(array_merge($ids, [$_SESSION['user_id']]));
        log_audit('bulk_delete_income', "Bulk Deleted " . count($ids) . " Income records. IDs: " . implode(',', $ids));
    }
    $redirect = $_SERVER['HTTP_REFERER'] ?? 'income.php';
    header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "success=Bulk deleted");
    exit();
} elseif ($action == 'bulk_change_category' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    $category = htmlspecialchars($_POST['category']);
    if (!empty($ids) && !empty($category)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("UPDATE income SET category = ? WHERE id IN ($placeholders) AND user_id = ?");
        $stmt->execute(array_merge([$category], $ids, [$_SESSION['user_id']]));
        log_audit('bulk_change_income_category', "Bulk Changed Category to $category for " . count($ids) . " Income records. IDs: " . implode(',', $ids));
    }
    $redirect = $_SERVER['HTTP_REFERER'] ?? 'income.php';
    header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "success=Bulk category updated");
    exit();
} elseif ($action == 'update_income' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $income_id = filter_input(INPUT_POST, 'income_id', FILTER_VALIDATE_INT);

    if (!$income_id) {
        header("Location: income.php?error=Invalid income");
        exit();
    }

    $amount = floatval($_POST['amount']);
    $currency = $_POST['currency'] ?? 'AED';
    $date = htmlspecialchars($_POST['income_date']);
    $desc = htmlspecialchars($_POST['description']);
    $category = htmlspecialchars($_POST['category']);
    $is_recurring = isset($_POST['is_recurring']) && $_POST['is_recurring'] == '1' ? 1 : 0;
    $recurrence_day = filter_input(INPUT_POST, 'recurrence_day', FILTER_VALIDATE_INT);

    if ($is_recurring && !$recurrence_day) {
        $recurrence_day = date('j', strtotime($date));
    }

    if ($amount <= 0 || empty($desc)) {
        header("Location: edit_income.php?id=$income_id&error=Invalid input");
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE income SET amount = ?, description = ?, category = ?, income_date = ?, is_recurring = ?, recurrence_day = ?, currency = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$amount, $desc, $category, $date, $is_recurring, $recurrence_day, $currency, $income_id, $user_id]);

        log_audit('update_income', "Updated Income: $desc ($amount $currency) - ID: $income_id");
        header("Location: edit_income.php?id=$income_id&success=Income updated successfully");
        exit();

    } catch (PDOException $e) {
        // Auto-fix for missing currency column (Error 1054 / 42S22)
        if ($e->getCode() == '42S22' || strpos($e->getMessage(), "Unknown column 'currency'") !== false) {
            try {
                // Add the missing column
                $pdo->exec("ALTER TABLE income ADD COLUMN currency VARCHAR(3) DEFAULT 'AED'");

                // Retry the update
                $stmt = $pdo->prepare("UPDATE income SET amount = ?, description = ?, category = ?, income_date = ?, is_recurring = ?, recurrence_day = ?, currency = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$amount, $desc, $category, $date, $is_recurring, $recurrence_day, $currency, $income_id, $user_id]);

                header("Location: edit_income.php?id=$income_id&success=Income updated successfully");
                exit();
            } catch (Exception $ex) {
                header("Location: edit_income.php?id=$income_id&error=Failed to update: " . urlencode($e->getMessage()));
                exit();
            }
        }

        header("Location: edit_income.php?id=$income_id&error=Failed to update: " . urlencode($e->getMessage()));
        exit();
    }
}

header("Location: income.php");
exit();
?>