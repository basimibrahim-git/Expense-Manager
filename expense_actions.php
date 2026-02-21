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

if ($action == 'add_expense' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $amount = floatval($_POST['amount']);
    $date = htmlspecialchars($_POST['expense_date']);
    $desc = htmlspecialchars($_POST['description']);
    $category = htmlspecialchars($_POST['category']);
    $category = htmlspecialchars($_POST['category']);
    $method = htmlspecialchars($_POST['payment_method']);
    $is_sub = isset($_POST['is_subscription']) && $_POST['is_subscription'] == '1' ? 1 : 0;

    // Multi-Currency Logic
    $currency = $_POST['currency'] ?? 'AED';
    $exchange_rate = floatval($_POST['exchange_rate'] ?? 1.0);
    $tags = htmlspecialchars($_POST['tags'] ?? '');

    // Auto-Heal removed for security - schema updates handled by installer
    $tags = htmlspecialchars($_POST['tags'] ?? '');

    $final_amount = $amount;
    $original_amount = null;

    if ($currency !== 'AED') {
        $original_amount = $amount; // Store the foreign amount
        $final_amount = $amount * $exchange_rate; // Convert to AED
    }

    // ROI & Anatomy
    $cashback = floatval($_POST['cashback_earned'] ?? 0);
    $is_fixed = isset($_POST['is_fixed']) && $_POST['is_fixed'] == '1' ? 1 : 0;

    // Validate
    if ($final_amount <= 0 || empty($desc) || empty($category)) {
        header("Location: add_expense.php?error=Invalid input");
        exit();
    }

    $card_id = null;
    if ($method === 'Card') {
        $card_id = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
        if (!$card_id) {
            header("Location: add_expense.php?error=Please select a card");
            exit();
        }
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO expenses (user_id, amount, description, category, payment_method, card_id, expense_date, is_subscription, currency, original_amount, tags, cashback_earned, is_fixed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $final_amount, $desc, $category, $method, $card_id, $date, $is_sub, $currency, $original_amount, $tags, $cashback, $is_fixed]);

        // Deep Linking: Deduct from Bank Balance (ONLY for Debit cards)
        $deduct = isset($_POST['deduct_balance']) && $_POST['deduct_balance'] == '1';
        if ($deduct && $card_id) {
            // 1. Get Card Info - only deduct for DEBIT cards
            $cStmt = $pdo->prepare("SELECT bank_name, card_type, bank_id FROM cards WHERE id = ?");
            $cStmt->execute([$card_id]);
            $card_info = $cStmt->fetch();

            // Only proceed if it's a DEBIT card (not Credit)
            if ($card_info && strtolower($card_info['card_type']) === 'debit') {
                $bank_name = $card_info['bank_name'];
                $bank_id = $card_info['bank_id'];

                // 2. Get Latest Balance (Try bank_id first for precision, fallback to name)
                if ($bank_id) {
                    $bStmt = $pdo->prepare("SELECT amount FROM bank_balances WHERE user_id = ? AND (bank_id = ? OR bank_name = ?) ORDER BY balance_date DESC, id DESC LIMIT 1");
                    $bStmt->execute([$user_id, $bank_id, $bank_name]);
                } else {
                    $bStmt = $pdo->prepare("SELECT amount FROM bank_balances WHERE user_id = ? AND bank_name = ? ORDER BY balance_date DESC, id DESC LIMIT 1");
                    $bStmt->execute([$user_id, $bank_name]);
                }
                $current_bal = $bStmt->fetchColumn() ?: 0;

                // 3. New Balance
                $new_bal = $current_bal - $amount;

                // 4. Insert Snapshot
                $insStmt = $pdo->prepare("INSERT INTO bank_balances (user_id, bank_name, amount, balance_date, bank_id) VALUES (?, ?, ?, ?, ?)");
                $insStmt->execute([$user_id, $bank_name, $new_bal, $date, $bank_id]);
            }
            // Credit cards don't deduct from bank balance - they're a liability, not cash
        }

        // Check if user wants to add another
        $add_another = isset($_POST['add_another']) && $_POST['add_another'] == '1';
        $count = intval($_POST['add_count'] ?? 0) + 1;

        if ($add_another) {
            // Redirect back to add_expense with same date and count
            header("Location: add_expense.php?added=1&count=$count&date=$date");
        } else {
            header("Location: expenses.php?success=Expense added successfully");
        }
        exit();

    } catch (PDOException $e) {
        header("Location: add_expense.php?error=Failed to add expense: " . $e->getMessage());
        exit();
    }
} elseif ($action == 'delete_expense' && (isset($_POST['id']) || isset($_GET['id']))) {
    $id = filter_input(isset($_POST['id']) ? INPUT_POST : INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
    }
    header("Location: expenses.php?success=Deleted");
    exit();
} elseif ($action == 'delete_auto_expense' && (isset($_POST['id']) || isset($_GET['id']))) {
    // "Stop Tracking" just removes the subscription flag, keeping the expense record
    $id = filter_input(isset($_POST['id']) ? INPUT_POST : INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE expenses SET is_subscription = 0 WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
    }
    header("Location: subscriptions.php?success=Subscription removed");
    exit();
} elseif ($action == 'update_expense' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $expense_id = filter_input(INPUT_POST, 'expense_id', FILTER_VALIDATE_INT);

    if (!$expense_id) {
        header("Location: expenses.php?error=Invalid expense");
        exit();
    }

    $amount = floatval($_POST['amount']);
    $date = htmlspecialchars($_POST['expense_date']);
    $desc = htmlspecialchars($_POST['description']);
    $category = htmlspecialchars($_POST['category']);
    $method = htmlspecialchars($_POST['payment_method']);
    $tags = htmlspecialchars($_POST['tags'] ?? '');
    $is_sub = isset($_POST['is_subscription']) && $_POST['is_subscription'] == '1' ? 1 : 0;
    $cashback = floatval($_POST['cashback_earned'] ?? 0);
    $is_fixed = isset($_POST['is_fixed']) && $_POST['is_fixed'] == '1' ? 1 : 0;

    // Currency handling
    $currency = $_POST['currency'] ?? 'AED';
    $final_amount = $amount;
    $original_amount = null;

    if ($currency !== 'AED') {
        $original_amount = $amount;
        // Note: For edit, we'd need the exchange rate - default to keeping existing or 1:1
        $final_amount = $amount; // In edit mode, user enters the converted amount
    }

    $card_id = null;
    if ($method === 'Card') {
        $card_id = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
    }

    try {
        $stmt = $pdo->prepare("UPDATE expenses SET 
            amount = ?, description = ?, category = ?, payment_method = ?, 
            card_id = ?, expense_date = ?, is_subscription = ?, 
            currency = ?, original_amount = ?, tags = ?, 
            cashback_earned = ?, is_fixed = ?
            WHERE id = ? AND user_id = ?");
        $stmt->execute([
            $final_amount,
            $desc,
            $category,
            $method,
            $card_id,
            $date,
            $is_sub,
            $currency,
            $original_amount,
            $tags,
            $cashback,
            $is_fixed,
            $expense_id,
            $user_id
        ]);

        header("Location: edit_expense.php?id=$expense_id&success=Expense updated successfully");
        exit();

    } catch (PDOException $e) {
        header("Location: edit_expense.php?id=$expense_id&error=Failed to update: " . urlencode($e->getMessage()));
        exit();
    }
}

header("Location: expenses.php"); // Fallback
exit();
?>