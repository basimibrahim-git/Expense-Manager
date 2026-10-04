<?php
$page_title = "Monthly Interest";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;
use App\Helpers\Html;
use App\Helpers\Flash;
use App\Helpers\Layout;

Bootstrap::init();

// Records live in interest_tracker (same table as the yearly view, dashboard and export).
// Sign convention: positive amount = interest accrued, negative amount = payment (charitable disposal).

// Handle Actions (Add/Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $month = filter_var($_POST['month'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
    $year  = filter_var($_POST['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');
    $back  = "monthly_interest.php?month=" . (int) $month . "&year=" . (int) $year;

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        Flash::redirect($back, 'error', 'Unauthorized: Read-only access');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_record') {
        $type    = ($_POST['type'] ?? '') === 'payment' ? 'payment' : 'interest';
        $title   = trim((string) ($_POST['title'] ?? ''));
        $amount  = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $date    = (string) ($_POST['record_date'] ?? '');
        $d       = DateTime::createFromFormat('!Y-m-d', $date);
        $date_ok = $d && $d->format('Y-m-d') === $date && (int) $d->format('Y') >= 2000 && (int) $d->format('Y') <= 2100;

        if ($title === '' || mb_strlen($title) > 255 || $amount === false || $amount <= 0 || $amount > 99999999.99 || !$date_ok) {
            Flash::redirect($back, 'error', 'Please enter a description, an amount greater than zero and a valid date.');
        }

        $signed = $type === 'payment' ? -abs($amount) : abs($amount);
        $stmt = $pdo->prepare("INSERT INTO interest_tracker (user_id, tenant_id, title, amount, interest_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], $title, $signed, $date]);

        AuditHelper::log($pdo, 'interest_entry', "Added $type: $title (AED $amount)");
        Flash::redirect('monthly_interest.php?month=' . (int) $d->format('n') . '&year=' . (int) $d->format('Y'), 'success', 'Record added');
    } elseif ($action === 'delete_record') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM interest_tracker WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);
            AuditHelper::log($pdo, 'interest_delete', "Deleted interest record ID: $id");
        }
        Flash::redirect($back, 'success', 'Record deleted');
    }

    header("Location: $back");
    exit();
}

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
$year  = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');

// Default date for a new record: today when viewing the current month, otherwise the 1st of the viewed month
$default_date = ($month === (int) date('n') && $year === (int) date('Y')) ? date('Y-m-d') : sprintf('%04d-%02d-01', $year, $month);

Layout::header();
Layout::sidebar();

// Fetch Records
$stmt = $pdo->prepare("SELECT id, title, amount, interest_date FROM interest_tracker WHERE tenant_id = ? AND MONTH(interest_date) = ? AND YEAR(interest_date) = ? ORDER BY interest_date ASC, id ASC");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_interest = 0;
$total_payment = 0;
foreach ($records as &$r) {
    $r['type'] = $r['amount'] < 0 ? 'payment' : 'interest';
    if ($r['type'] == 'interest') {
        $total_interest += $r['amount'];
    } else {
        $total_payment += abs($r['amount']);
    }
}
unset($r);
$can_edit = ($_SESSION['permission'] ?? 'edit') !== 'read_only';
?>

<div class="container-fluid py-4">

    <!-- Header with Filter -->
    <div class="row align-items-center mb-4 g-3">
        <div class="col-md-6">
            <h1 class="h3 fw-bold mb-1 text-dark">Interest Statement</h1>
            <p class="text-muted mb-0">Disposal metrics for <strong><?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></strong></p>
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
                    <button class="btn btn-success fw-bold rounded-pill px-4 shadow-sm hover-lift" data-bs-toggle="modal" data-bs-target="#addRecordModal">
                        <i class="fa-solid fa-plus me-1"></i> Add Record
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Widgets row -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="glass-panel-premium p-4 h-100 hover-lift position-relative overflow-hidden" style="border-left: 4px solid #f43f5e !important;">
                <div class="position-absolute end-0 top-50 translate-middle-y me-4 opacity-10">
                    <i class="fa-solid fa-circle-exclamation fa-4x text-danger"></i>
                </div>
                <h6 class="text-muted fw-bold text-uppercase small mb-2">Interest Accrued (Debt)</h6>
                <h3 class="fw-bold text-danger mb-1">
                    <small class="fs-6 text-muted">AED</small> 
                    <span class="blur-sensitive"><?php echo number_format($total_interest, 2); ?></span>
                </h3>
                <p class="text-muted small mb-0">Total non-permissible interest charged</p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="glass-panel-premium p-4 h-100 hover-lift position-relative overflow-hidden" style="border-left: 4px solid #10b981 !important;">
                <div class="position-absolute end-0 top-50 translate-middle-y me-4 opacity-10">
                    <i class="fa-solid fa-heart fa-4x text-success"></i>
                </div>
                <h6 class="text-muted fw-bold text-uppercase small mb-2">Interest Payments (Charity)</h6>
                <h3 class="fw-bold text-success mb-1">
                    <small class="fs-6 text-muted">AED</small> 
                    <span class="blur-sensitive"><?php echo number_format($total_payment, 2); ?></span>
                </h3>
                <p class="text-muted small mb-0">Voluntary clearing offset payments made</p>
            </div>
        </div>
    </div>

    <!-- Progress Tracker -->
    <div class="glass-panel-premium p-4 mb-4">
        <h6 class="fw-bold mb-2 text-dark">Disposal Progress Ratio</h6>
        <?php
        $progress = ($total_interest > 0) ? ($total_payment / $total_interest) * 100 : 100;
        $progress = min(100, $progress);
        $progress_color = $progress >= 100 ? 'bg-success' : 'bg-warning';
        ?>
        <div class="progress rounded-pill mb-2" style="height: 10px; background: rgba(0,0,0,0.05);">
            <div class="progress-bar rounded-pill <?php echo $progress_color; ?>" role="progressbar" 
                 style="width: <?php echo $progress; ?>%; transition: width 0.6s ease;" 
                 aria-valuenow="<?php echo $progress; ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="d-flex justify-content-between small text-muted">
            <span>Cleared: <strong><?php echo number_format($progress, 1); ?>%</strong></span>
            <span>Outstanding balance: <strong>AED <?php echo number_format(max(0, $total_interest - $total_payment), 2); ?></strong></span>
        </div>
    </div>

    <!-- Logs List -->
    <div class="glass-panel-premium p-0 overflow-hidden shadow-sm">
        <div class="p-4 border-bottom border-light d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark">Transaction Entries</h5>
            <span class="badge rounded-pill bg-light text-dark px-3 py-1 border"><?php echo count($records); ?> records</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr class="text-uppercase text-muted small" style="border-bottom: 1px solid rgba(0,0,0,0.05);">
                        <th class="ps-4 py-3">Description</th>
                        <th class="py-3">Type</th>
                        <th class="py-3">Amount</th>
                        <th class="text-end pe-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-percent fa-3x mb-3 d-block opacity-20"></i>
                                <span>No entries tracked for this period. Click "Add Record" to configure.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $r): ?>
                            <tr style="border-bottom: 1px solid rgba(0,0,0,0.03);">
                                <td class="ps-4 py-3">
                                    <div class="d-flex align-items-center">
                                        <?php if ($r['type'] === 'interest'): ?>
                                            <div class="rounded-circle p-2 bg-danger bg-opacity-10 text-danger d-flex align-items-center justify-content-center me-3" style="width: 38px; height: 38px;">
                                                <i class="fa-solid fa-circle-exclamation"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="rounded-circle p-2 bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center me-3" style="width: 38px; height: 38px;">
                                                <i class="fa-solid fa-circle-check"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold text-dark"><?php echo Html::e($r['title']); ?></div>
                                            <small class="text-muted"><?php echo date('d M Y', strtotime($r['interest_date'])); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge rounded-pill <?php echo $r['type'] === 'interest' ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'; ?> px-3 py-1">
                                        <?php echo ucfirst($r['type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold <?php echo $r['type'] === 'interest' ? 'text-danger' : 'text-success'; ?>">
                                        AED <?php echo number_format(abs($r['amount']), 2); ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if ($can_edit): ?>
                                        <form method="POST" class="d-inline"
                                              data-confirm="<?php echo Html::e('Delete entry "' . $r['title'] . '" (AED ' . number_format(abs($r['amount']), 2) . ')? This cannot be undone.'); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="delete_record">
                                            <input type="hidden" name="month" value="<?php echo (int) $month; ?>">
                                            <input type="hidden" name="year" value="<?php echo (int) $year; ?>">
                                            <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger border-0 rounded-circle p-2 hover-lift"
                                                    style="width: 36px; height: 36px;" title="Delete">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
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

<!-- Add Record Modal -->
<div class="modal fade" id="addRecordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel-premium border-0 shadow-lg p-0">
            <div class="modal-header border-bottom border-light p-4">
                <h5 class="modal-title fw-bold text-dark">Add Statement Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="add_record">
                    <input type="hidden" name="month" value="<?php echo (int) $month; ?>">
                    <input type="hidden" name="year" value="<?php echo (int) $year; ?>">

                    <div class="mb-3">
                        <label for="recordType" class="form-label fw-bold text-muted small">Record Type</label>
                        <select name="type" id="recordType" class="form-select rounded-pill px-3">
                            <option value="interest">Interest Accrued (Accrued Debt)</option>
                            <option value="payment">Payment (Charitable Disposal)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="recordTitle" class="form-label fw-bold text-muted small">Description <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="recordTitle" class="form-control rounded-pill px-3"
                            placeholder="e.g. Mashreq Credit Card Jan" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                        <label for="recordDate" class="form-label fw-bold text-muted small">Date <span class="text-danger">*</span></label>
                        <input type="date" name="record_date" id="recordDate" class="form-control rounded-pill px-3"
                            value="<?php echo Html::e($default_date); ?>" required>
                    </div>
                    <div class="mb-4">
                        <label for="recordAmount" class="form-label fw-bold text-muted small">Amount (AED) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted border-end-0" style="border-top-left-radius: 20px; border-bottom-left-radius: 20px;">AED</span>
                            <input type="number" step="0.01" min="0.01" name="amount" id="recordAmount" class="form-control border-start-0" 
                                   placeholder="0.00" style="border-top-right-radius: 20px; border-bottom-right-radius: 20px;" required>
                        </div>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-bold py-2.5 rounded-pill shadow-sm hover-lift">
                            Save Record <i class="fa-solid fa-check ms-1"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php Layout::footer(); ?>
