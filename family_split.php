<?php
$page_title = "Family Split";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;
use App\Helpers\SecurityHelper;
use App\Helpers\Html;
use App\Helpers\Flash;
use App\Helpers\AuditHelper;
use App\Helpers\SplitHelper;

Bootstrap::init();

$tenant_id = (int) $_SESSION['tenant_id'];
$read_only = ($_SESSION['permission'] ?? 'edit') === 'read_only';

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
$year  = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');

// ---------------------------------------------------------------------------
// POST: record / delete a settlement
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $postMonth = filter_input(INPUT_POST, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: $month;
    $postYear  = filter_input(INPUT_POST, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: $year;
    $back = "family_split.php?month=$postMonth&year=$postYear";

    if ($read_only) {
        Flash::redirect($back, 'error', 'Unauthorized: Read-only access');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_settlement') {
        $from   = filter_input(INPUT_POST, 'from_user_id', FILTER_VALIDATE_INT);
        $to     = filter_input(INPUT_POST, 'to_user_id', FILTER_VALIDATE_INT);
        $amtRaw = trim((string) ($_POST['amount'] ?? ''));
        $date   = (string) ($_POST['settled_on'] ?? '');
        $note   = trim((string) ($_POST['note'] ?? ''));

        if (!$from || !$to) {
            Flash::redirect($back, 'error', 'Choose who paid and who received the money');
        }
        if ($from === $to) {
            Flash::redirect($back, 'error', 'A settlement needs two different members');
        }
        if (!is_numeric($amtRaw) || (float) $amtRaw <= 0 || (float) $amtRaw > 1e9) {
            Flash::redirect($back, 'error', 'Enter an amount greater than zero');
        }
        $amount = round((float) $amtRaw, 2);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dm) || !checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1])
            || (int) $dm[1] < 2000 || (int) $dm[1] > 2100) {
            Flash::redirect($back, 'error', 'Enter a valid date');
        }
        $note = mb_substr($note, 0, 255);

        try {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND id IN (?, ?)");
            $chk->execute([$tenant_id, $from, $to]);
            if ((int) $chk->fetchColumn() !== 2) {
                Flash::redirect($back, 'error', 'Both members must belong to your family');
            }

            $ins = $pdo->prepare("INSERT INTO settlements (tenant_id, from_user_id, to_user_id, amount, currency, settled_on, note, created_by)
                                  VALUES (?, ?, ?, ?, 'AED', ?, ?, ?)");
            $ins->execute([$tenant_id, $from, $to, $amount, $date, $note !== '' ? $note : null, (int) $_SESSION['user_id']]);
            AuditHelper::log($pdo, 'add_settlement', "Settlement of $amount AED from user $from to user $to on $date");
        } catch (PDOException $e) {
            error_log('Add settlement: ' . $e->getMessage());
            Flash::redirect($back, 'error', 'Could not save the settlement.');
        }
        Flash::redirect($back, 'success', 'Settlement recorded');
    }

    if ($action === 'delete_settlement') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            Flash::redirect($back, 'error', 'Invalid settlement');
        }
        try {
            $del = $pdo->prepare("DELETE FROM settlements WHERE id = ? AND tenant_id = ?");
            $del->execute([$id, $tenant_id]);
            if ($del->rowCount() === 0) {
                Flash::redirect($back, 'error', 'Settlement not found');
            }
            AuditHelper::log($pdo, 'delete_settlement', "Deleted settlement ID: $id");
        } catch (PDOException $e) {
            error_log('Delete settlement: ' . $e->getMessage());
            Flash::redirect($back, 'error', 'Could not delete the settlement.');
        }
        Flash::redirect($back, 'success', 'Settlement deleted');
    }

    Flash::redirect($back);
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
$members = [];
$mStmt = $pdo->prepare("SELECT id, name FROM users WHERE tenant_id = ? ORDER BY name ASC");
$mStmt->execute([$tenant_id]);
foreach ($mStmt->fetchAll() as $m) {
    $members[(int) $m['id']] = $m['name'];
}
$memberName = function (int $id) use ($members): string {
    return $members[$id] ?? ('Former member #' . $id);
};

$ready        = true;
$split_rows   = [];   // every (expense, participant) row: expense_id, user_id, share, payer_id
$settlements  = [];
$month_expenses = [];
try {
    $sStmt = $pdo->prepare("SELECT s.expense_id, s.user_id, s.share_amount AS share,
                                   COALESCE(e.spent_by_user_id, e.user_id) AS payer_id
                            FROM expense_splits s
                            JOIN expenses e ON e.id = s.expense_id AND e.tenant_id = s.tenant_id
                            WHERE s.tenant_id = ?");
    $sStmt->execute([$tenant_id]);
    $split_rows = $sStmt->fetchAll();

    $tStmt = $pdo->prepare("SELECT id, from_user_id, to_user_id, amount, currency, settled_on, note, created_by
                            FROM settlements WHERE tenant_id = ? ORDER BY settled_on DESC, id DESC");
    $tStmt->execute([$tenant_id]);
    $settlements = $tStmt->fetchAll();

    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-d', strtotime("$start +1 month"));
    $eStmt = $pdo->prepare("SELECT e.id, e.expense_date, e.description, e.category, e.amount,
                                   COALESCE(e.spent_by_user_id, e.user_id) AS payer_id
                            FROM expenses e
                            WHERE e.tenant_id = ? AND e.expense_date >= ? AND e.expense_date < ?
                              AND e.id IN (SELECT expense_id FROM expense_splits WHERE tenant_id = ?)
                            ORDER BY e.expense_date DESC, e.id DESC");
    $eStmt->execute([$tenant_id, $start, $end, $tenant_id]);
    $month_expenses = $eStmt->fetchAll();
} catch (PDOException $e) {
    $ready = false; // migrations/2026_10_05_feature_release.sql not run yet
    error_log('Family split load: ' . $e->getMessage());
}

// Balances
$net       = SplitHelper::netBalances($split_rows, $settlements);
$transfers = SplitHelper::simplify($net);

// Per-member breakdown (AED)
$stats = [];
$ensure = function (int $uid) use (&$stats) {
    $stats[$uid] ??= ['paid_for_others' => 0.0, 'owed_to_others' => 0.0, 'settled_out' => 0.0, 'settled_in' => 0.0];
};
foreach (array_keys($members) as $uid) {
    $ensure($uid);
}
$shares_by_expense = [];
foreach ($split_rows as $r) {
    $uid = (int) $r['user_id'];
    $payer = (int) $r['payer_id'];
    $shares_by_expense[(int) $r['expense_id']][$uid] = (float) $r['share'];
    if ($uid === $payer) {
        continue;
    }
    $ensure($uid);
    $ensure($payer);
    $stats[$payer]['paid_for_others'] += (float) $r['share'];
    $stats[$uid]['owed_to_others']    += (float) $r['share'];
}
foreach ($settlements as $s) {
    $ensure((int) $s['from_user_id']);
    $ensure((int) $s['to_user_id']);
    $stats[(int) $s['from_user_id']]['settled_out'] += (float) $s['amount'];
    $stats[(int) $s['to_user_id']]['settled_in']    += (float) $s['amount'];
}

$month_name = date('F', mktime(0, 0, 0, $month, 10));
$prev_ts = mktime(0, 0, 0, $month - 1, 1, $year);
$next_ts = mktime(0, 0, 0, $month + 1, 1, $year);
$month_split_total = array_sum(array_map(fn($e) => (float) $e['amount'], $month_expenses));

Layout::header();
Layout::sidebar();
?>

<style>
    .split-transfer { border-bottom: 1px solid rgba(0,0,0,0.05); }
    .split-transfer:last-child { border-bottom: 0; }
    .split-arrow { color: var(--bs-secondary-color, #6c757d); }
</style>

<!-- Header -->
<div class="row mb-4">
    <div class="col-12">
        <div class="gradient-card-info p-4 rounded-4 shadow-sm position-relative overflow-hidden">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h1 class="h2 fw-bold text-white mb-1"><i class="fa-solid fa-people-arrows me-2"></i>Family Split</h1>
                    <p class="text-white-50 mb-0">Who owes whom for shared expenses, and the settlements that cleared them.</p>
                </div>
                <div class="text-md-end">
                    <span class="small text-white-50 d-block">Open transfers</span>
                    <h2 class="fw-bold text-white mb-0"><?php echo count($transfers); ?></h2>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$ready): ?>
    <div class="alert alert-warning border-0 rounded-4 shadow-sm">
        <i class="fa-solid fa-triangle-exclamation me-2"></i>
        Family split is not set up yet. Run <code>migrations/2026_10_05_feature_release.sql</code> in phpMyAdmin first.
    </div>
<?php else: ?>

<div class="row g-4 mb-4">
    <!-- Who owes whom -->
    <div class="col-lg-6">
        <div class="glass-panel-premium p-4 h-100">
            <h5 class="fw-bold mb-3"><i class="fa-solid fa-scale-balanced text-primary me-2"></i>Who owes whom</h5>
            <?php if (empty($transfers)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fa-solid fa-circle-check fa-2x text-success mb-2 d-block"></i>
                    Everyone is settled up.
                </div>
            <?php else: ?>
                <?php foreach ($transfers as $t): ?>
                    <div class="split-transfer d-flex align-items-center justify-content-between gap-2 py-2">
                        <div>
                            <span class="fw-bold"><?php echo Html::e($memberName($t['from'])); ?></span>
                            <i class="fa-solid fa-arrow-right mx-2 split-arrow"></i>
                            <span class="fw-bold"><?php echo Html::e($memberName($t['to'])); ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold text-danger text-nowrap">AED <span class="blur-sensitive"><?php echo number_format($t['amount'], 2); ?></span></span>
                            <?php if (!$read_only && isset($members[$t['from']], $members[$t['to']])): ?>
                                <button type="button" class="btn btn-sm btn-outline-success rounded-pill"
                                    data-onclick="prefillSettlement"
                                    data-args="<?php echo Html::args($t['from'], $t['to'], number_format($t['amount'], 2, '.', '')); ?>">
                                    Settle
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <p class="x-small text-muted mt-3 mb-0">Simplified: the fewest payments that clear every balance, not one per expense.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Per-member balances -->
    <div class="col-lg-6">
        <div class="glass-panel-premium p-4 h-100">
            <h5 class="fw-bold mb-3"><i class="fa-solid fa-users text-primary me-2"></i>Member balances</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr class="small text-muted">
                            <th>Member</th>
                            <th class="text-end" title="Other members' shares of expenses this member paid">Paid for others</th>
                            <th class="text-end" title="This member's shares of expenses others paid">Share of others'</th>
                            <th class="text-end">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats as $uid => $st):
                            $bal = $net[$uid] ?? 0.0;
                            if (!isset($members[$uid]) && abs($bal) < 0.005) {
                                continue; // former member with nothing outstanding
                            } ?>
                            <tr>
                                <td class="fw-semibold"><?php echo Html::e($memberName($uid)); ?></td>
                                <td class="text-end blur-sensitive"><?php echo number_format($st['paid_for_others'], 2); ?></td>
                                <td class="text-end blur-sensitive"><?php echo number_format($st['owed_to_others'], 2); ?></td>
                                <td class="text-end">
                                    <?php if ($bal > 0.004): ?>
                                        <span class="badge bg-success-subtle text-success">is owed <span class="blur-sensitive"><?php echo number_format($bal, 2); ?></span></span>
                                    <?php elseif ($bal < -0.004): ?>
                                        <span class="badge bg-danger-subtle text-danger">owes <span class="blur-sensitive"><?php echo number_format(-$bal, 2); ?></span></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary">settled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="x-small text-muted mt-3 mb-0">All amounts in AED, all time. Settlements are included in the balance.</p>
        </div>
    </div>
</div>

<!-- Split expenses for the month -->
<div class="glass-panel-premium p-4 mb-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
        <h5 class="fw-bold mb-0"><i class="fa-solid fa-receipt text-primary me-2"></i>Split expenses: <?php echo Html::e($month_name . ' ' . $year); ?></h5>
        <div class="btn-group shadow-sm">
            <a href="?month=<?php echo (int) date('n', $prev_ts); ?>&amp;year=<?php echo (int) date('Y', $prev_ts); ?>" class="btn btn-white border px-3" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></a>
            <span class="btn btn-white border fw-bold px-3 disabled" style="opacity: 1;"><?php echo Html::e(date('M Y', mktime(0, 0, 0, $month, 1, $year))); ?></span>
            <a href="?month=<?php echo (int) date('n', $next_ts); ?>&amp;year=<?php echo (int) date('Y', $next_ts); ?>" class="btn btn-white border px-3" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></a>
        </div>
    </div>

    <?php if (empty($month_expenses)): ?>
        <p class="text-muted mb-0">No split expenses in <?php echo Html::e($month_name); ?>. Turn on "Split with family" when adding or editing an expense.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr class="small text-muted">
                        <th>Date</th>
                        <th>Description</th>
                        <th>Paid by</th>
                        <th>Shares</th>
                        <th class="text-end">Amount</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($month_expenses as $e):
                        $eid = (int) $e['id'];
                        $payer = (int) $e['payer_id']; ?>
                        <tr>
                            <td class="text-nowrap"><?php echo Html::e(date('d M', strtotime($e['expense_date']))); ?></td>
                            <td>
                                <div class="fw-semibold"><?php echo Html::e($e['description']); ?></div>
                                <div class="x-small text-muted"><?php echo Html::e($e['category']); ?></div>
                            </td>
                            <td class="small"><?php echo Html::e($memberName($payer)); ?></td>
                            <td class="small">
                                <?php foreach ($shares_by_expense[$eid] ?? [] as $uid => $share): ?>
                                    <span class="badge <?php echo $uid === $payer ? 'bg-success-subtle text-success' : 'bg-light text-secondary border'; ?> me-1 mb-1">
                                        <?php echo Html::e($memberName($uid)); ?>: <span class="blur-sensitive"><?php echo number_format($share, 2); ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </td>
                            <td class="text-end fw-bold text-nowrap">AED <span class="blur-sensitive"><?php echo number_format((float) $e['amount'], 2); ?></span></td>
                            <td class="text-end">
                                <?php if (!$read_only): ?>
                                    <a href="edit_expense.php?id=<?php echo $eid; ?>" class="btn btn-sm btn-link text-muted" title="Edit split"><i class="fa-solid fa-pen"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-end small text-muted">Total split this month</td>
                        <td class="text-end fw-bold text-nowrap">AED <span class="blur-sensitive"><?php echo number_format($month_split_total, 2); ?></span></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="row g-4 mb-4">
    <!-- Record settlement -->
    <?php if (!$read_only && count($members) > 1): ?>
    <div class="col-lg-5">
        <div class="glass-panel-premium p-4 h-100" id="settlementFormCard">
            <h5 class="fw-bold mb-3"><i class="fa-solid fa-hand-holding-dollar text-success me-2"></i>Record settlement</h5>
            <form method="POST" action="family_split.php" id="settlementForm">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="add_settlement">
                <input type="hidden" name="month" value="<?php echo $month; ?>">
                <input type="hidden" name="year" value="<?php echo $year; ?>">

                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label small fw-bold" for="settleFrom">Paid by</label>
                        <select name="from_user_id" id="settleFrom" class="form-select" required>
                            <option value="">-- Member --</option>
                            <?php foreach ($members as $id => $name): ?>
                                <option value="<?php echo (int) $id; ?>"><?php echo Html::e($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold" for="settleTo">Paid to</label>
                        <select name="to_user_id" id="settleTo" class="form-select" required>
                            <option value="">-- Member --</option>
                            <?php foreach ($members as $id => $name): ?>
                                <option value="<?php echo (int) $id; ?>"><?php echo Html::e($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label small fw-bold" for="settleAmount">Amount (AED)</label>
                        <input type="number" name="amount" id="settleAmount" class="form-control" step="0.01" min="0.01" required placeholder="0.00">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold" for="settleDate">Date</label>
                        <input type="date" name="settled_on" id="settleDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold" for="settleNote">Note (optional)</label>
                    <input type="text" name="note" id="settleNote" class="form-control" maxlength="255" placeholder="e.g. Bank transfer">
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-success rounded-pill fw-bold">
                        <i class="fa-solid fa-check me-1"></i> Save settlement
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Settlement history -->
    <div class="<?php echo (!$read_only && count($members) > 1) ? 'col-lg-7' : 'col-12'; ?>">
        <div class="glass-panel-premium p-4 h-100">
            <h5 class="fw-bold mb-3"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Settlement history</h5>
            <?php if (empty($settlements)): ?>
                <p class="text-muted mb-0">No settlements recorded yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr class="small text-muted">
                                <th>Date</th>
                                <th>From</th>
                                <th>To</th>
                                <th class="text-end">Amount</th>
                                <th>Note</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($settlements as $s): ?>
                                <tr>
                                    <td class="text-nowrap small"><?php echo Html::e(date('d M Y', strtotime($s['settled_on']))); ?></td>
                                    <td class="small fw-semibold"><?php echo Html::e($memberName((int) $s['from_user_id'])); ?></td>
                                    <td class="small fw-semibold"><?php echo Html::e($memberName((int) $s['to_user_id'])); ?></td>
                                    <td class="text-end text-nowrap"><?php echo Html::e($s['currency'] ?: 'AED'); ?> <span class="blur-sensitive"><?php echo number_format((float) $s['amount'], 2); ?></span></td>
                                    <td class="small text-muted"><?php echo Html::e($s['note'] ?? ''); ?></td>
                                    <td class="text-end">
                                        <?php if (!$read_only): ?>
                                            <form method="POST" action="family_split.php" class="d-inline"
                                                data-confirm="<?php echo Html::e('Delete the settlement of AED ' . number_format((float) $s['amount'], 2) . ' from ' . $memberName((int) $s['from_user_id']) . ' to ' . $memberName((int) $s['to_user_id']) . '?'); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="delete_settlement">
                                                <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                                                <input type="hidden" name="month" value="<?php echo $month; ?>">
                                                <input type="hidden" name="year" value="<?php echo $year; ?>">
                                                <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="Delete settlement">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php endif; ?>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
function prefillSettlement(from, to, amount) {
    const form = document.getElementById('settlementForm');
    if (!form) return;
    document.getElementById('settleFrom').value = String(from);
    document.getElementById('settleTo').value = String(to);
    document.getElementById('settleAmount').value = amount;
    document.getElementById('settlementFormCard').scrollIntoView({ behavior: 'smooth', block: 'center' });
    document.getElementById('settleAmount').focus({ preventScroll: true });
}

document.getElementById('settlementForm')?.addEventListener('submit', function (e) {
    if (document.getElementById('settleFrom').value === document.getElementById('settleTo').value) {
        e.preventDefault();
        alert('Choose two different members.');
    }
});
</script>

<?php Layout::footer(); ?>
