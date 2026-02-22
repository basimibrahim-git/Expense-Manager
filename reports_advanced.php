<?php
$page_title = "Advanced Reports";
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;

Bootstrap::init();

Layout::header();
Layout::sidebar();

$user_id = $_SESSION['user_id'];
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');
$prev_year = $year - 1;

// 1. Fetch YoY Category Data
$stmt = $pdo->prepare("
    SELECT category,
           SUM(CASE WHEN YEAR(expense_date) = ? THEN amount ELSE 0 END) as current_year,
           SUM(CASE WHEN YEAR(expense_date) = ? THEN amount ELSE 0 END) as previous_year
    FROM expenses
    WHERE tenant_id = ?
    AND YEAR(expense_date) IN (?, ?)
    GROUP BY category
    ORDER BY current_year DESC
");
$stmt->execute([$year, $prev_year, $_SESSION['tenant_id'], $year, $prev_year]);
$yoy_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch Heatmap Data (Spending by Day of Week vs Week of Month)
// This is for the current year
$stmt = $pdo->prepare("
    SELECT DAYOFWEEK(expense_date) as dow,
           DAY(expense_date) as dom,
           SUM(amount) as total
    FROM expenses
    WHERE tenant_id = ? AND YEAR(expense_date) = ?
    GROUP BY dow, dom
");
$stmt->execute([$_SESSION['tenant_id'], $year]);
$heatmap_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$heatmap = array_fill(1, 31, array_fill(1, 7, 0)); // Initialize 31 days x 7 days-of-week
foreach ($heatmap_raw as $row) {
    // We just need a simple Intensity map for the whole year by day of month
    // Actually a better heatmap is Day of Week vs Month
    // Let's do Day of Week (1-7) vs Month (1-12)
}

$stmt = $pdo->prepare("
    SELECT DAYOFWEEK(expense_date) as dow,
           MONTH(expense_date) as month,
           SUM(amount) as total
    FROM expenses
    WHERE tenant_id = ? AND YEAR(expense_date) = ?
    GROUP BY dow, month
");
$stmt->execute([$_SESSION['tenant_id'], $year]);
$heatmap_month_dow = $stmt->fetchAll(PDO::FETCH_ASSOC);

$heatmap_data = [];
$max_val = 0;
foreach ($heatmap_month_dow as $row) {
    $heatmap_data[$row['month']][$row['dow']] = $row['total'];
    if ($row['total'] > $max_val)
        $max_val = $row['total'];
}
?>

<div class="d-flex justify-content-between align-items-center mb-5">
    <div>
        <h1 class="h3 fw-bold mb-1">Advanced Reporting</h1>
        <p class="text-muted mb-0">Deep dive into your financial habits</p>
    </div>
    <div class="dropdown">
        <button class="btn btn-white shadow-sm dropdown-toggle fw-bold" type="button" data-bs-toggle="dropdown">
            Year:
            <?php echo $year; ?>
        </button>
        <ul class="dropdown-menu border-0 shadow">
            <?php for ($i = date('Y'); $i >= 2023; $i--): ?>
                <li><a class="dropdown-item" href="?year=<?php echo $i; ?>">
                        <?php echo $i; ?>
                    </a></li>
            <?php endfor; ?>
        </ul>
    </div>
</div>

<!-- YoY Summary Cards -->
<div class="row g-4 mb-5">
    <div class="col-md-6">
        <div class="glass-panel p-4 h-100">
            <h6 class="text-muted fw-bold text-uppercase small mb-3">Year-on-Year Growth</h6>
            <canvas id="yoyChart" style="max-height: 300px;"></canvas>
        </div>
    </div>
    <div class="col-md-6">
        <div class="glass-panel p-4 h-100">
            <h6 class="text-muted fw-bold text-uppercase small mb-3">Spending Intensity (Heatmap)</h6>
            <div class="table-responsive">
                <table class="table table-sm table-borderless text-center mb-0" style="font-size: 0.75rem;">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Sun</th>
                            <th>Mon</th>
                            <th>Tue</th>
                            <th>Wed</th>
                            <th>Thu</th>
                            <th>Fri</th>
                            <th>Sat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                        for ($m = 1; $m <= 12; $m++): ?>
                            <tr>
                                <td class="fw-bold text-muted text-start">
                                    <?php echo $months[$m - 1]; ?>
                                </td>
                                <?php for ($d = 1; $d <= 7; $d++):
                                    $val = $heatmap_data[$m][$d] ?? 0;
                                    $opacity = ($max_val > 0) ? ($val / $max_val) : 0;
                                    $color = $val > 0 ? "rgba(220, 53, 69, $opacity)" : "#f8f9fa";
                                    ?>
                                    <td style="background: <?php echo $color; ?>; width: 30px; height: 30px; border: 2px solid #fff;"
                                        title="<?php echo $months[$m - 1]; ?> DOW <?php echo $d; ?>: AED <?php echo number_format($val); ?>">
                                    </td>
                                <?php endfor; ?>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between x-small text-muted mt-2">
                <span>Less Intensity</span>
                <span>Higher Intensity</span>
            </div>
        </div>
    </div>
</div>

<!-- Category YoY Table -->
<div class="glass-panel p-4 mb-5">
    <h5 class="fw-bold mb-4">Category Analysis (
        <?php echo $year; ?> vs
        <?php echo $prev_year; ?>)
    </h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="bg-light">
                <tr>
                    <th class="ps-4">Category</th>
                    <th class="text-end">
                        <?php echo $prev_year; ?>
                    </th>
                    <th class="text-end">
                        <?php echo $year; ?>
                    </th>
                    <th class="text-center">Trend</th>
                    <th class="text-end pe-4">Change</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($yoy_data as $row):
                    if ($row['current_year'] == 0 && $row['previous_year'] == 0)
                        continue;
                    $diff = $row['current_year'] - $row['previous_year'];
                    $pct = ($row['previous_year'] > 0) ? ($diff / $row['previous_year']) * 100 : 100;
                    $color = ($diff > 0) ? 'text-danger' : 'text-success';
                    $icon = ($diff > 0) ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
                    if ($row['previous_year'] == 0)
                        $icon = 'fa-plus';
                    ?>
                    <tr>
                        <td class="ps-4 fw-bold">
                            <?php echo htmlspecialchars($row['category']); ?>
                        </td>
                        <td class="text-end text-muted">AED
                            <?php echo number_format($row['previous_year'], 2); ?>
                        </td>
                        <td class="text-end fw-bold">AED
                            <?php echo number_format($row['current_year'], 2); ?>
                        </td>
                        <td class="text-center">
                            <i class="fa-solid <?php echo $icon; ?> <?php echo $color; ?>"></i>
                        </td>
                        <td class="text-end pe-4 fw-bold <?php echo $color; ?>">
                            <?php echo ($diff > 0 ? '+' : ''); ?>
                            <?php echo number_format($pct, 1); ?>%
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const yoyCtx = document.getElementById('yoyChart').getContext('2d');
    new Chart(yoyCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($yoy_data, 'category')); ?>,
            datasets: [
                {
                    label: '<?php echo $prev_year; ?>',
                    data: <?php echo json_encode(array_column($yoy_data, 'previous_year')); ?>,
                    backgroundColor: 'rgba(108, 117, 125, 0.5)',
                    borderRadius: 4
                },
                {
                    label: '<?php echo $year; ?>',
                    data: <?php echo json_encode(array_column($yoy_data, 'current_year')); ?>,
                    backgroundColor: 'rgba(13, 110, 253, 0.8)',
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            },
            scales: {
                y: { beginAtZero: true, grid: { borderDash: [5, 5] } },
                x: { grid: { display: false } }
            }
        }
    });
</script>

<?php Layout::footer(); ?>