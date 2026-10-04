<?php
/**
 * Review bank transactions received through open banking (Lean) and import them as
 * expenses or income, or ignore them. Imported entries never move a bank balance
 * (balance_bank_id stays NULL): the synced balance already includes them.
 */
$page_title = "Bank Transactions";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\Categories;
use App\Helpers\LeanClient;
use App\Helpers\LeanSync;

Bootstrap::init();

$tenant_id  = (int) $_SESSION['tenant_id'];
$can_edit   = ($_SESSION['permission'] ?? 'edit') !== 'read_only';
$configured = LeanClient::isConfigured();
$tables     = LeanSync::tablesReady($pdo);
const LEAN_TX_LIMIT = 300;

$validDate = function ($raw): string {
    $raw = (string) $raw;
    $dt = DateTime::createFromFormat('!Y-m-d', $raw);
    return ($dt && $dt->format('Y-m-d') === $raw) ? $raw : '';
};
$f_from   = $validDate($_GET['from'] ?? '');
$f_to     = $validDate($_GET['to'] ?? '');
$f_status = in_array($_GET['status'] ?? '', ['new', 'ignored', 'imported'], true) ? $_GET['status'] : 'new';
$f_account = LeanClient::isId($_GET['account'] ?? null) ? (string) $_GET['account'] : '';

$accounts = [];
$rows = [];
$counts = ['new' => 0, 'ignored' => 0, 'imported' => 0];
if ($tables) {
    try {
        $stmt = $pdo->prepare(
            "SELECT a.account_id, a.display_name, a.masked_number, e.bank_name
               FROM lean_accounts a
               LEFT JOIN lean_entities e ON e.tenant_id = a.tenant_id AND e.entity_id = a.entity_id
              WHERE a.tenant_id = ?
              ORDER BY e.bank_name, a.display_name"
        );
        $stmt->execute([$tenant_id]);
        foreach ($stmt->fetchAll() as $a) {
            $accounts[$a['account_id']] = $a;
        }
        if ($f_account !== '' && !isset($accounts[$f_account])) {
            $f_account = ''; // not this family's account
        }

        $stmt = $pdo->prepare("SELECT status, COUNT(*) AS n FROM lean_transactions WHERE tenant_id = ? GROUP BY status");
        $stmt->execute([$tenant_id]);
        foreach ($stmt->fetchAll() as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }

        $sql = "SELECT * FROM lean_transactions WHERE tenant_id = ? AND status = ?";
        $params = [$tenant_id, $f_status];
        if ($f_account !== '') {
            $sql .= " AND account_id = ?";
            $params[] = $f_account;
        }
        if ($f_from !== '') {
            $sql .= " AND booking_date >= ?";
            $params[] = $f_from;
        }
        if ($f_to !== '') {
            $sql .= " AND booking_date <= ?";
            $params[] = $f_to;
        }
        $sql .= " ORDER BY booking_date DESC, id DESC LIMIT " . (LEAN_TX_LIMIT + 1);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('lean_transactions: ' . $e->getMessage());
    }
}
$more = count($rows) > LEAN_TX_LIMIT;
$rows = array_slice($rows, 0, LEAN_TX_LIMIT);
$filter_qs = function (string $status) use ($f_account, $f_from, $f_to): string {
    return http_build_query(array_filter(['status' => $status, 'account' => $f_account, 'from' => $f_from, 'to' => $f_to]));
};

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <a href="lean_accounts.php" class="btn btn-sm btn-light rounded-pill px-3 shadow-sm mb-2 hover-lift">
            <i class="fa-solid fa-arrow-left me-1"></i> Open Banking
        </a>
        <h1 class="h3 fw-bold mb-1 text-dark">Bank Transactions</h1>
        <p class="text-muted mb-0">Transactions from your connected banks. Import the ones you want as expenses or income.</p>
    </div>

    <?php if (!$configured || !$tables): ?>
        <div class="glass-panel-premium p-5 text-center mx-auto" style="max-width: 640px;">
            <i class="fa-solid fa-plug-circle-xmark fa-3x text-muted opacity-50 mb-3"></i>
            <h5 class="fw-bold text-dark">Open banking is not configured</h5>
            <p class="text-muted small mb-0">The administrator has not set up the Lean integration for this site yet.</p>
        </div>
    <?php else: ?>

    <!-- Status tabs -->
    <ul class="nav nav-pills mb-3 gap-2">
        <?php foreach (['new' => 'To review', 'ignored' => 'Ignored', 'imported' => 'Imported'] as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link rounded-pill <?php echo $f_status === $key ? 'active' : ''; ?>" href="lean_transactions.php?<?php echo Html::e($filter_qs($key)); ?>">
                    <?php echo Html::e($label); ?> <span class="badge bg-light text-dark ms-1"><?php echo (int) $counts[$key]; ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Filters -->
    <form method="GET" action="lean_transactions.php" class="glass-panel-premium p-3 mb-4 row g-2 align-items-end">
        <input type="hidden" name="status" value="<?php echo Html::e($f_status); ?>">
        <div class="col-md-4">
            <label class="form-label small fw-bold text-muted" for="f_account">Account</label>
            <select name="account" id="f_account" class="form-select rounded-pill">
                <option value="">All accounts</option>
                <?php foreach ($accounts as $aid => $a): ?>
                    <option value="<?php echo Html::e($aid); ?>" <?php echo $f_account === $aid ? 'selected' : ''; ?>>
                        <?php echo Html::e(implode(' · ', array_filter([(string) $a['bank_name'], trim($a['display_name'] . ' ' . $a['masked_number'])]))); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small fw-bold text-muted" for="f_from">From</label>
            <input type="date" name="from" id="f_from" class="form-control rounded-pill" value="<?php echo Html::e($f_from); ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small fw-bold text-muted" for="f_to">To</label>
            <input type="date" name="to" id="f_to" class="form-control rounded-pill" value="<?php echo Html::e($f_to); ?>">
        </div>
        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-outline-primary rounded-pill"><i class="fa-solid fa-filter me-1"></i> Filter</button>
        </div>
    </form>

    <?php if (empty($rows)): ?>
        <div class="glass-panel-premium p-5 text-center">
            <i class="fa-solid fa-inbox fa-3x text-muted opacity-25 mb-3"></i>
            <p class="text-muted mb-0"><?php echo $f_status === 'new' ? 'Nothing to review. New bank transactions appear here after each sync.' : 'No transactions here.'; ?></p>
        </div>
    <?php else: ?>
        <form method="POST" action="lean_actions.php">
            <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
            <input type="hidden" name="f_account" value="<?php echo Html::e($f_account); ?>">
            <input type="hidden" name="f_from" value="<?php echo Html::e($f_from); ?>">
            <input type="hidden" name="f_to" value="<?php echo Html::e($f_to); ?>">
            <input type="hidden" name="f_status" value="<?php echo Html::e($f_status); ?>">

            <?php if ($can_edit && $f_status !== 'imported'): ?>
                <div class="glass-panel-premium p-3 mb-3 d-flex flex-wrap gap-2 align-items-center">
                    <?php if ($f_status === 'new'): ?>
                        <button type="submit" name="action" value="import_expense" class="btn btn-danger rounded-pill px-3">
                            <i class="fa-solid fa-receipt me-1"></i> Import as expense
                        </button>
                        <button type="submit" name="action" value="import_income" class="btn btn-success rounded-pill px-3">
                            <i class="fa-solid fa-wallet me-1"></i> Import as income
                        </button>
                        <button type="submit" name="action" value="ignore" class="btn btn-outline-secondary rounded-pill px-3">
                            <i class="fa-solid fa-eye-slash me-1"></i> Ignore
                        </button>
                        <span class="small text-muted ms-lg-2">Categories are suggested from the merchant; change them before importing. Imports never change bank balances.</span>
                    <?php else: ?>
                        <button type="submit" name="action" value="restore" class="btn btn-outline-primary rounded-pill px-3">
                            <i class="fa-solid fa-rotate-left me-1"></i> Move back to review
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="glass-panel-premium p-0 overflow-hidden">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr class="small text-muted text-uppercase">
                                <?php if ($can_edit && $f_status !== 'imported'): ?>
                                    <th style="width: 40px;">
                                        <input type="checkbox" class="form-check-input" aria-label="Select all"
                                               data-onchange="leanToggleAll" data-args="<?php echo Html::args('$this'); ?>">
                                    </th>
                                <?php endif; ?>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Account</th>
                                <th class="text-end">Amount</th>
                                <th style="min-width: 200px;"><?php echo $f_status === 'imported' ? 'Imported as' : 'Category'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $t): ?>
                                <?php
                                $is_debit = (float) $t['amount'] < 0;
                                $acc = $accounts[$t['account_id']] ?? null;
                                $text = trim(($t['merchant'] ?? '') . ' ' . $t['description']);
                                $suggested = LeanSync::suggestCategory($text, $is_debit ? 'expense' : 'income');
                                ?>
                                <tr>
                                    <?php if ($can_edit && $f_status !== 'imported'): ?>
                                        <td><input type="checkbox" class="form-check-input lean-row-check" name="ids[]" value="<?php echo (int) $t['id']; ?>" aria-label="Select transaction"></td>
                                    <?php endif; ?>
                                    <td class="text-nowrap small"><?php echo Html::e(date('d M Y', strtotime($t['booking_date']))); ?></td>
                                    <td>
                                        <?php if (!empty($t['merchant'])): ?>
                                            <div class="fw-bold text-dark"><?php echo Html::e($t['merchant']); ?></div>
                                        <?php endif; ?>
                                        <div class="small text-muted"><?php echo Html::e($t['description']); ?></div>
                                        <?php if (!empty($t['card_last4'])): ?>
                                            <span class="badge bg-light text-dark border small"><i class="fa-solid fa-credit-card me-1"></i>•••• <?php echo Html::e($t['card_last4']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?php echo Html::e($acc ? implode(' · ', array_filter([(string) $acc['bank_name'], (string) $acc['display_name']])) : '—'); ?></td>
                                    <td class="text-end text-nowrap fw-bold <?php echo $is_debit ? 'text-danger' : 'text-success'; ?>">
                                        <span class="blur-sensitive"><?php echo ($is_debit ? '−' : '+') . number_format(abs((float) $t['amount']), 2); ?></span>
                                        <small class="text-muted fw-normal"><?php echo Html::e($t['currency']); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($f_status === 'imported'): ?>
                                            <?php if ($t['imported_expense_id']): ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill">Expense</span>
                                            <?php elseif ($t['imported_income_id']): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success rounded-pill">Income</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill">Entry deleted</span>
                                            <?php endif; ?>
                                        <?php elseif ($can_edit && $f_status === 'new'): ?>
                                            <select name="cat[<?php echo (int) $t['id']; ?>]" class="form-select form-select-sm rounded-pill" aria-label="Category">
                                                <?php echo $is_debit ? Categories::expenseOptions($suggested) : Categories::incomeOptions($suggested); ?>
                                            </select>
                                        <?php else: ?>
                                            <span class="small text-muted"><?php echo Html::e($suggested); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($more): ?>
                <p class="text-muted small mt-2">Showing the latest <?php echo LEAN_TX_LIMIT; ?> transactions. Narrow the dates to see older ones.</p>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    function leanToggleAll(box) {
        document.querySelectorAll('.lean-row-check').forEach(function (c) { c.checked = box.checked; });
    }
</script>

<?php Layout::footer(); ?>
