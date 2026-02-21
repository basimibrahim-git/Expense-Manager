<?php
$page_title = "Advanced Search";
require_once 'config.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

// Params
$keyword = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_SPECIAL_CHARS);
$category = filter_input(INPUT_GET, 'category', FILTER_SANITIZE_SPECIAL_CHARS);
$tag = filter_input(INPUT_GET, 'tag', FILTER_SANITIZE_SPECIAL_CHARS);
$min_amount = filter_input(INPUT_GET, 'min', FILTER_VALIDATE_FLOAT);
$max_amount = filter_input(INPUT_GET, 'max', FILTER_VALIDATE_FLOAT);
$start_date = filter_input(INPUT_GET, 'start');
$end_date = filter_input(INPUT_GET, 'end');

// Pagination settings
$items_per_page = 20;
$page_num = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$offset = ($page_num - 1) * $items_per_page;

// Build Count Query first
$count_sql = "SELECT COUNT(*) FROM expenses WHERE tenant_id = :tenant_id";
$params = ['tenant_id' => $_SESSION['tenant_id']];

if ($keyword) {
    $count_sql .= " AND (description LIKE :kw OR category LIKE :kw OR tags LIKE :kw)";
    $params['kw'] = "%$keyword%";
}
if ($category) {
    $count_sql .= " AND category = :cat";
    $params['cat'] = $category;
}
if ($tag) {
    $count_sql .= " AND tags LIKE :tag";
    $params['tag'] = "%$tag%";
}
if ($min_amount) {
    $count_sql .= " AND amount >= :min";
    $params['min'] = $min_amount;
}
if ($max_amount) {
    $count_sql .= " AND amount <= :max";
    $params['max'] = $max_amount;
}
if ($start_date) {
    $count_sql .= " AND expense_date >= :start";
    $params['start'] = $start_date;
}
if ($end_date) {
    $count_sql .= " AND expense_date <= :end";
    $params['end'] = $end_date;
}

try {
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_items = $count_stmt->fetchColumn();
    $total_pages = max(1, ceil($total_items / $items_per_page));

    // Build Data Query with LIMIT
    $sql = str_replace("SELECT COUNT(*)", "SELECT *", $count_sql);
    $sql .= " ORDER BY expense_date DESC LIMIT $items_per_page OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // Total amount of filtered results
    $sum_sql = str_replace("SELECT COUNT(*)", "SELECT SUM(amount)", $count_sql);
    $sum_stmt = $pdo->prepare($sum_sql);
    $sum_stmt->execute($params);
    $total_found = $sum_stmt->fetchColumn() ?: 0;

} catch (PDOException $e) {
    $error = "Search failed: " . $e->getMessage();
    $total_pages = 1;
    $total_items = 0;
}
?>

<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 fw-bold mb-3">ðŸ” Advanced Transaction Search</h1>
    </div>
</div>

<div class="row g-4">
    <!-- Filter Panel -->
    <div class="col-md-3">
        <div class="glass-panel p-4">
            <h5 class="fw-bold mb-3">Filters</h5>
            <form method="GET" action="search.php">
                <div class="mb-3">
                    <label for="q" class="form-label small fw-bold">Keyword</label>
                    <input type="text" id="q" name="q" class="form-control"
                        value="<?php echo htmlspecialchars($keyword ?? ''); ?>" placeholder="e.g. Dinner, Uber...">
                </div>
                <div class="mb-3">
                    <label for="tag" class="form-label small fw-bold">Tag</label>
                    <input type="text" id="tag" name="tag" class="form-control"
                        value="<?php echo htmlspecialchars($tag ?? ''); ?>" placeholder="#Vacation">
                </div>
                <div class="mb-3">
                    <label for="category" class="form-label small fw-bold">Category</label>
                    <select id="category" name="category" class="form-select">
                        <option value="">All Categories</option>
                        <!-- Should populate dynamically, hardcoded for speed -->
                        <option value="Grocery" <?php echo $category == 'Grocery' ? 'selected' : ''; ?>>Grocery</option>
                        <option value="Dining" <?php echo $category == 'Dining' ? 'selected' : ''; ?>>Dining</option>
                        <option value="Transport" <?php echo $category == 'Transport' ? 'selected' : ''; ?>>Transport
                        </option>
                        <option value="Bills" <?php echo $category == 'Bills' ? 'selected' : ''; ?>>Bills</option>
                        <option value="Shopping" <?php echo $category == 'Shopping' ? 'selected' : ''; ?>>Shopping
                        </option>
                    </select>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label for="min" class="form-label small fw-bold">Min $</label>
                        <input type="number" id="min" name="min" class="form-control"
                            value="<?php echo htmlspecialchars($min_amount ?? ''); ?>">
                    </div>
                    <div class="col-6 mb-3">
                        <label for="max" class="form-label small fw-bold">Max $</label>
                        <input type="number" id="max" name="max" class="form-control"
                            value="<?php echo htmlspecialchars($max_amount ?? ''); ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="start" class="form-label small fw-bold">Date Range</label>
                    <input type="date" id="start" name="start" class="form-control mb-2"
                        value="<?php echo htmlspecialchars($start_date ?? ''); ?>">
                    <input type="date" name="end" class="form-control"
                        value="<?php echo htmlspecialchars($end_date ?? ''); ?>">
                </div>
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary fw-bold"><i class="fa-solid fa-filter me-2"></i>
                        Filter</button>
                    <a href="search.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Results Panel -->
    <div class="col-md-9">
        <?php if (isset($results)): ?>
            <div class="glass-panel p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-0">
                    <div>Found <span class="fw-bold text-primary">
                            <?php echo count($results); ?>
                        </span> transactions</div>
                    <div class="h5 fw-bold text-success mb-0">Total:
                        <?php echo number_format($total_found, 2); ?> AED
                    </div>
                </div>
            </div>

            <?php if (empty($results)): ?>
                <div class="text-center py-5">
                    <div class="mb-3 text-muted display-1"><i class="fa-solid fa-magnifying-glass-chart"></i></div>
                    <h4>No results found</h4>
                    <p class="text-muted">Try adjusting your filters.</p>
                </div>
            <?php else: ?>
                <div class="glass-panel p-0 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table custom-table mb-0 align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Date</th>
                                    <th>Description</th>
                                    <th>Category</th>
                                    <th>Tags</th>
                                    <th>Amount</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $ex): ?>
                                    <tr>
                                        <td class="ps-4 text-muted small">
                                            <?php echo date('M j, Y', strtotime($ex['expense_date'])); ?>
                                        </td>
                                        <td class="fw-bold text-dark">
                                            <?php echo htmlspecialchars($ex['description']); ?>
                                        </td>
                                        <td><span class="badge bg-light text-dark border">
                                                <?php echo htmlspecialchars($ex['category']); ?>
                                            </span></td>
                                        <td>
                                            <?php if (!empty($ex['tags'])): ?>
                                                <?php foreach (explode(',', $ex['tags']) as $t): ?>
                                                    <span class="badge bg-primary-subtle text-primary x-small">
                                                        <?php echo htmlspecialchars(trim($t)); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-bold text-dark blur-sensitive">
                                            <?php echo number_format($ex['amount'], 2); ?>
                                            <?php if ($ex['currency'] != 'AED') { echo ' <small class="text-muted">(' . htmlspecialchars($ex['currency']) . ')</small>'; } ?>
                                        </td>
                                        <td class="pe-4 text-end">
                                            <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                                <form action="expense_actions.php" method="POST" class="d-inline"
                                                    onsubmit="return confirmSubmit(this, 'Delete <?php echo addslashes(htmlspecialchars($ex['description'])); ?> - AED <?php echo number_format($ex['amount'], 2); ?> - on <?php echo date('d M Y', strtotime($ex['expense_date'])); ?>?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                    <input type="hidden" name="action" value="delete_expense">
                                                    <input type="hidden" name="id" value="<?php echo $ex['id']; ?>">
                                                    <button type="submit" class="btn btn-link text-danger p-0 small border-0">
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
                                Showing <?php echo $offset + 1; ?>â€“<?php echo min($offset + $items_per_page, $total_items); ?> of
                                <?php echo $total_items; ?> results
                            </div>
                            <nav aria-label="Page navigation">
                                <ul class="pagination pagination-sm mb-0">
                                    <?php
                                    $base_url = "?";
                                    if ($keyword) { $base_url .= "q=" . urlencode($keyword) . "&"; }
                                    if ($category) { $base_url .= "category=" . urlencode($category) . "&"; }
                                    if ($tag) { $base_url .= "tag=" . urlencode($tag) . "&"; }
                                    if ($min_amount) { $base_url .= "min=$min_amount&"; }
                                    if ($max_amount) { $base_url .= "max=$max_amount&"; }
                                    if ($start_date) { $base_url .= "start=$start_date&"; }
                                    if ($end_date) { $base_url .= "end=$end_date&"; }
                                    ?>
                                    <li class="page-item <?php echo $page_num <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo $base_url; ?>page=<?php echo $page_num - 1; ?>">
                                            <i class="fa-solid fa-chevron-left"></i>
                                        </a>
                                    </li>
                                    <?php for ($i = max(1, $page_num - 2); $i <= min($total_pages, $page_num + 2); $i++): ?>
                                        <li class="page-item <?php echo $i == $page_num ? 'active' : ''; ?>">
                                            <a class="page-link"
                                                href="<?php echo $base_url; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $page_num >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo $base_url; ?>page=<?php echo $page_num + 1; ?>">
                                            <i class="fa-solid fa-chevron-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
