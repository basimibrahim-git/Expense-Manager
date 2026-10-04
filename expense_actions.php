<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;
use App\Helpers\BalanceHelper;
use App\Helpers\ExchangeRateHelper;
use App\Helpers\Flash;
use App\Helpers\Categories;
use App\Helpers\SplitHelper;

Bootstrap::init();

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

/** True once migrations/2026_10_05_family_split.sql has been run. Call outside a transaction. */
function expenseSplitReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $pdo->query("SELECT 1 FROM expense_splits LIMIT 0");
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Read the "Split with family" fields of the add/edit forms.
 * Returns null when splitting is off, an error string when invalid, or
 * ['mode' => 'equal'|'custom', 'users' => int[], 'amounts' => array<int, float>].
 * Custom amounts are typed in the expense's entry currency.
 *
 * @return array|string|null
 */
function expenseParseSplit(PDO $pdo, int $tenantId)
{
    if (($_POST['split_enabled'] ?? '') !== '1') {
        return null;
    }
    if (!expenseSplitReady($pdo)) {
        return 'Family split is not set up yet (database migration pending)';
    }
    $mode = ($_POST['split_mode'] ?? 'equal') === 'custom' ? 'custom' : 'equal';
    $ids  = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['split_users'] ?? [])),
        fn($v) => $v > 0
    )));
    if (empty($ids)) {
        return 'Pick the family members to split with';
    }
    if (count($ids) > 50) {
        return 'Too many split members';
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$tenantId], $ids));
    if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) {
        return 'Split members must belong to your family';
    }

    $amounts = [];
    if ($mode === 'custom') {
        $raw = (array) ($_POST['split_amounts'] ?? []);
        foreach ($ids as $uid) {
            $v = trim((string) ($raw[$uid] ?? ''));
            if ($v === '') {
                $v = '0';
            }
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > 1e12) {
                return 'Split amounts must be zero or more';
            }
            $amounts[$uid] = round((float) $v, 2);
        }
    }
    return ['mode' => $mode, 'users' => $ids, 'amounts' => $amounts];
}

/**
 * AED shares for one expense, or an error string.
 * $entered is the amount in the entry currency (what custom amounts must add up to),
 * $aed the stored AED amount the shares are expressed in.
 *
 * @return array<int, float>|string
 */
function expenseSplitShares(array $split, float $entered, float $aed, int $payerId, string $desc)
{
    if ($split['mode'] === 'custom') {
        if (!SplitHelper::customSumMatches($split['amounts'], $entered)) {
            return 'Custom split amounts for "' . $desc . '" must add up to ' . number_format($entered, 2)
                . ' (they add up to ' . number_format(array_sum($split['amounts']), 2) . ')';
        }
        $shares = SplitHelper::scaleShares($split['amounts'], $aed, $payerId);
    } else {
        $shares = SplitHelper::equalShares($aed, $split['users'], $payerId);
    }
    foreach ($shares as $uid => $amt) {
        if ($uid !== $payerId && $amt > 0) {
            return $shares;
        }
    }
    return 'A split needs at least one family member other than the person who paid';
}

/** Store the shares of one expense (replacing any previous ones). Call inside the expense's transaction. */
function expenseWriteSplits(PDO $pdo, int $tenantId, int $expenseId, ?array $shares, bool $replace): void
{
    if ($replace) {
        $pdo->prepare("DELETE FROM expense_splits WHERE expense_id = ? AND tenant_id = ?")->execute([$expenseId, $tenantId]);
    }
    if (!$shares) {
        return;
    }
    $ins = $pdo->prepare("INSERT INTO expense_splits (tenant_id, expense_id, user_id, share_amount) VALUES (?, ?, ?, ?)");
    foreach ($shares as $uid => $amt) {
        if ($amt > 0) {
            $ins->execute([$tenantId, $expenseId, (int) $uid, $amt]);
        }
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
        Flash::redirect($redirect, 'error', 'Unauthorized: Read-only access');
    }
}

$tenant_id = $_SESSION['tenant_id'];

if ($action == 'add_expense' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $addMonth = filter_input(INPUT_POST, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
    $addYear  = filter_input(INPUT_POST, 'year',  FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');
    $addUrl   = "add_expense.php?month=$addMonth&year=$addYear";

    // --- Shared fields ---
    // Fallback date used only when a row does not carry its own date
    $defaultDateRaw = $_POST['expense_date'] ?? ($_POST['default_date'] ?? '');

    $method = trim($_POST['payment_method'] ?? '');
    if (!in_array($method, PAYMENT_METHODS, true)) {
        Flash::redirect($addUrl, 'error', 'Invalid payment method');
    }
    $currency = strtoupper(trim($_POST['currency'] ?? 'AED'));
    if (!in_array($currency, EXPENSE_CURRENCIES, true)) {
        Flash::redirect($addUrl, 'error', 'Invalid currency');
    }
    $exchange_rate = 1.0;
    if ($currency !== 'AED') {
        $rateRaw = trim((string) ($_POST['exchange_rate'] ?? ''));
        $exchange_rate = $rateRaw === '' ? ExchangeRateHelper::getRate($currency, 'AED', $pdo) : floatval($rateRaw);
        if ($exchange_rate <= 0) {
            Flash::redirect($addUrl, 'error', "Please enter a valid exchange rate for $currency");
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

    // --- Optional family split (applies to every row) ---
    $split = expenseParseSplit($pdo, (int) $tenant_id);
    if (is_string($split)) {
        Flash::redirect($addUrl, 'error', $split);
    }

    // --- Per-row expense data ---
    $rows = $_POST['expenses'] ?? [];
    if (empty($rows) || !is_array($rows)) {
        Flash::redirect($addUrl, 'error', 'No expenses to save');
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

        if (!Categories::isExpense($category)) {
            Flash::redirect($addUrl, 'error', "Invalid category on \"$desc\"");
        }

        // Per-row date (fall back to the shared default)
        $rowDate = $validateDate($row['date'] ?? $defaultDateRaw);
        if ($rowDate === null) {
            Flash::redirect($addUrl, 'error', "Invalid or missing date on \"$desc\"");
        }

        // Per-row card (only when paying by card)
        $row_card_id = null;
        if ($method === 'Card') {
            $row_card_id = filter_var($row['card_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$row_card_id || !isset($cardMap[$row_card_id])) {
                Flash::redirect($addUrl, 'error', "Please select a valid card for \"$desc\"");
            }
        }

        $final_amount    = $amount;
        $original_amount = null;
        if ($currency !== 'AED') {
            $original_amount = $amount;
            $final_amount    = round($amount * $exchange_rate, 2);
        }

        // Split shares (AED) for this row; equal splits are computed per row
        $shares = null;
        if ($split !== null) {
            $shares = expenseSplitShares($split, $amount, $final_amount, (int) $spent_by, $desc);
            if (is_string($shares)) {
                Flash::redirect($addUrl, 'error', $shares);
            }
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
            'shares'          => $shares,
        ];
    }

    if (empty($prepared)) {
        Flash::redirect($addUrl, 'error', 'No valid expenses to save');
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
            if ($p['shares']) {
                expenseWriteSplits($pdo, (int) $tenant_id, (int) $pdo->lastInsertId(), $p['shares'], false);
            }
            $saved++;
        }

        if ($saved === 0) {
            $pdo->rollBack();
            Flash::redirect($addUrl, 'error', 'No valid expenses to save');
        }

        $pdo->commit();
        AuditHelper::log($pdo, 'add_expense', "Added $saved expense(s)" . ($split !== null ? ' (split with family)' : ''));
        try {
            if (class_exists(\App\Helpers\BudgetAlertHelper::class)) { \App\Helpers\BudgetAlertHelper::checkTenant($pdo, (int) $tenant_id); }
        } catch (Throwable $e) {
            error_log("Budget alert check: " . $e->getMessage());
        }
        // ?added= drives the "add more?" modal on add_expense.php (a count, not a message)
        header("Location: add_expense.php?added=$saved&month=$addMonth&year=$addYear");
        exit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Bulk expense insert: " . $e->getMessage());
        Flash::redirect($addUrl, 'error', 'System error occurred during expense processing.');
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
            Flash::redirect('expenses.php', 'error', 'System error occurred while deleting.');
        }
    }
    Flash::redirect('expenses.php', 'success', 'Deleted');
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
    Flash::redirect('subscriptions.php', 'success', 'Subscription removed');
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

                Flash::redirect($redirect, 'success', 'Logged successfully');
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Auto-Draft Error: " . $e->getMessage());
            Flash::redirect('subscriptions.php', 'error', 'System error during auto-draft.');
        }
    }
    header("Location: subscriptions.php");
    exit();
} elseif ($action == 'bulk_delete' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_slice(array_map('intval', (array)($_POST['ids'] ?? [])), 0, 500);
    if (empty($ids)) {
        $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'expenses.php');
        Flash::redirect($redirect, 'error', 'No valid IDs provided');
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
        Flash::redirect($redirect, 'error', 'System error occurred while deleting.');
    }
    AuditHelper::log($pdo, 'bulk_delete_expenses', "Bulk Deleted " . count($ids) . " Expenses. IDs: " . implode(',', $ids));

    Flash::redirect($redirect, 'success', 'Bulk deleted');
} elseif ($action == 'bulk_change_category' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_slice(array_filter(array_map('intval', $_POST['ids'])), 0, 500);
    $category = trim((string) ($_POST['category'] ?? ''));
    if (!Categories::isExpense($category)) {
        $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'expenses.php');
        Flash::redirect($redirect, 'error', 'Invalid category');
    }
    if (!empty($ids)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("UPDATE expenses SET category = ? WHERE id IN ($placeholders) AND tenant_id = ?");
        $stmt->execute(array_merge([$category], $ids, [$tenant_id]));
        AuditHelper::log($pdo, 'bulk_change_category', "Bulk Changed Category to $category for " . count($ids) . " Expenses. IDs: " . implode(',', $ids));
    }
    $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'expenses.php');

    Flash::redirect($redirect, 'success', 'Bulk category updated');
} elseif ($action == 'update_expense' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $expense_id = filter_input(INPUT_POST, 'expense_id', FILTER_VALIDATE_INT);

    if (!$expense_id) {
        Flash::redirect('expenses.php', 'error', 'Invalid expense');
    }

    $editUrl = "edit_expense.php?id=$expense_id";
    $fail = function (string $msg) use ($editUrl) {
        Flash::redirect($editUrl, 'error', $msg);
    };

    $oldStmt = $pdo->prepare("SELECT category, balance_bank_id, user_id, spent_by_user_id FROM expenses WHERE id = ? AND tenant_id = ?");
    $oldStmt->execute([$expense_id, $tenant_id]);
    $old = $oldStmt->fetch();
    if (!$old) {
        Flash::redirect('expenses.php', 'error', 'Expense not found');
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
    if (!Categories::isExpense($category) && $category !== $old['category']) {
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

    // Family split: the form carries split_present when it showed the split controls, so an
    // unticked "Split with family" clears the splits while older forms leave them untouched.
    $writeSplits = ($_POST['split_present'] ?? '') === '1' && expenseSplitReady($pdo);
    $shares = null;
    $split = expenseParseSplit($pdo, (int) $tenant_id);
    if (is_string($split)) {
        $fail($split);
    }
    if ($split !== null) {
        $payerId = (int) ($old['spent_by_user_id'] ?? $old['user_id']);
        $shares = expenseSplitShares($split, $amount, $final_amount, $payerId, $desc);
        if (is_string($shares)) {
            $fail($shares);
        }
        $writeSplits = true;
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
            Flash::redirect('expenses.php', 'error', 'Expense not found');
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

        if ($writeSplits) {
            expenseWriteSplits($pdo, (int) $tenant_id, (int) $expense_id, $shares, true);
        }

        $pdo->commit();

        AuditHelper::log($pdo, 'update_expense', "Updated Expense: $desc ($final_amount AED) - ID: $expense_id");
        try {
            if (class_exists(\App\Helpers\BudgetAlertHelper::class)) { \App\Helpers\BudgetAlertHelper::checkTenant($pdo, (int) $tenant_id); }
        } catch (Throwable $e) {
            error_log("Budget alert check: " . $e->getMessage());
        }
        Flash::redirect($editUrl, 'success', 'Expense updated successfully');

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Update expense: " . $e->getMessage());
        Flash::redirect($editUrl, 'error', 'System error occurred during update.');
    }
}

header("Location: expenses.php"); // Fallback
exit();
