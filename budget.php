<?php
$page_title = "Smart Budget";
require_once 'config.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$user_id = $_SESSION['user_id'];
$month = date('n');
$year = date('Y');

// 1. Get Total Income for this month
$stmt = $pdo->prepare("SELECT SUM(amount) FROM income WHERE tenant_id = ? AND MONTH(income_date) = ? AND YEAR(income_date) = ?");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$total_income = $stmt->fetchColumn() ?: 0;

// 2. Get Expenses grouped by Category
$stmt = $pdo->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ? GROUP BY category");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$expenses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Define Buckets
$needs_cats = ['Grocery', 'Medical', 'Utilities', 'Transport', 'Education'];
$wants_cats = ['Food', 'Shopping', 'Entertainment', 'Travel', 'Other'];

$total_needs = 0;
$total_wants = 0;

foreach ($expenses as $cat => $amount) {
    if (in_array($cat, $needs_cats)) {
        $total_needs += $amount;
    } else {
        $total_wants += $amount; // Default to wants if unknown
    }
}

// 4. Calculate Savings (Remaining)
$total_spent = $total_needs + $total_wants;
$total_savings = $total_income - $total_spent;

// Avoid division by zero
$income_base = $total_income > 0 ? $total_income : 1;

$needs_pct = ($total_needs / $income_base) * 100;
$wants_pct = ($total_wants / $income_base) * 100;
$savings_pct = ($total_savings / $income_base) * 100;

// Status Logic
function getStatusColor($pct, $target, $is_savings = false)
{
    if ($is_savings) {
        return $pct >= $target ? "success" : "danger";
    }
    return $pct <= $target ? "success" : "danger";
}

$needs_color = getStatusColor($needs_pct, 50);
$wants_color = getStatusColor($wants_pct, 30);
$savings_color = getStatusColor($savings_pct, 20, true);

?>

<div class="mb-4">
    <h1 class="h3 fw-bold mb-1">Smart Budget <span class="badge bg-light text-dark border ms-2">50/30/20 Rule</span>
    </h1>
    <p class="text-muted">Analysis for
        <?php echo date('F Y'); ?>
    </p>
</div>

<?php if ($total_income == 0): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation me-2"></i>
        No income recorded for this month. <a href="add_income.php" class="alert-link">Add Income</a> to see your budget
        analysis.
    </div>
<?php endif; ?>

<div class="row g-4 mb-5">
    <!-- Needs (50%) -->
    <div class="col-md-4">
        <div class="glass-panel p-4 h-100">
            <div class="d-flex justify-content-between mb-3">
                <span class="badge bg-<?php echo $needs_color; ?>-subtle text-<?php echo $needs_color; ?>">Target:
                    50%</span>
                <i class="fa-solid fa-house-chimney text-<?php echo $needs_color; ?> fa-lg"></i>
            </div>
            <h4 class="fw-bold mb-0">Needs</h4>
            <div class="display-6 fw-bold my-2">AED <span class="blur-sensitive">
                    <?php echo number_format($total_needs); ?>
                </span></div>

            <progress class="progress w-100" style="height: 10px;" value="<?php echo min($needs_pct, 100); ?>"
                max="100"></progress>
            <div class="mt-2 small text-muted">
                You have used <strong>
                    <?php echo number_format($needs_pct, 1); ?>%
                </strong> of your income.
            </div>
            <div class="mt-2 text-muted x-small">
                Includes: Grocery, Rent, Bills, Transport
            </div>
        </div>
    </div>

    <!-- Wants (30%) -->
    <div class="col-md-4">
        <div class="glass-panel p-4 h-100">
            <div class="d-flex justify-content-between mb-3">
                <span class="badge bg-<?php echo $wants_color; ?>-subtle text-<?php echo $wants_color; ?>">Target:
                    30%</span>
                <i class="fa-solid fa-gamepad text-<?php echo $wants_color; ?> fa-lg"></i>
            </div>
            <h4 class="fw-bold mb-0">Wants</h4>
            <div class="display-6 fw-bold my-2">AED <span class="blur-sensitive">
                    <?php echo number_format($total_wants); ?>
                </span></div>

            <progress class="progress w-100" style="height: 10px;" value="<?php echo min($wants_pct, 100); ?>"
                max="100"></progress>
            <div class="mt-2 small text-muted">
                You have used <strong>
                    <?php echo number_format($wants_pct, 1); ?>%
                </strong> of your income.
            </div>
            <div class="mt-2 text-muted x-small">
                Includes: Dining, Shopping, Travel, Entertainment
            </div>
        </div>
    </div>

    <!-- Savings (20%) -->
    <div class="col-md-4">
        <div class="glass-panel p-4 h-100">
            <div class="d-flex justify-content-between mb-3">
                <span class="badge bg-<?php echo $savings_color; ?>-subtle text-<?php echo $savings_color; ?>">Target:
                    20%</span>
                <i class="fa-solid fa-piggy-bank text-<?php echo $savings_color; ?> fa-lg"></i>
            </div>
            <h4 class="fw-bold mb-0">Savings</h4>
            <div class="display-6 fw-bold my-2">AED <span class="blur-sensitive">
                    <?php echo number_format($total_savings); ?>
                </span></div>

            <progress class="progress w-100" style="height: 10px;" value="<?php echo min(max($savings_pct, 0), 100); ?>"
                max="100"></progress>
            <div class="mt-2 small text-muted">
                You have saved <strong>
                    <?php echo number_format($savings_pct, 1); ?>%
                </strong> of your income.
            </div>
            <div class="mt-2 text-muted x-small">
                Remaining Income (Income - Expenses)
            </div>
        </div>
    </div>
</div>

<div class="glass-panel p-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0">Category Budgets vs Actuals</h5>
        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
            <a href="manage_budgets.php" class="btn btn-outline-primary btn-sm rounded-pill px-3">Manage Targets</a>
        <?php endif; ?>
    </div>

    <?php
    // Fetch specifically defined budgets
    $stmt = $pdo->prepare("SELECT category, amount FROM budgets WHERE tenant_id = ? AND month = ? AND year = ?");
    $stmt->execute([$_SESSION['tenant_id'], $month, $year]);
    $cat_budgets = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    ?>

    <?php if (empty($cat_budgets)): ?>
        <div class="text-center py-4 bg-light rounded-4">
            <p class="text-muted mb-0">No specific category budgets defined for this month.</p>
            <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                <a href="manage_budgets.php" class="small text-primary">Set individual targets</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($cat_budgets as $cat => $limit):
                $spent = $expenses[$cat] ?? 0;
                $pct = ($spent / $limit) * 100;
                $var = $limit - $spent;
                $color = 'success';
                if ($pct > 80) {
                    $color = 'warning';
                }
                if ($pct > 100) {
                    $color = 'danger';
                }
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="p-3 border rounded-4 bg-white hover-shadow transition-all">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="fw-bold"><?php echo $cat; ?></div>
                            <div class="badge bg-<?php echo $color; ?>-subtle text-<?php echo $color; ?>">
                                <?php echo number_format($pct, 0); ?>%
                            </div>
                        </div>
                        <progress class="progress w-100 mb-2" style="height: 8px;" value="<?php echo min($pct, 100); ?>"
                            max="100"></progress>
                        <div class="d-flex justify-content-between small text-muted">
                            <span>Spent: AED <?php echo number_format($spent); ?></span>
                            <span>Goal: <?php echo number_format($limit); ?></span>
                        </div>
                        <?php if ($var < 0): ?>
                            <div class="mt-2 text-danger x-small fw-bold">
                                <i class="fa-solid fa-arrow-up"></i> Over by AED <?php echo number_format(abs($var)); ?>
                            </div>
                        <?php else: ?>
                            <div class="mt-2 text-success x-small fw-bold">
                                <i class="fa-solid fa-arrow-down"></i> AED <?php echo number_format($var); ?> remaining
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="glass-panel p-4">
    <h5 class="fw-bold mb-3">Budget Insights</h5>
    <?php if ($savings_pct >= 20): ?>
        <p class="text-success mb-0"><i class="fa-solid fa-circle-check me-2"></i> You are hitting your savings goal! Great
            job.</p>
    <?php else: ?>
        <p class="text-danger mb-0"><i class="fa-solid fa-circle-exclamation me-2"></i> You are falling short of the 20%
            savings target. Try reducing your 'Wants'.</p>
    <?php endif; ?>

    <?php if ($needs_pct > 50): ?>
        <p class="text-warning mt-2 mb-0"><i class="fa-solid fa-triangle-exclamation me-2"></i> Your 'Needs' are high
            (>50%). Consider reviewing recurring bills.</p>
    <?php endif; ?>

    <?php
    $over_cats = [];
    foreach ($cat_budgets as $cat => $limit) {
        if (($expenses[$cat] ?? 0) > $limit) {
            $over_cats[] = $cat;
        }
    }
    if (!empty($over_cats)):
        ?>
        <p class="text-danger mt-2 mb-0">
            <i class="fa-solid fa-circle-xmark me-2"></i> You have exceeded your budget in:
            <strong><?php echo implode(', ', $over_cats); ?></strong>
        </p>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
