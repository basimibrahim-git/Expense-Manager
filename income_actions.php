<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;
use App\Helpers\BalanceHelper;
use App\Helpers\ExchangeRateHelper;
use App\Helpers\Categories;
use App\Helpers\Flash;

Bootstrap::init();

const INCOME_CURRENCIES = ['AED', 'INR'];

/**
 * A referer-based redirect target without legacy message params (?success= / ?error= / ?msg=),
 * so an old bookmarked URL does not show a stale message next to the flash.
 */
function incomeCleanUrl(string $url): string
{
    $qPos = strpos($url, '?');
    if ($qPos === false) {
        return $url;
    }
    $fragment = '';
    $hashPos = strpos($url, '#', $qPos);
    if ($hashPos !== false) {
        $fragment = substr($url, $hashPos);
        $url = substr($url, 0, $hashPos);
    }
    parse_str(substr($url, $qPos + 1), $query);
    unset($query['success'], $query['error'], $query['msg']);
    $base = substr($url, 0, $qPos);
    return ($query ? $base . '?' . http_build_query($query) : $base) . $fragment;
}

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

/**
 * Move a bank balance by an income row ($sign +1 = credit, -1 = reverse), in the bank's currency.
 * $row needs amount, currency, income_date.
 */
function incomeMoveBalance(PDO $pdo, int $tenantId, int $userId, int $bankId, array $row, int $sign): bool
{
    $bank = BalanceHelper::bank($pdo, $tenantId, $bankId);
    if (!$bank) {
        return false;
    }
    $bankCur  = strtoupper($bank['currency'] ?: 'AED');
    $entryCur = strtoupper($row['currency'] ?: 'AED');
    $value    = (float) $row['amount'];
    if ($bankCur !== $entryCur) {
        $value = $value * fxToAed($pdo, $entryCur) / fxToAed($pdo, $bankCur);
    }
    return BalanceHelper::adjust($pdo, $tenantId, $userId, $bankId, $sign * round($value, 2), $row['income_date']);
}

/** fxPrewarm() only when one of these tenant income rows actually moved a bank balance. */
function incomePrewarmFor(PDO $pdo, int $tenantId, array $ids): void
{
    if (empty($ids)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT COALESCE(currency, 'AED') FROM income WHERE id IN ($placeholders) AND tenant_id = ? AND balance_bank_id IS NOT NULL");
    $stmt->execute(array_merge($ids, [$tenantId]));
    $currencies = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($currencies) {
        fxPrewarm($pdo, $tenantId, $currencies);
    }
}

/** Reverse the balance effect of tenant income rows (by id) that recorded a balance_bank_id. Call inside a transaction. */
function incomeReverseBalances(PDO $pdo, int $tenantId, int $userId, array $ids): void
{
    if (empty($ids)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT amount, currency, income_date, balance_bank_id FROM income
                           WHERE id IN ($placeholders) AND tenant_id = ? AND balance_bank_id IS NOT NULL FOR UPDATE");
    $stmt->execute(array_merge($ids, [$tenantId]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        incomeMoveBalance($pdo, $tenantId, $userId, (int) $row['balance_bank_id'], $row, -1);
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

        Flash::redirect(incomeCleanUrl($redirect), 'error', 'Unauthorized: Read-only access');
    }
}

$tenant_id = $_SESSION['tenant_id'];

if ($action == 'add_income' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $amount = floatval($_POST['amount'] ?? 0);
    $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'AED')));
    if (!in_array($currency, INCOME_CURRENCIES, true)) {
        Flash::redirect('add_income.php', 'error', 'Invalid currency');
    }
    $dateRaw = (string) ($_POST['income_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) || !strtotime($dateRaw)) {
        Flash::redirect('add_income.php', 'error', 'Invalid date format');
    }
    $year_check = (int)substr($dateRaw, 0, 4);
    if ($year_check < 2000 || $year_check > 2100) {
        Flash::redirect('add_income.php', 'error', 'Invalid year');
    }
    $date = $dateRaw;
    $desc = trim((string) ($_POST['description'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));

    // Foresight Fields
    $is_recurring = isset($_POST['is_recurring']) && $_POST['is_recurring'] == '1' ? 1 : 0;
    $recurrence_day = filter_input(INPUT_POST, 'recurrence_day', FILTER_VALIDATE_INT);
    if ($is_recurring && !$recurrence_day) {
        $recurrence_day = date('j', strtotime($date)); // Default to the selected date's day if not specified
    }

    if ($amount <= 0 || empty($desc)) {
        Flash::redirect('add_income.php', 'error', 'Invalid input');
    }
    if (!Categories::isIncome($category)) {
        Flash::redirect('add_income.php', 'error', 'Invalid category');
    }
    $recurrence_day = $recurrence_day ? max(1, min(31, (int) $recurrence_day)) : null;

    // Credit a bank balance if requested (the bank must belong to this tenant)
    $add_to_balance = isset($_POST['add_to_balance']) && $_POST['add_to_balance'] == '1';
    $bank_id = $add_to_balance ? filter_input(INPUT_POST, 'bank_id', FILTER_VALIDATE_INT) : null;
    if ($bank_id && !BalanceHelper::bank($pdo, (int) $tenant_id, $bank_id)) {
        Flash::redirect('add_income.php', 'error', 'Invalid bank selected');
    }

    try {
        if ($bank_id) {
            fxPrewarm($pdo, (int) $tenant_id, [$currency]);
        }

        $pdo->beginTransaction();

        // Snapshot is dated max(income date, latest snapshot), so back-dated income still reaches the current balance
        $balance_bank_id = null;
        if ($bank_id) {
            $moveRow = ['amount' => $amount, 'currency' => $currency, 'income_date' => $date];
            if (incomeMoveBalance($pdo, (int) $tenant_id, (int) $user_id, $bank_id, $moveRow, +1)) {
                $balance_bank_id = $bank_id;
            }
        }

        $stmt = $pdo->prepare("INSERT INTO income (user_id, tenant_id, amount, description, category, income_date, is_recurring, recurrence_day, currency, balance_bank_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $tenant_id, $amount, $desc, $category, $date, $is_recurring, $recurrence_day, $currency, $balance_bank_id]);

        $pdo->commit();

        $month = date('n', strtotime($date));
        $year = date('Y', strtotime($date));
        AuditHelper::log($pdo, 'add_income', "Added Income: $desc ($amount $currency)");
        $msg = $balance_bank_id ? 'Income recorded and balance updated' : 'Income recorded';
        Flash::redirect("monthly_income.php?month=$month&year=$year", 'success', $msg);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Add income: " . $e->getMessage());
        Flash::redirect('add_income.php', 'error', 'System error occurred during income processing.');
    }

} elseif ($action == 'delete_income' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $month = filter_input(INPUT_POST, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
    $year = filter_input(INPUT_POST, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
    if ($id) {
        try {
            incomePrewarmFor($pdo, (int) $tenant_id, [$id]);
            $pdo->beginTransaction();
            // Take back what this income added to its bank (rows recorded before balance_bank_id existed are left alone)
            incomeReverseBalances($pdo, (int) $tenant_id, (int) $_SESSION['user_id'], [$id]);
            $stmt = $pdo->prepare("DELETE FROM income WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenant_id]);
            $pdo->commit();
            AuditHelper::log($pdo, 'delete_income', "Deleted Income ID: $id");
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Delete income: " . $e->getMessage());
            Flash::redirect('income.php', 'error', 'System error occurred while deleting.');
        }
    }
    if ($month && $year) {
        Flash::redirect("monthly_income.php?month=$month&year=$year", 'success', 'Income deleted');
    }
    Flash::redirect('income.php', 'success', 'Income deleted');
} elseif ($action == 'bulk_delete' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_slice(array_map('intval', (array)($_POST['ids'] ?? [])), 0, 500);
    if (empty($ids)) {
        $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'income.php');
        Flash::redirect(incomeCleanUrl($redirect), 'error', 'No valid IDs provided');
    }
    $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'income.php');
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    try {
        incomePrewarmFor($pdo, (int) $tenant_id, $ids);
        $pdo->beginTransaction();
        incomeReverseBalances($pdo, (int) $tenant_id, (int) $_SESSION['user_id'], $ids);
        $stmt = $pdo->prepare("DELETE FROM income WHERE id IN ($placeholders) AND tenant_id = ?");
        $stmt->execute(array_merge($ids, [$tenant_id]));
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Bulk delete income: " . $e->getMessage());
        Flash::redirect(incomeCleanUrl($redirect), 'error', 'System error occurred while deleting.');
    }
    AuditHelper::log($pdo, 'bulk_delete_income', "Bulk Deleted " . count($ids) . " Income records. IDs: " . implode(',', $ids));
    Flash::redirect(incomeCleanUrl($redirect), 'success', 'Bulk deleted');
} elseif ($action == 'bulk_change_category' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_slice(array_filter(array_map('intval', $_POST['ids'])), 0, 500);
    $category = trim((string) ($_POST['category'] ?? ''));
    if (!empty($ids) && Categories::isIncome($category)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("UPDATE income SET category = ? WHERE id IN ($placeholders) AND tenant_id = ?");
        $stmt->execute(array_merge([$category], $ids, [$tenant_id]));
        AuditHelper::log($pdo, 'bulk_change_income_category', "Bulk Changed Category to $category for " . count($ids) . " Income records. IDs: " . implode(',', $ids));
    }
    $redirect = SecurityHelper::getSafeRedirect($_SERVER['HTTP_REFERER'] ?? null, 'income.php');
    Flash::redirect(incomeCleanUrl($redirect), 'success', 'Bulk category updated');
} elseif ($action == 'update_income' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $income_id = filter_input(INPUT_POST, 'income_id', FILTER_VALIDATE_INT);

    if (!$income_id) {
        Flash::redirect('income.php', 'error', 'Invalid income');
    }

    $oldStmt = $pdo->prepare("SELECT category, currency, balance_bank_id FROM income WHERE id = ? AND tenant_id = ?");
    $oldStmt->execute([$income_id, $tenant_id]);
    $old = $oldStmt->fetch();
    if (!$old) {
        Flash::redirect('income.php', 'error', 'Income not found');
    }

    $amount = floatval($_POST['amount'] ?? 0);
    // Keep the stored currency when the form does not post one (never silently relabel INR as AED)
    $currency = strtoupper(trim((string) ($_POST['currency'] ?? ($old['currency'] ?: 'AED'))));
    if (!in_array($currency, INCOME_CURRENCIES, true) && $currency !== strtoupper((string) $old['currency'])) {
        Flash::redirect("edit_income.php?id=$income_id", 'error', 'Invalid currency');
    }
    $dateRaw = (string) ($_POST['income_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) || !strtotime($dateRaw)) {
        Flash::redirect("edit_income.php?id=$income_id", 'error', 'Invalid date format');
    }
    $year_check = (int)substr($dateRaw, 0, 4);
    if ($year_check < 2000 || $year_check > 2100) {
        Flash::redirect("edit_income.php?id=$income_id", 'error', 'Invalid year');
    }
    $date = $dateRaw;
    $desc = trim((string) ($_POST['description'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $is_recurring = isset($_POST['is_recurring']) && $_POST['is_recurring'] == '1' ? 1 : 0;
    $recurrence_day = filter_input(INPUT_POST, 'recurrence_day', FILTER_VALIDATE_INT);

    if ($is_recurring && !$recurrence_day) {
        $recurrence_day = date('j', strtotime($date));
    }

    if ($amount <= 0 || empty($desc)) {
        Flash::redirect("edit_income.php?id=$income_id", 'error', 'Invalid input');
    }
    // Allow the standard categories, or keeping the record's existing (legacy) category
    if (!Categories::isIncome($category) && $category !== $old['category']) {
        Flash::redirect("edit_income.php?id=$income_id", 'error', 'Invalid category');
    }
    $recurrence_day = $recurrence_day ? max(1, min(31, (int) $recurrence_day)) : null;

    try {
        if ($old['balance_bank_id'] !== null) {
            fxPrewarm($pdo, (int) $tenant_id, [$currency, $old['currency'] ?: 'AED']);
        }

        $pdo->beginTransaction();

        $rowStmt = $pdo->prepare("SELECT amount, currency, income_date, balance_bank_id FROM income WHERE id = ? AND tenant_id = ? FOR UPDATE");
        $rowStmt->execute([$income_id, $tenant_id]);
        $current = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            $pdo->rollBack();
            Flash::redirect('income.php', 'error', 'Income not found');
        }

        // Reverse the original credit and re-apply the new amount to the same bank.
        // If the reversal fails (bank removed) the link is dropped; legacy rows (NULL) are left alone.
        $balance_bank_id = null;
        if ($current['balance_bank_id'] !== null) {
            $bankId = (int) $current['balance_bank_id'];
            if (incomeMoveBalance($pdo, (int) $tenant_id, (int) $user_id, $bankId, $current, -1)) {
                $newRow = ['amount' => $amount, 'currency' => $currency, 'income_date' => $date];
                if (incomeMoveBalance($pdo, (int) $tenant_id, (int) $user_id, $bankId, $newRow, +1)) {
                    $balance_bank_id = $bankId;
                }
            }
        }

        $stmt = $pdo->prepare("UPDATE income SET amount = ?, description = ?, category = ?, income_date = ?, is_recurring = ?, recurrence_day = ?, currency = ?, balance_bank_id = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$amount, $desc, $category, $date, $is_recurring, $recurrence_day, $currency, $balance_bank_id, $income_id, $tenant_id]);

        $pdo->commit();

        AuditHelper::log($pdo, 'update_income', "Updated Income: $desc ($amount $currency) - ID: $income_id");
        Flash::redirect("edit_income.php?id=$income_id", 'success', 'Income updated successfully');

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Update income: " . $e->getMessage());
        Flash::redirect("edit_income.php?id=$income_id", 'error', 'System error occurred during update.');
    }
}

header("Location: income.php");
exit();
