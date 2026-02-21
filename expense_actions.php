<?php
require_once 'config.php'; // NOSONAR

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // Permission Check: Read-Only users cannot perform POST actions
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        $redirect = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
        header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "error=Unauthorized: Read-only access");
        exit();
    }
}

$tenant_id = $_SESSION['tenant_id'];

if ($action == 'add_expense' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $amount = floatval($_POST['amount']);
    $date = htmlspecialchars($_POST['expense_date']);
    $desc = htmlspecialchars($_POST['description']);
    $category = htmlspecialchars($_POST['category']);
    $method = htmlspecialchars($_POST['payment_method']);
    $is_sub = isset($_POST['is_subscription']) && $_POST['is_subscription'] == '1' ? 1 : 0;
    $spent_by = filter_input(INPUT_POST, 'spent_by_user_id', FILTER_VALIDATE_INT) ?: $user_id;

    // Multi-Currency Logic
    $currency = $_POST['currency'] ?? 'AED';
    $exchange_rate = floatval($_POST['exchange_rate'] ?? 1.0);
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
        $stmt = $pdo->prepare("INSERT INTO expenses (user_id, tenant_id, spent_by_user_id, amount, description, category, payment_method, card_id, expense_date, is_subscription, currency, original_amount, tags, cashback_earned, is_fixed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $tenant_id, $spent_by, $final_amount, $desc, $category, $method, $card_id, $date, $is_sub, $currency, $original_amount, $tags, $cashback, $is_fixed]);

        // Deep Linking: Deduct from Bank Balance (ONLY for Debit cards)
        $deduct = isset($_POST['deduct_balance']) && $_POST['deduct_balance'] == '1';
        if ($deduct && $card_id) {
            // 1. Get Card Info - only deduct for DEBIT cards
            $cStmt = $pdo->prepare("SELECT bank_name, card_type, bank_id FROM cards WHERE id = ? AND tenant_id = ?");
            $cStmt->execute([$card_id, $tenant_id]);
            $card_info = $cStmt->fetch();

            // Only proceed if it's a DEBIT card (not Credit)
            if ($card_info && strtolower($card_info['card_type']) === 'debit') {
                $bank_name = $card_info['bank_name'];
                $bank_id = $card_info['bank_id'];

                // 2. Get Latest Balance (Try bank_id first for precision, fallback to name)
                if ($bank_id) {
                    $bStmt = $pdo->prepare("SELECT amount FROM bank_balances WHERE tenant_id = ? AND (bank_id = ? OR bank_name = ?) ORDER BY balance_date DESC, id DESC LIMIT 1");
                    $bStmt->execute([$tenant_id, $bank_id, $bank_name]);
                } else {
                    $bStmt = $pdo->prepare("SELECT amount FROM bank_balances WHERE tenant_id = ? AND bank_name = ? ORDER BY balance_date DESC, id DESC LIMIT 1");
                    $bStmt->execute([$tenant_id, $bank_name]);
                }
                $current_bal = $bStmt->fetchColumn() ?: 0;

                // 3. New Balance
                $new_bal = $current_bal - $amount;

                // 4. Insert Snapshot
                $insStmt = $pdo->prepare("INSERT INTO bank_balances (user_id, tenant_id, bank_name, amount, balance_date, bank_id) VALUES (?, ?, ?, ?, ?, ?)");
                $insStmt->execute([$user_id, $tenant_id, $bank_name, $new_bal, $date, $bank_id]);
            }
            // Credit cards don't deduct from bank balance - they're a liability, not cash
        }

        // Check if user wants to add another
        $add_another = isset($_POST['add_another']) && $_POST['add_another'] == '1';
        $count = intval($_POST['add_count'] ?? 0) + 1;

        if ($add_another) {
            log_audit('add_expense', "Added Expense: $desc ($final_amount AED)");
            header("Location: add_expense.php?added=1&count=$count&date=$date");
        } else {
            log_audit('add_expense', "Added Expense: $desc ($final_amount AED)");
            header("Location: expenses.php?success=Expense added successfully");
        }
        exit();

    } catch (PDOException $e) {
        header("Location: add_expense.php?error=Failed to add expense: " . $e->getMessage());
        exit();
    }
} elseif ($action == 'delete_expense' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenant_id]);
        log_audit('delete_expense', "Deleted Expense ID: $id");
    }
    header("Location: expenses.php?success=Deleted");
    exit();
} elseif ($action == 'delete_auto_expense' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    // "Stop Tracking" just removes the subscription flag, keeping the expense record
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE expenses SET is_subscription = 0 WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenant_id]);
    }
    header("Location: subscriptions.php?success=Subscription removed");
    exit();
} elseif ($action == 'log_subscription' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['template_id'])) {
    $template_id = filter_input(INPUT_POST, 'template_id', FILTER_VALIDATE_INT);
    $user_id = $_SESSION['user_id'];
    $tenant_id = $_SESSION['tenant_id'];

    if ($template_id) {
        try {
            // 1. Fetch Template
            $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$template_id, $tenant_id]);
            $tpl = $stmt->fetch();

            if ($tpl) {
                $today = date('Y-m-d');
                $desc = $tpl['description'];
                $amount = $tpl['amount'];

                // 2. Insert New Expense
                $insStmt = $pdo->prepare("
                    INSERT INTO expenses (
                        user_id, tenant_id, spent_by_user_id, amount, description,
                        category, payment_method, card_id, expense_date,
                        is_subscription, currency, original_amount, tags,
                        cashback_earned, is_fixed
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insStmt->execute([
                    $user_id,
                    $tenant_id,
                    $user_id,
                    $tpl['amount'],
                    $tpl['description'],
                    $tpl['category'],
                    $tpl['payment_method'],
                    $tpl['card_id'],
                    $today,
                    1, // Still a subscription
                    $tpl['currency'],
                    $tpl['original_amount'],
                    $tpl['tags'],
                    $tpl['cashback_earned'],
                    $tpl['is_fixed']
                ]);

                // 3. Optional: Deduct Balance if it was a Debit card (logic mirrored from add_expense)
                if ($tpl['payment_method'] === 'Card' && $tpl['card_id']) {
                    $cStmt = $pdo->prepare("SELECT bank_name, card_type, bank_id FROM cards WHERE id = ? AND tenant_id = ?");
                    $cStmt->execute([$tpl['card_id'], $tenant_id]);
                    $card_info = $cStmt->fetch();

                    if ($card_info && strtolower($card_info['card_type']) === 'debit') {
                        $bank_id = $card_info['bank_id'];
                        $bank_name = $card_info['bank_name'];

                        // Get latest balance
                        if ($bank_id) {
                            $bStmt = $pdo->prepare("SELECT amount FROM bank_balances WHERE tenant_id = ? AND (bank_id = ? OR bank_name = ?) ORDER BY balance_date DESC, id DESC LIMIT 1");
                            $bStmt->execute([$tenant_id, $bank_id, $bank_name]);
                        } else {
                            $bStmt = $pdo->prepare("SELECT amount FROM bank_balances WHERE tenant_id = ? AND bank_name = ? ORDER BY balance_date DESC, id DESC LIMIT 1");
                            $bStmt->execute([$tenant_id, $bank_name]);
                        }
                        $current_bal = $bStmt->fetchColumn() ?: 0;
                        $new_bal = $current_bal - $tpl['amount'];

                        // Insert Snapshot
                        $balIns = $pdo->prepare("INSERT INTO bank_balances (user_id, tenant_id, bank_name, amount, balance_date, bank_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $balIns->execute([$user_id, $tenant_id, $bank_name, $new_bal, $today, $bank_id]);
                    }
                }

                log_audit('log_subscription', "Auto-Drafted Subscription: $desc ($amount AED)");
                $redirect = $_SERVER['HTTP_REFERER'] ?? 'subscriptions.php';
                header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "success=Logged successfully");
                exit();
            }
        } catch (Exception $e) {
            error_log("Auto-Draft Error: " . $e->getMessage());
            header("Location: subscriptions.php?error=Auto-draft failed");
            exit();
        }
    }
    header("Location: subscriptions.php");
    exit();
} elseif ($action == 'bulk_delete' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    if (!empty($ids)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id IN ($placeholders) AND tenant_id = ?");
        $stmt->execute(array_merge($ids, [$tenant_id]));
        log_audit('bulk_delete_expenses', "Bulk Deleted " . count($ids) . " Expenses. IDs: " . implode(',', $ids));
    }
    $redirect = $_SERVER['HTTP_REFERER'] ?? 'expenses.php';
    header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "success=Bulk deleted");
    exit();
} elseif ($action == 'bulk_change_category' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    $category = htmlspecialchars($_POST['category']);
    if (!empty($ids) && !empty($category)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("UPDATE expenses SET category = ? WHERE id IN ($placeholders) AND tenant_id = ?");
        $stmt->execute(array_merge([$category], $ids, [$tenant_id]));
        log_audit('bulk_change_category', "Bulk Changed Category to $category for " . count($ids) . " Expenses. IDs: " . implode(',', $ids));
    }
    $redirect = $_SERVER['HTTP_REFERER'] ?? 'expenses.php';
    header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "success=Bulk category updated");
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
        // Logic: Use exchange rate to get the AED value if provided, else keep as is to avoid drift
        $exchange_rate = floatval($_POST['exchange_rate'] ?? 0);
        if ($exchange_rate > 0) {
            $final_amount = $amount * $exchange_rate;
        } else {
            // If no rate provided in edit, we assume 'amount' IS the AED amount (legacy behavior)
            // or the user manually entered the converted value.
            $final_amount = $amount;
        }
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
            WHERE id = ? AND tenant_id = ?");
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
            $tenant_id
        ]);

        log_audit('update_expense', "Updated Expense: $desc ($final_amount AED) - ID: $expense_id");
        header("Location: edit_expense.php?id=$expense_id&success=Expense updated successfully");
        exit();

    } catch (PDOException $e) {
        header("Location: edit_expense.php?id=$expense_id&error=Failed to update: " . urlencode($e->getMessage()));
        exit();
    }
}

header("Location: expenses.php"); // Fallback
exit();
