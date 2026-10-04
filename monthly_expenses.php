<?php
$page_title = "Monthly Expenses";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;
use App\Helpers\SecurityHelper;
use App\Helpers\Html;

Bootstrap::init();

function in_text($str, $arr) {
    foreach ($arr as $a) {
        if (stripos($str, $a) !== false) return true;
    }
    return false;
}

// SQL Fragments for Filtering
const SQL_FILTER_CATEGORY = " AND e.category = :cat";
const SQL_FILTER_PAYMENT = " AND e.payment_method = :pm";
const SQL_FILTER_CARD = " AND e.card_id = :card_id";
const SQL_FILTER_START_DATE = " AND e.expense_date >= :start";
const SQL_FILTER_END_DATE = " AND e.expense_date <= :end";


Layout::header();
Layout::sidebar();

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');
$category_filter = filter_input(INPUT_GET, 'category') ?: null;
$payment_filter = filter_input(INPUT_GET, 'payment_method') ?: null;
$card_filter = filter_input(INPUT_GET, 'card_id', FILTER_VALIDATE_INT) ?: null;
$start_date = filter_input(INPUT_GET, 'start', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^\d{4}-\d{2}-\d{2}$/']]) ?: null;
$end_date = filter_input(INPUT_GET, 'end', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^\d{4}-\d{2}-\d{2}$/']]) ?: null;

// Active filters, reused by the export and pagination links
$filter_query = array_filter([
    'category'       => $category_filter,
    'payment_method' => $payment_filter,
    'card_id'        => $card_filter,
    'start'          => $start_date,
    'end'            => $end_date,
], fn($v) => $v !== null && $v !== '');

// Fetch family's cards for filter dropdown
$cards_stmt = $pdo->prepare("SELECT id, bank_name, card_name FROM cards WHERE tenant_id = ? ORDER BY card_name");
$cards_stmt->execute([$_SESSION['tenant_id']]);
$user_cards = $cards_stmt->fetchAll();

$month_name = date("F", mktime(0, 0, 0, $month, 10));

// Pagination settings
$items_per_page = 15;
$page_num = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$offset = ($page_num - 1) * $items_per_page;

// Count total for pagination
$count_query = "SELECT COUNT(*) FROM expenses e
          WHERE e.tenant_id = :tenant_id
          AND MONTH(e.expense_date) = :month
          AND YEAR(e.expense_date) = :year";
$count_params = ['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year];

if ($category_filter) {
    $count_query .= SQL_FILTER_CATEGORY;
    $count_params['cat'] = $category_filter;
}
if ($payment_filter) {
    $count_query .= SQL_FILTER_PAYMENT;
    $count_params['pm'] = $payment_filter;
}
if ($card_filter) {
    $count_query .= SQL_FILTER_CARD;
    $count_params['card_id'] = $card_filter;
}
if ($start_date) {
    $count_query .= SQL_FILTER_START_DATE;
    $count_params['start'] = $start_date;
}
if ($end_date) {
    $count_query .= SQL_FILTER_END_DATE;
    $count_params['end'] = $end_date;
}

$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($count_params);
$total_items = $count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_items / $items_per_page));

// Build Query with LIMIT
$query = "SELECT e.*, c.bank_name, c.card_name, u.name as spender_name
          FROM expenses e
          LEFT JOIN cards c ON e.card_id = c.id AND c.tenant_id = e.tenant_id
          LEFT JOIN users u ON e.spent_by_user_id = u.id AND u.tenant_id = e.tenant_id
          WHERE e.tenant_id = :tenant_id
          AND MONTH(e.expense_date) = :month
          AND YEAR(e.expense_date) = :year";

$params = ['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year];

if ($category_filter) {
    $query .= SQL_FILTER_CATEGORY;
    $params['cat'] = $category_filter;
}
if ($payment_filter) {
    $query .= SQL_FILTER_PAYMENT;
    $params['pm'] = $payment_filter;
}
if ($card_filter) {
    $query .= SQL_FILTER_CARD;
    $params['card_id'] = $card_filter;
}
if ($start_date) {
    $query .= SQL_FILTER_START_DATE;
    $params['start'] = $start_date;
}
if ($end_date) {
    $query .= SQL_FILTER_END_DATE;
    $params['end'] = $end_date;
}

$query .= " ORDER BY e.expense_date DESC LIMIT $items_per_page OFFSET $offset";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();

    // Calculate total for this month (BASED ON FILTERS)
    $total_query = "SELECT SUM(amount) FROM expenses e WHERE e.tenant_id = :tenant_id
                    AND MONTH(e.expense_date) = :month
                    AND YEAR(e.expense_date) = :year";

    // Append the same filters as count/list
    if ($category_filter) {
        $total_query .= SQL_FILTER_CATEGORY;
    }
    if ($payment_filter) {
        $total_query .= SQL_FILTER_PAYMENT;
    }
    if ($card_filter) {
        $total_query .= SQL_FILTER_CARD;
    }
    if ($start_date) {
        $total_query .= SQL_FILTER_START_DATE;
    }
    if ($end_date) {
        $total_query .= SQL_FILTER_END_DATE;
    }

    $total_stmt = $pdo->prepare($total_query);
    $total_stmt->execute($count_params); // Reuse same params as count
    $view_total = $total_stmt->fetchColumn() ?: 0;

    // Fetch Category Budgets and Actuals for Progress Bars
    $budget_stmt = $pdo->prepare("SELECT category, amount FROM budgets WHERE tenant_id = ? AND month = ? AND year = ?");
    $budget_stmt->execute([$_SESSION['tenant_id'], $month, $year]);
    $cat_budgets = $budget_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $actual_stmt = $pdo->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ? GROUP BY category");
    $actual_stmt->execute([$_SESSION['tenant_id'], $month, $year]);
    $cat_actuals = $actual_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

} catch (PDOException $e) {
    $expenses = [];
    $view_total = 0;
    $total_pages = 1;
    $cat_budgets = [];
    $cat_actuals = [];
}
?>

<!-- Premium Header Banner -->
<div class="row mb-4">
    <div class="col-12">
        <div class="gradient-card-danger p-4 rounded-4 hover-lift position-relative overflow-hidden shadow-sm" style="border-radius: 16px;">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <a href="expenses.php?year=<?php echo $year; ?>" class="text-decoration-none text-white-50 small mb-1 d-inline-block">
                        <i class="fa-solid fa-arrow-left"></i> Back to Yearly Map
                    </a>
                    <h1 class="h2 fw-bold text-white mb-0"><?php echo $month_name . ' ' . $year; ?> Details</h1>
                    <p class="text-white-50 mb-0">Comprehensive transaction ledger and budget tracking.</p>
                </div>
                <div class="text-md-end">
                    <span class="small text-white-50 d-block uppercase tracking-wider">Total Spent This Month</span>
                    <h2 class="fw-bold text-white mb-0">
                        <small style="font-size: 0.6em">AED</small>
                        <span class="blur-sensitive"><?php echo number_format($view_total, 2); ?></span>
                    </h2>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Drawer (Glassmorphic) -->
<div class="glass-panel-premium p-4 mb-4">
    <form method="GET" class="row g-3 align-items-end">
        <input type="hidden" name="month" value="<?php echo $month; ?>">
        <input type="hidden" name="year" value="<?php echo $year; ?>">

        <div class="col-12 col-sm-6 col-md-2">
            <label for="filterCategory" class="small text-muted mb-1 fw-bold">Category</label>
            <select name="category" id="filterCategory" class="form-select" data-autosubmit>
                <option value="">All Categories</option>
                <?php
                $categories = ['Grocery', 'Medical', 'Food', 'Utilities', 'Transport', 'Shopping', 'Entertainment', 'Travel', 'Education', 'Other'];
                foreach ($categories as $cat): ?>
                    <option value="<?php echo Html::e($cat); ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>>
                        <?php echo Html::e($cat); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12 col-sm-6 col-md-2">
            <label for="filterPayment" class="small text-muted mb-1 fw-bold">Payment Method</label>
            <select name="payment_method" id="filterPayment" class="form-select" data-autosubmit>
                <option value="">All Methods</option>
                <option value="Cash" <?php echo $payment_filter == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                <option value="Card" <?php echo $payment_filter == 'Card' ? 'selected' : ''; ?>>Card</option>
            </select>
        </div>

        <div class="col-12 col-sm-6 col-md-2">
            <label for="filterCard" class="small text-muted mb-1 fw-bold">Credit Card</label>
            <select name="card_id" id="filterCard" class="form-select" data-autosubmit>
                <option value="">All Cards</option>
                <?php foreach ($user_cards as $uc): ?>
                    <option value="<?php echo (int) $uc['id']; ?>" <?php echo $card_filter == $uc['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($uc['bank_name'] . ' - ' . $uc['card_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-sm-3 col-md-2">
            <label for="filterStart" class="small text-muted mb-1 fw-bold">Start Date</label>
            <input type="date" name="start" id="filterStart" class="form-control" value="<?php echo htmlspecialchars($start_date ?? ''); ?>" data-autosubmit>
        </div>

        <div class="col-6 col-sm-3 col-md-2">
            <label for="filterEnd" class="small text-muted mb-1 fw-bold">End Date</label>
            <input type="date" name="end" id="filterEnd" class="form-control" value="<?php echo htmlspecialchars($end_date ?? ''); ?>" data-autosubmit>
        </div>

        <div class="col-12 col-md-2 d-flex gap-2 justify-content-md-end">
            <a href="print_report.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" target="_blank" class="btn btn-outline-secondary flex-grow-1" title="Print Ledger">
                <i class="fa-solid fa-print"></i>
            </a>
            <a href="export_actions.php?<?php echo Html::e(http_build_query(['action' => 'export_expenses', 'month' => $month, 'year' => $year] + $filter_query)); ?>" class="btn btn-outline-secondary flex-grow-1" title="Export CSV">
                <i class="fa-solid fa-file-csv"></i>
            </a>
            <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                <a href="add_expense.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-primary flex-grow-1">
                    <i class="fa-solid fa-plus"></i> Add
                </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($category_filter || $payment_filter || $card_filter || $start_date || $end_date): ?>
        <div class="mt-3 pt-3 border-top">
            <a href="?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-sm btn-outline-danger">
                <i class="fa-solid fa-times me-1"></i> Reset Active Filters
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Budget Progress Indicators (Glassmorphic Grid) -->
<?php if (!empty($cat_budgets)): ?>
    <div class="glass-panel-premium p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0"><i class="fa-solid fa-bullseye text-primary me-2"></i> Monthly Budget Tracking</h6>
            <a href="manage_budgets.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="small text-primary text-decoration-none fw-bold">Adjust Budgets</a>
        </div>
        <div class="row g-3">
            <?php foreach ($cat_budgets as $cat => $limit):
                $spent = $cat_actuals[$cat] ?? 0;
                $pct = $limit > 0 ? ($spent / $limit) * 100 : 0;
                $color = 'bg-success';
                if ($pct > 80) {
                    $color = 'bg-warning text-dark';
                }
                if ($pct > 100) {
                    $color = 'bg-danger';
                }
                ?>
                <div class="col-md-3">
                    <div class="small d-flex justify-content-between mb-1">
                        <span class="fw-bold"><?php echo Html::e($cat); ?></span>
                        <span class="fw-bold"><?php echo number_format($pct, 0); ?>%</span>
                    </div>
                    <div class="progress" style="height: 8px;" title="Spent AED <?php echo number_format($spent); ?> of AED <?php echo number_format($limit); ?>">
                        <div class="progress-bar <?php echo $color; ?>" role="progressbar" style="width: <?php echo min($pct, 100); ?>%"></div>
                    </div>
                    <div class="x-small text-muted mt-1">
                        AED <?php echo number_format($spent); ?> / <?php echo number_format($limit); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Transaction Ledger Card -->
<?php if (empty($expenses)): ?>
    <div class="glass-panel-premium text-center py-5">
        <i class="fa-solid fa-face-meh fa-3x text-muted mb-3"></i>
        <p class="text-muted fw-bold mb-0">No transaction records found matching your filters.</p>
    </div>
<?php else: ?>
    <div class="glass-panel-premium overflow-hidden shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid rgba(0,0,0,0.05);">
                        <th class="ps-4 py-3" style="width: 40px; background: transparent;">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                        </th>
                        <th class="py-3" style="background: transparent;">Day</th>
                        <th style="background: transparent;">Description</th>
                        <th style="background: transparent;">Category</th>
                        <th style="background: transparent;">Spender</th>
                        <th style="background: transparent;">Source</th>
                        <th class="text-end pe-4" style="background: transparent;">Amount</th>
                        <th style="background: transparent;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $expense): ?>
                        <tr class="hover-lift-row" data-id="<?php echo (int) $expense['id']; ?>" style="border-bottom: 1px solid rgba(0,0,0,0.03); transition: background 0.2s;">
                            <td class="ps-4">
                                <input type="checkbox" class="form-check-input row-checkbox" name="expense_ids[]" value="<?php echo (int) $expense['id']; ?>">
                            </td>
                            <td class="fw-bold">
                                <?php echo date('d', strtotime($expense['expense_date'])); ?>
                                <span class="small text-muted fw-normal d-block" style="font-size: 0.75em;">
                                    <?php echo date('D', strtotime($expense['expense_date'])); ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($expense['description']); ?></div>
                                <?php if (!empty($expense['tags'])): ?>
                                    <div class="mt-1">
                                        <?php foreach (explode(',', $expense['tags']) as $tag): ?>
                                            <span class="badge bg-light text-secondary border me-1" style="font-size: 0.65em; letter-spacing: 0.2px;">
                                                #<?php echo htmlspecialchars(trim($tag)); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badge_style = "bg-primary-subtle text-primary";
                                if (in_text($expense['category'], ['Grocery', 'Food'])) $badge_style = "bg-success-subtle text-success";
                                if (in_text($expense['category'], ['Utilities', 'Bill'])) $badge_style = "bg-warning-subtle text-warning";
                                if (in_text($expense['category'], ['Medical'])) $badge_style = "bg-danger-subtle text-danger";
                                if (in_text($expense['category'], ['Travel', 'Transport'])) $badge_style = "bg-info-subtle text-info";
                                ?>
                                <span class="badge rounded-pill <?php echo $badge_style; ?> px-3 py-2 fw-semibold">
                                    <?php echo htmlspecialchars($expense['category']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="small fw-bold text-muted">
                                    <i class="fa-solid fa-circle-user me-1 text-secondary"></i>
                                    <?php echo htmlspecialchars($expense['spender_name'] ?? 'Family Head'); ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($expense['payment_method'] == 'Card'): ?>
                                    <div class="small fw-semibold text-dark">
                                        <i class="fa-solid fa-credit-card text-primary me-1"></i>
                                        <?php echo htmlspecialchars($expense['card_name'] ?: $expense['bank_name']); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="small text-secondary fw-semibold">
                                        <i class="fa-solid fa-coins text-warning me-1"></i> Cash Assets
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4 fw-bold">
                                <span class="text-dark">AED</span>
                                <span class="blur-sensitive text-dark"><?php echo number_format($expense['amount'], 2); ?></span>
                                <?php if (!empty($expense['currency']) && $expense['currency'] != 'AED'): ?>
                                    <div class="small text-muted fw-normal mt-1" style="font-size: 0.75em;">
                                        (<?php echo htmlspecialchars($expense['currency'] . ' ' . number_format($expense['original_amount'], 2)); ?>)
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                    <a href="edit_expense.php?id=<?php echo (int) $expense['id']; ?>" class="btn btn-sm btn-link text-muted me-1" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <form action="expense_actions.php" method="POST" class="d-inline" data-confirm="<?php echo Html::e('Delete ' . $expense['description'] . ' - AED ' . number_format((float) $expense['amount'], 2) . '?'); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="delete_expense">
                                        <input type="hidden" name="id" value="<?php echo (int) $expense['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-link text-danger border-0 p-0" title="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <i class="fa-solid fa-lock text-muted small" title="Read Only"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-light d-flex justify-content-between align-items-center py-3">
                <div class="text-muted small">
                    Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $items_per_page, $total_items); ?> of
                    <?php echo $total_items; ?> expenses
                </div>
                <nav aria-label="Page navigation">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $base_url = Html::e('?' . http_build_query(['month' => $month, 'year' => $year] + $filter_query));
                        ?>
                        <li class="page-item <?php echo $page_num <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $base_url; ?>&page=<?php echo $page_num - 1; ?>">
                                <i class="fa-solid fa-chevron-left"></i>
                            </a>
                        </li>
                        <?php for ($i = max(1, $page_num - 2); $i <= min($total_pages, $page_num + 2); $i++): ?>
                            <li class="page-item <?php echo $i == $page_num ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo $base_url; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page_num >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo $base_url; ?>&page=<?php echo $page_num + 1; ?>">
                                <i class="fa-solid fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Bulk Action Floating Bar -->
<?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
    <div id="bulkActionBar"
        class="position-fixed bottom-0 start-50 translate-middle-x mb-4 shadow-lg glass-panel p-3 d-none animate__animated animate__fadeInUp"
        style="z-index: 1050; border-radius: 50px; min-width: 300px;">
        <div class="d-flex align-items-center justify-content-between gap-4 px-2">
            <div class="text-nowrap fw-bold">
                <span id="selectedCount">0</span> Selected
            </div>
            <div class="d-flex gap-2">
                <div class="dropdown">
                    <button class="btn btn-outline-primary btn-sm rounded-pill dropdown-toggle" type="button"
                        data-bs-toggle="dropdown">
                        Change Category
                    </button>
                    <ul class="dropdown-menu border-0 shadow">
                        <?php foreach ($categories as $cat): ?>
                            <li>
                                <button type="button" class="dropdown-item"
                                    data-onclick="bulkAction" data-args="<?php echo Html::args('change_category', $cat); ?>">
                                    <?php echo Html::e($cat); ?>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <button class="btn btn-danger btn-sm rounded-pill px-3" data-onclick="bulkAction" data-args="<?php echo Html::args('delete'); ?>">
                    <i class="fa-solid fa-trash me-1"></i> Delete
                </button>
                <button class="btn btn-link btn-sm text-muted" data-onclick="deselectAll">Cancel</button>
            </div>
        </div>
    </div>

    <form id="bulkActionForm" action="expense_actions.php" method="POST" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
        <input type="hidden" name="action" id="bulkActionType">
        <input type="hidden" name="category" id="bulkActionCategory">
        <div id="bulkActionIds"></div>
    </form>
<?php endif; ?>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    document.addEventListener('DOMContentLoaded', function () {
        const selectAll = document.getElementById('selectAll');
        const rowCheckboxes = document.querySelectorAll('.row-checkbox');
        const bulkBar = document.getElementById('bulkActionBar');
        const selectedCount = document.getElementById('selectedCount');

        function updateBulkBar() {
            const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
            selectedCount.innerText = checkedCount;
            if (checkedCount > 0) {
                bulkBar.classList.remove('d-none');
            } else {
                bulkBar.classList.add('d-none');
            }
        }

        if (!selectAll || !bulkBar) return; // no rows, or read-only view

        selectAll.addEventListener('change', function () {
            rowCheckboxes.forEach(cb => cb.checked = selectAll.checked);
            updateBulkBar();
        });

        rowCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateBulkBar);
        });
    });

    function deselectAll() {
        document.getElementById('selectAll').checked = false;
        document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('bulkActionBar').classList.add('d-none');
    }

    function bulkAction(type, value = '') {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        if (checked.length === 0) return;

        let confirmMsg = '';
        if (type === 'delete') {
            confirmMsg = `Are you sure you want to delete ${checked.length} selected expenses? This cannot be undone.`;
        } else {
            confirmMsg = `Change category to ${value} for ${checked.length} expenses?`;
        }

        if (confirm(confirmMsg)) {
            const form = document.getElementById('bulkActionForm');
            document.getElementById('bulkActionType').value = 'bulk_' + type;
            document.getElementById('bulkActionCategory').value = value;

            const idsContainer = document.getElementById('bulkActionIds');
            idsContainer.innerHTML = '';
            checked.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = cb.value;
                idsContainer.appendChild(input);
            });

            form.submit();
        }
    }
</script>

<?php Layout::footer(); ?>
