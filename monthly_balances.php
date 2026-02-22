<?php
$page_title = "Monthly Balances";
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;

Bootstrap::init();
Layout::header();
Layout::sidebar();

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');
$month_name = date("F", mktime(0, 0, 0, $month, 10));

// Fetch Balances
// Fetch Balances (Exclude Opening Balance Adjustments as they are for previous month baselining)
$query = "SELECT * FROM bank_balances WHERE tenant_id = :tenant_id AND MONTH(balance_date) = :month AND YEAR(balance_date) = :year AND bank_name != 'Opening Balance Adjustment' ORDER BY created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute(['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year]);
$balances = $stmt->fetchAll();

// Calculate Total (Converted to AED)
$total = 0;
foreach ($balances as $b) {
    if (($b['currency'] ?? 'AED') == 'INR') {
        $total += ($b['amount'] / 24); // Exch: 1 AED = 24 INR
    } else {
        // Default AED
        $total += $b['amount'];
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="bank_balances.php?year=<?php echo $year; ?>" class="text-decoration-none text-muted small mb-1">
            <i class="fa-solid fa-arrow-left"></i> Back to Year
        </a>
        <h1 class="h3 fw-bold mb-0">Balances:
            <?php echo $month_name . ' ' . $year; ?>
        </h1>
    </div>
    <div class="text-end">
        <div class="small text-muted">Total Net Worth</div>
        <h3 class="fw-bold text-info mb-0">AED <span class="blur-sensitive">
                <?php echo number_format($total, 2); ?></span>
        </h3>
    </div>
</div>


<?php
// RECONCILIATION LOGIC
$prev_month_ts = strtotime("$year-$month-01 -1 month");
$prev_m = date('n', $prev_month_ts);
$prev_y = date('Y', $prev_month_ts);

// 1. Get Previous Month Closing Balance
$stmt = $pdo->prepare("
SELECT SUM(amount) FROM bank_balances b1
WHERE tenant_id = ?
AND id = (SELECT MAX(id) FROM bank_balances b2 WHERE b2.bank_name = b1.bank_name AND b2.tenant_id = b1.tenant_id AND
MONTH(b2.balance_date) = ? AND YEAR(b2.balance_date) = ?)
");
$stmt->execute([$_SESSION['tenant_id'], $prev_m, $prev_y]);
$opening_balance = $stmt->fetchColumn() ?: 0;

// 2. Get Income & Expenses This Month
$stmt = $pdo->prepare("SELECT SUM(amount) FROM income WHERE tenant_id = ? AND MONTH(income_date) = ? AND YEAR(income_date)
= ?");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$m_income = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND
YEAR(expense_date) = ?");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$m_expense = $stmt->fetchColumn() ?: 0;

$expected_balance = $opening_balance + $m_income - $m_expense;
$actual_balance = $total;
$difference = $actual_balance - $expected_balance;
?>

<!-- Reconciliation Widget -->
<?php if ($total > 0): ?>
    <div class="glass-panel p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0"><i class="fa-solid fa-scale-balanced me-2 text-primary"></i> Reconciliation</h5>
            <?php if (abs($difference) < 1): ?>
                <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i> Balanced</span>
            <?php else: ?>
                <span class="badge bg-warning text-dark"><i class="fa-solid fa-triangle-exclamation me-1"></i> Discrepancy
                    Found</span>
            <?php endif; ?>
        </div>

        <div class="row text-center mb-3">
            <div class="col-md-4 border-end">
                <small class="text-muted">Expected (Calc)</small>
                <div class="h5 fw-bold text-muted">AED <?php echo number_format($expected_balance); ?></div>
                <div class="tiny text-muted small">(Open: <?php echo number_format($opening_balance); ?> + Inc:
                    <?php echo number_format($m_income); ?> - Exp: <?php echo number_format($m_expense); ?>)
                </div>
            </div>
            <div class="col-md-4 border-end">
                <small class="text-muted">Actual (Recorded)</small>
                <div class="h5 fw-bold text-primary">AED <?php echo number_format($actual_balance); ?></div>
            </div>
            <div class="col-md-4">
                <small class="text-muted">Gap</small>
                <div class="h5 fw-bold <?php echo $difference >= 0 ? 'text-success' : 'text-danger'; ?>">
                    AED <?php echo number_format($difference, 2); ?>
                </div>
            </div>
        </div>

        <?php if (abs($difference) > 1): ?>
            <div class="alert alert-light border-0 d-flex justify-content-between align-items-center">
                <div class="small">
                    <?php if ($difference > 0): ?>
                        <i class="fa-solid fa-circle-info text-info me-2"></i> You have <b>AED
                            <?php echo number_format($difference); ?></b> more than expected. Did you forget to log some income?
                    <?php else: ?>
                        <i class="fa-solid fa-circle-info text-danger me-2"></i> You are missing <b>AED
                            <?php echo number_format(abs($difference)); ?></b>. Did you forget an expense?
                    <?php endif; ?>
                </div>
                <form method="POST" action="reconcile_fix.php">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="difference" value="<?php echo $difference; ?>">
                    <input type="hidden" name="desc" value="Reconciliation Adjustment (<?php echo $month_name; ?>)">
                    <input type="hidden" name="date"
                        value="<?php echo "$year-$month-" . date('t', strtotime("$year-$month-01")); ?>">
                    <div class="d-flex gap-2">
                        <button type="submit" name="action" value="auto_fix" class="btn btn-sm btn-dark fw-bold"
                            title="Adds an Income/Expense to fix the gap">Auto-Fix Transaction</button>
                        <button type="submit" name="action" value="update_opening"
                            class="btn btn-sm btn-outline-secondary fw-bold"
                            title="Updates LAST month's closing balance to match. Use this for new apps.">Set as
                            Opening</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="glass-panel p-3 mb-4 d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
        <?php if (!empty($balances) && ($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
            <div class="form-check ms-2">
                <input type="checkbox" class="form-check-input" id="selectAll">
                <label class="form-check-label small fw-bold text-muted" for="selectAll">Select All</label>
            </div>
        <?php endif; ?>
    </div>
    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
        <a href="add_balance.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>"
            class="btn btn-info text-white px-4">
            <i class="fa-solid fa-plus me-2"></i> Update Bank Balance
        </a>
    <?php endif; ?>
</div>

<?php if (empty($balances)): ?>
    <div class="text-center py-5">
        <p class="text-muted">No balances recorded for this month.</p>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($balances as $b): ?>
            <div class="col-md-4">
                <div class="glass-panel p-4 position-relative h-100">
                    <!-- Checkbox for Bulk Actions -->
                    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                        <div class="position-absolute top-0 start-0 p-2" style="z-index: 10;">
                            <input type="checkbox" class="form-check-input row-checkbox" name="balance_ids[]"
                                value="<?php echo $b['id']; ?>">
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between align-items-start mb-3 ms-2">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center me-3"
                                style="width: 48px; height: 48px;">
                                <i class="fa-solid fa-building-columns text-secondary"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0">
                                    <?php echo htmlspecialchars($b['bank_name']); ?>
                                </h5>
                                <small class="text-muted">Updated:
                                    <?php echo date('d M', strtotime($b['balance_date'])); ?>
                                </small>
                            </div>
                        </div>
                        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                            <form action="balance_actions.php" method="POST" class="d-inline"
                                onsubmit="return confirmSubmit(this, 'Delete <?php echo addslashes(htmlspecialchars($b['bank_name'])); ?> balance of <?php echo number_format($b['amount'], 2); ?> - recorded on <?php echo date('d M', strtotime($b['balance_date'])); ?>?');">
                                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="delete_balance">
                                <input type="hidden" name="id" value="<?php echo $b['id']; ?>">
                                <button type="submit" class="btn btn-sm text-danger border-0 p-0" title="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2 ms-2">
                        <h4 class="fw-bold mb-0">
                            <span class=""
                                style="font-size:0.6em; text-transform:uppercase;"><?php echo htmlspecialchars($b['currency'] ?? 'AED'); ?></span>
                            <span class="blur-sensitive">
                                <?php echo number_format($b['amount'], 2); ?></span>
                        </h4>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php Layout::footer(); ?>

<!-- Bulk Action Floating Bar -->
<?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
    <div id="bulkActionBar"
        class="position-fixed bottom-0 start-50 translate-middle-x mb-4 shadow-lg glass-panel p-3 d-none animate__animated animate__fadeInUp"
        style="z-index: 1050; border-radius: 50px; min-width: 300px;">
        <div class="d-flex align-items-center justify-content-between gap-4 px-2">
            <div class="text-nowrap fw-bold">
                <span id="selectedCount">0</span> Selected
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-danger btn-sm rounded-pill px-3" onclick="bulkAction('delete')">
                    <i class="fa-solid fa-trash me-1"></i> Delete
                </button>
                <button class="btn btn-link btn-sm text-muted" onclick="deselectAll()">Cancel</button>
            </div>
        </div>
    </div>

    <form id="bulkActionForm" action="balance_actions.php" method="POST" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
        <input type="hidden" name="action" id="bulkActionType">
        <div id="bulkActionIds"></div>
    </form>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const selectAll = document.getElementById('selectAll');
        const rowCheckboxes = document.querySelectorAll('.row-checkbox');
        const bulkBar = document.getElementById('bulkActionBar');
        const selectedCount = document.getElementById('selectedCount');

        if (!selectAll) return;

        function updateBulkBar() {
            const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
            if (selectedCount) selectedCount.innerText = checkedCount;
            if (bulkBar) {
                if (checkedCount > 0) {
                    bulkBar.classList.remove('d-none');
                } else {
                    bulkBar.classList.add('d-none');
                }
            }
        }

        selectAll.addEventListener('change', function () {
            rowCheckboxes.forEach(cb => cb.checked = selectAll.checked);
            updateBulkBar();
        });

        rowCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateBulkBar);
        });
    });

    function deselectAll() {
        const selectAll = document.getElementById('selectAll');
        if (selectAll) selectAll.checked = false;
        document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
        const bulkBar = document.getElementById('bulkActionBar');
        if (bulkBar) bulkBar.classList.add('d-none');
    }

    function bulkAction(type) {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        if (checked.length === 0) return;

        let confirmMsg = `Are you sure you want to delete ${checked.length} selected balance snapshots? This cannot be undone.`;

        if (confirm(confirmMsg)) {
            const form = document.getElementById('bulkActionForm');
            document.getElementById('bulkActionType').value = 'bulk_' + type;

            const idsContainer = document.getElementById('bulkActionIds');
            idsContainer.innerHTML = '';
            checked.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = cb.value;
                idsContainer.appendChild(input);
            });

            form.submit();
        }
    }
</script>