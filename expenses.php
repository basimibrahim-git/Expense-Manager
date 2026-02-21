<?php
$page_title = "Expenses Overview";
include_once 'config.php';
include_once 'includes/header.php';
include_once 'includes/sidebar.php';

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? 2026;

// Get total expenses per month for the selected year
$stmt = $pdo->prepare("
    SELECT MONTH(expense_date) as month, SUM(amount) as total
    FROM expenses
    WHERE tenant_id = :tenant_id AND YEAR(expense_date) = :year
    GROUP BY MONTH(expense_date)
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
    <h1 class="h3 fw-bold mb-0">Expenses <span class="text-primary fw-light">|</span> <?php echo $year; ?></h1>
    <div class="btn-group">
        <a href="?year=<?php echo $year - 1; ?>" class="btn btn-outline-light text-dark"><i
                class="fa-solid fa-chevron-left"></i></a>
        <button type="button" class="btn btn-light fw-bold px-4"><?php echo $year; ?></button>
        <a href="?year=<?php echo $year + 1; ?>" class="btn btn-outline-light text-dark"><i
                class="fa-solid fa-chevron-right"></i></a>
    </div>
</div>

<div class="row g-4">
    <?php foreach ($months as $num => $name): ?>
        <?php
        $total = $monthly_totals[$num] ?? 0;
        $is_current = ($year == $current_year && $num == $current_month);
        $has_data = $total > 0;

        // Card Style
        $bg_style = $is_current
            ? "background: linear-gradient(135deg, #0d6efd, #0a58ca); color: white;"
            : "background: white;";

        $text_muted = $is_current ? "text-white-50" : "text-muted";
        $text_primary = $is_current ? "text-white" : "text-primary";
        ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="monthly_expenses.php?month=<?php echo $num; ?>&year=<?php echo $year; ?>" class="text-decoration-none">
                <div class="card shadow-sm border-0 h-100 <?php echo $is_current ? 'shadow-lg transform-scale' : ''; ?>"
                    style="<?php echo $bg_style; ?> transition: transform 0.2s;">
                    <div class="card-body p-4 d-flex flex-column justify-content-between text-center">
                        <div>
                            <h5 class="fw-bold mb-1 <?php echo $is_current ? 'text-white' : 'text-dark'; ?>">
                                <?php echo $name; ?>
                            </h5>
                            <small class="<?php echo $text_muted; ?>"><?php echo $year; ?></small>
                        </div>

                        <div class="mt-4">
                            <?php if ($has_data): ?>
                                <h4 class="fw-bold mb-0 <?php echo $text_primary; ?>">
                                    <small style="font-size: 0.6em">AED</small>
                                    <span class="blur-sensitive"><?php echo number_format($total, 2); ?></span>
                                </h4>
                            <?php else: ?>
                                <div class="<?php echo $text_muted; ?> small py-1">- No Entry -</div>
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

<?php include_once 'includes/footer.php'; ?>