<?php
$page_title = "Lending Tracker";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Html;
use App\Helpers\Flash;
use App\Helpers\Layout;
use App\Helpers\ExchangeRateHelper;

Bootstrap::init();

/**
 * Returns the value when it is a real Y-m-d date, otherwise null.
 */
function lending_valid_date($date): ?string
{
    $date = (string) $date;
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date) ? $date : null;
}

// Handle Actions (Must be before outputting any HTML)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        Flash::redirect('lending_tracker.php', 'error', 'Unauthorized: Read-only access');
    }

    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add_lending') {
            $name = trim((string) ($_POST['borrower_name'] ?? ''));
            $amount = floatval($_POST['amount'] ?? 0);
            $currency = in_array($_POST['currency'] ?? 'AED', ['AED', 'INR'], true) ? $_POST['currency'] : 'AED';
            $lent_date = lending_valid_date($_POST['lent_date'] ?? '');
            $due_date = lending_valid_date($_POST['due_date'] ?? '');
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if (!empty($name) && $amount > 0 && $lent_date !== null) {
                $stmt = $pdo->prepare("INSERT INTO lending_tracker (user_id, tenant_id, borrower_name, amount, currency, lent_date, due_date, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], $name, $amount, $currency, $lent_date, $due_date, $notes]);
                Flash::redirect('lending_tracker.php', 'success', 'Record Added');
            }
        } elseif ($_POST['action'] == 'edit_lending') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $name = trim((string) ($_POST['borrower_name'] ?? ''));
            $amount = floatval($_POST['amount'] ?? 0);
            $currency = in_array($_POST['currency'] ?? 'AED', ['AED', 'INR'], true) ? $_POST['currency'] : 'AED';
            $lent_date = lending_valid_date($_POST['lent_date'] ?? '');
            $due_date = lending_valid_date($_POST['due_date'] ?? '');
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($id && !empty($name) && $amount > 0 && $lent_date !== null) {
                $stmt = $pdo->prepare("UPDATE lending_tracker SET borrower_name = ?, amount = ?, currency = ?, lent_date = ?, due_date = ?, notes = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$name, $amount, $currency, $lent_date, $due_date, $notes, $id, $_SESSION['tenant_id']]);
                Flash::redirect('lending_tracker.php', 'success', 'Record Updated');
            }
        } elseif ($_POST['action'] == 'mark_paid') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if ($id) {
                // Update status to Paid
                $stmt = $pdo->prepare("UPDATE lending_tracker SET status = 'Paid' WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$id, $_SESSION['tenant_id']]);

                Flash::redirect('lending_tracker.php', 'success', 'Marked as Paid');
            }
        } elseif ($_POST['action'] == 'delete_lending') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if ($id) {
                $stmt = $pdo->prepare("DELETE FROM lending_tracker WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$id, $_SESSION['tenant_id']]);

                Flash::redirect('lending_tracker.php', 'success', 'Record Deleted');
            }
        } elseif ($_POST['action'] == 'bulk_delete_lending') {
            if (isset($_POST['ids']) && is_array($_POST['ids'])) {
                $ids = array_map('intval', $_POST['ids']);
                if (!empty($ids)) {
                    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                    $stmt = $pdo->prepare("DELETE FROM lending_tracker WHERE id IN ($placeholders) AND tenant_id = ?");
                    $stmt->execute(array_merge($ids, [$_SESSION['tenant_id']]));
                }
            }
            Flash::redirect('lending_tracker.php', 'success', 'Bulk Deleted');
        } elseif ($_POST['action'] == 'bulk_paid_lending') {
            if (isset($_POST['ids']) && is_array($_POST['ids'])) {
                $ids = array_map('intval', $_POST['ids']);
                if (!empty($ids)) {
                    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                    $stmt = $pdo->prepare("UPDATE lending_tracker SET status = 'Paid' WHERE id IN ($placeholders) AND tenant_id = ?");
                    $stmt->execute(array_merge($ids, [$_SESSION['tenant_id']]));
                }
            }
            Flash::redirect('lending_tracker.php', 'success', 'Records Marked as Paid');
        }
    }

    Flash::redirect('lending_tracker.php', 'error', 'Please fill in the name, an amount greater than zero and a valid lent date.');
}

Layout::header();
Layout::sidebar();

// Fetch Logic
$filter_status = in_array($_GET['status'] ?? 'Pending', ['Pending', 'Paid', 'Partially Paid', 'All'], true) ? ($_GET['status'] ?? 'Pending') : 'Pending';
$query = "SELECT * FROM lending_tracker WHERE tenant_id = ?";
$params = [$_SESSION['tenant_id']];

if ($filter_status != 'All') {
    $query .= " AND status = ?";
    $params[] = $filter_status;
}

$query .= " ORDER BY due_date ASC, lent_date DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Summary
$inr_to_aed = ExchangeRateHelper::getRate('INR', 'AED', $pdo);
$stmt = $pdo->prepare("SELECT
    SUM(CASE
        WHEN status = 'Pending' AND currency = 'INR' THEN amount * ?
        WHEN status = 'Pending' THEN amount
        ELSE 0
    END) as pending_total,
    SUM(CASE
        WHEN status = 'Paid' AND currency = 'INR' THEN amount * ?
        WHEN status = 'Paid' THEN amount
        ELSE 0
    END) as paid_total
    FROM lending_tracker WHERE tenant_id = ?");
$stmt->execute([$inr_to_aed, $inr_to_aed, $_SESSION['tenant_id']]);
$summary = $stmt->fetch();
?>


<!-- Premium Header Banner -->
<div class="row mb-4">
    <div class="col-12">
        <div class="gradient-card-primary p-4 rounded-4 hover-lift position-relative overflow-hidden shadow-sm" style="border-radius: 16px;">
            <div class="position-absolute top-0 end-0 p-3 opacity-10">
                <i class="fa-solid fa-hand-holding-dollar fa-9x"></i>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 position-relative" style="z-index: 2;">
                <div>
                    <h1 class="h3 fw-bold mb-1 text-white">Lending Tracker</h1>
                    <p class="text-white text-opacity-75 mb-0">Manage outstanding loans, repayments, and consolidated receivables</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="export_actions.php?action=export_lending" class="btn btn-white text-primary border-0 rounded-pill px-3 py-1.5 fw-bold shadow-sm hover-lift">
                        <i class="fa-solid fa-file-csv me-1"></i> Export Data
                    </a>
                    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                        <button class="btn btn-dark border-0 rounded-pill px-4 py-1.5 fw-bold shadow-sm hover-lift" data-bs-toggle="modal" data-bs-target="#addLendingModal">
                            <i class="fa-solid fa-plus me-1"></i> New Record
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div class="row g-4 mb-4">
    <div class="col-12 col-md-6">
        <div class="glass-panel-premium p-4 h-100 hover-lift position-relative overflow-hidden" style="border-left: 4px solid #f59e0b !important;">
            <div class="position-absolute end-0 top-50 translate-middle-y me-4 opacity-10">
                <i class="fa-solid fa-clock-rotate-left fa-4x text-warning"></i>
            </div>
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Pending Collection</h6>
            <h2 class="fw-bold text-warning mb-1">
                <small class="fs-6 text-muted">AED</small> 
                <span class="blur-sensitive"><?php echo number_format($summary['pending_total'] ?? 0, 2); ?></span>
            </h2>
            <p class="text-muted small mb-0">Total active funds currently out on loan</p>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="glass-panel-premium p-4 h-100 hover-lift position-relative overflow-hidden" style="border-left: 4px solid #10b981 !important;">
            <div class="position-absolute end-0 top-50 translate-middle-y me-4 opacity-10">
                <i class="fa-solid fa-circle-check fa-4x text-success"></i>
            </div>
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Total Recovered</h6>
            <h2 class="fw-bold text-success mb-1">
                <small class="fs-6 text-muted">AED</small> 
                <span class="blur-sensitive"><?php echo number_format($summary['paid_total'] ?? 0, 2); ?></span>
            </h2>
            <p class="text-muted small mb-0">Settled debts collected from borrowers</p>
        </div>
    </div>
</div>

<!-- Premium Filter Navigation -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div class="bg-light p-1 rounded-pill d-flex gap-1" style="border: 1px solid rgba(0,0,0,0.05);">
        <a class="btn btn-sm rounded-pill px-4 fw-bold <?php echo $filter_status == 'Pending' ? 'btn-primary text-white shadow-sm' : 'btn-light text-muted'; ?>"
           href="?status=Pending">Pending</a>
        <a class="btn btn-sm rounded-pill px-4 fw-bold <?php echo $filter_status == 'Paid' ? 'btn-primary text-white shadow-sm' : 'btn-light text-muted'; ?>" 
           href="?status=Paid">Completed</a>
        <a class="btn btn-sm rounded-pill px-4 fw-bold <?php echo $filter_status == 'All' ? 'btn-primary text-white shadow-sm' : 'btn-light text-muted'; ?>" 
           href="?status=All">All Records</a>
    </div>
</div>

<!-- Records List -->
<?php if (empty($records)): ?>
    <div class="text-center py-5 glass-panel-premium">
        <div class="mb-3 text-muted opacity-25">
            <i class="fa-solid fa-hand-holding-dollar fa-3x"></i>
        </div>
        <h5 class="text-dark fw-bold mb-1">No Lending Records Found</h5>
        <p class="text-muted small px-3">Have you lent money to someone? Add it to track status updates here.</p>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($records as $r): ?>
            <?php
            $is_overdue = ($r['status'] == 'Pending' && !empty($r['due_date']) && strtotime($r['due_date']) < time());
            $border_color = '#e2e8f0'; // default
            if ($r['status'] == 'Paid') {
                $border_color = '#10b981';
            } elseif ($is_overdue) {
                $border_color = '#f43f5e';
            } elseif ($r['status'] == 'Pending') {
                $border_color = '#f59e0b';
            }
            $curr = $r['currency'] ?? 'AED';
            ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="glass-panel-premium p-4 h-100 position-relative hover-lift transition-all" 
                     style="border-left: 4px solid <?php echo $border_color; ?> !important;">
                    <div class="position-absolute top-0 end-0 m-3 d-flex align-items-center gap-2">
                        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                            <input type="checkbox" class="form-check-input row-checkbox" value="<?php echo $r['id']; ?>" style="width: 18px; height: 18px; border-radius: 4px;">
                        <?php endif; ?>
                    </div>

                    <div class="d-flex justify-content-between align-items-start mb-3 pe-4">
                        <div>
                            <h5 class="fw-bold mb-1 text-dark"><?php echo htmlspecialchars($r['borrower_name']); ?></h5>
                            <small class="text-muted d-block">
                                <i class="fa-solid fa-calendar-day me-1"></i> Lent: <?php echo date('M d, Y', strtotime($r['lent_date'])); ?>
                            </small>
                        </div>
                        <div class="text-end">
                            <h4 class="fw-bold text-primary mb-0">
                                <small class="fs-6 text-muted"><?php echo Html::e($curr); ?></small>
                                <span class="blur-sensitive"><?php echo number_format($r['amount'], 2); ?></span>
                            </h4>
                            <?php if ($r['status'] == 'Pending'): ?>
                                <span class="badge rounded-pill bg-warning-subtle text-warning px-2 py-0.5 small fw-bold">Pending</span>
                            <?php else: ?>
                                <span class="badge rounded-pill bg-success-subtle text-success px-2 py-0.5 small fw-bold">Paid</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($r['notes'])): ?>
                        <div class="p-3 bg-light rounded-3 text-muted small mb-3 fst-italic border border-light">
                            "<?php echo htmlspecialchars($r['notes']); ?>"
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top border-light">
                        <div class="small fw-bold <?php echo $is_overdue ? 'text-danger animate-pulse' : 'text-muted'; ?>">
                            <?php if ($r['status'] == 'Paid'): ?>
                                <span class="text-success"><i class="fa-solid fa-check me-1"></i> Recovered</span>
                            <?php elseif (!empty($r['due_date'])): ?>
                                <i class="fa-solid fa-calendar-check me-1"></i> Due: <?php echo date('M d, Y', strtotime($r['due_date'])); ?>
                                <?php echo $is_overdue ? '(Overdue)' : ''; ?>
                            <?php else: ?>
                                <i class="fa-solid fa-infinity me-1"></i> Open repayment
                            <?php endif; ?>
                        </div>

                        <div class="d-flex gap-1">
                            <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                <?php if ($r['status'] == 'Pending'): ?>
                                    <button type="button" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm hover-lift"
                                        data-onclick="openPayModal" data-args="<?php echo Html::args((int) $r['id'], $r['borrower_name']); ?>">
                                        <i class="fa-solid fa-check"></i> Paid
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary border-0 rounded-circle hover-lift"
                                    data-onclick="openEditModal" data-args="<?php echo Html::args((int) $r['id'], $r['borrower_name'], $r['amount'], $curr, $r['lent_date'], $r['due_date'] ?? '', $r['notes'] ?? ''); ?>"
                                    style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger border-0 rounded-circle hover-lift"
                                    data-onclick="openDeleteModal" data-args="<?php echo Html::args((int) $r['id'], $r['borrower_name'], number_format($r['amount'], 2), $curr); ?>"
                                    style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            <?php else: ?>
                                <span class="badge bg-light text-muted"><i class="fa-solid fa-lock me-1"></i> Locked</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Add Modal -->
<div class="modal fade" id="addLendingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel-premium border-0 shadow-lg p-0">
            <div class="modal-header border-bottom border-light p-4">
                <h5 class="modal-title fw-bold text-dark">Add Lending Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="add_lending">

                    <div class="mb-3">
                        <label for="borrowerName" class="form-label fw-bold text-muted small">Borrower Name <span class="text-danger">*</span></label>
                        <input type="text" name="borrower_name" id="borrowerName" class="form-control rounded-pill px-3"
                            placeholder="e.g. John Doe" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-4">
                            <label for="lendingCurrency" class="form-label fw-bold text-muted small">Currency</label>
                            <select name="currency" id="lendingCurrency" class="form-select rounded-pill px-3">
                                <option value="AED">AED</option>
                                <option value="INR">INR</option>
                            </select>
                        </div>
                        <div class="col-8">
                            <label for="lendingAmount" class="form-label fw-bold text-muted small">Amount <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="amount" id="lendingAmount" class="form-control rounded-pill px-3"
                                placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="lentDate" class="form-label fw-bold text-muted small">Lent Date <span class="text-danger">*</span></label>
                        <input type="date" name="lent_date" id="lentDate" class="form-control rounded-pill px-3"
                            value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="dueDate" class="form-label fw-bold text-muted small">Expected Repayment (Optional)</label>
                        <input type="date" name="due_date" id="dueDate" class="form-control rounded-pill px-3">
                    </div>

                    <div class="mb-4">
                        <label for="lendingNotes" class="form-label fw-bold text-muted small">Notes / Description</label>
                        <textarea name="notes" id="lendingNotes" class="form-control rounded-4 p-3" rows="2"
                            placeholder="Reason or repayment agreements..."></textarea>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-bold py-2.5 rounded-pill shadow-sm hover-lift">
                            Record Loan <i class="fa-solid fa-check ms-1"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editLendingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel-premium border-0 shadow-lg p-0">
            <div class="modal-header border-bottom border-light p-4">
                <h5 class="modal-title fw-bold text-dark">Edit Lending Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="edit_lending">
                    <input type="hidden" name="id" id="editLendingId">

                    <div class="mb-3">
                        <label for="editBorrowerName" class="form-label fw-bold text-muted small">Borrower Name <span class="text-danger">*</span></label>
                        <input type="text" name="borrower_name" id="editBorrowerName" class="form-control rounded-pill px-3"
                            placeholder="e.g. John Doe" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-4">
                            <label for="editLendingCurrency" class="form-label fw-bold text-muted small">Currency</label>
                            <select name="currency" id="editLendingCurrency" class="form-select rounded-pill px-3">
                                <option value="AED">AED</option>
                                <option value="INR">INR</option>
                            </select>
                        </div>
                        <div class="col-8">
                            <label for="editLendingAmount" class="form-label fw-bold text-muted small">Amount <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="amount" id="editLendingAmount" class="form-control rounded-pill px-3"
                                placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="editLentDate" class="form-label fw-bold text-muted small">Lent Date <span class="text-danger">*</span></label>
                        <input type="date" name="lent_date" id="editLentDate" class="form-control rounded-pill px-3" required>
                    </div>

                    <div class="mb-3">
                        <label for="editDueDate" class="form-label fw-bold text-muted small">Expected Repayment (Optional)</label>
                        <input type="date" name="due_date" id="editDueDate" class="form-control rounded-pill px-3">
                    </div>

                    <div class="mb-4">
                        <label for="editLendingNotes" class="form-label fw-bold text-muted small">Notes / Description</label>
                        <textarea name="notes" id="editLendingNotes" class="form-control rounded-4 p-3" rows="2"
                            placeholder="Reason or repayment agreements..."></textarea>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-bold py-2.5 rounded-pill shadow-sm hover-lift">
                            Update Loan <i class="fa-solid fa-check ms-1"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-panel-premium border-0 shadow-lg">
            <div class="modal-body text-center p-4">
                <div class="rounded-circle bg-danger bg-opacity-10 text-danger p-3 d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                    <i class="fa-solid fa-trash fa-2x"></i>
                </div>
                <h5 class="fw-bold mb-2 text-dark">Delete Record?</h5>
                <p id="deleteLendingMsg" class="text-muted small mb-4">This action cannot be undone.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="delete_lending">
                    <input type="hidden" name="id" id="deleteModalId">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light rounded-pill w-100 py-2" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-pill w-100 py-2">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Pay Confirmation Modal -->
<div class="modal fade" id="payConfirmModal" tabindex="-1" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-panel-premium border-0 shadow-lg">
            <div class="modal-body text-center p-4">
                <div class="rounded-circle bg-success bg-opacity-10 text-success p-3 d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                    <i class="fa-solid fa-circle-check fa-2x"></i>
                </div>
                <h5 class="fw-bold mb-2 text-dark">Confirm Recovery?</h5>
                <p class="text-muted small mb-4">Has <span id="payModalName" class="fw-bold text-dark"></span> fully returned this balance?</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="mark_paid">
                    <input type="hidden" name="id" id="payModalId">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light rounded-pill w-100 py-2" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success rounded-pill w-100 py-2">Mark Settled</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Action Floating Bar -->
<div id="bulkActionBar"
    class="position-fixed bottom-0 start-50 translate-middle-x mb-4 shadow-lg glass-panel-premium py-3 px-4 d-none animate__animated animate__fadeInUp"
    style="z-index: 1050; border-radius: 50px; min-width: 420px; background: rgba(255,255,255,0.95); backdrop-filter: blur(16px); border: 1px solid rgba(0,0,0,0.1);">
    <div class="d-flex align-items-center justify-content-between gap-4">
        <div class="text-nowrap fw-bold text-dark">
            <span id="selectedCount" class="badge bg-primary rounded-circle me-1" style="width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px;">0</span> Selected
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success btn-sm rounded-pill px-3 shadow-sm hover-lift" data-onclick="bulkAction" data-args="<?php echo Html::args('paid'); ?>">
                <i class="fa-solid fa-check me-1"></i> Settle Loans
            </button>
            <button class="btn btn-danger btn-sm rounded-pill px-3 shadow-sm hover-lift" data-onclick="bulkAction" data-args="<?php echo Html::args('delete'); ?>">
                <i class="fa-solid fa-trash me-1"></i> Delete
            </button>
            <button class="btn btn-link btn-sm text-muted text-decoration-none" data-onclick="deselectAll">Cancel</button>
        </div>
    </div>
</div>

<form id="bulkActionForm" method="POST" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
    <input type="hidden" name="action" id="bulkActionType">
    <div id="bulkActionIds"></div>
</form>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    document.addEventListener('DOMContentLoaded', function () {
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

        rowCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateBulkBar);
        });
    });

    function deselectAll() {
        document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('bulkActionBar').classList.add('d-none');
    }

    function bulkAction(type) {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        if (checked.length === 0) return;

        const label = type === 'paid' ? 'mark as paid' : 'delete';
        window.askConfirm(`Are you sure you want to ${label} ${checked.length} selected records?`, type === 'paid' ? 'Mark Paid' : 'Delete', function () {
            const form = document.getElementById('bulkActionForm');
            document.getElementById('bulkActionType').value = 'bulk_' + type + '_lending';

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
        });
    }

    function openDeleteModal(id, name, amount, curr) {
        document.getElementById('deleteModalId').value = id;
        const msg = document.getElementById('deleteLendingMsg');
        msg.textContent = 'Delete lending record for ';
        const strong = document.createElement('strong');
        strong.textContent = name;
        msg.appendChild(strong);
        msg.appendChild(document.createTextNode(` (${curr} ${amount})?`));
        msg.appendChild(document.createElement('br'));
        const warn = document.createElement('span');
        warn.className = 'text-danger small';
        warn.textContent = 'This cannot be undone.';
        msg.appendChild(warn);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteConfirmModal')).show();
    }

    function openPayModal(id, name) {
        document.getElementById('payModalId').value = id;
        document.getElementById('payModalName').innerText = name;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('payConfirmModal')).show();
    }

    function openEditModal(id, name, amount, curr, lentDate, dueDate, notes) {
        document.getElementById('editLendingId').value = id;
        document.getElementById('editBorrowerName').value = name;
        document.getElementById('editLendingAmount').value = amount;
        document.getElementById('editLendingCurrency').value = curr;
        document.getElementById('editLentDate').value = lentDate;
        document.getElementById('editDueDate').value = dueDate;
        document.getElementById('editLendingNotes').value = notes;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editLendingModal')).show();
    }
</script>

<?php Layout::footer(); ?>
