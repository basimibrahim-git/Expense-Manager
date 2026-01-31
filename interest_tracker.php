<?php
$page_title = "Interest Tracker";
require_once 'config.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';


$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');

// Get total interest per month for the selected year
$stmt = $pdo->prepare("
    SELECT MONTH(interest_date) as month, SUM(amount) as total 
    FROM interest_tracker 
    WHERE user_id = :user_id AND YEAR(interest_date) = :year 
    GROUP BY MONTH(interest_date)
");
$stmt->execute(['user_id' => $_SESSION['user_id'], 'year' => $year]);
$monthly_totals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get Year-Specific Totals (Due vs Paid for SELECTED YEAR)
$stmt = $pdo->prepare("SELECT SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_accrued, 
                            SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_paid
                     FROM interest_tracker WHERE user_id = ? AND YEAR(interest_date) = ?");
$stmt->execute([$_SESSION['user_id'], $year]);
$year_stats = $stmt->fetch();
$total_accrued = $year_stats['total_accrued'] ?? 0;
$total_paid = $year_stats['total_paid'] ?? 0;
// For the dashboard, "Remaining Due" is usually Net for that year? 
// Or does user want Global Remaining Due but Year Specific Paid?
// User said: "dashboard card shows that select years total". 
// So filtering both is correct based on instruction.
$current_balance = $total_accrued - $total_paid;

// Get Global Pending Breakdown (For the new Modal)
$stmt = $pdo->prepare("SELECT YEAR(interest_date) as year, SUM(amount) as net_balance 
                       FROM interest_tracker 
                       WHERE user_id = ? 
                       GROUP BY YEAR(interest_date) 
                       HAVING net_balance > 0 
                       ORDER BY year ASC");
$stmt->execute([$_SESSION['user_id']]);
$global_pending_breakdown = $stmt->fetchAll();

$global_net_pending = 0;
foreach($global_pending_breakdown as $row) { $global_net_pending += $row['net_balance']; }

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$current_month = date('n');
$current_year = date('Y');
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 fw-bold mb-0">Interest <span class="text-info fw-light">|</span> Tracker</h1>
        
        <div class="d-flex gap-2 align-items-center">
            <!-- Global Pending Button -->
            <button class="btn btn-outline-danger fw-bold btn-sm" data-bs-toggle="modal" data-bs-target="#globalPendingModal">
                <i class="fa-solid fa-list-ul me-1"></i> Total Due
            </button>
            
            <!-- Year Dropdown -->
            <form action="" method="GET" class="d-inline-block">
                <select name="year" class="form-select form-select-sm fw-bold border-info text-info" onchange="this.form.submit()" style="min-width: 100px;">
                    <?php 
                    $start_year = 2025;
                    $end_year = date('Y') + 5; // Show next 5 years for future planning
                    for ($y = $start_year; $y <= $end_year; $y++): 
                        $selected = ($y == $year) ? 'selected' : '';
                    ?>
                        <option value="<?php echo $y; ?>" <?php echo $selected; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>
    </div>
    
    <!-- Dashboard Stats (Filtered by Year) -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="glass-panel p-4 text-center position-relative overflow-hidden">
                <div class="position-absolute top-0 start-0 w-100 h-100 bg-info bg-opacity-10"></div>
                <div class="position-relative">
                    <h6 class="text-muted text-uppercase fw-bold small">Remaining Due (<?php echo $year; ?>)</h6>
                    <h2 class="display-5 fw-bold text-info mb-0">
                        <small class="fs-6">AED</small> <span class="blur-sensitive"><?php echo number_format($current_balance, 2); ?></span>
                    </h2>
                </div>
            </div>
        </div>
        <div class="col-md-6">
             <div class="glass-panel p-4 text-center position-relative overflow-hidden">
                 <div class="position-absolute top-0 start-0 w-100 h-100 bg-success bg-opacity-10"></div>
                <div class="position-relative">
                    <h6 class="text-muted text-uppercase fw-bold small">Total Paid in <?php echo $year; ?></h6>
                    <h2 class="display-5 fw-bold text-success mb-0">
                        <small class="fs-6">AED</small> <span class="blur-sensitive"><?php echo number_format($total_paid, 2); ?></span>
                    </h2>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Record Payment Button -->
    <button class="btn btn-dark w-100 py-3 fw-bold mb-4 shadow" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
        <i class="fa-solid fa-hand-holding-dollar me-2"></i> Record Interest Payment (Charity)
    </button>
</div>

<!-- Monthly Grid -->
<div class="row g-4">
    <?php foreach ($months as $num => $name): ?>
        <?php
        $total = $monthly_totals[$num] ?? 0;
        $is_current = ($year == $current_year && $num == $current_month);
        $has_data = $total != 0; // Show even if negative (overpaid?) or positive
        
        // Style Logic
        $bg_style = "background: white;";
        $text_class = "text-dark";
        $muted_class = "text-muted";
        
        if ($is_current) {
             $bg_style = "background: linear-gradient(135deg, #0dcaf0, #0aa2c0); color: white;";
             $text_class = "text-white";
             $muted_class = "text-white-50";
        }
        
        // Status Indication
        $status_html = "";
        if ($total > 0) {
             $amount_html = '<h4 class="fw-bold mb-0 ' . ($is_current ? 'text-white' : 'text-danger') . '"><small style="font-size: 0.6em">AED</small> ' . number_format($total, 2) . '</h4>';
             $status_html = '<small class="' . $muted_class . '">Outstanding</small>';
        } elseif ($total < 0) {
             $amount_html = '<h4 class="fw-bold mb-0 ' . ($is_current ? 'text-white' : 'text-success') . '"><small style="font-size: 0.6em">AED</small> ' . number_format(abs($total), 2) . ' <i class="fa-solid fa-check ms-1"></i></h4>';
             $status_html = '<small class="' . $muted_class . '">Overpaid / Credit</small>';
        } elseif ($has_data && $total == 0) {
              $amount_html = '<h4 class="fw-bold mb-0 ' . ($is_current ? 'text-white' : 'text-success') . '"><i class="fa-solid fa-check-circle"></i> Paid</h4>';
        } else {
             $amount_html = '<div class="' . $muted_class . ' small py-1">- No Entry -</div>';
        }

        ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="monthly_interest.php?month=<?php echo $num; ?>&year=<?php echo $year; ?>" class="text-decoration-none">
                <div class="card shadow-sm border-0 h-100 <?php echo $is_current ? 'shadow-lg transform-scale' : ''; ?>"
                    style="<?php echo $bg_style; ?> transition: transform 0.2s;">
                    <div class="card-body p-4 d-flex flex-column justify-content-between text-center">
                        <div>
                            <h5 class="fw-bold mb-1 <?php echo $text_class; ?>">
                                <?php echo $name; ?>
                            </h5>
                            <small class="<?php echo $muted_class; ?>"><?php echo $year; ?></small>
                        </div>

                        <div class="mt-4">
                            <?php echo $amount_html; ?>
                            <?php if($has_data) echo '<div class="mt-1">' . $status_html . '</div>'; ?>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Record Interest Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form action="interest_actions.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_payment">

                    <div class="mb-3">
                        <label class="form-label">Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Paying For (Month/Year)</label>
                        <input type="month" name="target_month_year" class="form-control" required>
                        <div class="form-text">Select which month/year this payment is for.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Amount Paid</label>
                        <div class="input-group">
                            <span class="input-group-text">AED</span>
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description / Charity</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Charity via Emirates Red Crescent" required>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-dark fw-bold">Record Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Global Pending Modal -->
<div class="modal fade" id="globalPendingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold text-danger">Total Interest Due</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-4">
                    <h6 class="text-muted text-uppercase small fw-bold">Global Outstanding</h6>
                    <h1 class="display-4 fw-bold text-danger mb-0">
                        <small class="fs-6 text-muted">AED</small> <?php echo number_format($global_net_pending, 2); ?>
                    </h1>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light fw-bold small text-uppercase py-2">
                        Breakdown by Year
                    </div>
                    <ul class="list-group list-group-flush">
                        <?php if (empty($global_pending_breakdown)): ?>
                            <li class="list-group-item text-center text-muted py-3">No pending interest found.</li>
                        <?php else: ?>
                            <?php foreach ($global_pending_breakdown as $row): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                                    <span class="fw-bold"><?php echo $row['year']; ?></span>
                                    <span class="text-danger fw-bold">AED <?php echo number_format($row['net_balance'], 2); ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light w-100" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
    .transform-scale:hover {
        transform: translateY(-5px);
    }
</style>

<?php require_once 'includes/footer.php'; ?>
