<?php
$page_title = "Sadaqa Tracker";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;

Bootstrap::init();

Layout::header();
Layout::sidebar();

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');

// Get total sadaqa per month for the selected year
$stmt = $pdo->prepare("
    SELECT MONTH(sadaqa_date) as month, SUM(amount) as total
    FROM sadaqa_tracker
    WHERE tenant_id = :tenant_id AND YEAR(sadaqa_date) = :year
    GROUP BY MONTH(sadaqa_date)
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

<!-- Premium Header Banner -->
<div class="row mb-4">
    <div class="col-12">
        <div class="gradient-card-primary p-4 rounded-4 hover-lift position-relative overflow-hidden shadow-sm" style="border-radius: 16px;">
            <div class="position-absolute top-0 end-0 p-3 opacity-10">
                <i class="fa-solid fa-heart fa-9x"></i>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 position-relative" style="z-index: 2;">
                <div>
                    <h1 class="h3 fw-bold mb-1 text-white">Sadaqa Tracker</h1>
                    <p class="text-white text-opacity-75 mb-0">Record and review your charitable contributions for the year <?php echo $year; ?></p>
                </div>
                <div>
                    <div class="d-flex align-items-center bg-white bg-opacity-20 rounded-pill p-1">
                        <a href="?year=<?php echo $year - 1; ?>" class="btn btn-sm btn-link text-white text-decoration-none px-3 py-1">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                        <span class="text-white fw-bold px-3"><?php echo $year; ?></span>
                        <a href="?year=<?php echo $year + 1; ?>" class="btn btn-sm btn-link text-white text-decoration-none px-3 py-1">
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <?php foreach ($months as $num => $name): ?>
        <?php
        $total = $monthly_totals[$num] ?? 0;
        $is_current = ($year == $current_year && $num == $current_month);
        $has_data = $total > 0;
        ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="monthly_sadaqa.php?month=<?php echo $num; ?>&year=<?php echo $year; ?>" class="text-decoration-none d-block h-100">
                <?php if ($is_current): ?>
                    <!-- Active/Current Month Card (Emerald Gradient) -->
                    <div class="card border-0 h-100 shadow-md hover-lift transition-all" 
                         style="background: linear-gradient(135deg, #10b981, #059669); border-radius: 16px;">
                        <div class="card-body p-4 d-flex flex-column justify-content-between text-center min-h-140">
                            <div>
                                <h5 class="fw-bold mb-1 text-white">
                                    <?php echo $name; ?>
                                </h5>
                                <span class="badge rounded-pill bg-white bg-opacity-25 text-white small px-2 py-0.5">Current</span>
                            </div>

                            <div class="mt-4">
                                <?php if ($has_data): ?>
                                    <h4 class="fw-bold mb-0 text-white">
                                        <small style="font-size: 0.65em;">AED</small>
                                        <span class="blur-sensitive"><?php echo number_format($total, 2); ?></span>
                                    </h4>
                                <?php else: ?>
                                    <div class="text-white text-opacity-75 small py-1">- No Entry -</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Regular Month Card (Premium Glass) -->
                    <div class="glass-panel-premium h-100 hover-lift text-center">
                        <div class="p-4 d-flex flex-column justify-content-between min-h-140">
                            <div>
                                <h5 class="fw-bold mb-1 text-dark">
                                    <?php echo $name; ?>
                                </h5>
                                <small class="text-muted"><?php echo $year; ?></small>
                            </div>

                            <div class="mt-4">
                                <?php if ($has_data): ?>
                                    <h4 class="fw-bold mb-0 text-primary">
                                        <small style="font-size: 0.65em;">AED</small>
                                        <span class="blur-sensitive"><?php echo number_format($total, 2); ?></span>
                                    </h4>
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

<?php
Layout::footer();
?>
