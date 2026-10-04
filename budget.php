<?php
$page_title = "Smart Budget";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\ExchangeRateHelper;
use App\Helpers\Categories;
use App\Helpers\BudgetAlertHelper;

Bootstrap::init();

Layout::header();
Layout::sidebar();

$user_id = $_SESSION['user_id'];
$month = date('n');
$year = date('Y');

// 1. Get Total Income for this month (income is stored in its entered currency; convert to AED)
$stmt = $pdo->prepare("SELECT COALESCE(currency, 'AED') AS currency, SUM(amount) AS total FROM income WHERE tenant_id = ? AND MONTH(income_date) = ? AND YEAR(income_date) = ? GROUP BY COALESCE(currency, 'AED')");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$total_income = 0;
foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $cur => $sum) {
    $cur = strtoupper((string) $cur);
    $total_income += (float) $sum * ($cur === 'AED' ? 1.0 : ExchangeRateHelper::getRate($cur, 'AED', $pdo));
}

// 2. Get Expenses grouped by Category
$stmt = $pdo->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ? GROUP BY category");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$expenses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Define Buckets
$needs_cats = Categories::NEEDS;
$wants_cats = Categories::WANTS;

$total_needs = 0;
$total_wants = 0;

foreach ($expenses as $cat => $amount) {
    if (in_array($cat, $needs_cats, true)) {
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

// Budget alert thresholds (manage_budgets.php#alerts)
$alert_settings = BudgetAlertHelper::settings($pdo, (int) $_SESSION['tenant_id']);
$warn_pct = $alert_settings['warn_pct'];
$over_pct = $alert_settings['over_pct'];
$can_edit = ($_SESSION['permission'] ?? 'edit') !== 'read_only';

// Helper for SVG circular progress offset
function getCircleOffset($pct) {
    $clamped = max(0, min($pct, 100));
    return 314.16 - (314.16 * ($clamped / 100));
}
?>

<style>
    .budget-track { position: relative; }
    .budget-marker {
        position: absolute; top: -3px; width: 2px; height: 14px;
        transform: translateX(-1px); border-radius: 1px;
    }
    .budget-marker-warn { background: #d97706; }
    .budget-marker-over { background: #dc2626; }
    .budget-alert-pill { font-size: 0.75rem; }
    a.budget-alert-pill:hover { filter: brightness(0.95); }
</style>

<!-- SVG Gradients Definition -->
<svg width="0" height="0" style="position: absolute;">
  <defs>
    <linearGradient id="needsGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#10b981" />
      <stop offset="100%" stop-color="#34d399" />
    </linearGradient>
    <linearGradient id="wantsGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#6366f1" />
      <stop offset="100%" stop-color="#8b5cf6" />
    </linearGradient>
    <linearGradient id="savingsGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#06b6d4" />
      <stop offset="100%" stop-color="#2dd4bf" />
    </linearGradient>
    <linearGradient id="dangerGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#f43f5e" />
      <stop offset="100%" stop-color="#fb7185" />
    </linearGradient>
  </defs>
</svg>

<!-- Premium Header Banner -->
<div class="row mb-4">
    <div class="col-12">
        <div class="gradient-card-primary p-4 rounded-4 hover-lift position-relative overflow-hidden shadow-sm" style="border-radius: 16px;">
            <div class="position-absolute top-0 end-0 p-3 opacity-10">
                <i class="fa-solid fa-chart-pie fa-9x"></i>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 position-relative" style="z-index: 2;">
                <div>
                    <h1 class="h3 fw-bold mb-1 text-white">Smart Budget</h1>
                    <p class="text-white text-opacity-75 mb-0">Unified financial planning using the 50/30/20 Rule for <?php echo date('F Y'); ?></p>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge bg-white text-primary border-0 fw-bold px-3 py-2 fs-6" style="border-radius: 8px;">50% Needs</span>
                    <span class="badge bg-white text-primary border-0 fw-bold px-3 py-2 fs-6" style="border-radius: 8px;">30% Wants</span>
                    <span class="badge bg-white text-primary border-0 fw-bold px-3 py-2 fs-6" style="border-radius: 8px;">20% Savings</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($total_income == 0): ?>
    <div class="alert alert-warning border-0 shadow-sm p-3 rounded-4 mb-4">
        <div class="d-flex align-items-center">
            <i class="fa-solid fa-triangle-exclamation text-warning fa-xl me-3"></i>
            <div>
                <strong>No income recorded this month!</strong> 
                <a href="add_income.php" class="alert-link text-decoration-none ms-1">Add Income</a> to activate your smart budget metrics.
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4 mb-5">
    <!-- Needs (50%) -->
    <div class="col-md-4">
        <div class="glass-panel-premium p-4 h-100 text-center hover-lift position-relative">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="badge rounded-pill <?php echo $needs_color === 'success' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?> px-3 py-1 fw-bold">
                    Target: 50%
                </span>
                <div class="p-2 rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="fa-solid fa-house-chimney text-primary"></i>
                </div>
            </div>

            <div class="position-relative d-inline-flex align-items-center justify-content-center my-3">
                <svg width="130" height="130" viewBox="0 0 120 120" style="transform: rotate(-90deg);">
                    <circle cx="60" cy="60" r="50" fill="transparent" stroke="rgba(var(--primary-rgb, 99, 102, 241), 0.08)" stroke-width="8"></circle>
                    <circle cx="60" cy="60" r="50" fill="transparent" 
                            stroke="<?php echo $needs_color === 'success' ? 'url(#needsGrad)' : 'url(#dangerGrad)'; ?>" 
                            stroke-width="8"
                            stroke-dasharray="314.16" 
                            stroke-dashoffset="<?php echo getCircleOffset($needs_pct); ?>" 
                            stroke-linecap="round"
                            style="transition: stroke-dashoffset 0.8s ease-in-out;"></circle>
                </svg>
                <div class="position-absolute d-flex flex-column align-items-center">
                    <span class="fs-4 fw-bold text-dark"><?php echo number_format($needs_pct, 1); ?>%</span>
                    <span class="text-muted small">used</span>
                </div>
            </div>

            <h4 class="fw-bold mb-1">Needs</h4>
            <div class="h3 fw-bold text-dark mb-3">
                <small class="text-muted fs-6">AED</small>
                <span class="blur-sensitive"><?php echo number_format($total_needs); ?></span>
            </div>
            
            <p class="text-muted small mb-0 px-2">
                Includes essential groceries, housing, utilities, medical bills, and transport costs.
            </p>
        </div>
    </div>

    <!-- Wants (30%) -->
    <div class="col-md-4">
        <div class="glass-panel-premium p-4 h-100 text-center hover-lift position-relative">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="badge rounded-pill <?php echo $wants_color === 'success' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?> px-3 py-1 fw-bold">
                    Target: 30%
                </span>
                <div class="p-2 rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="fa-solid fa-gamepad text-primary"></i>
                </div>
            </div>

            <div class="position-relative d-inline-flex align-items-center justify-content-center my-3">
                <svg width="130" height="130" viewBox="0 0 120 120" style="transform: rotate(-90deg);">
                    <circle cx="60" cy="60" r="50" fill="transparent" stroke="rgba(var(--primary-rgb, 99, 102, 241), 0.08)" stroke-width="8"></circle>
                    <circle cx="60" cy="60" r="50" fill="transparent" 
                            stroke="<?php echo $wants_color === 'success' ? 'url(#wantsGrad)' : 'url(#dangerGrad)'; ?>" 
                            stroke-width="8"
                            stroke-dasharray="314.16" 
                            stroke-dashoffset="<?php echo getCircleOffset($wants_pct); ?>" 
                            stroke-linecap="round"
                            style="transition: stroke-dashoffset 0.8s ease-in-out;"></circle>
                </svg>
                <div class="position-absolute d-flex flex-column align-items-center">
                    <span class="fs-4 fw-bold text-dark"><?php echo number_format($wants_pct, 1); ?>%</span>
                    <span class="text-muted small">used</span>
                </div>
            </div>

            <h4 class="fw-bold mb-1">Wants</h4>
            <div class="h3 fw-bold text-dark mb-3">
                <small class="text-muted fs-6">AED</small>
                <span class="blur-sensitive"><?php echo number_format($total_wants); ?></span>
            </div>
            
            <p class="text-muted small mb-0 px-2">
                Includes dining out, apparel shopping, entertainment, non-essential travel, and leisure.
            </p>
        </div>
    </div>

    <!-- Savings (20%) -->
    <div class="col-md-4">
        <div class="glass-panel-premium p-4 h-100 text-center hover-lift position-relative">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="badge rounded-pill <?php echo $savings_color === 'success' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?> px-3 py-1 fw-bold">
                    Target: 20%
                </span>
                <div class="p-2 rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="fa-solid fa-piggy-bank text-primary"></i>
                </div>
            </div>

            <div class="position-relative d-inline-flex align-items-center justify-content-center my-3">
                <svg width="130" height="130" viewBox="0 0 120 120" style="transform: rotate(-90deg);">
                    <circle cx="60" cy="60" r="50" fill="transparent" stroke="rgba(var(--primary-rgb, 99, 102, 241), 0.08)" stroke-width="8"></circle>
                    <circle cx="60" cy="60" r="50" fill="transparent" 
                            stroke="<?php echo $savings_color === 'success' ? 'url(#savingsGrad)' : 'url(#dangerGrad)'; ?>" 
                            stroke-width="8"
                            stroke-dasharray="314.16" 
                            stroke-dashoffset="<?php echo getCircleOffset($savings_pct); ?>" 
                            stroke-linecap="round"
                            style="transition: stroke-dashoffset 0.8s ease-in-out;"></circle>
                </svg>
                <div class="position-absolute d-flex flex-column align-items-center">
                    <span class="fs-4 fw-bold text-dark"><?php echo number_format(max(0, $savings_pct), 1); ?>%</span>
                    <span class="text-muted small">saved</span>
                </div>
            </div>

            <h4 class="fw-bold mb-1">Savings</h4>
            <div class="h3 fw-bold text-dark mb-3">
                <small class="text-muted fs-6">AED</small>
                <span class="blur-sensitive"><?php echo number_format($total_savings); ?></span>
            </div>
            
            <p class="text-muted small mb-0 px-2">
                Accumulated savings (Income remaining after all monthly wants and needs are subtracted).
            </p>
        </div>
    </div>
</div>

<!-- Section: Category Limits vs Actuals -->
<div class="glass-panel-premium p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="fw-bold mb-1">Category Budgets vs Actuals</h5>
            <p class="text-muted small mb-0">Live trackers matching your actual category spending with defined constraints</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
            <?php
            $alert_pill_text = $alert_settings['enabled']
                ? "Alerts: on — warn at {$warn_pct}%, over at {$over_pct}%"
                : 'Alerts: off';
            $alert_pill_class = $alert_settings['enabled'] ? 'bg-primary-subtle text-primary' : 'bg-light text-muted';
            $alert_pill_icon = $alert_settings['enabled'] ? 'fa-bell' : 'fa-bell-slash';
            ?>
            <?php if ($can_edit): ?>
                <a href="manage_budgets.php#alerts" class="badge rounded-pill <?php echo $alert_pill_class; ?> px-3 py-2 fw-semibold text-decoration-none budget-alert-pill" title="Budget alert settings">
                    <i class="fa-solid <?php echo $alert_pill_icon; ?> me-1"></i><?php echo Html::e($alert_pill_text); ?>
                </a>
                <a href="manage_budgets.php" class="btn btn-primary btn-sm rounded-pill px-4 shadow-sm hover-lift">
                    <i class="fa-solid fa-sliders me-1"></i> Manage Targets
                </a>
            <?php else: ?>
                <span class="badge rounded-pill <?php echo $alert_pill_class; ?> px-3 py-2 fw-semibold budget-alert-pill">
                    <i class="fa-solid <?php echo $alert_pill_icon; ?> me-1"></i><?php echo Html::e($alert_pill_text); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // Fetch specifically defined budgets
    // ORDER BY id: if legacy data holds several rows per category, the latest one wins
    $stmt = $pdo->prepare("SELECT category, amount FROM budgets WHERE tenant_id = ? AND month = ? AND year = ? ORDER BY id");
    $stmt->execute([$_SESSION['tenant_id'], $month, $year]);
    $cat_budgets = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    ?>

    <?php if (empty($cat_budgets)): ?>
        <div class="text-center py-5 bg-light bg-opacity-50 rounded-4 border border-dashed">
            <div class="mb-3 text-muted">
                <i class="fa-solid fa-bullseye fa-3x opacity-25"></i>
            </div>
            <p class="text-muted mb-2">No category targets defined for this month.</p>
            <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                <a href="manage_budgets.php" class="btn btn-outline-primary btn-sm rounded-pill px-3">Set Targets Now</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($cat_budgets as $cat => $limit):
                $spent = (float) ($expenses[$cat] ?? 0);
                $limit = (float) $limit;
                $pct = $limit > 0 ? ($spent / $limit) * 100 : 0;
                $var = $limit - $spent;
                // Same thresholds as the alert emails
                $color = 'success';
                if ($pct >= $warn_pct) {
                    $color = 'warning';
                }
                if ($pct >= $over_pct) {
                    $color = 'danger';
                }
                // The bar spans 0..max(100%, over threshold) so both markers fit
                $scale = max(100, $over_pct);
                $bar_width = min($pct, $scale) / $scale * 100;
                $warn_pos = $warn_pct / $scale * 100;
                $over_pos = $over_pct / $scale * 100;
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="p-4 rounded-4 border border-light bg-white bg-opacity-50 shadow-sm hover-lift transition-all">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="d-flex align-items-center">
                                <div class="category-icon me-2 rounded-circle d-flex align-items-center justify-content-center"
                                    style="width: 32px; height: 32px; background: rgba(var(--primary-rgb, 99, 102, 241), 0.08); color: var(--primary-color);">
                                    <i class="fa-solid <?php
                                    echo match ($cat) {
                                        'Grocery' => 'fa-cart-shopping',
                                        'Food' => 'fa-utensils',
                                        'Medical' => 'fa-heart-pulse',
                                        'Shopping' => 'fa-bag-shopping',
                                        'Utilities' => 'fa-bolt',
                                        'Transport' => 'fa-car',
                                        'Travel' => 'fa-plane',
                                        'Entertainment' => 'fa-clapperboard',
                                        'Education' => 'fa-graduation-cap',
                                        default => 'fa-tag'
                                    };
                                    ?>"></i>
                                </div>
                                <span class="fw-bold text-dark"><?php echo Html::e(Categories::EXPENSE[$cat] ?? $cat); ?></span>
                            </div>
                            <span class="badge rounded-pill bg-<?php echo $color; ?>-subtle text-<?php echo $color; ?> px-2 py-1 small fw-bold">
                                <?php echo number_format($pct, 0); ?>%
                            </span>
                        </div>
                        
                        <!-- Progress bar with alert threshold markers -->
                        <div class="budget-track mb-2">
                            <div class="progress rounded-pill" style="height: 8px; background: rgba(0,0,0,0.05);">
                                <div class="progress-bar rounded-pill bg-<?php echo $color; ?>" role="progressbar"
                                     style="width: <?php echo round($bar_width, 2); ?>%; transition: width 0.6s ease-in-out;"
                                     aria-label="<?php echo Html::e($cat); ?> budget used"
                                     aria-valuenow="<?php echo round($pct); ?>" aria-valuemin="0" aria-valuemax="<?php echo $scale; ?>"></div>
                            </div>
                            <span class="budget-marker budget-marker-warn" style="left: <?php echo round($warn_pos, 2); ?>%;" title="Warning at <?php echo $warn_pct; ?>%"></span>
                            <?php if ($over_pct > 100): ?>
                                <span class="budget-marker budget-marker-over" style="left: <?php echo round($over_pos, 2); ?>%;" title="Over budget at <?php echo $over_pct; ?>%"></span>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex justify-content-between small text-muted mb-2">
                            <span>Spent: <strong>AED <?php echo number_format($spent); ?></strong></span>
                            <span>Limit: AED <?php echo number_format($limit); ?></span>
                        </div>

                        <?php if ($var < 0): ?>
                            <div class="text-danger x-small fw-bold d-flex align-items-center gap-1">
                                <i class="fa-solid fa-triangle-exclamation"></i> Over budget by AED <?php echo number_format(abs($var)); ?>
                            </div>
                        <?php else: ?>
                            <div class="text-success x-small fw-bold d-flex align-items-center gap-1">
                                <i class="fa-solid fa-circle-check"></i> AED <?php echo number_format($var); ?> safe to spend
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Section: Budget Insights -->
<div class="glass-panel-premium p-4">
    <div class="d-flex align-items-center mb-3">
        <div class="rounded-circle p-2 bg-primary-subtle text-primary d-flex align-items-center justify-content-center me-3" style="width: 42px; height: 42px;">
            <i class="fa-solid fa-lightbulb fa-lg"></i>
        </div>
        <h5 class="fw-bold mb-0">Budget Insights & Analytics</h5>
    </div>
    
    <div class="d-flex flex-column gap-3">
        <?php if ($savings_pct >= 20): ?>
            <div class="d-flex align-items-start gap-3 p-3 rounded-4 bg-success-subtle bg-opacity-25 border border-success border-opacity-10 text-success">
                <i class="fa-solid fa-circle-check fa-lg mt-1"></i>
                <div>
                    <h6 class="fw-bold mb-1">Savings Goal Achieved</h6>
                    <p class="small mb-0 text-success-emphasis">You are currently saving <?php echo number_format($savings_pct, 1); ?>% of your income, beating the minimum 20% savings rule. Keep this momentum up!</p>
                </div>
            </div>
        <?php else: ?>
            <div class="d-flex align-items-start gap-3 p-3 rounded-4 bg-danger-subtle bg-opacity-25 border border-danger border-opacity-10 text-danger">
                <i class="fa-solid fa-triangle-exclamation fa-lg mt-1"></i>
                <div>
                    <h6 class="fw-bold mb-1">Under Savings Target</h6>
                    <p class="small mb-0 text-danger-emphasis">You are falling short of the recommended 20% savings threshold. Review your 'Wants' category budgets and scale back non-essential expenditures.</p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($needs_pct > 50): ?>
            <div class="d-flex align-items-start gap-3 p-3 rounded-4 bg-warning-subtle bg-opacity-25 border border-warning border-opacity-10 text-warning">
                <i class="fa-solid fa-triangle-exclamation fa-lg mt-1"></i>
                <div>
                    <h6 class="fw-bold mb-1">High Needs Ratio</h6>
                    <p class="small mb-0 text-warning-emphasis">Your 'Needs' (essential spending) represents <?php echo number_format($needs_pct, 1); ?>% of your monthly cash flow. Consider renegotiating utility plans, reviewing subscriptions, or optimizing groceries to lower fixed costs.</p>
                </div>
            </div>
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
            <div class="d-flex align-items-start gap-3 p-3 rounded-4 bg-danger-subtle bg-opacity-25 border border-danger border-opacity-10 text-danger">
                <i class="fa-solid fa-circle-xmark fa-lg mt-1"></i>
                <div>
                    <h6 class="fw-bold mb-1">Category Targets Exceeded</h6>
                    <p class="small mb-0 text-danger-emphasis">You have exceeded targets in the following categories: <strong><?php echo Html::e(implode(', ', $over_cats)); ?></strong>. Consider rebalancing your available limits.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php Layout::footer(); ?>
