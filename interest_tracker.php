<?php
$page_title = "Interest Tracker";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Html;
use App\Helpers\Layout;

Bootstrap::init();
Layout::header();
Layout::sidebar();

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');

// Get total interest per month for the selected year
$stmt = $pdo->prepare("
    SELECT MONTH(interest_date) as month, SUM(amount) as total
    FROM interest_tracker
    WHERE tenant_id = :tenant_id AND YEAR(interest_date) = :year
    GROUP BY MONTH(interest_date)
");
$stmt->execute(['tenant_id' => $_SESSION['tenant_id'], 'year' => $year]);
$monthly_totals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get Year-Specific Totals (Due vs Paid for SELECTED YEAR)
$stmt = $pdo->prepare("SELECT SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_accrued,
                            SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_paid
                     FROM interest_tracker WHERE tenant_id = ? AND YEAR(interest_date) = ?");
$stmt->execute([$_SESSION['tenant_id'], $year]);
$year_stats = $stmt->fetch();
$total_accrued = $year_stats['total_accrued'] ?? 0;
$total_paid = $year_stats['total_paid'] ?? 0;
$current_balance = $total_accrued - $total_paid;

// Get Global Pending Breakdown (For the new Modal)
$stmt = $pdo->prepare("SELECT YEAR(interest_date) as year, SUM(amount) as net_balance
                       FROM interest_tracker
                       WHERE tenant_id = ?
                       GROUP BY YEAR(interest_date)
                       HAVING net_balance > 0
                       ORDER BY year ASC");
$stmt->execute([$_SESSION['tenant_id']]);
$global_pending_breakdown = $stmt->fetchAll();

$global_net_pending = 0;
foreach ($global_pending_breakdown as $row) {
    $global_net_pending += $row['net_balance'];
}

$months = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];

$current_month = date('n');
$current_year = date('Y');
?>


<!-- Premium Header Banner -->
<div class="row mb-4">
    <div class="col-12">
        <div class="gradient-card-primary p-4 rounded-4 hover-lift position-relative overflow-hidden shadow-sm" style="border-radius: 16px;">
            <div class="position-absolute top-0 end-0 p-3 opacity-10">
                <i class="fa-solid fa-percent fa-9x"></i>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 position-relative" style="z-index: 2;">
                <div>
                    <h1 class="h3 fw-bold mb-1 text-white">Interest Tracker</h1>
                    <p class="text-white text-opacity-75 mb-0">Monitor non-permissible interest and manage charitable offset payments for <?php echo $year; ?></p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-white text-primary border-0 rounded-pill px-3 py-1.5 fw-bold shadow-sm hover-lift" data-bs-toggle="modal" data-bs-target="#globalPendingModal">
                        <i class="fa-solid fa-calculator me-1"></i> Total Due Breakdown
                    </button>
                    <form action="" method="GET" class="d-inline-block">
                        <select name="year" class="form-select rounded-pill px-3 fw-bold" data-autosubmit style="min-width: 100px; border: none; height: 38px;">
                            <?php
                            $start_year = min(2025, $year);
                            $end_year = max((int) date('Y') + 5, $year);
                            for ($y = $start_year; $y <= $end_year; $y++):
                                $selected = ($y == $year) ? 'selected' : '';
                                ?>
                                <option value="<?php echo $y; ?>" <?php echo $selected; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="glass-panel-premium p-4 h-100 hover-lift position-relative overflow-hidden" style="border-left: 4px solid #f43f5e !important;">
            <div class="position-absolute end-0 top-50 translate-middle-y me-4 opacity-10">
                <i class="fa-solid fa-circle-exclamation fa-4x text-danger"></i>
            </div>
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Remaining Interest Due (<?php echo $year; ?>)</h6>
            <h2 class="fw-bold text-danger mb-1">
                <small class="fs-6 text-muted">AED</small> 
                <span class="blur-sensitive"><?php echo number_format($current_balance, 2); ?></span>
            </h2>
            <p class="text-muted small mb-0">Total interest received needing disposal</p>
        </div>
    </div>
    <div class="col-md-6">
        <div class="glass-panel-premium p-4 h-100 hover-lift position-relative overflow-hidden" style="border-left: 4px solid #10b981 !important;">
            <div class="position-absolute end-0 top-50 translate-middle-y me-4 opacity-10">
                <i class="fa-solid fa-circle-check fa-4x text-success"></i>
            </div>
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Total Paid / Disposed (<?php echo $year; ?>)</h6>
            <h2 class="fw-bold text-success mb-1">
                <small class="fs-6 text-muted">AED</small> 
                <span class="blur-sensitive"><?php echo number_format($total_paid, 2); ?></span>
            </h2>
            <p class="text-muted small mb-0">Charitable payments cleared for this year</p>
        </div>
    </div>
</div>

<?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
    <div class="mb-4">
        <button class="btn btn-dark w-100 py-3 rounded-4 fw-bold hover-lift shadow-sm d-flex align-items-center justify-content-center gap-2" 
                data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
            <i class="fa-solid fa-hand-holding-dollar fa-lg text-primary"></i> 
            <span>Record Interest Payment (Charity Donation)</span>
        </button>
    </div>
<?php endif; ?>

<!-- Monthly Grid -->
<div class="row g-4">
    <?php foreach ($months as $num => $name): ?>
        <?php
        $total = $monthly_totals[$num] ?? 0;
        $is_current = ($year == $current_year && $num == $current_month);
        $has_data = $total != 0;
        ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="monthly_interest.php?month=<?php echo $num; ?>&year=<?php echo $year; ?>" class="text-decoration-none d-block h-100">
                <?php if ($is_current): ?>
                    <!-- Current Month (Accent Gradient) -->
                    <div class="card border-0 h-100 shadow-md hover-lift transition-all" 
                         style="background: linear-gradient(135deg, #06b6d4, #0891b2); border-radius: 16px;">
                        <div class="card-body p-4 d-flex flex-column justify-content-between text-center min-h-140">
                            <div>
                                <h5 class="fw-bold mb-1 text-white">
                                    <?php echo $name; ?>
                                </h5>
                                <span class="badge rounded-pill bg-white bg-opacity-25 text-white small px-2 py-0.5">Current</span>
                            </div>

                            <div class="mt-4">
                                <?php if ($total > 0): ?>
                                    <h4 class="fw-bold mb-0 text-white">
                                        <small style="font-size: 0.65em;">AED</small> 
                                        <span class="blur-sensitive"><?php echo number_format($total, 2); ?></span>
                                    </h4>
                                    <small class="text-white text-opacity-75">Outstanding</small>
                                <?php elseif ($total < 0): ?>
                                    <h4 class="fw-bold mb-0 text-white">
                                        <small style="font-size: 0.65em;">AED</small> 
                                        <span class="blur-sensitive"><?php echo number_format(abs($total), 2); ?></span>
                                    </h4>
                                    <small class="text-white text-opacity-75">Credit</small>
                                <?php else: ?>
                                    <div class="text-white text-opacity-75 small py-1">- No Entry -</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Glass Card -->
                    <div class="glass-panel-premium h-100 hover-lift text-center">
                        <div class="p-4 d-flex flex-column justify-content-between min-h-140">
                            <div>
                                <h5 class="fw-bold mb-1 text-dark">
                                    <?php echo $name; ?>
                                </h5>
                                <small class="text-muted"><?php echo $year; ?></small>
                            </div>

                            <div class="mt-4">
                                <?php if ($total > 0): ?>
                                    <h4 class="fw-bold mb-0 text-danger">
                                        <small style="font-size: 0.65em;">AED</small> 
                                        <span class="blur-sensitive"><?php echo number_format($total, 2); ?></span>
                                    </h4>
                                    <small class="text-muted">Outstanding</small>
                                <?php elseif ($total < 0): ?>
                                    <h4 class="fw-bold mb-0 text-success">
                                        <small style="font-size: 0.65em;">AED</small> 
                                        <span class="blur-sensitive"><?php echo number_format(abs($total), 2); ?></span>
                                        <i class="fa-solid fa-check-double ms-1"></i>
                                    </h4>
                                    <small class="text-muted">Overpaid / Credit</small>
                                <?php elseif ($has_data && $total == 0): ?>
                                    <h4 class="fw-bold mb-0 text-success"><i class="fa-solid fa-check-circle"></i> Paid</h4>
                                    <small class="text-muted">Settled</small>
                                <?php else: ?>
                                    <div class="text-muted small py-1">- No Entry -</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel-premium border-0 shadow-lg p-0">
            <div class="modal-header border-bottom border-light p-4">
                <h5 class="modal-title fw-bold text-dark">Record Interest Disposal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form action="interest_actions.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="add_payment">

                    <div class="mb-3">
                        <label for="paymentDate" class="form-label fw-bold text-muted small">Payment Date</label>
                        <input type="date" name="payment_date" id="paymentDate" class="form-control rounded-pill px-3"
                            value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="targetMonthYear" class="form-label fw-bold text-muted small">Paying For (Month/Year)</label>
                        <input type="month" name="target_month_year" id="targetMonthYear" class="form-control rounded-pill px-3" required>
                    </div>

                    <div class="mb-3">
                        <label for="amountPaid" class="form-label fw-bold text-muted small">Amount Paid</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted border-end-0" style="border-top-left-radius: 20px; border-bottom-left-radius: 20px;">AED</span>
                            <input type="number" step="0.01" name="amount" id="amountPaid" class="form-control border-start-0" 
                                   placeholder="0.00" style="border-top-right-radius: 20px; border-bottom-right-radius: 20px;" required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="paymentTitle" class="form-label fw-bold text-muted small">Description / Charity Target</label>
                        <input type="text" name="title" id="paymentTitle" class="form-control rounded-pill px-3"
                            placeholder="e.g. Charity via Red Crescent" required>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-bold py-2.5 rounded-pill shadow-sm hover-lift">
                            Record Payment <i class="fa-solid fa-check ms-1"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Global Pending Modal -->
<div class="modal fade" id="globalPendingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel-premium border-0 shadow-lg p-0">
            <div class="modal-header border-bottom border-light p-4">
                <h5 class="modal-title fw-bold text-dark">Outstanding Interest Balances</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-4">
                    <h6 class="text-muted text-uppercase small fw-bold">Cumulative Outstanding</h6>
                    <h1 class="display-5 fw-bold text-danger mb-1">
                        <small class="fs-6 text-muted">AED</small> <span class="blur-sensitive"><?php echo number_format($global_net_pending, 2); ?></span>
                    </h1>
                    <p class="text-muted small mb-0">Total across all historic tracking years</p>
                </div>

                <div class="p-3 bg-light rounded-4 border border-light">
                    <div class="fw-bold small text-uppercase text-muted mb-2 ps-1">
                        Yearly Outstanding Breakdown
                    </div>
                    <div class="d-flex flex-column gap-2">
                        <?php if (empty($global_pending_breakdown)): ?>
                            <div class="text-center text-muted py-3 small">All interest is fully cleared.</div>
                        <?php else: ?>
                            <?php foreach ($global_pending_breakdown as $row): ?>
                                <div class="d-flex justify-content-between align-items-center py-2 px-3 bg-white rounded-3 shadow-sm border-light">
                                    <span class="fw-bold text-dark"><?php echo $row['year']; ?></span>
                                    <span class="text-danger fw-bold">AED <?php echo number_format($row['net_balance'], 2); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-3">
                <button type="button" class="btn btn-light w-100 rounded-pill py-2.5" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php Layout::footer(); ?>
