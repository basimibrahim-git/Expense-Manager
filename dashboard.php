<?php
$page_title = "Dashboard";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\ExchangeRateHelper;
use App\Helpers\DashboardStats;
use App\Helpers\Html;

Bootstrap::init();
Layout::header();
Layout::sidebar();

$user_id = $_SESSION['user_id'];
$tenant_id = (int) $_SESSION['tenant_id'];
$curr_month = date('n');
$curr_year = date('Y');

// Base Currency Scaling Logic
$base_currency = $_SESSION['preferences']['base_currency'] ?? 'AED';
if ($base_currency === 'INR') {
    $currency_multiplier = ExchangeRateHelper::getRate('AED', 'INR', $pdo);
} elseif ($base_currency === 'AED') {
    $currency_multiplier = 1.0;
} else {
    $currency_multiplier = 1.0;
}
$currency_label = $base_currency;

// INR income is converted to AED when summed
$income_aed_sql = ExchangeRateHelper::aedSql($pdo);

// Month boundaries. Ranges (date >= first day AND date < first day of next month) select exactly
// the same rows as MONTH(col) = m AND YEAR(col) = y on these DATE columns, and can use an index.
$month_start_ts = function (int $monthsBack): int {
    return mktime(0, 0, 0, (int) date('n') - $monthsBack, 1, (int) date('Y'));
};
$curr_key        = DashboardStats::monthKey($month_start_ts(0));
$curr_from       = date('Y-m-d', $month_start_ts(0));
$next_month_from = date('Y-m-d', $month_start_ts(-1));
$chart_from      = date('Y-m-d', $month_start_ts(5));           // first day of the 6-month chart
$interest_from   = date('Y-m-d', $month_start_ts(11));          // first day of the 12-month interest chart
$year_from       = date('Y-01-01');
$next_year_from  = ((int) $curr_year + 1) . '-01-01';

// 1. Income per month for the 6-month chart (includes the current month). One query.
$stmt = $pdo->prepare("SELECT YEAR(income_date) AS y, MONTH(income_date) AS m, SUM(" . $income_aed_sql . ") AS total
                       FROM income
                       WHERE tenant_id = ? AND income_date >= ? AND income_date < ?
                       GROUP BY YEAR(income_date), MONTH(income_date)");
$stmt->execute([$tenant_id, $chart_from, $next_month_from]);
$income_by_month = DashboardStats::byMonth($stmt->fetchAll(PDO::FETCH_ASSOC));

// Expenses per month, from the earlier of (6-month chart start, 1 Jan) up to the end of this year
// (the cashback figure counts the whole calendar year, including future-dated rows). One query.
$stmt = $pdo->prepare("SELECT YEAR(expense_date) AS y, MONTH(expense_date) AS m,
                              SUM(amount) AS total,
                              SUM(CASE WHEN payment_method = 'Card' THEN amount END) AS card_total,
                              SUM(CASE WHEN is_fixed = 1 THEN amount END) AS fixed_total,
                              SUM(CASE WHEN is_fixed = 0 THEN amount END) AS var_total,
                              SUM(cashback_earned) AS cashback
                       FROM expenses
                       WHERE tenant_id = ? AND expense_date >= ? AND expense_date < ?
                       GROUP BY YEAR(expense_date), MONTH(expense_date)");
$stmt->execute([$tenant_id, min($chart_from, $year_from), $next_year_from]);
$expense_by_month = DashboardStats::byMonth($stmt->fetchAll(PDO::FETCH_ASSOC));
$expense_curr = $expense_by_month[$curr_key] ?? [];

// Summary Stats (Current Month)
$income_now  = ($income_by_month[$curr_key]['total'] ?? 0) ?: 0;
$expense_now = ($expense_curr['total'] ?? 0) ?: 0;

// Bank balances (AED) as of today, every month end of the last 12 months, and the same month
// last year: one query instead of 14 BalanceHelper::totalAed() calls (see DashboardStats).
$wealth_month_ends = [];
for ($i = 11; $i >= 0; $i--) {
    $wealth_month_ends[] = date('Y-m-t', $month_start_ts($i));
}
$last_year_month_end = date('Y-m-t', mktime(0, 0, 0, (int) date('n'), 1, (int) date('Y') - 1));
$today = date('Y-m-d');
$balance_totals = DashboardStats::balanceTotalsAed($pdo, $tenant_id, array_merge([$today, $last_year_month_end], $wealth_month_ends));

// Total Net Worth (current balance of every bank, in AED)
$net_worth = $balance_totals[$today] ?? 0.0;

// Cards (credit limit, smart-swap alerts, liquidity alerts)
$stmt = $pdo->prepare("SELECT * FROM cards WHERE tenant_id = ?");
$stmt->execute([$tenant_id]);
$roi_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total Credit Limit
$total_limit = DashboardStats::sumMoney(array_column($roi_cards, 'limit_amount'));

// Card spend this month
$total_card_spend = ($expense_curr['card_total'] ?? 0) ?: 0;

// Credit Utilization Logic
$utilization = ($total_limit > 0) ? ($total_card_spend / $total_limit) * 100 : 0;
$savings_rate = ($income_now > 0) ? (($income_now - $expense_now) / $income_now) * 100 : 0;
$savings_rate = max($savings_rate, 0); // No negative savings rate visually

// 1.5 PHASE 8: Wealth Journey (Snapshot Comparison)
// Net Worth Last Year (Same Month): balances as of the end of that month
$last_year_net_worth = $balance_totals[$last_year_month_end] ?? 0.0;

$wealth_growth_abs = $net_worth - $last_year_net_worth;
$wealth_growth_pct = ($last_year_net_worth > 0) ? ($wealth_growth_abs / $last_year_net_worth) * 100 : 100;

// Wealth Chart Data (Last 12 Months Net Worth Trend)
$wealth_months = [];
$wealth_data = [];
foreach ($wealth_month_ends as $i => $month_end_date) {
    $wealth_months[] = date('M Y', $month_start_ts(11 - $i));
    $wealth_data[] = round($balance_totals[$month_end_date] ?? 0.0, 2);
}

// 2. Chart Data (Last 6 Months); months without rows are 0
$months = [];
$income_data = [];
$expense_data = [];
for ($i = 5; $i >= 0; $i--) {
    $month_ts = $month_start_ts($i);
    $key = DashboardStats::monthKey($month_ts);
    $months[] = date('M', $month_ts);
    $income_data[] = ($income_by_month[$key]['total'] ?? 0) ?: 0;
    $expense_data[] = ($expense_by_month[$key]['total'] ?? 0) ?: 0;
}

// 3. Category Data (Current Month) + 7. Lifestyle Creep base (same month last year): one query.
// ORDER BY category = the implicit GROUP BY order MariaDB used before (keeps the doughnut colours).
$ly_ts = strtotime('-1 year');
$ly_from = date('Y-m-01', $ly_ts);
$ly_to = date('Y-m-d', mktime(0, 0, 0, (int) date('n', $ly_ts) + 1, 1, (int) date('Y', $ly_ts)));
$stmt = $pdo->prepare("SELECT category,
                              SUM(CASE WHEN expense_date >= ? AND expense_date < ? THEN amount END) AS now_total,
                              SUM(CASE WHEN expense_date >= ? AND expense_date < ? THEN amount END) AS ly_total
                       FROM expenses
                       WHERE tenant_id = ?
                         AND ((expense_date >= ? AND expense_date < ?) OR (expense_date >= ? AND expense_date < ?))
                       GROUP BY category
                       ORDER BY category");
$stmt->execute([$curr_from, $next_month_from, $ly_from, $ly_to, $tenant_id, $curr_from, $next_month_from, $ly_from, $ly_to]);
$cat_results = [];
$last_year_cats = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    // amount is NOT NULL, so a non-NULL sum means the category has rows in that month
    if ($row['now_total'] !== null) {
        $cat_results[$row['category']] = $row['now_total'];
    }
    if ($row['ly_total'] !== null) {
        $last_year_cats[$row['category']] = $row['ly_total'];
    }
}
$cat_labels = array_keys($cat_results);
$cat_values = array_values($cat_results);

// 5. ROI & Anatomy Stats
// Cashback earned this calendar year (exact cent sum of the per-month SUM()s)
$total_cashback = DashboardStats::sumMoney(array_map(
    fn($r) => (int) $r['y'] === (int) $curr_year ? $r['cashback'] : null,
    $expense_by_month
));

$fixed_cost = $expense_curr['fixed_total'] ?? 0;
$var_cost = $expense_curr['var_total'] ?? 0;
$total_cost = $fixed_cost + $var_cost;
$fixed_pct = ($total_cost > 0) ? ($fixed_cost / $total_cost) * 100 : 0;

// 6. Emergency Runway (Avg Expense Last 3 Months) + 9. fixed spend over the same window: one query
$stmt = $pdo->prepare("SELECT SUM(amount) AS total, SUM(CASE WHEN is_fixed = 1 THEN amount END) AS fixed_total
                       FROM expenses
                       WHERE tenant_id = ? AND expense_date BETWEEN DATE_SUB(NOW(), INTERVAL 3 MONTH) AND NOW()");
$stmt->execute([$tenant_id]);
$last_3m = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$last_3m_spend = ($last_3m['total'] ?? 0) ?: 0;
$avg_monthly_spend = $last_3m_spend / 3;
$runway_months = ($avg_monthly_spend > 0) ? $net_worth / $avg_monthly_spend : 0;

// Fetch Monthly Budgets for Dashboard Overview
$budget_stmt = $pdo->prepare("SELECT category, amount FROM budgets WHERE tenant_id = ? AND month = ? AND year = ?");
$budget_stmt->execute([$tenant_id, $curr_month, $curr_year]);
$dash_budgets = $budget_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$total_budgeted = array_sum($dash_budgets);
$budget_utilization = ($total_budgeted > 0) ? ($expense_now / $total_budgeted) * 100 : 0;

// 7. Lifestyle Creep (YoY Category Comparison)
$creep_alerts = [];
foreach ($cat_results as $cat => $amount) {
    if (isset($last_year_cats[$cat]) && $last_year_cats[$cat] > 0) {
        $prev = $last_year_cats[$cat];
        $diff_pct = (($amount - $prev) / $prev) * 100;
        if ($diff_pct > 10) { // 10% Increase Warning
            $creep_alerts[] = [
                'category' => $cat,
                'current' => $amount,
                'prev' => $prev,
                'pct' => $diff_pct
            ];
        }
    }
}

// 8. Cash Flow Projection (Next 30 Days)
// Fetch Recurring Income
$stmt = $pdo->prepare("SELECT " . $income_aed_sql . " AS amount, recurrence_day FROM income WHERE tenant_id = ? AND is_recurring = 1");
$stmt->execute([$tenant_id]);
$recurring_incomes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Subscription templates: one row per template (latest entry per description).
// Used by the projection below and by the Upcoming Bills list (section 12).
$stmt = $pdo->prepare("
    SELECT e1.*
    FROM expenses e1
    JOIN (
        SELECT MAX(id) as max_id
        FROM expenses
        WHERE tenant_id = ? AND is_subscription = 1
        GROUP BY description
    ) e2 ON e1.id = e2.max_id
");
$stmt->execute([$tenant_id]);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$recurring_expenses = [];
foreach ($templates as $tpl) {
    $recurring_expenses[] = [
        'amount' => $tpl['amount'],
        'day' => (int) date('j', strtotime($tpl['expense_date'])), // = DAY(expense_date)
    ];
}

$projected_dates = [];
$projected_balance = [];
$running_bal = $net_worth;

for ($i = 0; $i <= 30; $i++) {
    $target_date = strtotime("+$i days");
    $day_num = date('j', $target_date);
    $formatted_date = date('M j', $target_date);

    // Add Income
    foreach ($recurring_incomes as $inc) {
        if ($inc['recurrence_day'] == $day_num) {
            $running_bal += $inc['amount'];
        }
    }

    // Subtract Subscriptions
    foreach ($recurring_expenses as $exp) {
        if ($exp['day'] == $day_num) {
            $running_bal -= $exp['amount'];
        }
    }

    $projected_dates[] = $formatted_date;
    $projected_balance[] = $running_bal;
}

// 8.5 PHASE 4: ROI & Liquidity (Restored)
// True Liquidity: Net Worth - Unbilled Card Spends (approx using this month's card spend)
$unbilled_card_spend = $total_card_spend;
$true_liquidity = $net_worth - $unbilled_card_spend;

// 9. PHASE 6: Safe-to-Spend Logic
// Estimate Monthly Fixed Cost (Avg of last 3 months fixed spend)
$avg_fixed_cost = (($last_3m['fixed_total'] ?? 0) ?: 0) / 3;
$remaining_fixed = max(0, $avg_fixed_cost - $fixed_cost);
$savings_target = $income_now * 0.20; // 20% Goal

// 9.5 PHASE 8: Sinking Funds Deduction
// Calculate how much we need to save THIS MONTH for all active goals
$total_goal_contribution = 0;
$stmt = $pdo->prepare("SELECT * FROM sinking_funds WHERE tenant_id = ? AND current_saved < target_amount AND target_date > NOW()");
$stmt->execute([$tenant_id]);
$active_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($active_goals as $goal) {
    $needed = $goal['target_amount'] - $goal['current_saved'];
    if ($needed <= 0) {
        continue;
    }

    $days_left = ceil((strtotime($goal['target_date']) - time()) / 86400);
    $months_left = max(1, ceil($days_left / 30));

    $contribution = $needed / $months_left;
    $total_goal_contribution += $contribution;
}

// Deduct Goal Contribution from Safe-to-Spend
$safe_to_spend = $true_liquidity - $remaining_fixed - $savings_target - $total_goal_contribution;

// 10. Smart Alerts Engine
$alerts = [];

// A. Smart Swap (Check last 5 card expenses)
$stmt = $pdo->prepare("
    SELECT e.amount, e.category, c.card_name, c.id as used_card_id
    FROM expenses e
    JOIN cards c ON e.card_id = c.id AND c.tenant_id = e.tenant_id
    WHERE e.tenant_id = ? AND e.payment_method = 'Card'
    ORDER BY e.expense_date DESC LIMIT 5
");
$stmt->execute([$tenant_id]);
$recent_card_txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($recent_card_txns as $txn) {
    if (empty($txn['category'])) {
        continue;
    }
    foreach ($roi_cards as $card) {
        if ($card['id'] == $txn['used_card_id']) {
            continue; // Skip same card
        }

        $keywords = json_decode($card['cashback_struct'] ?? '[]', true);
        if (is_array($keywords)) {
            foreach ($keywords as $key => $val) {
                // cashback_struct is {"Category": pct}; older rows may be a plain list of categories
                $k = is_string($key) ? $key : (string) $val;
                if ($k === '') {
                    continue;
                }
                if (stripos($txn['category'], $k) !== false || stripos($k, $txn['category']) !== false) {
                    $alerts[] = [
                        'type' => 'swap',
                        'icon' => 'fa-arrow-right-arrow-left',
                        'color' => 'info',
                        'msg' => 'Smart Swap: You spent ' . Html::e(number_format((float) $txn['amount'], 2)) . ' on ' . Html::e($txn['category'])
                            . ' with ' . Html::e($txn['card_name']) . '. Use <b>' . Html::e($card['card_name']) . '</b> next time for better rewards!'
                    ];
                    break 2; // Alert once per batch to avoid spam
                }
            }
        }
    }
}

// B. Liquidity Warning (Bill vs Balance)
// Find cards with bills due in next 7 days
foreach ($roi_cards as $card) {
    // Determine next Bill Due Date (Approx Statement Day + 20 days grace)
    if ($card['statement_day']) {
        $stmt_day = $card['statement_day'];
        $today_day = date('j');

        // Very rough accumulation approximation
        // In real app, we'd query API or DB for "Statement Balance"
        // Here we assume 1000 AED estimated bill for demo if statement just passed
        if ($today_day == $stmt_day && $true_liquidity < 2000) {
            $alerts[] = [
                'type' => 'bill',
                'icon' => 'fa-triangle-exclamation',
                'color' => 'danger',
                'msg' => 'Liquidity Alert: Bill for <b>' . Html::e($card['card_name']) . '</b> generated today. Ensure you have funds.'
            ];
        }
    }
}
// 11. Interest Tracker Chart Data (Last 12 Months)
// Positive = Interest (Debt), Negative = Payments
$interest_months = [];
$interest_accrued_data = [];
$interest_paid_data = [];

// Interest Accrued (sum of positive amounts) and Payments Made (sum of negative amounts as
// positive values) per month: one query, months without rows are 0
$stmt = $pdo->prepare("SELECT YEAR(interest_date) AS y, MONTH(interest_date) AS m,
                              SUM(CASE WHEN amount > 0 THEN amount END) AS accrued,
                              SUM(CASE WHEN amount < 0 THEN ABS(amount) END) AS paid
                       FROM interest_tracker
                       WHERE tenant_id = ? AND interest_date >= ? AND interest_date < ?
                       GROUP BY YEAR(interest_date), MONTH(interest_date)");
$stmt->execute([$tenant_id, $interest_from, $next_month_from]);
$interest_by_month = DashboardStats::byMonth($stmt->fetchAll(PDO::FETCH_ASSOC));

for ($i = 11; $i >= 0; $i--) {
    $month_ts = $month_start_ts($i);
    $key = DashboardStats::monthKey($month_ts);
    $interest_months[] = date('M Y', $month_ts);
    $interest_accrued_data[] = ($interest_by_month[$key]['accrued'] ?? 0) ?: 0;
    $interest_paid_data[] = ($interest_by_month[$key]['paid'] ?? 0) ?: 0;
}

// 12. Upcoming Bills & Pending Auto-Drafts
$upcoming_bills = [];
$curr_month_logged = $pdo->prepare("SELECT description FROM expenses WHERE tenant_id = ? AND expense_date >= ? AND expense_date < ?");
$curr_month_logged->execute([$tenant_id, $curr_from, $next_month_from]);
$logged_subs = $curr_month_logged->fetchAll(PDO::FETCH_COLUMN);

// $templates (unique subscription templates) was fetched in section 8
foreach ($templates as $sb) {
    $day = date('d', strtotime($sb['expense_date']));
    $is_logged = in_array($sb['description'], $logged_subs);

    // Calculate next renewal for "Upcoming" list
    $target_renewal = date('Y-m-') . $day;
    if (strtotime($target_renewal) < time()) {
        $target_renewal = date('Y-m-', strtotime('+1 month')) . $day;
    }
    $days_to_bill = ceil((strtotime($target_renewal) - time()) / 86400);

    // Alert Logic:
    // 1. If not logged this month AND (due in 7 days OR overdue)
    $is_due_this_month = true; // Subscriptions are monthly
    if (!$is_logged) {
        $is_overdue = date('d') >= $day;
        if ($is_overdue || ($days_to_bill >= 0 && $days_to_bill <= 7)) {
            $upcoming_bills[] = [
                'id' => $sb['id'],
                'name' => $sb['description'],
                'amount' => $sb['amount'],
                'due_date' => $target_renewal,
                'days_left' => $days_to_bill,
                'is_overdue' => $is_overdue,
                'status' => $is_overdue ? 'Overdue' : 'Due Soon'
            ];
        }
    }
}
// Sort by urgency: Overdue first, then by days left
usort($upcoming_bills, function ($a, $b) {
    if ($a['is_overdue'] != $b['is_overdue']) {
        return $b['is_overdue'] <=> $a['is_overdue'];
    }
    return $a['days_left'] <=> $b['days_left'];
});
?>

<?php
$hour = date('H');
if ($hour < 12) {
    $greeting = "Good Morning";
    $greeting_sub = "Start your day with a clear financial overview.";
} elseif ($hour < 17) {
    $greeting = "Good Afternoon";
    $greeting_sub = "Keep tabs on your daily transactions and limits.";
} else {
    $greeting = "Good Evening";
    $greeting_sub = "Review your progress and track final spends.";
}
$user_display_name = htmlspecialchars($_SESSION['user_name'] ?? 'User');
?>

<!-- Header & Welcome Banner -->
<div class="row mb-5">
    <div class="col-12">
        <div class="glass-panel-premium p-4 position-relative overflow-hidden hover-lift" style="border-left: 5px solid var(--primary-color);">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <span class="badge bg-primary-subtle text-primary mb-2 fw-bold text-uppercase ls-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">Financial Intelligence</span>
                    <h1 class="h2 fw-bold mb-1"><?php echo $greeting; ?>, <?php echo $user_display_name; ?>!</h1>
                    <p class="text-muted mb-0"><?php echo $greeting_sub; ?> Overview for <b><?php echo date('F Y'); ?></b></p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="my_banks.php" class="btn btn-outline-primary rounded-pill px-3 shadow-sm d-flex align-items-center fw-bold">
                        <i class="fa-solid fa-landmark me-2"></i> Banks
                    </a>
                    <div class="bg-primary-subtle text-primary px-3 py-2 rounded-pill shadow-sm d-flex align-items-center fw-bold" title="Emergency Fund Runway">
                        <i class="fa-solid fa-plane-departure me-2"></i> <?php echo number_format($runway_months, 1); ?> Mo. Runway
                    </div>
                    <?php if ($total_cashback > 0): ?>
                        <div class="bg-warning-subtle text-warning px-3 py-2 rounded-pill shadow-sm d-flex align-items-center fw-bold">
                            <i class="fa-solid fa-gift me-2"></i> AED <?php echo number_format($total_cashback, 2); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div class="row g-4 mb-5">
    <!-- Income -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="gradient-card-success hover-lift p-4 h-100 rounded-4 position-relative overflow-hidden shadow-sm" style="border-radius: 16px;">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div class="rounded-circle bg-white bg-opacity-20 p-3 text-white">
                    <i class="fa-solid fa-arrow-trend-up fa-xl"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-1 text-white"><?php echo Html::e($currency_label); ?> <span class="blur-sensitive"><?php echo number_format($income_now * $currency_multiplier, 2); ?></span></h3>
            <span class="text-white-50 small">Income (This Month)</span>
        </div>
    </div>

    <!-- Expenses -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="gradient-card-danger hover-lift p-4 h-100 rounded-4 position-relative overflow-hidden shadow-sm" style="border-radius: 16px;">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div class="rounded-circle bg-white bg-opacity-20 p-3 text-white">
                    <i class="fa-solid fa-arrow-trend-down fa-xl"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-1 text-white"><?php echo Html::e($currency_label); ?> <span class="blur-sensitive"><?php echo number_format($expense_now * $currency_multiplier, 2); ?></span></h3>
            <span class="text-white-50 small">Expenses (This Month)</span>
        </div>
    </div>

    <!-- Net Worth & True Liquidity -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="gradient-card-info hover-lift p-4 h-100 rounded-4 position-relative overflow-hidden shadow-sm" style="border-radius: 16px;">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div class="rounded-circle bg-white bg-opacity-20 p-3 text-white">
                    <i class="fa-solid fa-building-columns fa-xl"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-0 text-white"><?php echo Html::e($currency_label); ?> <span class="blur-sensitive"><?php echo number_format($net_worth * $currency_multiplier, 2); ?></span></h3>
            <div class="small text-white-50 mb-2">Total Bank Balance</div>
            <div class="border-top border-white border-opacity-20 pt-2">
                <div class="d-flex justify-content-between text-white fw-bold small">
                    <span class="text-white-50">Liq. Assets:</span>
                    <span class="blur-sensitive"><?php echo number_format($true_liquidity * $currency_multiplier, 2); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Credit Limit -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="gradient-card-accent hover-lift p-4 h-100 rounded-4 position-relative overflow-hidden shadow-sm" style="border-radius: 16px;">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div class="rounded-circle bg-white bg-opacity-20 p-3 text-white">
                    <i class="fa-solid fa-credit-card fa-xl"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-1 text-white"><?php echo Html::e($currency_label); ?> <span class="blur-sensitive"><?php echo number_format($total_limit * $currency_multiplier, 2); ?></span></h3>
            <span class="text-white-50 small">Total Credit Limit</span>
        </div>
    </div>
</div>

<?php
// ── Feature widgets: card dues, Zakath, family split (each hides itself when not relevant
//    and never breaks the dashboard, e.g. before its migration has been run) ──
$widget_card_dues = [];
$widget_hawl = null;
$widget_split_net = null;
try {
    $widget_card_dues = array_slice(\App\Helpers\CardCycleHelper::upcoming($pdo, (int) $tenant_id, 10), 0, 3);
} catch (Throwable $e) {
    error_log('Dashboard card dues widget: ' . $e->getMessage());
}
try {
    $zs = \App\Helpers\ZakathHelper::settings($pdo, (int) $tenant_id);
    $widget_hawl = \App\Helpers\ZakathHelper::hawl($zs['hawl_start_date'] ?? null);
} catch (Throwable $e) {
    error_log('Dashboard zakath widget: ' . $e->getMessage());
}
try {
    $wsStmt = $pdo->prepare("SELECT s.user_id, s.share_amount AS share, COALESCE(e.spent_by_user_id, e.user_id) AS payer_id
                             FROM expense_splits s
                             JOIN expenses e ON e.id = s.expense_id AND e.tenant_id = s.tenant_id
                             WHERE s.tenant_id = ?");
    $wsStmt->execute([$tenant_id]);
    $wsRows = $wsStmt->fetchAll();
    if ($wsRows) {
        $wtStmt = $pdo->prepare("SELECT from_user_id, to_user_id, amount FROM settlements WHERE tenant_id = ?");
        $wtStmt->execute([$tenant_id]);
        $wsNet = \App\Helpers\SplitHelper::netBalances($wsRows, $wtStmt->fetchAll());
        $widget_split_net = $wsNet[(int) $_SESSION['user_id']] ?? 0.0;
    }
} catch (Throwable $e) {
    // expense_splits/settlements not created yet — no widget
}
$show_hawl  = $widget_hawl !== null && $widget_hawl['days_left'] <= 60;
$show_split = $widget_split_net !== null && abs($widget_split_net) >= 0.01;
?>
<?php if ($widget_card_dues || $show_hawl || $show_split): ?>
<div class="row g-4 mb-4">
    <?php if ($widget_card_dues): ?>
        <div class="col-12 col-lg">
            <div class="glass-panel p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0"><i class="fa-solid fa-credit-card text-primary me-2"></i>Card payments due</h6>
                    <a href="my_cards.php" class="small text-decoration-none">All cards</a>
                </div>
                <?php foreach ($widget_card_dues as $due): ?>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-light">
                        <div>
                            <div class="fw-bold small"><?php echo Html::e($due['bank_name'] . ' ' . $due['card_name']); ?></div>
                            <span class="badge bg-<?php echo Html::e(\App\Helpers\CardCycleHelper::statusColor($due['status'])); ?>">
                                <?php echo Html::e(\App\Helpers\CardCycleHelper::dueLabel($due['days_to_due'])); ?>
                            </span>
                            <span class="text-muted small ms-1"><?php echo Html::e(date('d M', strtotime($due['last_statement_due_date']))); ?></span>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold blur-sensitive">AED <?php echo number_format($due['due_remaining'], 2); ?></div>
                            <a href="pay_card.php?card_id=<?php echo (int) $due['card_id']; ?>" class="small text-decoration-none">Pay</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($show_hawl): ?>
        <div class="col-12 col-md-6 col-lg-3">
            <a href="zakath_tracker.php" class="text-decoration-none">
                <div class="glass-panel p-4 h-100 hover-lift">
                    <h6 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-hand-holding-heart text-success me-2"></i>Zakath due</h6>
                    <h3 class="fw-bold mb-1 <?php echo $widget_hawl['days_left'] <= 7 ? 'text-danger' : 'text-success'; ?>">
                        <?php echo $widget_hawl['days_left'] <= 0 ? 'Today' : (int) $widget_hawl['days_left'] . ' days'; ?>
                    </h3>
                    <div class="small text-muted">
                        <?php echo Html::e(date('d M Y', strtotime($widget_hawl['due']))); ?>
                        <?php if (!empty($widget_hawl['due_hijri'])): ?> · <?php echo Html::e($widget_hawl['due_hijri']); ?><?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
    <?php endif; ?>

    <?php if ($show_split): ?>
        <div class="col-12 col-md-6 col-lg-3">
            <a href="family_split.php" class="text-decoration-none">
                <div class="glass-panel p-4 h-100 hover-lift">
                    <h6 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-people-arrows text-primary me-2"></i>Family split</h6>
                    <h3 class="fw-bold mb-1 blur-sensitive <?php echo $widget_split_net > 0 ? 'text-success' : 'text-danger'; ?>">
                        AED <?php echo number_format(abs($widget_split_net), 2); ?>
                    </h3>
                    <div class="small text-muted"><?php echo $widget_split_net > 0 ? 'Owed to you' : 'You owe'; ?></div>
                </div>
            </a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Proactive Alerts -->
<?php if (!empty($alerts)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <?php foreach ($alerts as $alert): ?>
                <div class="alert alert-<?php echo $alert['color']; ?> border-0 shadow-sm d-flex align-items-center mb-2"
                    role="alert">
                    <i class="fa-solid <?php echo $alert['icon']; ?> fa-lg me-3"></i>
                    <div><?php echo $alert['msg']; /* built from escaped parts above */ ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Financial Health Row -->
<div class="row g-4 mb-5">
    <!-- Safe to Spend (New) -->
    <div class="col-lg-4">
        <div class="glass-panel p-4 h-100 text-center position-relative overflow-hidden">
            <div class="position-absolute top-0 start-0 w-100 h-100 bg-success bg-opacity-10" style="z-index: 0;"></div>
            <h5 class="fw-bold mb-3 position-relative">🟢 Safe to Spend</h5>
            <h2 class="display-4 fw-bold text-success position-relative blur-sensitive">
                <?php echo number_format(max(0, $safe_to_spend), 2); ?>
            </h2>
            <p class="text-muted small position-relative mb-0">Guilt-free cash after bills & savings</p>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="glass-panel p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">❤️ Financial Health</h5>
                <span class="badge bg-success-subtle text-success">Target: >20% Savings</span>
            </div>

            <div class="row align-items-center">
                <div class="col-auto">
                    <div class="display-5 fw-bold text-success"><?php echo number_format($savings_rate, 1); ?>%</div>
                    <div class="small text-muted">Savings Rate</div>
                </div>
                <div class="col">
                    <div class="progress" style="height: 12px;">
                        <div class="progress-bar bg-success" style="width: <?php echo min($savings_rate, 100); ?>%">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="glass-panel p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">💳 Credit Utilization</h5>
                <span class="badge bg-primary-subtle text-primary">Target: &lt;30%</span>
            </div>

            <div class="row align-items-center">
                <div class="col-auto">
                    <?php
                    if ($utilization < 30) {
                        $util_color = 'success';
                    } elseif ($utilization < 50) {
                        $util_color = 'warning';
                    } else {
                        $util_color = 'danger';
                    }
                    ?>
                    <div class="display-5 fw-bold text-<?php echo $util_color; ?>">
                        <?php echo number_format($utilization, 1); ?>%
                    </div>
                    <div class="small text-muted">Credit Usage</div>
                </div>
                <div class="col">
                    <div class="progress" style="height: 12px;">
                        <div class="progress-bar bg-<?php echo $util_color; ?>"
                            style="width: <?php echo min($utilization, 100); ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Budget Status (New) -->
    <div class="col-12">
        <div class="glass-panel p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0"><i class="fa-solid fa-bullseye text-primary me-2"></i> Monthly Budget Health
                </h5>
                <a href="budget.php" class="small text-primary text-decoration-none">View Details</a>
            </div>

            <?php if (empty($dash_budgets)): ?>
                <div class="text-center py-3 text-muted">
                    No budgets set for this month. <a href="manage_budgets.php">Setup now</a>
                </div>
            <?php else: ?>
                <div class="row align-items-center g-4">
                    <div class="col-md-4">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <div
                                    class="display-6 fw-bold <?php echo $budget_utilization > 100 ? 'text-danger' : 'text-primary'; ?>">
                                    <?php echo number_format($budget_utilization, 1); ?>%
                                </div>
                                <div class="small text-muted text-uppercase fw-bold ls-1">Overall Budget Used</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="row g-3">
                            <?php
                            // Show top 4 spending categories vs budget
                            $count = 0;
                            foreach ($dash_budgets as $cat => $limit):
                                if ($count++ >= 4) {
                                    break;
                                }
                                $spent = $cat_results[$cat] ?? 0;
                                $pct = ($limit > 0) ? ($spent / $limit) * 100 : 0;
                                if ($pct > 100) {
                                    $color = 'danger';
                                } elseif ($pct > 80) {
                                    $color = 'warning';
                                } else {
                                    $color = 'success';
                                }
                                ?>
                                <div class="col-6 col-md-3">
                                    <div class="small fw-bold mb-1 d-flex justify-content-between">
                                        <span><?php echo Html::e($cat); ?></span>
                                        <span><?php echo number_format($pct, 0); ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-<?php echo $color; ?>"
                                            style="width: <?php echo min($pct, 100); ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Foresight Row -->
<div class="row mb-5 g-5">
    <!-- Cash Flow Projection -->
    <div class="col-lg-8">
        <div class="glass-panel p-4 h-100">
            <h5 class="fw-bold mb-4">🔮 30-Day Cash Flow Projection</h5>
            <canvas id="projectionChart" style="max-height: 250px;"></canvas>
        </div>
    </div>

    <!-- Lifestyle Creep -->
    <div class="col-lg-4">
        <div class="glass-panel p-4 h-100">
            <h5 class="fw-bold mb-4">📉 Lifestyle Creep <span class="badge bg-warning text-dark ms-2">YoY
                    Alerts</span>
            </h5>
            <?php if (empty($creep_alerts)): ?>
                <div class="text-center py-5 text-muted"> <i
                        class="fa-solid fa-check-circle text-success fa-2x mb-2"></i><br>No significant spending increases.
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($creep_alerts as $alert): ?>
                        <div class="list-group-item bg-transparent px-0">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold"><?php echo Html::e($alert['category']); ?></span>
                                <span
                                    class="badge bg-danger-subtle text-danger">+<?php echo number_format($alert['pct'], 0); ?>%</span>
                            </div>
                            <div class="d-flex justify-content-between small text-muted">
                                <span>Now: <?php echo number_format($alert['current'], 2); ?></span>
                                <span>Last Year: <?php echo number_format($alert['prev'], 2); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Upcoming Bills (New) -->
    <div class="col-12 mt-4">
        <div class="glass-panel p-4 border-start border-4 border-warning">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="fw-bold mb-0 text-warning"><i class="fa-solid fa-hourglass-half me-2"></i> Upcoming Bills
                    (Next 7 Days)</h5>
                <a href="subscriptions.php" class="btn btn-sm btn-outline-warning rounded-pill px-3">Manage Subs</a>
            </div>
            <?php if (empty($upcoming_bills)): ?>
                <p class="text-muted mb-0">No bills due in the next 7 days. You're clear!</p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($upcoming_bills as $bill): ?>
                        <div class="col-md-4 col-lg-3">
                            <div class="p-3 rounded-4 bg-light border-0 shadow-sm h-100 d-flex flex-column">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="fw-bold text-truncate me-2"
                                        title="<?php echo Html::e($bill['name']); ?>">
                                        <?php echo Html::e($bill['name']); ?>
                                    </span>
                                    <span
                                        class="text-<?php echo $bill['is_overdue'] ? 'danger' : 'warning'; ?> small fw-bold text-nowrap">
                                        <?php echo $bill['status']; ?>
                                    </span>
                                </div>
                                <div class="h5 mb-2 fw-bold text-dark">
                                    AED <?php echo number_format($bill['amount'], 2); ?>
                                </div>
                                <div class="x-small text-muted mb-3">
                                    <?php echo date('D, j M Y', strtotime($bill['due_date'])); ?>
                                </div>

                                <div class="mt-auto pt-2 border-top">
                                    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                        <form action="expense_actions.php" method="POST">
                                            <input type="hidden" name="csrf_token"
                                                value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="log_subscription">
                                            <input type="hidden" name="template_id" value="<?php echo (int) $bill['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success w-100 rounded-pill fw-bold">
                                                Log & Pay
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-light w-100 rounded-pill disabled small">Read Only</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Spend Anatomy (Fixed vs Discretionary) -->
<div class="row mb-5">
    <div class="col-12">
        <div class="glass-panel p-4">
            <h5 class="fw-bold mb-3">🧩 Spend Anatomy <span class="text-muted small fw-normal">(Fixed vs.
                    Lifestyle)</span></h5>
            <div class="progress" style="height: 25px;">
                <div class="progress-bar bg-secondary" style="width: <?php echo $fixed_pct; ?>%"
                    title="Fixed Costs: <?php echo number_format($fixed_cost); ?>">
                    Fixed <?php echo number_format($fixed_pct, 0); ?>%
                </div>
                <div class="progress-bar bg-info" style="width: <?php echo 100 - $fixed_pct; ?>%"
                    title="Variable Costs: <?php echo number_format($var_cost); ?>">
                    Lifestyle <?php echo number_format(100 - $fixed_pct, 0); ?>%
                </div>
            </div>
            <div class="d-flex justify-content-between mt-2 small text-muted">
                <span><i class="fa-solid fa-lock me-1"></i> Fixed (Rent, Bills): <b>AED
                        <?php echo number_format($fixed_cost, 2); ?></b></span>
                <span><i class="fa-solid fa-martini-glass me-1"></i> Lifestyle (Fun, Shopping): <b>AED
                        <?php echo number_format($var_cost, 2); ?></b></span>
            </div>
        </div>
    </div>
</div>

<!-- Interest Chart (Replaced Best Card Engine) -->
<div class="row mb-5">
    <div class="col-12">
        <div class="glass-panel p-4">
            <div class="d-flex justify-content-between align-items-end mb-4">
                <div>
                    <h5 class="fw-bold mb-1"><i class="fa-solid fa-chart-line text-danger me-2"></i> Interest Tracker
                    </h5>
                    <p class="text-muted small mb-0">Interest Accrued vs Payments (Last 12 Months)</p>
                </div>
                <a href="interest_tracker.php" class="btn btn-sm btn-outline-danger fw-bold rounded-pill px-3">
                    View Details <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="interestChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-5 mb-5 pb-3">
    <!-- Line Chart -->
    <div class="col-lg-8">
        <div class="glass-panel p-4 h-100">
            <h5 class="fw-bold mb-4">Income vs Expenses</h5>
            <canvas id="mainChart" style="max-height: 300px;"></canvas>
        </div>
    </div>

    <!-- Doughnut Chart -->
    <div class="col-lg-4">
        <div class="glass-panel p-4 h-100">
            <h5 class="fw-bold mb-4">Spending by Category</h5>
            <?php if (empty($cat_values)): ?>
                <div class="text-center py-5">
                    <i class="fa-solid fa-chart-pie empty-state-icon"></i>
                    <p class="text-muted fw-bold">No spending data yet</p>
                    <small>Go live your life! (Then track it)</small>
                </div>
            <?php else: ?>
                <canvas id="catChart" style="max-height: 250px;"></canvas>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Reusable Info Modal -->
<div class="modal fade" id="infoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="infoModalTitle">Notification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4" id="infoModalBody"></div>
            <div class="modal-footer border-0 justify-content-center">
                <button type="button" class="btn btn-primary px-4" data-bs-dismiss="modal">Okay</button>
            </div>
        </div>
    </div>
</div>

<!-- Wealth Journey Row -->
<div class="row mb-5">
    <div class="col-12">
        <div class="glass-panel p-4">
            <div class="d-flex justify-content-between align-items-end mb-4">
                <div>
                    <h5 class="fw-bold mb-1">🚀 Wealth Journey</h5>
                    <p class="text-muted small mb-0">Net Worth Growth over last 12 months</p>
                </div>
                <div class="text-end">
                    <div
                        class="display-6 fw-bold <?php echo $wealth_growth_abs >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo $wealth_growth_abs >= 0 ? '+' : ''; ?><?php echo number_format($wealth_growth_abs, 2); ?>
                    </div>
                    <div class="small <?php echo $wealth_growth_pct >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <i
                            class="fa-solid <?php echo $wealth_growth_pct >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'; ?> me-1"></i>
                        <?php echo number_format($wealth_growth_pct, 1); ?>% vs Last Year
                    </div>
                </div>
            </div>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="wealthChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    // Interest Chart (Moved here to ensure Chart.js is loaded)
    const interestCtx = document.getElementById('interestChart').getContext('2d');
    new Chart(interestCtx, {
        type: 'bar',
        data: {
            labels: <?php echo Html::json($interest_months); ?>,
            datasets: [
                {
                    label: 'Interest Accrued (Debt)',
                    data: <?php echo Html::json($interest_accrued_data); ?>,
                    backgroundColor: 'rgba(220, 53, 69, 0.7)', // Danger Red
                    borderColor: '#dc3545',
                    borderWidth: 1,
                    borderRadius: 4
                },
                {
                    label: 'Payments Made (Charity)',
                    data: <?php echo Html::json($interest_paid_data); ?>,
                    backgroundColor: 'rgba(25, 135, 84, 0.7)', // Success Green
                    borderColor: '#198754',
                    borderWidth: 1,
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return context.dataset.label + ': AED ' + context.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2 });
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { borderDash: [5, 5] },
                    ticks: { callback: function (value) { return 'AED ' + value.toLocaleString(); } }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });

    // Global Modal Function
    window.showPopup = function (message, title = "Notification") {
        document.getElementById('infoModalBody').innerHTML = message;
        document.getElementById('infoModalTitle').innerText = title;
        new bootstrap.Modal(document.getElementById('infoModal')).show();
    }

    // Wealth Chart (New)
    const ctxWealth = document.getElementById('wealthChart').getContext('2d');
    new Chart(ctxWealth, {
        type: 'line',
        data: {
            labels: <?php echo Html::json($wealth_months); ?>,
            datasets: [{
                label: 'Net Worth',
                data: <?php echo Html::json($wealth_data); ?>,
                borderColor: '#1e3a8a', // Deep Blue
                backgroundColor: 'rgba(30, 58, 138, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#1e3a8a',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: {
                    top: 20,
                    bottom: 10,
                    left: 10,
                    right: 10
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function (context) {
                            return 'AED ' + context.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    grace: '10%', // Adds 10% space at top to prevent cut-off
                    grid: { borderDash: [5, 5] },
                    ticks: {
                        callback: function (value) { return value.toLocaleString(); }
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: {
                        color: '#6c757d', // Make sure labels are visible (text-muted color)
                        maxRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 6
                    }
                }
            }
        }
    });


    // Main Chart
    const ctx = document.getElementById('mainChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo Html::json($months); ?>,
            datasets: [{
                label: 'Income',
                data: <?php echo Html::json($income_data); ?>,
                borderColor: '#198754',
                backgroundColor: 'rgba(25, 135, 84, 0.1)',
                tension: 0.4,
                fill: true
            },
            {
                label: 'Expense',
                data: <?php echo Html::json($expense_data); ?>,
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    // Category Chart
    <?php if (!empty($cat_values)): ?>
        const ctx2 = document.getElementById('catChart').getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: <?php echo Html::json($cat_labels); ?>,
                datasets: [{
                    data: <?php echo Html::json($cat_values); ?>,
                    backgroundColor: [
                        '#0d6efd', '#6610f2', '#6f42c1', '#d63384',
                        '#dc3545', '#fd7e14', '#ffc107', '#198754',
                        '#20c997', '#0dcaf0'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    <?php endif; ?>


    // Projection Chart
    const ctx3 = document.getElementById('projectionChart').getContext('2d');
    new Chart(ctx3, {
        type: 'line',
        data: {
            labels: <?php echo Html::json($projected_dates); ?>,
            datasets: [{
                label: 'Projected Balance',
                data: <?php echo Html::json($projected_balance); ?>,
                borderColor: '#6610f2',
                backgroundColor: 'rgba(102, 16, 242, 0.1)',
                borderDash: [5, 5],
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: false } }
        }
    });
</script>

<?php Layout::footer(); ?>
