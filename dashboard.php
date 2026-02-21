<?php
$page_title = "Dashboard";
require_once 'config.php'; // NOSONAR
require_once 'includes/header.php'; // NOSONAR
require_once 'includes/sidebar.php'; // NOSONAR

$user_id = $_SESSION['user_id'];
$curr_month = date('n');
$curr_year = date('Y');

// Base Currency Scaling Logic
$base_currency = $_SESSION['preferences']['base_currency'] ?? 'AED';
$currency_multiplier = ($base_currency == 'INR') ? 24 : 1;
$currency_label = $base_currency;

// 1. Fetch Summary Stats (Current Month)
// Total Income
$stmt = $pdo->prepare("SELECT SUM(amount) FROM income WHERE tenant_id = ? AND MONTH(income_date) = ? AND YEAR(income_date) = ?");
$stmt->execute([$_SESSION['tenant_id'], $curr_month, $curr_year]);
$income_now = $stmt->fetchColumn() ?: 0;

// Total Expenses
$stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?");
$stmt->execute([$_SESSION['tenant_id'], $curr_month, $curr_year]);
$expense_now = $stmt->fetchColumn() ?: 0;

// Total Net Worth (Latest Balances)
// We sum the latest entry for each bank
// This query gets the latest amount for each bank_name
$stmt = $pdo->prepare("
    SELECT SUM(
        CASE
            WHEN currency = 'INR' THEN amount / 24
            ELSE amount
        END
    )
    FROM bank_balances b1
    WHERE tenant_id = ?
    AND bank_name != 'Opening Balance Adjustment'
    AND id = (SELECT MAX(id) FROM bank_balances b2 WHERE b2.bank_name = b1.bank_name AND b2.tenant_id = b1.tenant_id)
");
$stmt->execute([$_SESSION['tenant_id']]);
$net_worth = $stmt->fetchColumn() ?: 0;

// Total Credit Limit
$stmt = $pdo->prepare("SELECT SUM(limit_amount) FROM cards WHERE tenant_id = ?");
$stmt->execute([$_SESSION['tenant_id']]);
$total_limit = $stmt->fetchColumn() ?: 0;

// Credit Utilization Logic
$stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE tenant_id = ? AND payment_method = 'Card' AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?");
$stmt->execute([$_SESSION['tenant_id'], $curr_month, $curr_year]);
$total_card_spend = $stmt->fetchColumn() ?: 0;

$utilization = ($total_limit > 0) ? ($total_card_spend / $total_limit) * 100 : 0;
$savings_rate = ($income_now > 0) ? (($income_now - $expense_now) / $income_now) * 100 : 0;
$savings_rate = max($savings_rate, 0); // No negative savings rate visually

// 1.5 PHASE 8: Wealth Journey (Snapshot Comparison)
// Get Net Worth Last Year (Same Month)
$last_year_net_worth = 0;
// Logic: Get latest balance for each bank recorded ON or BEFORE end of Last Year Month
$stmt = $pdo->prepare("
    SELECT SUM(CASE WHEN currency='INR' THEN amount / 24 ELSE amount END)
    FROM bank_balances b1
    WHERE tenant_id = ?
    AND id = (
        SELECT MAX(id) FROM bank_balances b2
        WHERE b2.bank_name = b1.bank_name
        AND b2.tenant_id = b1.tenant_id
        AND b2.balance_date <= LAST_DAY(DATE_SUB(NOW(), INTERVAL 1 YEAR))
    )
");
$stmt->execute([$_SESSION['tenant_id']]);
$last_year_net_worth = $stmt->fetchColumn() ?: 0;

$wealth_growth_abs = $net_worth - $last_year_net_worth;
$wealth_growth_pct = ($last_year_net_worth > 0) ? ($wealth_growth_abs / $last_year_net_worth) * 100 : 100;

// Wealth Chart Data (Last 12 Months Net Worth Trend)
$wealth_months = [];
$wealth_data = [];
for ($i = 11; $i >= 0; $i--) {
    $date_cursor = strtotime("-$i months");
    $wealth_months[] = date('M Y', $date_cursor);
    $month_end_date = date('Y-m-t', $date_cursor); // End of that month

    $stmt = $pdo->prepare("
        SELECT SUM(CASE WHEN currency='INR' THEN amount / 24 ELSE amount END)
        FROM bank_balances b1
        WHERE tenant_id = ?
        AND id = (
            SELECT MAX(id) FROM bank_balances b2
            WHERE b2.bank_name = b1.bank_name
            AND b2.tenant_id = b1.tenant_id
            AND b2.balance_date <= ?
        )
    ");
    $stmt->execute([$_SESSION['tenant_id'], $month_end_date]);
    $wealth_data[] = $stmt->fetchColumn() ?: 0;
}


// 2. Fetch Chart Data (Last 6 Months)
$months = [];
$income_data = [];
$expense_data = [];

for ($i = 5; $i >= 0; $i--) {
    $m = date('n', strtotime("-$i months"));
    $y = date('Y', strtotime("-$i months"));
    $months[] = date('M', strtotime("-$i months"));

    // Income
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM income WHERE tenant_id = ? AND MONTH(income_date) = ? AND YEAR(income_date) = ?");
    $stmt->execute([$_SESSION['tenant_id'], $m, $y]);
    $income_data[] = $stmt->fetchColumn() ?: 0;

    // Expense
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?");
    $stmt->execute([$_SESSION['tenant_id'], $m, $y]);
    $expense_data[] = $stmt->fetchColumn() ?: 0;
}

// 3. Category Data (Current Month)
$stmt = $pdo->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ? GROUP BY category");
$stmt->execute([$_SESSION['tenant_id'], $curr_month, $curr_year]);
$cat_results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$cat_labels = array_keys($cat_results);
$cat_values = array_values($cat_results);

// 5. ROI & Anatomy Stats
$stmt = $pdo->prepare("SELECT SUM(cashback_earned) FROM expenses WHERE tenant_id = ? AND YEAR(expense_date) = ?");
$stmt->execute([$_SESSION['tenant_id'], $curr_year]);
$total_cashback = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT is_fixed, SUM(amount) as total FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ? GROUP BY is_fixed");
$stmt->execute([$_SESSION['tenant_id'], $curr_month, $curr_year]);
$anatomy = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [0 => 'Discretionary', 1 => 'Fixed']

$fixed_cost = $anatomy[1] ?? 0;
$var_cost = $anatomy[0] ?? 0;
$total_cost = $fixed_cost + $var_cost;
$fixed_pct = ($total_cost > 0) ? ($fixed_cost / $total_cost) * 100 : 0;
$fixed_pct = ($total_cost > 0) ? ($fixed_cost / $total_cost) * 100 : 0;

// 6. Emergency Runway (Avg Expense Last 3 Months)
// Note: We use 3 months prior to current month for stability
$stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE tenant_id = ? AND expense_date BETWEEN DATE_SUB(NOW(), INTERVAL 3 MONTH) AND NOW()");
$stmt->execute([$_SESSION['tenant_id']]);
$last_3m_spend = $stmt->fetchColumn() ?: 0;
$avg_monthly_spend = $last_3m_spend / 3;
$runway_months = ($avg_monthly_spend > 0) ? $net_worth / $avg_monthly_spend : 0;

// Fetch Monthly Budgets for Dashboard Overview
$budget_stmt = $pdo->prepare("SELECT category, amount FROM budgets WHERE tenant_id = ? AND month = ? AND year = ?");
$budget_stmt->execute([$_SESSION['tenant_id'], $curr_month, $curr_year]);
$dash_budgets = $budget_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$total_budgeted = array_sum($dash_budgets);
$budget_utilization = ($total_budgeted > 0) ? ($expense_now / $total_budgeted) * 100 : 0;

// 7. Lifestyle Creep (YoY Category Comparison)
$last_year_month = date('n', strtotime('-1 year'));
$last_year_year = date('Y', strtotime('-1 year'));
$stmt = $pdo->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ? GROUP BY category");
$stmt->execute([$_SESSION['tenant_id'], $last_year_month, $last_year_year]);
$last_year_cats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

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
$stmt = $pdo->prepare("SELECT amount, recurrence_day FROM income WHERE tenant_id = ? AND is_recurring = 1");
$stmt->execute([$_SESSION['tenant_id']]);
$recurring_incomes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Recurring Expenses (Subscriptions)
$stmt = $pdo->prepare("SELECT amount, DAY(expense_date) as day FROM expenses WHERE tenant_id = ? AND is_subscription = 1");
$stmt->execute([$_SESSION['tenant_id']]);
$recurring_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    $projected_dates[] = $formatted_date;
    $projected_balance[] = $running_bal;
}

// 8.5 PHASE 4: ROI & Liquidity (Restored)
// Fetch Cards for Smart Engine
$stmt = $pdo->prepare("SELECT * FROM cards WHERE tenant_id = ?");
$stmt->execute([$_SESSION['tenant_id']]);
$roi_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

// True Liquidity: Net Worth - Unbilled Card Spends
$stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE tenant_id = ? AND payment_method = 'Card' AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?");
$stmt->execute([$_SESSION['tenant_id'], $curr_month, $curr_year]);
$unbilled_card_spend = $stmt->fetchColumn() ?: 0; // Approx using current month spend
$true_liquidity = $net_worth - $unbilled_card_spend;

// 9. PHASE 6: Safe-to-Spend Logic
// Estimate Monthly Fixed Cost (Avg of last 3 months fixed spend)
$stmt = $pdo->prepare("
    SELECT SUM(amount) FROM expenses
    WHERE tenant_id = ? AND is_fixed = 1
    AND expense_date BETWEEN DATE_SUB(NOW(), INTERVAL 3 MONTH) AND NOW()
");
$stmt->execute([$_SESSION['tenant_id']]);
$avg_fixed_cost = ($stmt->fetchColumn() ?: 0) / 3;
$remaining_fixed = max(0, $avg_fixed_cost - $fixed_cost);
$savings_target = $income_now * 0.20; // 20% Goal

// 9.5 PHASE 8: Sinking Funds Deduction
// Calculate how much we need to save THIS MONTH for all active goals
$total_goal_contribution = 0;
$stmt = $pdo->prepare("SELECT * FROM sinking_funds WHERE tenant_id = ? AND current_saved < target_amount AND target_date > NOW()");
$stmt->execute([$_SESSION['tenant_id']]);
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
    JOIN cards c ON e.card_id = c.id
    WHERE e.tenant_id = ? AND e.payment_method = 'Card'
    ORDER BY e.expense_date DESC LIMIT 5
");
$stmt->execute([$_SESSION['tenant_id']]);
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
            foreach ($keywords as $k) {
                if (stripos($txn['category'], $k) !== false || stripos($k, $txn['category']) !== false) {
                    $alerts[] = [
                        'type' => 'swap',
                        'icon' => 'fa-arrow-right-arrow-left',
                        'color' => 'info',
                        'msg' => "Smart Swap: You spent {$txn['amount']} on {$txn['category']} with {$txn['card_name']}. Use <b>{$card['card_name']}</b> next time for better rewards!"
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
                'msg' => "Liquidity Alert: Bill for <b>{$card['card_name']}</b> generated today. Ensure you have funds."
            ];
        }
    }
}
// 11. Interest Tracker Chart Data (Last 12 Months)
// Positive = Interest (Debt), Negative = Payments
$interest_months = [];
$interest_accrued_data = [];
$interest_paid_data = [];

for ($i = 11; $i >= 0; $i--) {
    $m = date('n', strtotime("-$i months"));
    $y = date('Y', strtotime("-$i months"));
    $interest_months[] = date('M Y', strtotime("-$i months"));

    // Interest Accrued (Sum of positive amounts)
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM interest_tracker WHERE tenant_id = ? AND amount > 0 AND MONTH(interest_date) = ? AND YEAR(interest_date) = ?");
    $stmt->execute([$_SESSION['tenant_id'], $m, $y]);
    $interest_accrued_data[] = $stmt->fetchColumn() ?: 0;

    // Payments Made (Sum of negative amounts -> convert to positive for chart)
    $stmt = $pdo->prepare("SELECT SUM(ABS(amount)) FROM interest_tracker WHERE tenant_id = ? AND amount < 0 AND MONTH(interest_date) = ? AND YEAR(interest_date) = ?");
    $stmt->execute([$_SESSION['tenant_id'], $m, $y]);
    $interest_paid_data[] = $stmt->fetchColumn() ?: 0;
}

// 12. Upcoming Bills & Pending Auto-Drafts
$upcoming_bills = [];
$curr_month_logged = $pdo->prepare("SELECT description FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?");
$curr_month_logged->execute([$_SESSION['tenant_id'], $curr_month, $curr_year]);
$logged_subs = $curr_month_logged->fetchAll(PDO::FETCH_COLUMN);

// Fetch Unique Subscription Templates
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
$stmt->execute([$_SESSION['tenant_id']]);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-5">
    <div>
        <h1 class="h3 fw-bold mb-1">Dashboard</h1>
        <p class="text-muted mb-0">Overview for <?php echo date('F Y'); ?></p>
    </div>
    <div class="d-flex align-items-center gap-3">
        <!-- Manage Banks Link -->
        <a href="my_banks.php"
            class="btn btn-outline-primary rounded-pill px-3 shadow-sm d-flex align-items-center fw-bold">
            <i class="fa-solid fa-landmark me-2"></i> Banks
        </a>

        <!-- Runway Badge -->
        <div class="bg-primary-subtle text-primary px-3 py-1 rounded-pill shadow-sm d-flex align-items-center fw-bold"
            title="Emergency Fund Runway">
            <i class="fa-solid fa-plane-departure me-2"></i> <?php echo number_format($runway_months, 1); ?> Months
        </div>

        <?php if ($total_cashback > 0): ?>
            <div class="bg-warning-subtle text-warning px-3 py-1 rounded-pill shadow-sm d-flex align-items-center fw-bold">
                <i class="fa-solid fa-gift me-2"></i> AED <?php echo number_format($total_cashback, 2); ?>
            </div>
        <?php endif; ?>
        <div class="bg-white p-1 rounded-pill shadow-sm d-flex align-items-center gap-2 pe-3">
            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['user_name'] ?? 'U'); ?>&background=random"
                class="rounded-circle" width="32" height="32" alt="User">
            <span class="small fw-bold"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div class="row g-4 mb-5">
    <!-- Income -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="glass-panel p-4 h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div class="rounded-circle bg-success-subtle p-3 text-success">
                    <i class="fa-solid fa-arrow-trend-up fa-xl"></i>
                </div>
                <div class="dropdown">
                    <button class="btn btn-sm btn-link text-muted" type="button"><i
                            class="fa-solid fa-ellipsis"></i></button>
                </div>
            </div>
            <h3 class="fw-bold mb-1"><?php echo $currency_label; ?> <span
                    class="blur-sensitive"><?php echo number_format($income_now * $currency_multiplier, 2); ?></span>
            </h3>
            <span class="text-muted small">Income (This Month)</span>
        </div>
    </div>

    <!-- Expenses -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="glass-panel p-4 h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div class="rounded-circle bg-danger-subtle p-3 text-danger">
                    <i class="fa-solid fa-arrow-trend-down fa-xl"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-1"><?php echo $currency_label; ?> <span
                    class="blur-sensitive"><?php echo number_format($expense_now * $currency_multiplier, 2); ?></span>
            </h3>
            <span class="text-muted small">Expenses (This Month)</span>
        </div>
    </div>

    <!-- Net Worth & True Liquidity -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="glass-panel p-4 h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div class="rounded-circle bg-info-subtle p-3 text-info">
                    <i class="fa-solid fa-building-columns fa-xl"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-0"><?php echo $currency_label; ?> <span
                    class="blur-sensitive"><?php echo number_format($net_worth * $currency_multiplier, 2); ?></span>
            </h3>
            <div class="small text-muted mb-2">Total Bank Balance</div>

            <div class="border-top pt-2">
                <div class="d-flex justify-content-between text-success fw-bold small">
                    <span>Liq. Assets:</span>
                    <span
                        class="blur-sensitive"><?php echo number_format($true_liquidity * $currency_multiplier, 2); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Credit Limit -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="glass-panel p-4 h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div class="rounded-circle bg-primary-subtle p-3 text-primary">
                    <i class="fa-solid fa-credit-card fa-xl"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-1"><?php echo $currency_label; ?> <span
                    class="blur-sensitive"><?php echo number_format($total_limit * $currency_multiplier, 2); ?></span>
            </h3>
            <span class="text-muted small">Total Credit Limit</span>
        </div>
    </div>
</div>

<!-- Proactive Alerts -->
<?php if (!empty($alerts)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <?php foreach ($alerts as $alert): ?>
                <div class="alert alert-<?php echo $alert['color']; ?> border-0 shadow-sm d-flex align-items-center mb-2"
                    role="alert">
                    <i class="fa-solid <?php echo $alert['icon']; ?> fa-lg me-3"></i>
                    <div><?php echo $alert['msg']; ?></div>
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
            <h5 class="fw-bold mb-3 position-relative">ðŸŸ¢ Safe to Spend</h5>
            <h2 class="display-4 fw-bold text-success position-relative blur-sensitive">
                <?php echo number_format(max(0, $safe_to_spend), 2); ?>
            </h2>
            <p class="text-muted small position-relative mb-0">Guilt-free cash after bills & savings</p>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="glass-panel p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">â¤ï¸ Financial Health</h5>
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
                <h5 class="fw-bold mb-0">ðŸ’³ Credit Utilization</h5>
                <span class="badge bg-primary-subtle text-primary">Target: <30%< /span>
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
                                $pct = ($spent / $limit) * 100;
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
                                        <span><?php echo $cat; ?></span>
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

</div>

<!-- Foresight Row -->
<div class="row mb-5 g-5">
    <!-- Cash Flow Projection -->
    <div class="col-lg-8">
        <div class="glass-panel p-4 h-100">
            <h5 class="fw-bold mb-4">ðŸ”® 30-Day Cash Flow Projection</h5>
            <canvas id="projectionChart" style="max-height: 250px;"></canvas>
        </div>
    </div>

    <!-- Lifestyle Creep -->
    <div class="col-lg-4">
        <div class="glass-panel p-4 h-100">
            <h5 class="fw-bold mb-4">ðŸ“‰ Lifestyle Creep <span class="badge bg-warning text-dark ms-2">YoY
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
                                <span class="fw-bold"><?php echo htmlspecialchars($alert['category']); ?></span>
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
                                        title="<?php echo htmlspecialchars($bill['name']); ?>">
                                        <?php echo htmlspecialchars($bill['name']); ?>
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
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="log_subscription">
                                            <input type="hidden" name="template_id" value="<?php echo $bill['id']; ?>">
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
            <h5 class="fw-bold mb-3">ðŸ§© Spend Anatomy <span class="text-muted small fw-normal">(Fixed vs.
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
                    <h5 class="fw-bold mb-1">ðŸš€ Wealth Journey</h5>
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    // Interest Chart (Moved here to ensure Chart.js is loaded)
    const interestCtx = document.getElementById('interestChart').getContext('2d');
    new Chart(interestCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($interest_months); ?>,
            datasets: [
                {
                    label: 'Interest Accrued (Debt)',
                    data: <?php echo json_encode($interest_accrued_data); ?>,
                    backgroundColor: 'rgba(220, 53, 69, 0.7)', // Danger Red
                    borderColor: '#dc3545',
                    borderWidth: 1,
                    borderRadius: 4
                },
                {
                    label: 'Payments Made (Charity)',
                    data: <?php echo json_encode($interest_paid_data); ?>,
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
            labels: <?php echo json_encode($wealth_months); ?>,
            datasets: [{
                label: 'Net Worth',
                data: <?php echo json_encode($wealth_data); ?>,
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
            labels: <?php echo json_encode($months); ?>,
            datasets: [{
                label: 'Income',
                data: <?php echo json_encode($income_data); ?>,
                borderColor: '#198754',
                backgroundColor: 'rgba(25, 135, 84, 0.1)',
                tension: 0.4,
                fill: true
            },
            {
                label: 'Expense',
                data: <?php echo json_encode($expense_data); ?>,
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
                labels: <?php echo json_encode($cat_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($cat_values); ?>,
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
            labels: <?php echo json_encode($projected_dates); ?>,
            datasets: [{
                label: 'Projected Balance',
                data: <?php echo json_encode($projected_balance); ?>,
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

<?php require_once 'includes/footer.php'; // NOSONAR ?>
