<?php
$page_title = "Bank Balances";
require_once 'config.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');

// Get Total Balance Snapshot per month
// Get Total Balance Snapshot per month
// Note: This logic sums the *latest* entry for each bank within that month to get "Total Net Worth"
$stmt = $pdo->prepare("
    SELECT MONTH(balance_date) as month, 
    SUM(CASE WHEN currency='INR' THEN amount / 24 ELSE amount END) as total 
    FROM bank_balances b1
    WHERE tenant_id = :tenant_id 
    AND YEAR(balance_date) = :year
    AND id = (
        SELECT MAX(id) 
        FROM bank_balances b2 
        WHERE b2.bank_name = b1.bank_name 
        AND MONTH(b2.balance_date) = MONTH(b1.balance_date) 
        AND YEAR(b2.balance_date) = YEAR(b1.balance_date)
        AND b2.tenant_id = b1.tenant_id
    )
    GROUP BY MONTH(balance_date)
");
$stmt->execute(['tenant_id' => $_SESSION['tenant_id'], 'year' => $year]);
$monthly_totals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

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

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">My Banks <span class="text-info fw-light">|</span>
        <?php echo $year; ?>
    </h1>
    <div class="d-flex align-items-center gap-2">
        <a href="my_banks.php" class="btn btn-outline-primary shadow-sm px-3">
            <i class="fa-solid fa-list me-2"></i> Manage Banks
        </a>
        <a href="my_banks.php" class="btn btn-primary shadow-sm px-3">
            <i class="fa-solid fa-building-columns me-2"></i> My Banks
        </a>
        <div class="btn-group shadow-sm">
            <a href="?year=<?php echo $year - 1; ?>" class="btn btn-outline-light text-dark"><i
                    class="fa-solid fa-chevron-left"></i></a>
            <button type="button" class="btn btn-light fw-bold px-4">
                <?php echo $year; ?>
            </button>
            <a href="?year=<?php echo $year + 1; ?>" class="btn btn-outline-light text-dark"><i
                    class="fa-solid fa-chevron-right"></i></a>
        </div>
    </div>
</div>

<div class="row g-4">
    <?php foreach ($months as $num => $name): ?>
        <?php
        $total = $monthly_totals[$num] ?? 0;
        $is_current = ($year == $current_year && $num == $current_month);
        $has_data = $total > 0;

        $bg_style = $is_current
            ? "background: linear-gradient(135deg, #0dcaf0, #0aa2c0); color: white;"
            : "background: white;";

        $text_muted = $is_current ? "text-white-50" : "text-muted";
        $text_primary = $is_current ? "text-white" : "text-info";
        ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="monthly_balances.php?month=<?php echo $num; ?>&year=<?php echo $year; ?>" class="text-decoration-none">
                <div class="card shadow-sm border-0 h-100 <?php echo $is_current ? 'shadow-lg transform-scale' : ''; ?>"
                    style="<?php echo $bg_style; ?> transition: transform 0.2s;">
                    <div class="card-body p-4 d-flex flex-column justify-content-between text-center">
                        <div>
                            <h5 class="fw-bold mb-1 <?php echo $is_current ? 'text-white' : 'text-dark'; ?>">
                                <?php echo $name; ?>
                            </h5>
                            <small class="<?php echo $text_muted; ?>">
                                <?php echo $year; ?>
                            </small>
                        </div>

                        <div class="mt-4">
                            <?php if ($has_data): ?>
                                <h4 class="fw-bold mb-0 <?php echo $text_primary; ?>">
                                    <small style="font-size: 0.6em">AED</small>
                                    <span class="blur-sensitive"><?php echo number_format($total, 2); ?></span>
                                </h4>
                                <div class="small <?php echo $text_muted; ?>">Total Assets</div>
                            <?php else: ?>
                                <div class="<?php echo $text_muted; ?> small py-1">- Not Recorded -</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<style>
    .transform-scale:hover {
        transform: translateY(-5px);
    }
</style>

<?php require_once 'includes/footer.php'; ?>
