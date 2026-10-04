<?php
/**
 * POST handler for open banking (Lean): finish a bank link, sync, disconnect,
 * link accounts to banks, and import/ignore reviewed bank transactions.
 */
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;
use App\Helpers\Flash;
use App\Helpers\LeanClient;
use App\Helpers\LeanSync;
use App\Helpers\LeanException;

Bootstrap::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: lean_accounts.php');
    exit();
}

SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
    Flash::redirect('lean_accounts.php', 'error', 'Unauthorized: Read-only access');
}

$tenant_id = (int) $_SESSION['tenant_id'];
$user_id   = (int) $_SESSION['user_id'];
$action    = (string) ($_POST['action'] ?? '');

if (!LeanClient::isConfigured() || !LeanSync::tablesReady($pdo)) {
    Flash::redirect('lean_accounts.php', 'error', 'Open banking is not configured.');
}

if (in_array($action, ['link_entity', 'disconnect'], true) && !LeanSync::isAdmin()) {
    Flash::redirect('lean_accounts.php', 'error', 'Only a family admin can connect or disconnect banks.');
}

/** Back to the review page with its (validated) filters. */
function leanReviewUrl(): string
{
    $q = [];
    $account = (string) ($_POST['f_account'] ?? '');
    if (LeanClient::isId($account)) {
        $q['account'] = $account;
    }
    foreach (['from', 'to'] as $k) {
        $v = (string) ($_POST['f_' . $k] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $q[$k] = $v;
        }
    }
    $status = (string) ($_POST['f_status'] ?? '');
    if (in_array($status, ['new', 'ignored', 'imported'], true)) {
        $q['status'] = $status;
    }
    return 'lean_transactions.php' . ($q ? '?' . http_build_query($q) : '');
}

function leanSyncMessage(array $sum): array
{
    if ($sum['busy']) {
        return ['warning', 'A sync is already running for your family. Try again in a minute.'];
    }
    $parts = [];
    $parts[] = $sum['accounts'] . ' account' . ($sum['accounts'] === 1 ? '' : 's') . ' checked';
    $parts[] = $sum['snapshots'] . ' balance' . ($sum['snapshots'] === 1 ? '' : 's') . ' updated';
    $parts[] = $sum['transactions'] . ' new transaction' . ($sum['transactions'] === 1 ? '' : 's');
    $msg = 'Sync finished: ' . implode(', ', $parts) . '.';
    if ($sum['refreshed'] > 0) {
        $msg .= ' A fresh pull from your bank was requested; new data usually arrives within minutes.';
    }
    if ($sum['errors']) {
        return ['warning', $msg . ' ' . implode(' ', $sum['errors'])];
    }
    return ['success', $msg];
}

$back = 'lean_accounts.php';
try {
    switch ($action) {
        case 'link_entity':
            // The browser only passes a hint; the entity list comes from Lean for this family's customer.
            $hint = (string) ($_POST['entity_id'] ?? '');
            $client = new LeanClient();
            LeanSync::ensureCustomer($pdo, $client, $tenant_id);
            $reg = LeanSync::registerEntities($pdo, $client, $tenant_id, LeanClient::isId($hint) ? $hint : null);
            AuditHelper::log($pdo, 'lean_connect', "Open banking: {$reg['added']} new bank connection(s)");
            if ($reg['added'] === 0 && !$reg['hint_found']) {
                Flash::redirect($back, 'warning', 'No new bank connection was found yet. If you finished the steps at your bank, it will appear here automatically in a few minutes.');
            }
            $sum = LeanSync::syncTenant($pdo, $tenant_id, $user_id, false);
            [$type, $msg] = leanSyncMessage($sum);
            Flash::redirect($back, $type, 'Bank connected. ' . $msg);
            break;

        case 'sync':
            $sum = LeanSync::syncTenant($pdo, $tenant_id, $user_id, true);
            [$type, $msg] = leanSyncMessage($sum);
            Flash::redirect($back, $type, $msg);
            break;

        case 'disconnect':
            $id = filter_input(INPUT_POST, 'entity_row_id', FILTER_VALIDATE_INT);
            if (!$id) {
                Flash::redirect($back, 'error', 'Invalid connection');
            }
            $remoteOk = LeanSync::disconnect($pdo, new LeanClient(), $tenant_id, (int) $id);
            AuditHelper::log($pdo, 'lean_disconnect', "Open banking: disconnected connection #{$id}");
            Flash::redirect($back, $remoteOk ? 'success' : 'warning', $remoteOk
                ? 'Bank disconnected. Your existing balances and imported entries are kept.'
                : 'Bank removed here, but Lean could not be reached to revoke access. You can also revoke it in your bank app.');
            break;

        case 'map_account':
            $id = filter_input(INPUT_POST, 'account_row_id', FILTER_VALIDATE_INT);
            $target = (string) ($_POST['bank_target'] ?? '');
            if (!$id || !preg_match('/^(new|\d{1,10})?$/', $target)) {
                Flash::redirect($back, 'error', 'Invalid selection');
            }
            $msg = LeanSync::mapAccount($pdo, $tenant_id, $user_id, (int) $id, $target);
            AuditHelper::log($pdo, 'lean_map_account', "Open banking: account #{$id} → " . ($target === '' ? 'unlinked' : $target));
            Flash::redirect($back, 'success', $msg);
            break;

        case 'import_expense':
        case 'import_income':
            $back = leanReviewUrl();
            $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
            $cats = [];
            foreach ((is_array($_POST['cat'] ?? null) ? $_POST['cat'] : []) as $k => $v) {
                if (ctype_digit((string) $k) && is_string($v)) {
                    $cats[(int) $k] = $v;
                }
            }
            if (!$ids) {
                Flash::redirect($back, 'warning', 'Select at least one transaction.');
            }
            $kind = $action === 'import_expense' ? 'expense' : 'income';
            $res = LeanSync::import($pdo, $tenant_id, $user_id, $ids, $kind, $cats);
            AuditHelper::log($pdo, 'lean_import', "Open banking: imported {$res['imported']} transaction(s) as {$kind}");
            $msg = "Imported {$res['imported']} transaction" . ($res['imported'] === 1 ? '' : 's') . " as {$kind}.";
            if ($res['skipped'] > 0) {
                $msg .= " {$res['skipped']} skipped (already handled).";
            }
            Flash::redirect($back, $res['imported'] > 0 ? 'success' : 'warning', $msg);
            break;

        case 'ignore':
        case 'restore':
            $back = leanReviewUrl();
            $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
            $n = LeanSync::setIgnored($pdo, $tenant_id, $ids, $action === 'ignore');
            Flash::redirect($back, 'success', $action === 'ignore'
                ? "{$n} transaction" . ($n === 1 ? '' : 's') . ' ignored.'
                : "{$n} transaction" . ($n === 1 ? '' : 's') . ' moved back to review.');
            break;

        default:
            Flash::redirect($back, 'error', 'Unknown action');
    }
} catch (DomainException $e) {
    Flash::redirect($back, 'error', $e->getMessage());
} catch (LeanException $e) {
    error_log('Lean action ' . $action . ' tenant ' . $tenant_id . ': ' . $e->getMessage());
    Flash::redirect($back, 'error', 'The open banking service could not be reached. Please try again later.');
} catch (PDOException $e) {
    error_log('Lean action ' . $action . ' DB error: ' . $e->getMessage());
    Flash::redirect($back, 'error', 'Something went wrong. Please try again.');
}
