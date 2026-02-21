<?php
$page_title = "Monthly Expenses";
require_once 'config.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');
$category_filter = filter_input(INPUT_GET, 'category');
$payment_filter = filter_input(INPUT_GET, 'payment_method');
$card_filter = filter_input(INPUT_GET, 'card_id', FILTER_VALIDATE_INT);
$start_date = filter_input(INPUT_GET, 'start');
$end_date = filter_input(INPUT_GET, 'end');

// Fetch family's cards for filter dropdown
$cards_stmt = $pdo->prepare("SELECT id, bank_name, card_name FROM cards WHERE tenant_id = ? ORDER BY card_name");
$cards_stmt->execute([$_SESSION['tenant_id']]);
$user_cards = $cards_stmt->fetchAll();

$month_name = date("F", mktime(0, 0, 0, $month, 10));

// Pagination settings
$items_per_page = 15;
$page_num = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$offset = ($page_num - 1) * $items_per_page;

// Count total for pagination
$count_query = "SELECT COUNT(*) FROM expenses e 
          WHERE e.tenant_id = :tenant_id 
          AND MONTH(e.expense_date) = :month 
          AND YEAR(e.expense_date) = :year";
$count_params = ['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year];

if ($category_filter) {
    $count_query .= " AND e.category = :cat";
    $count_params['cat'] = $category_filter;
}
if ($payment_filter) {
    $count_query .= " AND e.payment_method = :pm";
    $count_params['pm'] = $payment_filter;
}
if ($card_filter) {
    $count_query .= " AND e.card_id = :card_id";
    $count_params['card_id'] = $card_filter;
}
if ($start_date) {
    $count_query .= " AND e.expense_date >= :start";
    $count_params['start'] = $start_date;
}
if ($end_date) {
    $count_query .= " AND e.expense_date <= :end";
    $count_params['end'] = $end_date;
}

$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($count_params);
$total_items = $count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_items / $items_per_page));

// Build Query with LIMIT
$query = "SELECT e.*, c.bank_name, c.card_name, u.name as spender_name 
          FROM expenses e 
          LEFT JOIN cards c ON e.card_id = c.id 
          LEFT JOIN users u ON e.spent_by_user_id = u.id
          WHERE e.tenant_id = :tenant_id 
          AND MONTH(e.expense_date) = :month 
          AND YEAR(e.expense_date) = :year";

$params = ['tenant_id' => $_SESSION['tenant_id'], 'month' => $month, 'year' => $year];

if ($category_filter) {
    $query .= " AND e.category = :cat";
    $params['cat'] = $category_filter;
}
if ($payment_filter) {
    $query .= " AND e.payment_method = :pm";
    $params['pm'] = $payment_filter;
}
if ($card_filter) {
    $query .= " AND e.card_id = :card_id";
    $params['card_id'] = $card_filter;
}
if ($start_date) {
    $query .= " AND e.expense_date >= :start";
    $params['start'] = $start_date;
}
if ($end_date) {
    $query .= " AND e.expense_date <= :end";
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
    if ($category_filter)
        $total_query .= " AND e.category = :cat";
    if ($payment_filter)
        $total_query .= " AND e.payment_method = :pm";
    if ($card_filter)
        $total_query .= " AND e.card_id = :card_id";
    if ($start_date)
        $total_query .= " AND e.expense_date >= :start";
    if ($end_date)
        $total_query .= " AND e.expense_date <= :end";

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

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="expenses.php?year=<?php echo $year; ?>" class="text-decoration-none text-muted small mb-1">
            <i class="fa-solid fa-arrow-left"></i> Back to Year
        </a>
        <h1 class="h3 fw-bold mb-0">
            <?php echo $month_name . ' ' . $year; ?>
        </h1>
    </div>
    <div class="text-end">
        <div class="small text-muted">Total Spent</div>
        <h3 class="fw-bold text-primary mb-0">AED <span class="blur-sensitive">
                <?php echo number_format($view_total, 2); ?></span>
        </h3>
    </div>
</div>

<!-- Filters & Actions -->
<div class="glass-panel p-3 mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="month" value="<?php echo $month; ?>">
        <input type="hidden" name="year" value="<?php echo $year; ?>">

        <div class="col-6 col-md-2">
            <label class="small text-muted mb-1">Category</label>
            <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <?php
                $categories = ['Grocery', 'Medical', 'Food', 'Utilities', 'Transport', 'Shopping', 'Entertainment', 'Travel', 'Education', 'Other'];
                foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat; ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>>
                        <?php echo $cat; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-md-2">
            <label class="small text-muted mb-1">Payment</label>
            <select name="payment_method" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Methods</option>
                <option value="Cash" <?php echo $payment_filter == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                <option value="Card" <?php echo $payment_filter == 'Card' ? 'selected' : ''; ?>>Card</option>
            </select>
        </div>

        <div class="col-6 col-md-2">
            <label class="small text-muted mb-1">Card</label>
            <select name="card_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Cards</option>
                <?php foreach ($user_cards as $uc): ?>
                    <option value="<?php echo $uc['id']; ?>" <?php echo $card_filter == $uc['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($uc['bank_name'] . ' - ' . $uc['card_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-md-2">
            <label class="small text-muted mb-1">From</label>
            <input type="date" name="start" class="form-control form-control-sm"
                value="<?php echo htmlspecialchars($start_date ?? ''); ?>" onchange="this.form.submit()">
        </div>

        <div class="col-6 col-md-2">
            <label class="small text-muted mb-1">To</label>
            <input type="date" name="end" class="form-control form-control-sm"
                value="<?php echo htmlspecialchars($end_date ?? ''); ?>" onchange="this.form.submit()">
        </div>

        <div class="col-6 col-md-2 text-end d-flex gap-2">
            <a href="print_report.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" target="_blank"
                class="btn btn-outline-primary btn-sm flex-grow-1">
                <i class="fa-solid fa-print me-1"></i> Print
            </a>
            <a href="export_actions.php?action=export_expenses&month=<?php echo $month; ?>&year=<?php echo $year; ?>&category=<?php echo $category_filter; ?>&payment_method=<?php echo $payment_filter; ?>&card_id=<?php echo $card_filter; ?>&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>"
                class="btn btn-outline-secondary btn-sm flex-grow-1">
                <i class="fa-solid fa-file-csv me-1"></i> Export
            </a>
            <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                <a href="add_expense.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>"
                    class="btn btn-primary btn-sm flex-grow-1">
                    <i class="fa-solid fa-plus me-1"></i> Add
                </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($category_filter || $payment_filter || $card_filter || $start_date || $end_date): ?>
        <div class="mt-2 pt-2 border-top">
            <a href="?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-times me-1"></i> Clear Filters
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Budget Progress Bars -->
<?php if (!empty($cat_budgets)): ?>
    <div class="glass-panel p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0">Budget Progress</h6>
            <a href="manage_budgets.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>"
                class="small text-primary text-decoration-none">Manage Budgets</a>
        </div>
        <div class="row g-3">
            <?php foreach ($cat_budgets as $cat => $limit):
                $spent = $cat_actuals[$cat] ?? 0;
                $pct = ($spent / $limit) * 100;
                $color = 'success';
                if ($pct > 80)
                    $color = 'warning';
                if ($pct > 100)
                    $color = 'danger';
                ?>
                <div class="col-md-3">
                    <div class="small d-flex justify-content-between mb-1">
                        <span><?php echo $cat; ?></span>
                        <span class="fw-bold"><?php echo number_format($pct, 0); ?>%</span>
                    </div>
                    <div class="progress" style="height: 6px;"
                        title="Spent AED <?php echo number_format($spent); ?> of AED <?php echo number_format($limit); ?>">
                        <div class="progress-bar bg-<?php echo $color; ?>" role="progressbar"
                            style="width: <?php echo min($pct, 100); ?>%"></div>
                    </div>
                    <div class="x-small text-muted mt-1">
                        AED <?php echo number_format($spent); ?> / <?php echo number_format($limit); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($expenses)): ?>
    <div class="text-center py-5">
        <p class="text-muted">No expenses found for this period.</p>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4 py-3" style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                        </th>
                        <th class="py-3">Day</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Spent By</th>
                        <th>Payment</th>
                        <th class="text-end pe-4">Amount</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $expense): ?>
                        <tr data-id="<?php echo $expense['id']; ?>">
                            <td class="ps-4">
                                <input type="checkbox" class="form-check-input row-checkbox" name="expense_ids[]"
                                    value="<?php echo $expense['id']; ?>">
                            </td>
                            <td class="fw-bold">
                                <?php echo date('d', strtotime($expense['expense_date'])); ?>
                                <span class="small text-muted fw-normal d-block">
                                    <?php echo date('D', strtotime($expense['expense_date'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($expense['description']); ?>
                                <?php if (!empty($expense['tags'])): ?>
                                    <div class="mt-1">
                                        <?php foreach (explode(',', $expense['tags']) as $tag): ?>
                                            <span class="badge bg-secondary-subtle text-secondary me-1" style="font-size: 0.7em;">
                                                <?php echo htmlspecialchars(trim($tag)); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">
                                    <?php echo htmlspecialchars($expense['category']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="small fw-bold text-muted">
                                    <i class="fa-solid fa-user-tag me-1"></i>
                                    <?php echo htmlspecialchars($expense['spender_name'] ?? 'Family Head'); ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($expense['payment_method'] == 'Card'): ?>
                                    <div class="small">
                                        <i class="fa-solid fa-credit-card text-primary me-1"></i>
                                        <?php echo htmlspecialchars($expense['bank_name']); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="small text-secondary">
                                        <i class="fa-solid fa-coins me-1"></i> Cash
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4 fw-bold">
                                AED <span class="blur-sensitive">
                                    <?php echo number_format($expense['amount'], 2); ?></span>
                                <?php if (!empty($expense['currency']) && $expense['currency'] != 'AED'): ?>
                                    <div class="small text-muted fw-normal mt-1" style="font-size: 0.75em;">
                                        (<?php echo htmlspecialchars($expense['currency'] . ' ' . number_format($expense['original_amount'], 2)); ?>)
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                    <a href="edit_expense.php?id=<?php echo $expense['id']; ?>" class="btn btn-sm text-muted me-1"
                                        title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <form action="expense_actions.php" method="POST" class="d-inline"
                                        onsubmit="return confirmSubmit(this, 'Delete <?php echo addslashes(htmlspecialchars($expense['description'])); ?> - AED <?php echo number_format($expense['amount'], 2); ?> - on <?php echo date('d M Y', strtotime($expense['expense_date'])); ?>?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <input type="hidden" name="action" value="delete_expense">
                                        <input type="hidden" name="id" value="<?php echo $expense['id']; ?>">
                                        <button type="submit" class="btn btn-sm text-danger border-0 p-0" title="Delete">
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
                    Showing <?php echo ($offset + 1); ?>–<?php echo min($offset + $items_per_page, $total_items); ?> of
                    <?php echo $total_items; ?> expenses
                </div>
                <nav aria-label="Page navigation">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $base_url = "?month=$month&year=$year";
                        if ($category_filter)
                            $base_url .= "&category=" . urlencode($category_filter);
                        if ($payment_filter)
                            $base_url .= "&payment_method=" . urlencode($payment_filter);
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

<?php require_once 'includes/footer.php'; ?>

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
                            <li><a class="dropdown-item" href="#"
                                    onclick="bulkAction('change_category', '<?php echo $cat; ?>')"><?php echo $cat; ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <button class="btn btn-danger btn-sm rounded-pill px-3" onclick="bulkAction('delete')">
                    <i class="fa-solid fa-trash me-1"></i> Delete
                </button>
                <button class="btn btn-link btn-sm text-muted" onclick="deselectAll()">Cancel</button>
            </div>
        </div>
    </div>

    <form id="bulkActionForm" action="expense_actions.php" method="POST" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
        <input type="hidden" name="action" id="bulkActionType">
        <input type="hidden" name="category" id="bulkActionCategory">
        <div id="bulkActionIds"></div>
    </form>
<?php endif; ?>

<script>
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