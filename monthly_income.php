<?php
$page_title = "Monthly Income";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\ExchangeRateHelper;
use App\Helpers\Flash;
use App\Helpers\Categories;

Bootstrap::init();

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');


// Handle Bulk Category Change (before any output so the redirect works)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_change_category') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        Flash::redirect("monthly_income.php?month=$month&year=$year", 'error', 'Unauthorized: Read-only access');
    }

    $ids = array_slice(array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))), 0, 500);
    $new_category = (string) ($_POST['new_category'] ?? '');

    if (!empty($ids) && Categories::isIncome($new_category)) {
        $ids_placeholder = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE income SET category = ? WHERE id IN ($ids_placeholder) AND tenant_id = ?");
        $stmt->execute(array_merge([$new_category], $ids, [$_SESSION['tenant_id']]));

        AuditHelper::log($pdo, 'bulk_income_edit', "Changed category for " . count($ids) . " items to $new_category");
        Flash::redirect("monthly_income.php?month=$month&year=$year", 'success', 'Bulk update successful');
    }
    Flash::redirect("monthly_income.php?month=$month&year=$year", 'error', 'Nothing to update');
}

// Fetch Records
$stmt = $pdo->prepare("SELECT id, description AS source, category, amount, COALESCE(currency, 'AED') AS currency, income_date FROM income WHERE tenant_id = ? AND MONTH(income_date) = ? AND YEAR(income_date) = ? ORDER BY income_date DESC");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Income is stored in its entered currency; total it in AED
$rates = ['AED' => 1.0];
$total_income = 0;
foreach ($records as $record) {
    $cur = strtoupper($record['currency']);
    $rates[$cur] = $rates[$cur] ?? ExchangeRateHelper::getRate($cur, 'AED', $pdo);
    $total_income += (float) $record['amount'] * $rates[$cur];
}

Layout::header();
Layout::sidebar();
?>

<!-- Premium Header Banner -->
<div class="row mb-4">
    <div class="col-12">
        <div class="gradient-card-success p-4 rounded-4 hover-lift position-relative overflow-hidden shadow-sm" style="border-radius: 16px;">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <a href="income.php?year=<?php echo $year; ?>" class="text-decoration-none text-white-50 small mb-1 d-inline-block">
                        <i class="fa-solid fa-arrow-left"></i> Back to Yearly Map
                    </a>
                    <h1 class="h2 fw-bold text-white mb-0"><?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?> Deposits</h1>
                    <p class="text-white-50 mb-0">Overview of income streams, salary deposits, and investments.</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <form class="d-flex gap-2 me-2" method="GET">
                        <select name="month" class="form-select form-select-sm rounded-pill px-3" style="width: 130px;">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <select name="year" class="form-select form-select-sm rounded-pill px-3" style="width: 100px;">
                            <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-light rounded-pill px-3">Go</button>
                    </form>
                    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                        <a href="add_income.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-light text-success fw-bold rounded-pill px-3 shadow-sm">
                            <i class="fa-solid fa-plus me-1"></i> Add
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="glass-panel-premium p-4 h-100 position-relative overflow-hidden" style="border-left: 5px solid var(--success-color);">
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Total Monthly Revenue</h6>
            <h2 class="fw-bold text-success mb-0">
                <small style="font-size: 0.6em">AED</small>
                <span class="blur-sensitive"><?php echo number_format($total_income, 2); ?></span>
            </h2>
            <p class="text-muted small mb-0 mt-2">Sum of all active cash inflows.</p>
        </div>
    </div>
</div>

<div class="glass-panel-premium overflow-hidden border-0 shadow-sm">
    <form id="bulkActionForm" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
        <input type="hidden" name="action" value="bulk_change_category">
        <input type="hidden" name="new_category" id="bulkCategoryInput">

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid rgba(0,0,0,0.05);">
                        <th class="ps-4 py-3" style="width: 40px; background: transparent;">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                        </th>
                        <th class="py-3" style="background: transparent;">Source</th>
                        <th style="background: transparent;">Category</th>
                        <th style="background: transparent;">Amount</th>
                        <th style="background: transparent;">Received On</th>
                        <th class="text-end pe-4" style="background: transparent;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-money-bill-trend-up fa-3x mb-3 d-block opacity-25"></i>
                                No income entries logged for this month.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr class="hover-lift-row" style="border-bottom: 1px solid rgba(0,0,0,0.03); transition: background 0.2s;">
                                <td class="ps-4">
                                    <input type="checkbox" name="ids[]" value="<?php echo (int) $record['id']; ?>" class="form-check-input row-checkbox">
                                </td>
                                <td class="fw-bold text-dark">
                                    <?php echo htmlspecialchars($record['source']); ?>
                                </td>
                                <td>
                                    <?php
                                    $badge_style = "bg-primary-subtle text-primary";
                                    if (stripos($record['category'], 'salary') !== false) $badge_style = "bg-success-subtle text-success";
                                    if (stripos($record['category'], 'bonus') !== false) $badge_style = "bg-warning-subtle text-warning";
                                    if (stripos($record['category'], 'investment') !== false) $badge_style = "bg-info-subtle text-info";
                                    if (stripos($record['category'], 'freelance') !== false) $badge_style = "bg-secondary-subtle text-secondary";
                                    ?>
                                    <span class="badge rounded-pill <?php echo $badge_style; ?> px-3 py-2 fw-semibold">
                                        <?php echo htmlspecialchars($record['category'] ?? 'General'); ?>
                                    </span>
                                </td>
                                <td class="fw-bold text-success">
                                    <span><?php echo Html::e($record['currency']); ?></span>
                                    <span class="blur-sensitive"><?php echo number_format($record['amount'], 2); ?></span>
                                    <?php if (strtoupper($record['currency']) !== 'AED'): ?>
                                        <div class="small text-muted fw-normal" style="font-size: 0.75em;">
                                            (≈ AED <span class="blur-sensitive"><?php echo number_format((float) $record['amount'] * $rates[strtoupper($record['currency'])], 2); ?></span>)
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small">
                                    <?php echo date('d M Y', strtotime($record['income_date'])); ?>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                        <div class="btn-group btn-group-sm">
                                            <a href="edit_income.php?id=<?php echo (int) $record['id']; ?>" class="btn btn-sm btn-link text-muted me-1"><i class="fa-solid fa-pen"></i></a>
                                            <button type="button" class="btn btn-sm btn-link text-danger border-0 p-0" data-onclick="confirmDelete" data-args="<?php echo Html::args((int) $record['id'], $record['source']); ?>">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <i class="fa-solid fa-lock text-muted small" title="Read Only"></i>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<!-- Bulk Action Floating Bar -->
<?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
    <div id="bulkActionBar" class="position-fixed bottom-0 start-50 translate-middle-x mb-4 shadow-lg glass-panel-premium p-3 d-none animate__animated animate__fadeInUp" style="z-index: 1050; border-radius: 50px; min-width: 350px;">
        <div class="d-flex align-items-center justify-content-between gap-4 px-2">
            <div>
                <span id="selectedCount" class="badge bg-success rounded-pill me-2">0</span>
                <span class="fw-bold small">Selected</span>
            </div>
            <div class="d-flex gap-2">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-success dropdown-toggle rounded-pill" type="button" data-bs-toggle="dropdown">
                        Change Category
                    </button>
                    <ul class="dropdown-menu border-0 shadow">
                        <?php foreach (Categories::INCOME as $bulk_cat => $bulk_label): ?>
                            <li><button class="dropdown-item" type="button" data-onclick="submitBulkChange" data-args="<?php echo Html::args($bulk_cat); ?>"><?php echo Html::e($bulk_label); ?></button></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <button type="button" class="btn btn-sm btn-light rounded-pill px-3" data-onclick="clearSelection">Cancel</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.row-checkbox');
    const bulkActionBar = document.getElementById('bulkActionBar');
    const selectedCount = document.getElementById('selectedCount');
    const bulkCategoryInput = document.getElementById('bulkCategoryInput');
    const bulkActionForm = document.getElementById('bulkActionForm');

    function updateBulkBar() {
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        if (checkedCount > 0) {
            bulkActionBar.classList.remove('d-none');
            selectedCount.textContent = checkedCount;
        } else {
            bulkActionBar.classList.add('d-none');
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
            updateBulkBar();
        });
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkBar);
    });

    function clearSelection() {
        checkboxes.forEach(cb => cb.checked = false);
        if (selectAll) selectAll.checked = false;
        updateBulkBar();
    }

    function submitBulkChange(category) {
        if (confirm(`Change category to "${category}" for all selected items?`)) {
            bulkCategoryInput.value = category;
            bulkActionForm.submit();
        }
    }

    function confirmDelete(id, source) {
        // askConfirm (assets/js/app.js) shows the message with textContent
        window.askConfirm(`Are you sure you want to delete income from "${source}"? This cannot be undone.`, 'Delete', function () {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        });
    }
</script>

<form id="deleteForm" action="income_actions.php" method="POST" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
    <input type="hidden" name="action" value="delete_income">
    <input type="hidden" name="id" id="deleteId">
    <input type="hidden" name="month" value="<?php echo (int) $month; ?>">
    <input type="hidden" name="year" value="<?php echo (int) $year; ?>">
</form>

<?php Layout::footer(); ?>
