<?php
$page_title = "Monthly Income";
require_once 'config.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');
$category_filter = filter_input(INPUT_GET, 'category');
$start_date = filter_input(INPUT_GET, 'start');
$end_date = filter_input(INPUT_GET, 'end');

$month_name = date("F", mktime(0, 0, 0, $month, 10));

// Pagination settings
$items_per_page = 15;
$page_num = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$offset = ($page_num - 1) * $items_per_page;

// Build query with filters
$count_query = "SELECT COUNT(*) FROM income WHERE user_id = :user_id AND MONTH(income_date) = :month AND YEAR(income_date) = :year";
$params = ['user_id' => $_SESSION['user_id'], 'month' => $month, 'year' => $year];

if ($category_filter) {
    $count_query .= " AND category = :cat";
    $params['cat'] = $category_filter;
}
if ($start_date) {
    $count_query .= " AND income_date >= :start";
    $params['start'] = $start_date;
}
if ($end_date) {
    $count_query .= " AND income_date <= :end";
    $params['end'] = $end_date;
}

$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_items = $count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_items / $items_per_page));

// Fetch Income with LIMIT
$query = str_replace("SELECT COUNT(*)", "SELECT *", $count_query);
$query .= " ORDER BY income_date DESC LIMIT $items_per_page OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$incomes = $stmt->fetchAll();

// Total income for the month (with filters)
$sum_query = str_replace("SELECT COUNT(*)", "SELECT SUM(amount)", $count_query);
$sum_stmt = $pdo->prepare($sum_query);
$sum_stmt->execute($params);
$total_income = $sum_stmt->fetchColumn() ?: 0;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="income.php?year=<?php echo $year; ?>" class="text-decoration-none text-muted small mb-1">
            <i class="fa-solid fa-arrow-left"></i> Back to Year
        </a>
        <h1 class="h3 fw-bold mb-0">Income:
            <?php echo $month_name . ' ' . $year; ?>
        </h1>
    </div>
    <div class="text-end">
        <div class="small text-muted">Total Income</div>
        <h3 class="fw-bold text-success mb-0">AED <span class="blur-sensitive">
                <?php echo number_format($total_income, 2); ?></span>
        </h3>
    </div>
</div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
        <i class="fa-solid fa-check-circle me-2"></i>
        <?php echo htmlspecialchars($_GET['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
        <i class="fa-solid fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars($_GET['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Filters & Actions -->
<div class="glass-panel p-3 mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="month" value="<?php echo $month; ?>">
        <input type="hidden" name="year" value="<?php echo $year; ?>">

        <div class="col-md-3">
            <label class="small text-muted mb-1">Category</label>
            <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <?php
                $income_categories = ['Salary', 'Incentives', 'Business', 'Bonus', 'Investment', 'Gift', 'Other'];
                foreach ($income_categories as $cat): ?>
                    <option value="<?php echo $cat; ?>" <?php echo ($category_filter ?? '') == $cat ? 'selected' : ''; ?>>
                        <?php echo $cat; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <label class="small text-muted mb-1">From</label>
            <input type="date" name="start" class="form-control form-control-sm"
                value="<?php echo htmlspecialchars($start_date ?? ''); ?>" onchange="this.form.submit()">
        </div>

        <div class="col-md-2">
            <label class="small text-muted mb-1">To</label>
            <input type="date" name="end" class="form-control form-control-sm"
                value="<?php echo htmlspecialchars($end_date ?? ''); ?>" onchange="this.form.submit()">
        </div>

        <div class="col-md-3 ms-auto text-end d-flex gap-2">
            <a href="export_actions.php?action=export_income&month=<?php echo $month; ?>&year=<?php echo $year; ?>&category=<?php echo $category_filter; ?>&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>"
                class="btn btn-outline-secondary btn-sm flex-grow-1">
                <i class="fa-solid fa-file-csv me-1"></i> Export
            </a>
            <a href="add_income.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>"
                class="btn btn-success btn-sm flex-grow-1">
                <i class="fa-solid fa-plus me-1"></i> Add
            </a>
        </div>
    </form>

    <?php if (($category_filter ?? '') || ($start_date ?? '') || ($end_date ?? '')): ?>
        <div class="mt-2 pt-2 border-top">
            <a href="?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-times me-1"></i> Clear Filters
            </a>
        </div>
    <?php endif; ?>
</div>

<?php if (empty($incomes)): ?>
    <div class="text-center py-5">
        <p class="text-muted">No income recorded for this period.</p>
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
                        <th class="text-end pe-4">Amount</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($incomes as $inc): ?>
                        <tr data-id="<?php echo $inc['id']; ?>">
                            <td class="ps-4">
                                <input type="checkbox" class="form-check-input row-checkbox" name="income_ids[]"
                                    value="<?php echo $inc['id']; ?>">
                            </td>
                            <td class="fw-bold">
                                <?php echo date('d', strtotime($inc['income_date'])); ?>
                                <span class="small text-muted fw-normal d-block">
                                    <?php echo date('D', strtotime($inc['income_date'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($inc['description']); ?>
                            </td>
                            <td><span class="badge bg-light text-dark border">
                                    <?php echo htmlspecialchars($inc['category']); ?>
                                </span></td>
                            <td class="text-end pe-4 fw-bold text-success">
                                + AED <span class="blur-sensitive">
                                    <?php echo number_format($inc['amount'], 2); ?></span>
                            </td>
                            <td class="text-end pe-3">
                                <a href="edit_income.php?id=<?php echo $inc['id']; ?>" class="btn btn-sm text-muted me-1"
                                    title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form action="income_actions.php" method="POST" class="d-inline"
                                    onsubmit="return confirmSubmit(this, 'Delete <?php echo addslashes(htmlspecialchars($inc['description'])); ?> - AED <?php echo number_format($inc['amount'], 2); ?> - on <?php echo date('d M Y', strtotime($inc['income_date'])); ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="delete_income">
                                    <input type="hidden" name="id" value="<?php echo $inc['id']; ?>">
                                    <button type="submit" class="btn btn-sm text-danger border-0 p-0" title="Delete">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
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
                    <?php echo $total_items; ?> entries
                </div>
                <nav aria-label="Page navigation">
                    <ul class="pagination pagination-sm mb-0">
                        <?php $base_url = "?month=$month&year=$year"; ?>
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
<div id="bulkActionBar"
    class="position-fixed bottom-0 start-50 translate-middle-x mb-4 shadow-lg glass-panel p-3 d-none animate__animated animate__fadeInUp"
    style="z-index: 1050; border-radius: 50px; min-width: 300px;">
    <div class="d-flex align-items-center justify-content-between gap-4 px-2">
        <div class="text-nowrap fw-bold">
            <span id="selectedCount">0</span> Selected
        </div>
        <div class="d-flex gap-2">
            <div class="dropdown">
                <button class="btn btn-outline-success btn-sm rounded-pill dropdown-toggle" type="button"
                    data-bs-toggle="dropdown">
                    Change Category
                </button>
                <ul class="dropdown-menu border-0 shadow">
                    <?php foreach ($income_categories as $cat): ?>
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

<form id="bulkActionForm" action="income_actions.php" method="POST" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
    <input type="hidden" name="action" id="bulkActionType">
    <input type="hidden" name="category" id="bulkActionCategory">
    <div id="bulkActionIds"></div>
</form>

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
            confirmMsg = `Are you sure you want to delete ${checked.length} selected income entries? This cannot be undone.`;
        } else {
            confirmMsg = `Change category to ${value} for ${checked.length} entries?`;
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