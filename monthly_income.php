<?php
$page_title = "Monthly Income";
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;
use App\Helpers\Layout;

Bootstrap::init();

Layout::header();
Layout::sidebar();

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');

// Handle Bulk Category Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_change_category') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: monthly_income.php?month=$month&year=$year&error=Unauthorized: Read-only access");
        exit();
    }

    $ids = $_POST['ids'] ?? [];
    $new_category = $_POST['new_category'] ?? '';

    if (!empty($ids) && !empty($new_category)) {
        $ids_placeholder = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE monthly_income SET category = ? WHERE id IN ($ids_placeholder) AND tenant_id = ?");
        $stmt->execute(array_merge([$new_category], $ids, [$_SESSION['tenant_id']]));

        AuditHelper::log($pdo, 'bulk_income_edit', "Changed category for " . count($ids) . " items to $new_category");
        header("Location: monthly_income.php?month=$month&year=$year&success=Bulk update successful");
        exit();
    }
}

// Fetch Records
$stmt = $pdo->prepare("SELECT * FROM monthly_income WHERE tenant_id = ? AND month = ? AND year = ? ORDER BY id DESC");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_income = array_sum(array_column($records, 'amount'));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">Monthly Income</h1>
        <p class="text-muted mb-0">Track all income sources for
            <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <form class="d-flex gap-2 me-2" method="GET">
            <select name="month" class="form-select form-select-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                    </option>
                <?php endfor; ?>
            </select>
            <select name="year" class="form-select form-select-sm">
                <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                        <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-light border">Go</button>
        </form>
        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
            <a href="add_income.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn btn-primary fw-bold">
                <i class="fa-solid fa-plus me-2"></i> Add Income
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="glass-panel p-4 h-100 border-start border-4 border-primary">
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Total Income</h6>
            <h3 class="fw-bold text-primary mb-0">AED <span class="blur-sensitive">
                    <?php echo number_format($total_income, 2); ?>
                </span></h3>
            <p class="text-muted small mb-0 mt-2">Sum of all sources for this period</p>
        </div>
    </div>
</div>

<div class="glass-panel p-0 overflow-hidden">
    <form id="bulkActionForm" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
        <input type="hidden" name="action" value="bulk_change_category">
        <input type="hidden" name="new_category" id="bulkCategoryInput">

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4" style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                        </th>
                        <th>Source</th>
                        <th>Category</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-money-bill-trend-up fa-3x mb-3 d-block opacity-25"></i>
                                No income entries for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td class="ps-4">
                                    <input type="checkbox" name="ids[]" value="<?php echo $record['id']; ?>"
                                        class="form-check-input row-checkbox">
                                </td>
                                <td class="fw-bold">
                                    <?php echo htmlspecialchars($record['source']); ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?php echo htmlspecialchars($record['category'] ?? 'General'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold text-success">AED
                                        <?php echo number_format($record['amount'], 2); ?>
                                    </span>
                                </td>
                                <td class="text-muted small">
                                    <?php echo date('d M Y', strtotime($record['created_at'])); ?>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                        <div class="btn-group btn-group-sm">
                                            <a href="edit_income.php?id=<?php echo $record['id']; ?>"
                                                class="btn btn-outline-primary border-0"><i class="fa-solid fa-pen"></i></a>
                                            <button type="button" class="btn btn-outline-danger border-0"
                                                onclick="confirmDelete(<?php echo $record['id']; ?>, '<?php echo addslashes(htmlspecialchars($record['source'])); ?>')">
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
    <div id="bulkActionBar"
        class="position-fixed bottom-0 start-50 translate-middle-x mb-4 glass-panel p-3 border shadow-lg d-none"
        style="z-index: 1050; min-width: 400px;">
        <div class="d-flex align-items-center justify-content-between">
            <div class="me-3">
                <span id="selectedCount" class="badge bg-primary rounded-pill me-2">0</span>
                <span class="fw-bold">Selected</span>
            </div>
            <div class="d-flex gap-2">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle rounded-pill" type="button"
                        data-bs-toggle="dropdown">
                        Change Category
                    </button>
                    <ul class="dropdown-menu border-0 shadow">
                        <li><button class="dropdown-item" type="button" onclick="submitBulkChange('Salary')">Salary</button>
                        </li>
                        <li><button class="dropdown-item" type="button" onclick="submitBulkChange('Bonus')">Bonus</button>
                        </li>
                        <li><button class="dropdown-item" type="button"
                                onclick="submitBulkChange('Investment')">Investment</button></li>
                        <li><button class="dropdown-item" type="button"
                                onclick="submitBulkChange('Business')">Business</button></li>
                        <li><button class="dropdown-item" type="button"
                                onclick="submitBulkChange('Freelance')">Freelance</button></li>
                        <li><button class="dropdown-item" type="button" onclick="submitBulkChange('Gift')">Gift</button>
                        </li>
                        <li><button class="dropdown-item" type="button" onclick="submitBulkChange('Other')">Other</button>
                        </li>
                    </ul>
                </div>
                <button type="button" class="btn btn-sm btn-light rounded-pill px-3"
                    onclick="clearSelection()">Cancel</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
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
        if (confirm(`Are you sure you want to delete income from "${source}"? This cannot be undone.`)) {
            window.location.href = `income_actions.php?action=delete&id=${id}&month=<?php echo $month; ?>&year=<?php echo $year; ?>&csrf_token=<?php echo SecurityHelper::generateCsrfToken(); ?>`;
        }
    }
</script>

<?php Layout::footer(); ?>
