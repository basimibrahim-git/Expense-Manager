<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;

Bootstrap::init();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    // Permission Check: Read-Only users cannot perform POST actions
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'dashboard.php');

        header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "error=Unauthorized: Read-only access");
        exit();
    }
}

$tenant_id = $_SESSION['tenant_id'];

if ($action == 'add_expense' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];

    // --- Shared fields ---
    $dateRaw = $_POST['expense_date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) || !strtotime($dateRaw)) {
        header("Location: add_expense.php?error=Invalid date format");
        exit();
    }
    $year_check = (int)substr($dateRaw, 0, 4);
    if ($year_check < 2000 || $year_check > 2100) {
        header("Location: add_expense.php?error=Invalid year");
        exit();
    }
    $date = $dateRaw;

    $method = trim($_POST['payment_method'] ?? '');
    $currency = trim($_POST['currency'] ?? 'AED');
    $exchange_rate = floatval($_POST['exchange_rate'] ?? 1.0);
    $deduct = isset($_POST['deduct_balance']) && $_POST['deduct_balance'] == '1';

    $spent_by_raw = filter_input(INPUT_POST, 'spent_by_user_id', FILTER_VALIDATE_INT) ?: $user_id;
    if ($spent_by_raw !== $user_id) {
        $chkStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND tenant_id = ?");
        $chkStmt->execute([$spent_by_raw, $tenant_id]);
        $spent_by = $chkStmt->fetchColumn() ? $spent_by_raw : $user_id;
    } else {
        $spent_by = $user_id;
    }

    // Card validation (shared across all rows)
    $card_id = null;
    $card_info = null;
    if ($method === 'Card') {
        $card_id = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
        if (!$card_id) {
            header("Location: add_expense.php?error=Please select a card");
            exit();
        }
        $cardCheck = $pdo->prepare("SELECT id, bank_name, card_type, bank_id FROM cards WHERE id = ? AND tenant_id = ?");
        $cardCheck->execute([$card_id, $tenant_id]);
        $card_info = $cardCheck->fetch();
        if (!$card_info) {
            header("Location: add_expense.php?error=Invalid card selected");
            exit();
        }
    }

    // --- Per-row expense data ---
    $rows = $_POST['expenses'] ?? [];
    if (empty($rows) || !is_array($rows)) {
        header("Location: add_expense.php?error=No expenses to save");
        exit();
    }

    try {
        $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO expenses (user_id, tenant_id, spent_by_user_id, amount, description, category, payment_method, card_id, expense_date, is_subscription, currency, original_amount, tags, cashback_earned, is_fixed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $saved = 0;
        foreach ($rows as $row) {
            $amount    = floatval($row['amount'] ?? 0);
            $desc      = trim($row['description'] ?? '');
            $category  = trim($row['category'] ?? '');
            $tags      = trim($row['tags'] ?? '');
            $is_sub    = isset($row['is_subscription']) ? 1 : 0;
            $is_fixed  = isset($row['is_fixed']) ? 1 : 0;
            $cashback  = floatval($row['cashback_earned'] ?? 0);

            if ($amount <= 0 || empty($desc) || empty($category)) continue;

            $final_amount   = $amount;
            $original_amount = null;
            if ($currency !== 'AED') {
                $original_amount = $amount;
                $final_amount    = $amount * $exchange_rate;
            }

            $stmt->execute([$user_id, $tenant_id, $spent_by, $final_amount, $desc, $category, $method, $card_id, $date, $is_sub, $currency, $original_amount, $tags, $cashback, $is_fixed]);
            $saved++;

            // Deduct from bank balance for debit cards — re-query balance each iteration so deductions stack correctly
            if ($deduct && $card_info && strtolower($card_info['card_type']) === 'debit') {
                $bank_name = $card_info['bank_name'];
                $bank_id   = $card_info['bank_id'];

                if ($bank_id) {
                    $bStmt = $pdo->prepare("SELECT amount FROM bank_balances WHERE tenant_id = ? AND (bank_id = ? OR bank_name = ?) ORDER BY balance_date DESC, id DESC LIMIT 1");
                    $bStmt->execute([$tenant_id, $bank_id, $bank_name]);
                } else {
                    $bStmt = $pdo->prepare("SELECT amount FROM bank_balances WHERE tenant_id = ? AND bank_name = ? ORDER BY balance_date DESC, id DESC LIMIT 1");
                    $bStmt->execute([$tenant_id, $bank_name]);
                }
                $current_bal = $bStmt->fetchColumn() ?: 0;
                $new_bal     = $current_bal - $final_amount;

                $insStmt = $pdo->prepare("INSERT INTO bank_balances (user_id, tenant_id, bank_name, amount, balance_date, bank_id) VALUES (?, ?, ?, ?, ?, ?)");
                $insStmt->execute([$user_id, $tenant_id, $bank_name, $new_bal, $date, $bank_id]);
            }
        }

        if ($saved === 0) {
            $pdo->rollBack();
            header("Location: add_expense.php?error=No valid expenses to save");
            exit();
        }

        $pdo->commit();
        AuditHelper::log($pdo, 'add_expense', "Added $saved expense(s) on $date");
        $month = filter_input(INPUT_POST, 'month', FILTER_VALIDATE_INT) ?: date('n');
        $year  = filter_input(INPUT_POST, 'year',  FILTER_VALIDATE_INT) ?: date('Y');
        header("Location: add_expense.php?added=$saved&date=" . urlencode($date) . "&month=$month&year=$year");
        exit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Bulk expense insert: " . $e->getMessage());
        header("Location: add_expense.php?error=System error occurred during expense processing.");
        exit();
    }
} elseif ($action == 'delete_expense' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenant_id]);
        AuditHelper::log($pdo, 'delete_expense', "Deleted Expense ID: $id");
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
            $stmt = $pdo->prepare("SELECT description, amount, category, payment_method, card_id, currency, original_amount, tags, cashback_earned, is_fixed FROM expenses WHERE id = ? AND tenant_id = ?");
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

                AuditHelper::log($pdo, 'log_subscription', "Auto-Drafted Subscription: $desc ($amount AED)");
                $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'subscriptions.php');

                header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "success=Logged successfully");
                exit();
            }
        } catch (Exception $e) {
            error_log("Auto-Draft Error: " . $e->getMessage());
            header("Location: subscriptions.php?error=System error during auto-draft.");
            exit();
        }
    }
    header("Location: subscriptions.php");
    exit();
} elseif ($action == 'bulk_delete' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_slice(array_map('intval', (array)($_POST['ids'] ?? [])), 0, 500);
    if (empty($ids)) {
        $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'expenses.php');
        header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "error=No valid IDs provided");
        exit();
    }
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $stmt = $pdo->prepare("DELETE FROM expenses WHERE id IN ($placeholders) AND tenant_id = ?");
    $stmt->execute(array_merge($ids, [$tenant_id]));
    AuditHelper::log($pdo, 'bulk_delete_expenses', "Bulk Deleted " . count($ids) . " Expenses. IDs: " . implode(',', $ids));
    $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'expenses.php');

    header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "success=Bulk deleted");
    exit();
} elseif ($action == 'bulk_change_category' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    $category = trim($_POST['category'] ?? '');
    if (!empty($ids) && !empty($category)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("UPDATE expenses SET category = ? WHERE id IN ($placeholders) AND tenant_id = ?");
        $stmt->execute(array_merge([$category], $ids, [$tenant_id]));
        AuditHelper::log($pdo, 'bulk_change_category', "Bulk Changed Category to $category for " . count($ids) . " Expenses. IDs: " . implode(',', $ids));
    }
    $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'expenses.php');

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
    $dateRaw = $_POST['expense_date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) || !strtotime($dateRaw)) {
        header("Location: edit_expense.php?id=$expense_id&error=Invalid date format");
        exit();
    }
    $year_check = (int)substr($dateRaw, 0, 4);
    if ($year_check < 2000 || $year_check > 2100) {
        header("Location: add_expense.php?error=Invalid year");
        exit();
    }
    $date = $dateRaw;
    $desc = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $method = trim($_POST['payment_method'] ?? '');
    $tags = trim($_POST['tags'] ?? '');
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

        AuditHelper::log($pdo, 'update_expense', "Updated Expense: $desc ($final_amount AED) - ID: $expense_id");
        header("Location: edit_expense.php?id=$expense_id&success=Expense updated successfully");
        exit();

    } catch (PDOException $e) {
        header("Location: edit_expense.php?id=$expense_id&error=System error occurred during update.");
        exit();
    }
}

header("Location: expenses.php"); // Fallback
exit();
