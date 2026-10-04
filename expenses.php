<?php
$page_title = "Expenses Overview";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;

Bootstrap::init();

Layout::header();
Layout::sidebar();

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');

// Get total expenses per month for the selected year
$stmt = $pdo->prepare("
    SELECT MONTH(expense_date) as month, SUM(amount) as total
    FROM expenses
    WHERE tenant_id = :tenant_id AND YEAR(expense_date) = :year
    GROUP BY MONTH(expense_date)
");
$stmt->execute(['tenant_id' => $_SESSION['tenant_id'], 'year' => $year]);
$monthly_totals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get transaction counts per month
$count_stmt = $pdo->prepare("
    SELECT MONTH(expense_date) as month, COUNT(*) as tx_count
    FROM expenses
    WHERE tenant_id = :tenant_id AND YEAR(expense_date) = :year
    GROUP BY MONTH(expense_date)
");
$count_stmt->execute(['tenant_id' => $_SESSION['tenant_id'], 'year' => $year]);
$monthly_counts = $count_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

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

// Build trend data array for Chart.js
$trend_data = [];
foreach ($months as $num => $name) {
    $trend_data[] = $monthly_totals[$num] ?? 0;
}

$current_month = date('n');
$current_year = date('Y');
?>

<!-- Header & Year Selector -->
<div class="row mb-4 align-items-center">
    <div class="col-md-6">
        <h1 class="h3 fw-bold mb-1">Expenses Map</h1>
        <p class="text-muted mb-0">Yearly overview and trend analysis for <b><?php echo $year; ?></b></p>
    </div>
    <div class="col-md-6 text-md-end mt-3 mt-md-0">
        <div class="btn-group shadow-sm">
            <a href="?year=<?php echo $year - 1; ?>" class="btn btn-white border px-3"><i class="fa-solid fa-chevron-left"></i></a>
            <button type="button" class="btn btn-white border fw-bold px-4 disabled" style="opacity: 1;"><?php echo $year; ?></button>
            <a href="?year=<?php echo $year + 1; ?>" class="btn btn-white border px-3"><i class="fa-solid fa-chevron-right"></i></a>
        </div>
    </div>
</div>

<!-- Yearly Spend Sparkline Trend -->
<div class="row mb-5">
    <div class="col-12">
        <div class="glass-panel-premium p-4">
            <h5 class="fw-bold mb-3 d-flex align-items-center">
                <i class="fa-solid fa-chart-area text-primary me-2"></i> <?php echo $year; ?> Spending Trend Line
            </h5>
            <div style="position: relative; height: 140px; width: 100%;">
                <canvas id="expensesTrendChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Months Grid -->
<div class="row g-4 mb-5">
    <?php foreach ($months as $num => $name): ?>
        <?php
        $total = $monthly_totals[$num] ?? 0;
        $count = $monthly_counts[$num] ?? 0;
        $is_current = ($year == $current_year && $num == $current_month);
        $has_data = $total > 0;

        // Custom Card Design
        if ($is_current) {
            $card_class = "gradient-card-accent hover-lift text-white";
            $text_muted_class = "text-white-50";
            $badge_class = "bg-white bg-opacity-20 text-white";
        } else {
            $card_class = "glass-panel-premium month-grid-card hover-lift";
            $text_muted_class = "text-muted";
            $badge_class = "bg-primary-subtle text-primary";
        }
        ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="monthly_expenses.php?month=<?php echo $num; ?>&year=<?php echo $year; ?>" class="text-decoration-none text-dark">
                <div class="p-4 h-100 d-flex flex-column justify-content-between text-center <?php echo $card_class; ?>" style="min-height: 180px;">
                    <div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small <?php echo $text_muted_class; ?> fw-bold uppercase tracking-wider"><?php echo $year; ?></span>
                            <?php if ($count > 0): ?>
                                <span class="badge rounded-pill <?php echo $badge_class; ?> small">
                                    <?php echo $count; ?> txns
                                </span>
                            <?php endif; ?>
                        </div>
                        <h4 class="fw-bold mb-1 <?php echo $is_current ? 'text-white' : 'text-dark'; ?>">
                            <?php echo $name; ?>
                        </h4>
                    </div>

                    <div class="mt-4">
                        <?php if ($has_data): ?>
                            <h3 class="fw-bold mb-0 <?php echo $is_current ? 'text-white' : 'text-primary'; ?>">
                                <span class="small" style="font-size: 0.6em">AED</span>
                                <span class="blur-sensitive"><?php echo number_format($total, 2); ?></span>
                            </h3>
                        <?php else: ?>
                            <div class="<?php echo $text_muted_class; ?> small py-1">- No Entries -</div>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('expensesTrendChart').getContext('2d');
        
        // Gradient fill for area chart
        const gradient = ctx.createLinearGradient(0, 0, 0, 120);
        gradient.addColorStop(0, 'rgba(99, 102, 241, 0.4)');
        gradient.addColorStop(1, 'rgba(99, 102, 241, 0)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_values($months)); ?>,
                datasets: [{
                    label: 'Monthly Spend',
                    data: <?php echo json_encode($trend_data); ?>,
                    borderColor: '#6366f1',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#6366f1',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'AED ' + context.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2 });
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { borderDash: [5, 5] },
                        ticks: {
                            callback: function(value) {
                                return 'AED ' + value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    });
</script>

<?php Layout::footer(); ?>
