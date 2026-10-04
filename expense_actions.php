<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;
use App\Helpers\BalanceHelper;
use App\Helpers\ExchangeRateHelper;

Bootstrap::init();

const EXPENSE_CATEGORIES = ['Grocery', 'Medical', 'Food', 'Utilities', 'Transport', 'Shopping', 'Entertainment', 'Travel', 'Education', 'Other'];
const EXPENSE_CURRENCIES = ['AED', 'USD', 'INR', 'EUR', 'GBP'];
const PAYMENT_METHODS    = ['Cash', 'Card']; // expenses.payment_method ENUM

/**
 * Rate from $cur to AED, cached per request.
 * Call fxPrewarm() BEFORE beginTransaction() so a slow rate-API call never runs
 * while the transaction holds row locks.
 */
function fxToAed(PDO $pdo, string $cur): float
{
    static $cache = ['AED' => 1.0];
    $cur = strtoupper($cur ?: 'AED');
    if (!isset($cache[$cur])) {
        $rate = ExchangeRateHelper::getRate($cur, 'AED', $pdo);
        $cache[$cur] = $rate > 0 ? $rate : 1.0;
    }
    return $cache[$cur];
}

/** Load every rate a balance move for this tenant could need (bank currencies + extras). */
function fxPrewarm(PDO $pdo, int $tenantId, array $currencies = []): void
{
    $stmt = $pdo->prepare("SELECT DISTINCT COALESCE(currency, 'AED') FROM banks WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
    foreach (array_merge($stmt->fetchAll(PDO::FETCH_COLUMN), $currencies) as $cur) {
        fxToAed($pdo, (string) $cur);
    }
}

/** Bank a debit card draws from (tenant-scoped), or null for credit cards / unknown banks. */
function expenseCardBankId(PDO $pdo, int $tenantId, ?array $card): ?int
{
    if (!$card || strtolower((string) $card['card_type']) !== 'debit') {
        return null;
    }
    if (!empty($card['bank_id'])) {
        return BalanceHelper::bank($pdo, $tenantId, (int) $card['bank_id']) ? (int) $card['bank_id'] : null;
    }
    // Legacy cards without bank_id: match the tenant's bank by name
    $stmt = $pdo->prepare("SELECT id FROM banks WHERE tenant_id = ? AND bank_name = ? ORDER BY is_default DESC, id ASC LIMIT 1");
    $stmt->execute([$tenantId, $card['bank_name']]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Move a bank balance by an expense row ($sign -1 = deduct, +1 = reverse).
 * The amount is expressed in the bank's currency: the entered amount when the
 * currencies match, otherwise the AED amount (converted when the bank is not AED).
 * $row needs amount (AED), original_amount, currency, expense_date.
 */
function expenseMoveBalance(PDO $pdo, int $tenantId, int $userId, int $bankId, array $row, int $sign): bool
{
    $bank = BalanceHelper::bank($pdo, $tenantId, $bankId);
    if (!$bank) {
        return false;
    }
    $bankCur  = strtoupper($bank['currency'] ?: 'AED');
    $entryCur = strtoupper($row['currency'] ?: 'AED');
    $aed      = (float) $row['amount'];

    if ($entryCur !== 'AED' && $row['original_amount'] !== null && $bankCur === $entryCur) {
        $value = (float) $row['original_amount'];
    } elseif ($bankCur === 'AED') {
        $value = $aed;
    } else {
        $value = $aed / fxToAed($pdo, $bankCur);
    }
    return BalanceHelper::adjust($pdo, $tenantId, $userId, $bankId, $sign * round($value, 2), $row['expense_date']);
}

/** fxPrewarm() only when one of these tenant expenses actually moved a bank balance. */
function expensePrewarmFor(PDO $pdo, int $tenantId, array $ids): void
{
    if (empty($ids)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT 1 FROM expenses WHERE id IN ($placeholders) AND tenant_id = ? AND balance_bank_id IS NOT NULL LIMIT 1");
    $stmt->execute(array_merge($ids, [$tenantId]));
    if ($stmt->fetchColumn()) {
        fxPrewarm($pdo, $tenantId);
    }
}

/** Reverse the balance effect of tenant expenses (by id) that recorded a balance_bank_id. Call inside a transaction. */
function expenseReverseBalances(PDO $pdo, int $tenantId, int $userId, array $ids): void
{
    if (empty($ids)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT amount, original_amount, currency, expense_date, balance_bank_id FROM expenses
                           WHERE id IN ($placeholders) AND tenant_id = ? AND balance_bank_id IS NOT NULL FOR UPDATE");
    $stmt->execute(array_merge($ids, [$tenantId]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        expenseMoveBalance($pdo, $tenantId, $userId, (int) $row['balance_bank_id'], $row, +1);
    }
}

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
    // Fallback date used only when a row does not carry its own date
    $defaultDateRaw = $_POST['expense_date'] ?? ($_POST['default_date'] ?? '');

    $method = trim($_POST['payment_method'] ?? '');
    if (!in_array($method, PAYMENT_METHODS, true)) {
        header("Location: add_expense.php?error=Invalid payment method");
        exit();
    }
    $currency = strtoupper(trim($_POST['currency'] ?? 'AED'));
    if (!in_array($currency, EXPENSE_CURRENCIES, true)) {
        header("Location: add_expense.php?error=Invalid currency");
        exit();
    }
    $exchange_rate = 1.0;
    if ($currency !== 'AED') {
        $rateRaw = trim((string) ($_POST['exchange_rate'] ?? ''));
        $exchange_rate = $rateRaw === '' ? ExchangeRateHelper::getRate($currency, 'AED', $pdo) : floatval($rateRaw);
        if ($exchange_rate <= 0) {
            header("Location: add_expense.php?error=" . urlencode("Please enter a valid exchange rate for $currency"));
            exit();
        }
    }
    $deduct = isset($_POST['deduct_balance']) && $_POST['deduct_balance'] == '1';

    $spent_by_raw = filter_input(INPUT_POST, 'spent_by_user_id', FILTER_VALIDATE_INT) ?: $user_id;
    if ($spent_by_raw !== $user_id) {
        $chkStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND tenant_id = ?");
        $chkStmt->execute([$spent_by_raw, $tenant_id]);
        $spent_by = $chkStmt->fetchColumn() ? $spent_by_raw : $user_id;
    } else {
        $spent_by = $user_id;
    }

    // --- Per-row expense data ---
    $rows = $_POST['expenses'] ?? [];
    if (empty($rows) || !is_array($rows)) {
        header("Location: add_expense.php?error=No expenses to save");
        exit();
    }

    // Preload this tenant's cards so each row's card can be validated without a query per row
    $cardMap = [];
    $cardsStmt = $pdo->prepare("SELECT id, bank_name, card_type, bank_id FROM cards WHERE tenant_id = ?");
    $cardsStmt->execute([$tenant_id]);
    foreach ($cardsStmt->fetchAll() as $c) {
        $cardMap[(int)$c['id']] = $c;
    }

    $validateDate = function ($raw) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) || !strtotime($raw)) return null;
        $y = (int)substr($raw, 0, 4);
        if ($y < 2000 || $y > 2100) return null;
        return $raw;
    };

    // --- Pass 1: normalise + validate every complete row (all-or-nothing) ---
    $prepared = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $amount   = floatval($row['amount'] ?? 0);
        $desc     = trim((string) ($row['description'] ?? ''));
        $category = trim((string) ($row['category'] ?? ''));

        // Skip blank/incomplete rows entirely
        if ($amount <= 0 || $desc === '' || $category === '') continue;

        if (!in_array($category, EXPENSE_CATEGORIES, true)) {
            header("Location: add_expense.php?error=" . urlencode("Invalid category on \"$desc\""));
            exit();
        }

        // Per-row date (fall back to the shared default)
        $rowDate = $validateDate($row['date'] ?? $defaultDateRaw);
        if ($rowDate === null) {
            header("Location: add_expense.php?error=" . urlencode("Invalid or missing date on \"$desc\""));
            exit();
        }

        // Per-row card (only when paying by card)
        $row_card_id = null;
        if ($method === 'Card') {
            $row_card_id = filter_var($row['card_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$row_card_id || !isset($cardMap[$row_card_id])) {
                header("Location: add_expense.php?error=" . urlencode("Please select a valid card for \"$desc\""));
                exit();
            }
        }

        $final_amount    = $amount;
        $original_amount = null;
        if ($currency !== 'AED') {
            $original_amount = $amount;
            $final_amount    = round($amount * $exchange_rate, 2);
        }

        $prepared[] = [
            'amount'          => $final_amount,
            'original_amount' => $original_amount,
            'desc'            => $desc,
            'category'        => $category,
            'tags'            => trim((string) ($row['tags'] ?? '')),
            'is_sub'          => isset($row['is_subscription']) ? 1 : 0,
            'is_fixed'        => isset($row['is_fixed']) ? 1 : 0,
            'cashback'        => floatval($row['cashback_earned'] ?? 0),
            'date'            => $rowDate,
            'card_id'         => $row_card_id,
        ];
    }

    if (empty($prepared)) {
        header("Location: add_expense.php?error=No valid expenses to save");
        exit();
    }

    try {
        // Resolve the bank each debit-card row draws from, and any FX rates, before the transaction starts
        $rowBank = [];
        if ($deduct) {
            foreach ($prepared as $i => $p) {
                $card_info = $p['card_id'] ? ($cardMap[$p['card_id']] ?? null) : null;
                $rowBank[$i] = expenseCardBankId($pdo, (int) $tenant_id, $card_info);
            }
            if (array_filter($rowBank)) {
                fxPrewarm($pdo, (int) $tenant_id, [$currency]);
            }
        }

        $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO expenses (user_id, tenant_id, spent_by_user_id, amount, description, category, payment_method, card_id, balance_bank_id, expense_date, is_subscription, currency, original_amount, tags, cashback_earned, is_fixed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $saved = 0;
        foreach ($prepared as $i => $p) {
            // Deduct from the debit card's bank (current balance, even for back-dated rows) and remember the bank for reversal
            $balance_bank_id = null;
            $bank_id = $rowBank[$i] ?? null;
            if ($bank_id) {
                $moveRow = ['amount' => $p['amount'], 'original_amount' => $p['original_amount'], 'currency' => $currency, 'expense_date' => $p['date']];
                if (expenseMoveBalance($pdo, (int) $tenant_id, (int) $user_id, $bank_id, $moveRow, -1)) {
                    $balance_bank_id = $bank_id;
                }
            }

            $stmt->execute([$user_id, $tenant_id, $spent_by, $p['amount'], $p['desc'], $p['category'], $method, $p['card_id'], $balance_bank_id, $p['date'], $p['is_sub'], $currency, $p['original_amount'], $p['tags'], $p['cashback'], $p['is_fixed']]);
            $saved++;
        }

        if ($saved === 0) {
            $pdo->rollBack();
            header("Location: add_expense.php?error=No valid expenses to save");
            exit();
        }

        $pdo->commit();
        AuditHelper::log($pdo, 'add_expense', "Added $saved expense(s)");
        $month = filter_input(INPUT_POST, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
        $year  = filter_input(INPUT_POST, 'year',  FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');
        header("Location: add_expense.php?added=$saved&month=$month&year=$year");
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
        try {
            expensePrewarmFor($pdo, (int) $tenant_id, [$id]);
            $pdo->beginTransaction();
            // Give back what this expense took from its bank (rows recorded before balance_bank_id existed are left alone)
            expenseReverseBalances($pdo, (int) $tenant_id, (int) $_SESSION['user_id'], [$id]);
            $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenant_id]);
            $pdo->commit();
            AuditHelper::log($pdo, 'delete_expense', "Deleted Expense ID: $id");
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Delete expense: " . $e->getMessage());
            header("Location: expenses.php?error=System error occurred while deleting.");
            exit();
        }
    }
    header("Location: expenses.php?success=Deleted");
    exit();
} elseif ($action == 'delete_auto_expense' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    // "Stop Tracking" just removes the subscription flag, keeping the expense records.
    // Subscriptions are grouped by description (see subscriptions.php), so clear the flag on
    // every row of that subscription — otherwise an older row would resurface as the template.
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($id) {
        $dStmt = $pdo->prepare("SELECT description FROM expenses WHERE id = ? AND tenant_id = ?");
        $dStmt->execute([$id, $tenant_id]);
        $subDesc = $dStmt->fetchColumn();
        if ($subDesc !== false) {
            $stmt = $pdo->prepare("UPDATE expenses SET is_subscription = 0 WHERE tenant_id = ? AND description = ? AND is_subscription = 1");
            $stmt->execute([$tenant_id, $subDesc]);
        }
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

                // Debit card subscriptions are deducted from the card's bank (logic mirrored from add_expense)
                $bank_id = null;
                $card_id = null;
                if ($tpl['payment_method'] === 'Card' && $tpl['card_id']) {
                    $cStmt = $pdo->prepare("SELECT id, bank_name, card_type, bank_id FROM cards WHERE id = ? AND tenant_id = ?");
                    $cStmt->execute([$tpl['card_id'], $tenant_id]);
                    $card_info = $cStmt->fetch() ?: null;
                    $card_id = $card_info ? (int) $card_info['id'] : null;
                    $bank_id = expenseCardBankId($pdo, (int) $tenant_id, $card_info);
                    if ($bank_id) {
                        fxPrewarm($pdo, (int) $tenant_id);
                    }
                }

                $pdo->beginTransaction();

                $balance_bank_id = null;
                if ($bank_id) {
                    $moveRow = ['amount' => $tpl['amount'], 'original_amount' => $tpl['original_amount'], 'currency' => $tpl['currency'], 'expense_date' => $today];
                    if (expenseMoveBalance($pdo, (int) $tenant_id, (int) $user_id, $bank_id, $moveRow, -1)) {
                        $balance_bank_id = $bank_id;
                    }
                }

                // Insert New Expense
                $insStmt = $pdo->prepare("
                    INSERT INTO expenses (
                        user_id, tenant_id, spent_by_user_id, amount, description,
                        category, payment_method, card_id, balance_bank_id, expense_date,
                        is_subscription, currency, original_amount, tags,
                        cashback_earned, is_fixed
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insStmt->execute([
                    $user_id,
                    $tenant_id,
                    $user_id,
                    $tpl['amount'],
                    $tpl['description'],
                    $tpl['category'],
                    $tpl['payment_method'],
                    $card_id,
                    $balance_bank_id,
                    $today,
                    1, // Still a subscription
                    $tpl['currency'],
                    $tpl['original_amount'],
                    $tpl['tags'],
                    $tpl['cashback_earned'],
                    $tpl['is_fixed']
                ]);

                $pdo->commit();

                AuditHelper::log($pdo, 'log_subscription', "Auto-Drafted Subscription: $desc ($amount AED)");
                $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'subscriptions.php');

                header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "success=Logged successfully");
                exit();
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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
    $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'expenses.php');
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    try {
        expensePrewarmFor($pdo, (int) $tenant_id, $ids);
        $pdo->beginTransaction();
        expenseReverseBalances($pdo, (int) $tenant_id, (int) $_SESSION['user_id'], $ids);
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id IN ($placeholders) AND tenant_id = ?");
        $stmt->execute(array_merge($ids, [$tenant_id]));
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Bulk delete expenses: " . $e->getMessage());
        header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "error=System error occurred while deleting.");
        exit();
    }
    AuditHelper::log($pdo, 'bulk_delete_expenses', "Bulk Deleted " . count($ids) . " Expenses. IDs: " . implode(',', $ids));

    header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "success=Bulk deleted");
    exit();
} elseif ($action == 'bulk_change_category' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_slice(array_filter(array_map('intval', $_POST['ids'])), 0, 500);
    $category = trim((string) ($_POST['category'] ?? ''));
    if (!in_array($category, EXPENSE_CATEGORIES, true)) {
        $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'expenses.php');
        header("Location: $redirect" . (strpos($redirect, '?') === false ? '?' : '&') . "error=Invalid category");
        exit();
    }
    if (!empty($ids)) {
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

    $editUrl = "edit_expense.php?id=$expense_id";
    $fail = function (string $msg) use ($editUrl) {
        header("Location: $editUrl&error=" . urlencode($msg));
        exit();
    };

    $oldStmt = $pdo->prepare("SELECT category, balance_bank_id FROM expenses WHERE id = ? AND tenant_id = ?");
    $oldStmt->execute([$expense_id, $tenant_id]);
    $old = $oldStmt->fetch();
    if (!$old) {
        header("Location: expenses.php?error=Expense not found");
        exit();
    }

    $amount = floatval($_POST['amount'] ?? 0);
    $dateRaw = (string) ($_POST['expense_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) || !strtotime($dateRaw)) {
        $fail("Invalid date format");
    }
    $year_check = (int)substr($dateRaw, 0, 4);
    if ($year_check < 2000 || $year_check > 2100) {
        $fail("Invalid year");
    }
    $date = $dateRaw;
    $desc = trim((string) ($_POST['description'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $method = trim((string) ($_POST['payment_method'] ?? ''));
    $tags = trim((string) ($_POST['tags'] ?? ''));
    $is_sub = isset($_POST['is_subscription']) && $_POST['is_subscription'] == '1' ? 1 : 0;
    $cashback = floatval($_POST['cashback_earned'] ?? 0);
    $is_fixed = isset($_POST['is_fixed']) && $_POST['is_fixed'] == '1' ? 1 : 0;

    if ($amount <= 0) {
        $fail("Amount must be greater than zero");
    }
    if ($desc === '') {
        $fail("Description is required");
    }
    // Allow the standard categories, or keeping the expense's existing (legacy) category
    if (!in_array($category, EXPENSE_CATEGORIES, true) && $category !== $old['category']) {
        $fail("Invalid category");
    }
    if (!in_array($method, PAYMENT_METHODS, true)) {
        $fail("Invalid payment method");
    }

    // Currency handling: the form posts the amount in the entry currency plus its rate to AED
    $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'AED')));
    if (!in_array($currency, EXPENSE_CURRENCIES, true)) {
        $fail("Invalid currency");
    }
    $final_amount = round($amount, 2);
    $original_amount = null;

    if ($currency !== 'AED') {
        $rateRaw = trim((string) ($_POST['exchange_rate'] ?? ''));
        $exchange_rate = $rateRaw === '' ? ExchangeRateHelper::getRate($currency, 'AED', $pdo) : floatval($rateRaw);
        if ($exchange_rate <= 0) {
            $fail("Please enter a valid exchange rate for $currency");
        }
        $original_amount = $amount;
        $final_amount = round($amount * $exchange_rate, 2);
    }

    // The card must belong to this tenant
    $card_id = null;
    $card_info = null;
    if ($method === 'Card') {
        $card_id = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT);
        if ($card_id) {
            $cStmt = $pdo->prepare("SELECT id, bank_name, card_type, bank_id FROM cards WHERE id = ? AND tenant_id = ?");
            $cStmt->execute([$card_id, $tenant_id]);
            $card_info = $cStmt->fetch() ?: null;
        }
        if (!$card_info) {
            $fail("Please select a valid card");
        }
    }

    try {
        // If this expense moved a bank balance, prepare the FX rates before the transaction
        $newBankId = null;
        if ($old['balance_bank_id'] !== null) {
            $newBankId = expenseCardBankId($pdo, (int) $tenant_id, $card_info);
            fxPrewarm($pdo, (int) $tenant_id);
        }

        $pdo->beginTransaction();

        $rowStmt = $pdo->prepare("SELECT amount, original_amount, currency, expense_date, balance_bank_id FROM expenses WHERE id = ? AND tenant_id = ? FOR UPDATE");
        $rowStmt->execute([$expense_id, $tenant_id]);
        $current = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            $pdo->rollBack();
            header("Location: expenses.php?error=Expense not found");
            exit();
        }

        // Reverse the original deduction, then deduct the new amount from the (debit) card's bank.
        // Legacy rows (balance_bank_id NULL) were never linked to a balance and are left alone.
        $balance_bank_id = null;
        if ($current['balance_bank_id'] !== null
            && expenseMoveBalance($pdo, (int) $tenant_id, (int) $user_id, (int) $current['balance_bank_id'], $current, +1)
            && $newBankId !== null) {
            $newRow = ['amount' => $final_amount, 'original_amount' => $original_amount, 'currency' => $currency, 'expense_date' => $date];
            if (expenseMoveBalance($pdo, (int) $tenant_id, (int) $user_id, $newBankId, $newRow, -1)) {
                $balance_bank_id = $newBankId;
            }
        }

        $stmt = $pdo->prepare("UPDATE expenses SET
            amount = ?, description = ?, category = ?, payment_method = ?,
            card_id = ?, balance_bank_id = ?, expense_date = ?, is_subscription = ?,
            currency = ?, original_amount = ?, tags = ?,
            cashback_earned = ?, is_fixed = ?
            WHERE id = ? AND tenant_id = ?");
        $stmt->execute([
            $final_amount,
            $desc,
            $category,
            $method,
            $card_id,
            $balance_bank_id,
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

        $pdo->commit();

        AuditHelper::log($pdo, 'update_expense', "Updated Expense: $desc ($final_amount AED) - ID: $expense_id");
        header("Location: edit_expense.php?id=$expense_id&success=Expense updated successfully");
        exit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Update expense: " . $e->getMessage());
        header("Location: edit_expense.php?id=$expense_id&error=System error occurred during update.");
        exit();
    }
}

header("Location: expenses.php"); // Fallback
exit();
