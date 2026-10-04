<?php
$page_title = "Bank Balances";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\BalanceHelper;

Bootstrap::init();

Layout::header();
Layout::sidebar();

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');
$tenant_id = (int) $_SESSION['tenant_id'];

// Total balance per month = latest snapshot of every bank as of that month's last day (AED).
// Months after the current one have no data yet.
$monthly_totals = [];
$this_month_start = date('Y-m-01');
for ($m = 1; $m <= 12; $m++) {
    $month_start = sprintf('%04d-%02d-01', $year, $m);
    if ($month_start > $this_month_start) {
        break;
    }
    $monthly_totals[$m] = BalanceHelper::totalAed($pdo, $tenant_id, date('Y-m-t', strtotime($month_start)));
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

// Build trend data array for Chart.js
$trend_data = [];
foreach ($months as $num => $name) {
    $trend_data[] = isset($monthly_totals[$num]) ? round($monthly_totals[$num], 2) : null;
}

$current_month = date('n');
$current_year = date('Y');
?>

<div class="container-fluid py-4">
    <!-- Header & Controls -->
    <div class="row align-items-center mb-4 g-3">
        <div class="col-md-6">
            <h1 class="h3 fw-bold mb-1 text-dark">Liquid Net Worth Map</h1>
            <p class="text-muted mb-0">Monthly snapshots of cash reserves across all bank assets for <strong><?php echo (int) $year; ?></strong></p>
        </div>
        <div class="col-md-6">
            <div class="d-flex justify-content-md-end gap-2 align-items-center">
                <a href="my_banks.php" class="btn btn-outline-primary rounded-pill px-4 hover-lift">
                    <i class="fa-solid fa-list me-1"></i> Manage Accounts
                </a>
                <div class="btn-group shadow-sm" style="border-radius: 50px; overflow: hidden;">
                    <a href="?year=<?php echo max(2000, $year - 1); ?>" class="btn btn-light px-3"><i class="fa-solid fa-chevron-left"></i></a>
                    <span class="btn btn-light fw-bold px-4 disabled text-dark" style="opacity: 1;"><?php echo (int) $year; ?></span>
                    <a href="?year=<?php echo min(2100, $year + 1); ?>" class="btn btn-light px-3"><i class="fa-solid fa-chevron-right"></i></a>
                </div>
            </div>
        </div>
    </div>

    <!-- Net Worth Sparkline Trend -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="glass-panel-premium p-4 shadow-sm">
                <h5 class="fw-bold mb-3 text-dark d-flex align-items-center">
                    <i class="fa-solid fa-chart-area text-info me-2"></i> <?php echo (int) $year; ?> Net Worth Trend Line
                </h5>
                <div style="position: relative; height: 160px; width: 100%;">
                    <canvas id="netWorthTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Months Grid -->
    <div class="row g-3">
        <?php foreach ($months as $num => $name): ?>
            <?php
            $total = $monthly_totals[$num] ?? 0;
            $is_current = ($year == $current_year && $num == $current_month);
            $has_data = abs($total) > 0.004;

            // Custom Card Design
            if ($is_current) {
                $card_style = "background: linear-gradient(135deg, #0ea5e9, #0284c7); border: none !important; color: #ffffff;";
                $title_color = "text-white";
                $text_muted_class = "text-white-50";
                $val_color = "text-white";
            } else {
                $card_style = "background: rgba(255, 255, 255, 0.45); border: 1px solid rgba(255, 255, 255, 0.25);";
                $title_color = "text-dark";
                $text_muted_class = "text-muted";
                $val_color = "text-primary";
            }
            ?>
            <div class="col-6 col-md-4 col-lg-3">
                <a href="monthly_balances.php?month=<?php echo $num; ?>&year=<?php echo (int) $year; ?>" class="text-decoration-none d-block h-100">
                    <div class="glass-panel-premium p-4 h-100 d-flex flex-column justify-content-between text-center hover-lift position-relative overflow-hidden" 
                         style="border-radius: 16px; min-height: 180px; <?php echo $card_style; ?>">
                        
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small <?php echo $text_muted_class; ?> fw-bold tracking-wider"><?php echo (int) $year; ?></span>
                                <?php if ($has_data): ?>
                                    <span class="badge rounded-pill <?php echo $is_current ? 'bg-white bg-opacity-25 text-white' : 'bg-success bg-opacity-10 text-success'; ?> small" style="font-size: 0.75rem;">
                                        Active
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h5 class="fw-bold mb-1 <?php echo $title_color; ?>">
                                <?php echo $name; ?>
                            </h5>
                        </div>

                        <div class="mt-4">
                            <?php if ($has_data): ?>
                                <h3 class="fw-bold mb-0 <?php echo $val_color; ?>">
                                    <small style="font-size: 0.55em;">AED</small> 
                                    <span class="blur-sensitive"><?php echo number_format($total, 2); ?></span>
                                </h3>
                                <div class="small <?php echo $text_muted_class; ?> mt-1">Balance at Month End</div>
                            <?php else: ?>
                                <div class="<?php echo $text_muted_class; ?> small py-2 border border-dashed rounded-pill" style="border-color: rgba(0,0,0,0.08) !important;">
                                    No Snapshot
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('netWorthTrendChart').getContext('2d');
        
        // Gradient fill for area chart
        const gradient = ctx.createLinearGradient(0, 0, 0, 140);
        gradient.addColorStop(0, 'rgba(14, 165, 233, 0.35)');
        gradient.addColorStop(1, 'rgba(14, 165, 233, 0.02)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo Html::json(array_values($months)); ?>,
                datasets: [{
                    label: 'Net Worth Trend',
                    data: <?php echo Html::json($trend_data); ?>,
                    borderColor: '#0ea5e9',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    tension: 0.35,
                    fill: true,
                    pointBackgroundColor: '#0ea5e9',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 1.5,
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
                        padding: 12,
                        backgroundColor: 'rgba(30, 41, 59, 0.9)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                return ' AED ' + context.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2 });
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { 
                            color: 'rgba(0, 0, 0, 0.05)',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#64748b',
                            font: { size: 10 },
                            callback: function(value) {
                                return 'AED ' + value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: '#64748b',
                            font: { size: 10 }
                        }
                    }
                }
            }
        });
    });
</script>

<?php Layout::footer(); ?>
