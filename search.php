<?php
$page_title = "Advanced Search";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\Categories;

Bootstrap::init();

Layout::header();
Layout::sidebar();

// Params: raw values go to prepared statements; they are escaped on output
$keyword = trim((string) filter_input(INPUT_GET, 'q'));
$category = (string) filter_input(INPUT_GET, 'category');
$method = (string) filter_input(INPUT_GET, 'method');
$min_amount = filter_input(INPUT_GET, 'min', FILTER_VALIDATE_FLOAT);
$max_amount = filter_input(INPUT_GET, 'max', FILTER_VALIDATE_FLOAT);

// Build Query
$query = "SELECT id, expense_date, description, payment_method, category, amount FROM expenses WHERE tenant_id = :tenant_id";
$params = ['tenant_id' => $_SESSION['tenant_id']];

if ($keyword !== '') {
    // Native prepares (EMULATE_PREPARES off) cannot reuse a named placeholder
    $like = '%' . addcslashes($keyword, '%_\\') . '%';
    $query .= " AND (description LIKE :q1 OR category LIKE :q2)";
    $params['q1'] = $like;
    $params['q2'] = $like;
}
if ($category !== '') {
    $query .= " AND category = :cat";
    $params['cat'] = $category;
}
if ($method !== '') {
    $query .= " AND payment_method = :method";
    $params['method'] = $method;
}
if ($min_amount !== false && $min_amount !== null) {
    $query .= " AND amount >= :min";
    $params['min'] = $min_amount;
}
if ($max_amount !== false && $max_amount !== null) {
    $query .= " AND amount <= :max";
    $params['max'] = $max_amount;
}

$query .= " ORDER BY expense_date DESC LIMIT 50";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for filters
$stmt_cats = $pdo->prepare("SELECT DISTINCT category FROM expenses WHERE tenant_id = ?");
$stmt_cats->execute([$_SESSION['tenant_id']]);
// Standard categories (with labels) first, then any other category found in this family's data
$category_options = Categories::EXPENSE;
foreach ($stmt_cats->fetchAll(PDO::FETCH_COLUMN) as $cat) {
    $cat = (string) $cat;
    if ($cat !== '' && !isset($category_options[$cat])) {
        $category_options[$cat] = $cat;
    }
}

$stmt_methods = $pdo->prepare("SELECT DISTINCT payment_method FROM expenses WHERE tenant_id = ?");
$stmt_methods->execute([$_SESSION['tenant_id']]);
$all_methods = $stmt_methods->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="row mb-4">
    <div class="col">
        <h1 class="h3 fw-bold mb-1">Advanced Search</h1>
        <p class="text-muted">Find specific transactions across your history</p>
    </div>
</div>

<div class="row g-4">
    <!-- Filter Sidebar -->
    <div class="col-lg-3">
        <div class="glass-panel p-4 sticky-top" style="top: 20px;">
            <h6 class="fw-bold mb-3">Filters</h6>
            <form method="GET">
                <div class="mb-3">
                    <label for="searchQuery" class="form-label small fw-bold">Keyword</label>
                    <input type="text" name="q" id="searchQuery" class="form-control form-control-sm"
                        placeholder="Search..." value="<?php echo Html::e($keyword); ?>">
                </div>

                <div class="mb-3">
                    <label for="searchCategory" class="form-label small fw-bold">Category</label>
                    <select name="category" id="searchCategory" class="form-select form-select-sm">
                        <option value="">All Categories</option>
                        <?php foreach ($category_options as $cat => $cat_label): ?>
                            <option value="<?php echo Html::e($cat); ?>" <?php echo $category === (string) $cat ? 'selected' : ''; ?>>
                                <?php echo Html::e($cat_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="searchMethod" class="form-label small fw-bold">Payment Method</label>
                    <select name="method" id="searchMethod" class="form-select form-select-sm">
                        <option value="">All Methods</option>
                        <?php foreach ($all_methods as $m): ?>
                            <option value="<?php echo Html::e($m); ?>" <?php echo $method === (string) $m ? 'selected' : ''; ?>>
                                <?php echo Html::e($m); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label for="minAmount" class="form-label small fw-bold">Amount Range</label>
                    <div class="d-flex gap-2">
                        <input type="number" name="min" id="minAmount" class="form-control form-control-sm"
                            placeholder="Min" value="<?php echo Html::e($min_amount === false ? '' : $min_amount); ?>">
                        <input type="number" name="max" id="maxAmount" class="form-control form-control-sm"
                            placeholder="Max" value="<?php echo Html::e($max_amount === false ? '' : $max_amount); ?>">
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary fw-bold">Apply Filters</button>
                    <a href="search.php" class="btn btn-light btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Results -->
    <div class="col-lg-9">
        <div class="glass-panel p-0 overflow-hidden">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-light">
                <span class="small fw-bold text-muted">Showing
                    <?php echo count($results); ?> results
                </span>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" data-print><i
                            class="fa-solid fa-print"></i></button>
                    <button class="btn btn-outline-secondary"><i class="fa-solid fa-file-export"></i></button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr class="small text-muted text-uppercase">
                            <th class="ps-4">Date</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($results)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5">
                                    <i class="fa-solid fa-magnifying-glass fa-3x mb-3 d-block opacity-25"></i>
                                    <p class="text-muted">No transactions found matching your criteria.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($results as $res): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="small fw-bold">
                                            <?php echo date('d M', strtotime($res['expense_date'])); ?>
                                        </div>
                                        <div class="text-muted smaller">
                                            <?php echo date('Y', strtotime($res['expense_date'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold">
                                            <?php echo htmlspecialchars($res['description']); ?>
                                        </div>
                                        <div class="smaller text-muted">via
                                            <?php echo Html::e($res['payment_method']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?php echo htmlspecialchars($res['category']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-danger">AED
                                            <?php echo number_format($res['amount'], 2); ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="edit_expense.php?id=<?php echo (int) $res['id']; ?>"
                                            class="btn btn-sm btn-outline-primary border-0"><i class="fa-solid fa-pen"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php Layout::footer(); ?>
