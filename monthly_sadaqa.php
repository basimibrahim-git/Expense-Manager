<?php
$page_title = "Monthly Sadaqa";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;
use App\Helpers\Html;
use App\Helpers\Flash;
use App\Helpers\Layout;

Bootstrap::init();

// Categories offered in the add/edit modals (anything else is stored as "General").
$sadaqa_categories = ['General', 'Masjid', 'Education', 'Poor/Needy', 'Family', 'Emergency'];

/**
 * Returns the date string when it is a real Y-m-d calendar date, otherwise null.
 */
function sadaqa_valid_date(string $date): ?string
{
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date && (int) $d->format('Y') >= 2000 && (int) $d->format('Y') <= 2100) ? $date : null;
}

// Handle Actions (Add/Edit/Delete) — records live in sadaqa_tracker (same table as the yearly view and the export)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $month = filter_var($_POST['month'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
    $year  = filter_var($_POST['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');
    $back  = "monthly_sadaqa.php?month=" . (int) $month . "&year=" . (int) $year;

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        Flash::redirect($back, 'error', 'Unauthorized: Read-only access');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_sadaqa' || $action === 'edit_sadaqa') {
        $title    = trim((string) ($_POST['title'] ?? ''));
        $amount   = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $category = in_array($_POST['category'] ?? '', $sadaqa_categories, true) ? $_POST['category'] : 'General';
        $date     = sadaqa_valid_date((string) ($_POST['sadaqa_date'] ?? ''));

        if ($title === '' || mb_strlen($title) > 255 || $amount === false || $amount <= 0 || $amount > 99999999.99 || $date === null) {
            Flash::redirect($back, 'error', 'Please enter a description, an amount greater than zero and a valid date.');
        }

        // Show the month the entry was saved in
        $back = "monthly_sadaqa.php?month=" . (int) date('n', strtotime($date)) . "&year=" . (int) date('Y', strtotime($date));

        if ($action === 'add_sadaqa') {
            $stmt = $pdo->prepare("INSERT INTO sadaqa_tracker (user_id, tenant_id, title, amount, category, sadaqa_date) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], $title, $amount, $category, $date]);

            AuditHelper::log($pdo, 'add_sadaqa', "Added sadaqa: $title (AED $amount)");
            Flash::redirect($back, 'success', 'Sadaqa added');
        }

        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id) {
            $stmt = $pdo->prepare("UPDATE sadaqa_tracker SET title = ?, amount = ?, category = ?, sadaqa_date = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$title, $amount, $category, $date, $id, $_SESSION['tenant_id']]);
            AuditHelper::log($pdo, 'edit_sadaqa', "Updated sadaqa ID: $id");
        }
        Flash::redirect($back, 'success', 'Sadaqa updated');
    }

    if ($action === 'delete_sadaqa') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM sadaqa_tracker WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);
            AuditHelper::log($pdo, 'delete_sadaqa', "Deleted sadaqa ID: $id");
        }
        Flash::redirect($back, 'success', 'Sadaqa deleted');
    }

    header("Location: $back");
    exit();
}

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
$year  = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');

// Default date for a new entry: today when viewing the current month, otherwise the 1st of the viewed month
$default_date = ($month === (int) date('n') && $year === (int) date('Y')) ? date('Y-m-d') : sprintf('%04d-%02d-01', $year, $month);

Layout::header();
Layout::sidebar();

// Fetch Records
$stmt = $pdo->prepare("SELECT id, title, amount, category, sadaqa_date FROM sadaqa_tracker WHERE tenant_id = ? AND MONTH(sadaqa_date) = ? AND YEAR(sadaqa_date) = ? ORDER BY sadaqa_date ASC, id ASC");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_sadaqa = array_sum(array_column($records, 'amount'));
$can_edit = ($_SESSION['permission'] ?? 'edit') !== 'read_only';
?>

<div class="container-fluid py-4">

    <!-- Header row with Filter -->
    <div class="row align-items-center mb-4 g-3">
        <div class="col-md-6">
            <h1 class="h3 fw-bold mb-1 text-dark">Sadaqa Logs</h1>
            <p class="text-muted mb-0">Donations for <strong><?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></strong></p>
        </div>
        <div class="col-md-6">
            <div class="d-flex gap-2 justify-content-md-end flex-wrap">
                <form class="d-flex gap-2" method="GET">
                    <select name="month" class="form-select rounded-pill px-3" style="max-width: 130px; border: 1px solid rgba(0,0,0,0.1);">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <select name="year" class="form-select rounded-pill px-3" style="max-width: 110px; border: 1px solid rgba(0,0,0,0.1);">
                        <?php for ($y = min($year, (int) date('Y') - 1); $y <= max($year, (int) date('Y') + 1); $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">
                        Filter
                    </button>
                </form>
                <?php if ($can_edit): ?>
                    <button class="btn btn-success fw-bold rounded-pill px-4 shadow-sm hover-lift" data-bs-toggle="modal" data-bs-target="#addSadaqaModal">
                        <i class="fa-solid fa-plus me-1"></i> Add Entry
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Summary Widgets -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="glass-panel-premium p-4 h-100 hover-lift position-relative overflow-hidden" style="border-left: 4px solid #10b981 !important;">
                <div class="position-absolute end-0 top-50 translate-middle-y me-4 opacity-10">
                    <i class="fa-solid fa-heart fa-4x text-success"></i>
                </div>
                <h6 class="text-muted fw-bold text-uppercase small mb-2">Total Sadaqa Given</h6>
                <h3 class="fw-bold text-success mb-1">
                    <small class="fs-6 text-muted">AED</small>
                    <span class="blur-sensitive"><?php echo number_format($total_sadaqa, 2); ?></span>
                </h3>
                <p class="text-muted small mb-0">Total voluntary charity logged for the period</p>
            </div>
        </div>
    </div>

    <!-- Table Logs Container -->
    <div class="glass-panel-premium p-0 overflow-hidden shadow-sm">
        <div class="p-4 border-bottom border-light d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark">Donation Transactions</h5>
            <span class="badge rounded-pill bg-light text-dark px-3 py-1 border"><?php echo count($records); ?> entries</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr class="text-uppercase text-muted small" style="border-bottom: 1px solid rgba(0,0,0,0.05);">
                        <th class="ps-4 py-3">Description</th>
                        <th class="py-3">Category</th>
                        <th class="py-3">Amount</th>
                        <th class="text-end pe-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-heart fa-3x mb-3 d-block opacity-20"></i>
                                <span>No Sadaqa recorded for this period. Click "Add Entry" to begin tracking.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $r): ?>
                            <tr style="border-bottom: 1px solid rgba(0,0,0,0.03);">
                                <td class="ps-4 py-3">
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle p-2 bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center me-3" style="width: 38px; height: 38px;">
                                            <i class="fa-solid fa-heart-pulse"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?php echo Html::e($r['title']); ?></div>
                                            <small class="text-muted"><?php echo date('d M Y', strtotime($r['sadaqa_date'])); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge rounded-pill bg-light text-dark px-3 py-1 border">
                                        <?php echo Html::e($r['category'] ?? 'General'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold text-success">
                                        AED <?php echo number_format($r['amount'], 2); ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if ($can_edit): ?>
                                        <div class="d-inline-flex gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-primary border-0 rounded-circle p-2 hover-lift"
                                                    data-onclick="openEditSadaqa"
                                                    data-args="<?php echo Html::args((int) $r['id'], $r['title'], $r['amount'], $r['category'] ?? 'General', $r['sadaqa_date']); ?>"
                                                    style="width: 36px; height: 36px;" title="Edit">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <form method="POST" class="d-inline"
                                                  data-confirm="<?php echo Html::e('Delete "' . $r['title'] . '" (AED ' . number_format($r['amount'], 2) . ')? This cannot be undone.'); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="delete_sadaqa">
                                                <input type="hidden" name="month" value="<?php echo (int) $month; ?>">
                                                <input type="hidden" name="year" value="<?php echo (int) $year; ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger border-0 rounded-circle p-2 hover-lift"
                                                        style="width: 36px; height: 36px;" title="Delete">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted"><i class="fa-solid fa-lock me-1"></i> Read Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($can_edit): ?>
<!-- Add / Edit Sadaqa Modal -->
<div class="modal fade" id="addSadaqaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel-premium border-0 shadow-lg p-0">
            <div class="modal-header border-bottom border-light p-4">
                <h5 class="modal-title fw-bold text-dark" id="sadaqaModalTitle">Add Voluntary Sadaqa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" id="sadaqaForm">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" id="sadaqaAction" value="add_sadaqa">
                    <input type="hidden" name="id" id="sadaqaId" value="">
                    <input type="hidden" name="month" value="<?php echo (int) $month; ?>">
                    <input type="hidden" name="year" value="<?php echo (int) $year; ?>">

                    <div class="mb-3">
                        <label for="sadaqaTitle" class="form-label fw-bold text-muted small">Description <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="sadaqaTitle" class="form-control rounded-pill px-3"
                            placeholder="e.g. Masjid Donation" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                        <label for="sadaqaCategory" class="form-label fw-bold text-muted small">Category</label>
                        <select name="category" id="sadaqaCategory" class="form-select rounded-pill px-3">
                            <?php foreach ($sadaqa_categories as $cat): ?>
                                <option value="<?php echo Html::e($cat); ?>"><?php echo Html::e($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="sadaqaDate" class="form-label fw-bold text-muted small">Date <span class="text-danger">*</span></label>
                        <input type="date" name="sadaqa_date" id="sadaqaDate" class="form-control rounded-pill px-3"
                            value="<?php echo Html::e($default_date); ?>" required>
                    </div>
                    <div class="mb-4">
                        <label for="sadaqaAmount" class="form-label fw-bold text-muted small">Amount (AED) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted border-end-0" style="border-top-left-radius: 20px; border-bottom-left-radius: 20px;">AED</span>
                            <input type="number" step="0.01" min="0.01" name="amount" id="sadaqaAmount" class="form-control border-start-0"
                                   placeholder="0.00" style="border-top-right-radius: 20px; border-bottom-right-radius: 20px;" required>
                        </div>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-bold py-2.5 rounded-pill shadow-sm hover-lift" id="sadaqaSubmitBtn">
                            Save Sadaqa <i class="fa-solid fa-check ms-1"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    const sadaqaDefaultDate = <?php echo Html::json($default_date); ?>;

    function openEditSadaqa(id, title, amount, category, date) {
        document.getElementById('sadaqaModalTitle').textContent = 'Edit Sadaqa';
        document.getElementById('sadaqaAction').value = 'edit_sadaqa';
        document.getElementById('sadaqaId').value = id;
        document.getElementById('sadaqaTitle').value = title;
        document.getElementById('sadaqaAmount').value = amount;
        document.getElementById('sadaqaCategory').value = category;
        document.getElementById('sadaqaDate').value = date;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('addSadaqaModal')).show();
    }

    // Reset the modal to "add" mode when it closes
    document.getElementById('addSadaqaModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('sadaqaForm').reset();
        document.getElementById('sadaqaModalTitle').textContent = 'Add Voluntary Sadaqa';
        document.getElementById('sadaqaAction').value = 'add_sadaqa';
        document.getElementById('sadaqaId').value = '';
        document.getElementById('sadaqaDate').value = sadaqaDefaultDate;
    });
</script>
<?php endif; ?>

<?php Layout::footer(); ?>
