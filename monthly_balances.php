<?php
$page_title = "Monthly Balances";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\ExchangeRateHelper;
use App\Helpers\Html;
use App\Helpers\BalanceHelper;

Bootstrap::init();

$tenant_id = (int) $_SESSION['tenant_id'];
$can_edit  = ($_SESSION['permission'] ?? 'edit') !== 'read_only';
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');
$month_name = date("F", mktime(0, 0, 0, $month, 10));
$month_end = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
$prev_month_end = date('Y-m-t', mktime(0, 0, 0, $month - 1, 1, $year));

// All snapshots recorded this month (legacy 'Opening Balance Adjustment' rows are not bank balances)
$query = "SELECT * FROM bank_balances WHERE tenant_id = :tenant_id AND MONTH(balance_date) = :month AND YEAR(balance_date) = :year AND bank_name != 'Opening Balance Adjustment' ORDER BY balance_date DESC, id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute(['tenant_id' => $tenant_id, 'month' => $month, 'year' => $year]);
$balances = $stmt->fetchAll();

// Total = latest balance of every bank as of month end, in AED
$total = BalanceHelper::totalAed($pdo, $tenant_id, $month_end);

// Brand color function for consistent bank styling
function getBankColor($bank_name) {
    $brands = [
        'emirates nbd' => '#005fa9',
        'enbd' => '#005fa9',
        'adcb' => '#e2231a',
        'abu dhabi commercial' => '#e2231a',
        'fab' => '#002d62',
        'first abu dhabi' => '#002d62',
        'mashreq' => '#ff7c00',
        'hsbc' => '#db0011',
        'standard chartered' => '#00853e',
        'scb' => '#00853e',
        'rakbank' => '#d2122e',
        'adib' => '#007cc3',
        'dib' => '#00843d',
        'dubai islamic' => '#00843d',
        'cbd' => '#005c2a',
        'commercial bank of dubai' => '#005c2a',
        'wio' => '#00ffc4',
        'liv' => '#f43f5e',
    ];
    $name = strtolower(trim($bank_name));
    foreach ($brands as $brand => $color) {
        if (strpos($name, $brand) !== false) {
            return $color;
        }
    }
    // Determinstic fallback HSL color from name hash
    $hash = md5($bank_name);
    $hue = hexdec(substr($hash, 0, 4)) % 360;
    return "hsl(" . $hue . ", 70%, 38%)";
}

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <!-- Header with Back Link & Title -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 g-3">
        <div>
            <a href="bank_balances.php?year=<?php echo (int) $year; ?>" class="btn btn-sm btn-light rounded-pill px-3 mb-2 hover-lift">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Year
            </a>
            <h1 class="h3 fw-bold mb-0 text-dark">
                Balances: <?php echo Html::e($month_name . ' ' . $year); ?>
            </h1>
        </div>
        <div class="text-md-end">
            <span class="text-muted small text-uppercase tracking-wider">Total Bank Balance (as of <?php echo date('d M', strtotime($month_end)); ?>)</span>
            <h3 class="fw-bold text-primary mb-0 mt-1">
                <small class="text-muted" style="font-size: 0.6em">AED</small> 
                <span class="blur-sensitive"><?php echo number_format($total, 2); ?></span>
            </h3>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4" role="alert">
            <i class="fa-solid fa-check-circle me-2"></i> <?php echo Html::e($_GET['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4" role="alert">
            <i class="fa-solid fa-exclamation-circle me-2"></i> <?php echo Html::e($_GET['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php
    // RECONCILIATION LOGIC
    // 1. Previous month closing balance = latest balance of every bank as of last month's end
    $opening_balance = BalanceHelper::totalAed($pdo, $tenant_id, $prev_month_end);

    // 2. Get Income & Expenses This Month
    $stmt = $pdo->prepare("SELECT SUM(" . ExchangeRateHelper::aedSql($pdo) . ") FROM income WHERE tenant_id = ? AND MONTH(income_date) = ? AND YEAR(income_date) = ?");
    $stmt->execute([$tenant_id, $month, $year]);
    $m_income = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?");
    $stmt->execute([$tenant_id, $month, $year]);
    $m_expense = $stmt->fetchColumn() ?: 0;

    $expected_balance = $opening_balance + $m_income - $m_expense;
    $actual_balance = $total;
    $difference = $actual_balance - $expected_balance;
    ?>

    <!-- Reconciliation Widget -->
    <?php if ($total > 0): ?>
        <div class="glass-panel-premium p-4 mb-4 shadow-sm">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-scale-balanced me-2 text-primary"></i> Reconciliation Check
                </h5>
                <?php if (abs($difference) < 1): ?>
                    <span class="badge bg-success rounded-pill px-3 py-1.5"><i class="fa-solid fa-check me-1"></i> Balanced</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark rounded-pill px-3 py-1.5"><i class="fa-solid fa-triangle-exclamation me-1"></i> Discrepancy Found</span>
                <?php endif; ?>
            </div>

            <div class="row g-3 text-center mb-3">
                <div class="col-md-4 border-end">
                    <span class="text-muted small text-uppercase">Expected (Calculated)</span>
                    <div class="h5 fw-bold text-dark mt-1">AED <?php echo number_format($expected_balance); ?></div>
                    <div class="x-small text-muted">(Open: <?php echo number_format($opening_balance); ?> + Inc: <?php echo number_format($m_income); ?> - Exp: <?php echo number_format($m_expense); ?>)</div>
                </div>
                <div class="col-md-4 border-end">
                    <span class="text-muted small text-uppercase">Actual (Recorded)</span>
                    <div class="h5 fw-bold text-primary mt-1">AED <?php echo number_format($actual_balance); ?></div>
                    <div class="x-small text-muted">Latest balance of each bank at month end</div>
                </div>
                <div class="col-md-4">
                    <span class="text-muted small text-uppercase">Gap / Offset</span>
                    <div class="h5 fw-bold mt-1 <?php echo $difference >= 0 ? 'text-success' : 'text-danger'; ?>">
                        AED <?php echo number_format($difference, 2); ?>
                    </div>
                    <div class="x-small text-muted">Discrepancy value</div>
                </div>
            </div>

            <?php if (abs($difference) > 1 && $can_edit): ?>
                <div class="alert alert-light border border-light rounded-4 d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 p-3">
                    <div class="small text-muted mb-0">
                        <?php if ($difference > 0): ?>
                            <i class="fa-solid fa-circle-info text-info me-2"></i> You have <strong>AED <?php echo number_format($difference); ?></strong> more than expected. Did you forget to log some income?
                        <?php else: ?>
                            <i class="fa-solid fa-circle-info text-danger me-2"></i> You are missing <strong>AED <?php echo number_format(abs($difference)); ?></strong>. Did you forget an expense?
                        <?php endif; ?>
                    </div>
                    <form method="POST" action="reconcile_fix.php" class="d-flex flex-wrap gap-2">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="difference" value="<?php echo Html::e(round($difference, 2)); ?>">
                        <input type="hidden" name="date" value="<?php echo Html::e($month_end); ?>">
                        <button type="submit" name="action" value="auto_fix" class="btn btn-sm btn-dark rounded-pill px-3" title="Adds an Income/Expense to fix the gap"
                            data-confirm="<?php echo Html::e('Record an adjustment ' . ($difference > 0 ? 'income' : 'expense') . ' of AED ' . number_format(abs($difference), 2) . ' dated ' . date('d M Y', strtotime($month_end)) . '?'); ?>" data-confirm-btn="Record">
                            Auto-Fix Transaction
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Controls Bar -->
    <div class="glass-panel-premium p-3 mb-4 d-flex justify-content-between align-items-center shadow-sm">
        <div>
            <?php if (!empty($balances) && $can_edit): ?>
                <div class="form-check ms-2 mb-0 d-flex align-items-center gap-2">
                    <input type="checkbox" class="form-check-input" id="selectAll" style="width: 18px; height: 18px;">
                    <label class="form-check-label small fw-bold text-muted mb-0" for="selectAll">Select All</label>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($can_edit): ?>
            <a href="add_balance.php?month=<?php echo (int) $month; ?>&year=<?php echo (int) $year; ?>" class="btn btn-primary rounded-pill px-4 hover-lift">
                <i class="fa-solid fa-plus me-1"></i> Update Balance
            </a>
        <?php endif; ?>
    </div>

    <!-- Snapshot List -->
    <?php if (empty($balances)): ?>
        <div class="text-center py-5 glass-panel-premium">
            <i class="fa-solid fa-building-columns fa-3x mb-3 text-muted opacity-20"></i>
            <p class="text-muted mb-0">No bank balances recorded for this month.</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($balances as $b): ?>
                <?php $theme_color = getBankColor($b['bank_name']); ?>
                <div class="col-md-4">
                    <div class="glass-panel-premium p-4 position-relative h-100 hover-lift d-flex flex-column justify-content-between" 
                         style="border-radius: 16px; border-top: 4px solid <?php echo $theme_color; ?> !important;">
                        
                        <!-- Checkbox for Bulk Actions -->
                        <?php if ($can_edit): ?>
                            <div class="position-absolute top-0 start-0 p-2" style="z-index: 10;">
                                <input type="checkbox" class="form-check-input row-checkbox" name="balance_ids[]" value="<?php echo (int) $b['id']; ?>" style="width: 18px; height: 18px;">
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between align-items-start mb-3 <?php echo $can_edit ? 'ps-3' : ''; ?>">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle p-2 d-flex align-items-center justify-content-center me-3" 
                                     style="background: <?php echo $theme_color . '15'; ?>; color: <?php echo $theme_color; ?>; width: 44px; height: 44px;">
                                    <i class="fa-solid fa-building-columns"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold text-dark mb-0"><?php echo Html::e($b['bank_name']); ?></h6>
                                    <small class="text-muted x-small">Date: <?php echo date('d M', strtotime($b['balance_date'])); ?></small>
                                </div>
                            </div>
                            <?php if ($can_edit): ?>
                                <form action="balance_actions.php" method="POST" class="d-inline"
                                    data-confirm="<?php echo Html::e('Delete ' . $b['bank_name'] . ' balance of ' . number_format((float) $b['amount'], 2) . ' - recorded on ' . date('d M', strtotime($b['balance_date'])) . '?'); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="delete_balance">
                                    <input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
                                    <button type="submit" class="btn btn-link text-danger p-0 border-0 hover-lift" title="Delete" style="box-shadow: none;">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        
                        <div class="<?php echo $can_edit ? 'ps-3' : ''; ?> mt-2">
                            <span class="text-muted small text-uppercase tracking-wider">Balance</span>
                            <h4 class="fw-bold text-dark mb-0 mt-1">
                                <small class="text-muted" style="font-size: 0.6em;"><?php echo Html::e($b['currency'] ?? 'AED'); ?></small>
                                <span class="blur-sensitive"><?php echo number_format($b['amount'], 2); ?></span>
                            </h4>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Bulk Action Floating Bar -->
<?php if ($can_edit): ?>
    <div id="bulkActionBar"
        class="position-fixed bottom-0 start-50 translate-middle-x mb-4 shadow-lg glass-panel-premium p-3 d-none animate__animated animate__fadeInUp"
        style="z-index: 1050; border-radius: 50px; min-width: 320px; background: rgba(30, 41, 59, 0.95); border: 1px solid rgba(255,255,255,0.15) !important;">
        <div class="d-flex align-items-center justify-content-between gap-4 px-2 text-white">
            <div class="text-nowrap fw-bold small">
                <i class="fa-solid fa-check-double text-info me-1"></i> <span id="selectedCount">0</span> Selected
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-danger btn-sm rounded-pill px-3" data-onclick="bulkAction" data-args='["delete"]'>
                    <i class="fa-solid fa-trash me-1"></i> Delete
                </button>
                <button type="button" class="btn btn-link btn-sm text-white-50 text-decoration-none" data-onclick="deselectAll">Cancel</button>
            </div>
        </div>
    </div>

    <form id="bulkActionForm" action="balance_actions.php" method="POST" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
        <input type="hidden" name="action" id="bulkActionType">
        <div id="bulkActionIds"></div>
    </form>
<?php endif; ?>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
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

        const confirmMsg = `Are you sure you want to remove ${checked.length} selected snapshot records? This action is permanent.`;

        askConfirm(confirmMsg, 'Delete', function () {
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
        });
    }
</script>

<?php Layout::footer(); ?>
