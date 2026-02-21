<?php
$page_title = "Lending Tracker";
require_once 'config.php';

// Auth Check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}


// Handle Actions (Must be before outputting any HTML)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: lending_tracker.php?error=Unauthorized: Read-only access");
        exit();
    }

    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add_lending') {
            $name = trim($_POST['borrower_name']);
            $amount = floatval($_POST['amount']);
            $currency = $_POST['currency'] ?? 'AED';
            $lent_date = $_POST['lent_date'];
            $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $notes = trim($_POST['notes']);

            if (!empty($name) && $amount > 0) {
                $stmt = $pdo->prepare("INSERT INTO lending_tracker (user_id, tenant_id, borrower_name, amount, currency, lent_date, due_date, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], $name, $amount, $currency, $lent_date, $due_date, $notes]);
                header("Location: lending_tracker.php?success=Record Added");
                exit;
            }
        } elseif ($_POST['action'] == 'mark_paid') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if ($id) {
                // Update status to Paid
                $stmt = $pdo->prepare("UPDATE lending_tracker SET status = 'Paid' WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$id, $_SESSION['tenant_id']]);

                header("Location: lending_tracker.php?success=Marked as Paid");
                exit;
            }
        } elseif ($_POST['action'] == 'delete_lending') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if ($id) {
                $stmt = $pdo->prepare("DELETE FROM lending_tracker WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$id, $_SESSION['tenant_id']]);

                // Reset IDs (Optional, harmless for this app)
                $pdo->exec("SET @count = 0");
                $pdo->exec("UPDATE lending_tracker SET id = @count:= @count + 1");
                $pdo->exec("ALTER TABLE lending_tracker AUTO_INCREMENT = 1");

                header("Location: lending_tracker.php?success=Record Deleted");
                exit;
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
            header("Location: lending_tracker.php?success=Bulk Deleted");
            exit;
        } elseif ($_POST['action'] == 'bulk_paid_lending') {
            if (isset($_POST['ids']) && is_array($_POST['ids'])) {
                $ids = array_map('intval', $_POST['ids']);
                if (!empty($ids)) {
                    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                    $stmt = $pdo->prepare("UPDATE lending_tracker SET status = 'Paid' WHERE id IN ($placeholders) AND tenant_id = ?");
                    $stmt->execute(array_merge($ids, [$_SESSION['tenant_id']]));
                }
            }
            header("Location: lending_tracker.php?success=Records Marked as Paid");
            exit;
        }
    }
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';

// Fetch Logic
$filter_status = $_GET['status'] ?? 'Pending';
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

// Summary (Convert INR to AED for Display Stats approx / 24)
$stmt = $pdo->prepare("SELECT 
    SUM(CASE 
        WHEN status = 'Pending' AND currency = 'INR' THEN amount / 24 
        WHEN status = 'Pending' THEN amount 
        ELSE 0 
    END) as pending_total,
    SUM(CASE 
        WHEN status = 'Paid' AND currency = 'INR' THEN amount / 24 
        WHEN status = 'Paid' THEN amount 
        ELSE 0 
    END) as paid_total
    FROM lending_tracker WHERE tenant_id = ?");
$stmt->execute([$_SESSION['tenant_id']]);
$summary = $stmt->fetch();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">Money Lending Tracker</h1>
        <p class="text-muted mb-0">Track who owes you money.</p>
    </div>
    <div class="text-end">
        <div class="d-flex gap-2">
            <a href="export_actions.php?action=export_lending" class="btn btn-outline-secondary">
                <i class="fa-solid fa-file-csv me-1"></i> Export
            </a>
            <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addLendingModal">
                    <i class="fa-solid fa-plus me-2"></i> New Record
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-sm-6">
            <div class="glass-panel p-4 position-relative overflow-hidden">
                <div class="position-absolute top-0 end-0 opacity-10 p-3">
                    <i class="fa-solid fa-clock-rotate-left fa-3x text-warning"></i>
                </div>
                <h5 class="fw-bold text-muted mb-1">Pending Collection</h5>
                <h3 class="fw-bold text-warning mb-0">AED <span
                        class="blur-sensitive"><?php echo number_format($summary['pending_total'], 2); ?></span></h3>
                <div class="x-small text-muted mt-1">(Approx. consolidated in AED)</div>
            </div>
        </div>
        <div class="col-12 col-sm-6">
            <div class="glass-panel p-4 position-relative overflow-hidden">
                <div class="position-absolute top-0 end-0 opacity-10 p-3">
                    <i class="fa-solid fa-check-circle fa-3x text-success"></i>
                </div>
                <h5 class="fw-bold text-muted mb-1">Recovered</h5>
                <h3 class="fw-bold text-success mb-0">AED <span
                        class="blur-sensitive"><?php echo number_format($summary['paid_total'], 2); ?></span></h3>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <ul class="nav nav-pills mb-3" id="pills-tab">
        <li class="nav-item">
            <a class="nav-link <?php echo $filter_status == 'Pending' ? 'active' : ''; ?>"
                href="?status=Pending">Pending</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter_status == 'Paid' ? 'active' : ''; ?>"
                href="?status=Paid">Completed</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter_status == 'All' ? 'active' : ''; ?>" href="?status=All">All
                Records</a>
        </li>
    </ul>

    <!-- Records List -->
    <?php if (empty($records)): ?>
        <div class="text-center py-5 glass-panel">
            <div class="mb-3 text-muted opacity-25">
                <i class="fa-solid fa-hand-holding-dollar fa-3x"></i>
            </div>
            <h5 class="text-muted">No records found.</h5>
            <p class="text-muted small">Lent money to someone? Add it here to track it.</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($records as $r): ?>
                <?php
                // Color Logic
                $is_overdue = ($r['status'] == 'Pending' && !empty($r['due_date']) && strtotime($r['due_date']) < time());
                $border_class = $r['status'] == 'Paid' ? 'border-success' : ($is_overdue ? 'border-danger' : 'border-warning');
                $curr = $r['currency'] ?? 'AED';
                ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="glass-panel p-3 h-100 position-relative border-start border-4 <?php echo $border_class; ?>">
                        <div class="position-absolute top-0 end-0 m-2">
                            <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                <input type="checkbox" class="form-check-input row-checkbox" value="<?php echo $r['id']; ?>">
                            <?php endif; ?>
                        </div>
                        <div class="d-flex justify-content-between align-items-start mb-2 pe-4">
                            <div>
                                <h5 class="fw-bold mb-0"><?php echo htmlspecialchars($r['borrower_name']); ?></h5>
                                <div class="small text-muted">
                                    Lent: <?php echo date('M d, Y', strtotime($r['lent_date'])); ?>
                                </div>
                            </div>
                            <div class="text-end">
                                <h5 class="fw-bold text-primary mb-0">
                                    <?php echo $curr; ?> <span
                                        class="blur-sensitive"><?php echo number_format($r['amount'], 2); ?></span>
                                </h5>
                                <?php if ($r['status'] == 'Pending'): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis">Pending</span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success">Paid</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($r['notes'])): ?>
                            <p class="small text-muted bg-light p-2 rounded mb-2 fst-italic">
                                "<?php echo htmlspecialchars($r['notes']); ?>"
                            </p>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                            <div class="small fw-bold <?php echo $is_overdue ? 'text-danger' : 'text-muted'; ?>">
                                <?php if ($r['status'] == 'Paid'): ?>
                                    <i class="fa-solid fa-check me-1"></i> Recovered
                                <?php elseif (!empty($r['due_date'])): ?>
                                    <i class="fa-solid fa-calendar-check me-1"></i> Due:
                                    <?php echo date('M d, Y', strtotime($r['due_date'])); ?>
                                    <?php echo $is_overdue ? '(Overdue)' : ''; ?>
                                <?php else: ?>
                                    <i class="fa-solid fa-infinity me-1"></i> No Due Date
                                <?php endif; ?>
                            </div>

                            <div class="btn-group">
                                <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                    <?php if ($r['status'] == 'Pending'): ?>
                                        <button type="button" class="btn btn-sm btn-success"
                                            onclick="openPayModal(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars($r['borrower_name']); ?>')">
                                            <i class="fa-solid fa-check"></i> Paid
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger border-0"
                                        onclick="openDeleteModal(<?php echo $r['id']; ?>, '<?php echo addslashes(htmlspecialchars($r['borrower_name'])); ?>', '<?php echo number_format($r['amount'], 2); ?>', '<?php echo $curr; ?>')">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <i class="fa-solid fa-lock text-muted small" title="Read Only"></i>
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
            <div class="modal-content glass-panel border-0">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Add Lending Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="add_lending">

                        <div class="mb-3">
                            <label class="form-label">Borrower Name <span class="text-danger">*</span></label>
                            <input type="text" name="borrower_name" class="form-control" placeholder="e.g. John Doe"
                                required>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-4">
                                <label class="form-label">Currency</label>
                                <select name="currency" class="form-select">
                                    <option value="AED">AED</option>
                                    <option value="INR">INR</option>
                                </select>
                            </div>
                            <div class="col-8">
                                <label class="form-label">Amount <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00"
                                    required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Lent Date <span class="text-danger">*</span></label>
                            <input type="date" name="lent_date" class="form-control"
                                value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Expected Repayment Date (Optional)</label>
                            <input type="date" name="due_date" class="form-control">
                            <div class="form-text">Leave empty if indefinite.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"
                                placeholder="Any details..."></textarea>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary fw-bold">Save Record</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content glass-panel border-0">
                <div class="modal-body text-center p-4">
                    <div class="mb-3 text-danger opacity-75">
                        <i class="fa-solid fa-trash-can fa-3x"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Delete Record?</h5>
                    <p id="deleteLendingMsg" class="text-muted small mb-4">This action cannot be undone.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="delete_lending">
                        <input type="hidden" name="id" id="deleteModalId">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-danger fw-bold">Yes, Delete It</button>
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Pay Confirmation Modal -->
    <div class="modal fade" id="payConfirmModal" tabindex="-1" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content glass-panel border-0">
                <div class="modal-body text-center p-4">
                    <div class="mb-3 text-success opacity-75">
                        <i class="fa-solid fa-circle-check fa-3x"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Mark as Paid?</h5>
                    <p class="text-muted small mb-4">Confirm that <span id="payModalName" class="fw-bold"></span> has
                        returned the money.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="mark_paid">
                        <input type="hidden" name="id" id="payModalId">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success fw-bold">Yes, Fully Paid</button>
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php require_once 'includes/footer.php'; ?>

    <!-- Bulk Action Floating Bar -->
    <div id="bulkActionBar"
        class="position-fixed bottom-0 start-50 translate-middle-x mb-4 shadow-lg glass-panel p-3 d-none animate__animated animate__fadeInUp"
        style="z-index: 1050; border-radius: 50px; min-width: 400px;">
        <div class="d-flex align-items-center justify-content-between gap-4 px-2">
            <div class="text-nowrap fw-bold">
                <span id="selectedCount">0</span> Selected
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-success btn-sm rounded-pill px-3" onclick="bulkAction('paid')">
                    <i class="fa-solid fa-check me-1"></i> Mark Paid
                </button>
                <button class="btn btn-danger btn-sm rounded-pill px-3" onclick="bulkAction('delete')">
                    <i class="fa-solid fa-trash me-1"></i> Delete
                </button>
                <button class="btn btn-link btn-sm text-muted" onclick="deselectAll()">Cancel</button>
            </div>
        </div>
    </div>

    <form id="bulkActionForm" method="POST" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
        <input type="hidden" name="action" id="bulkActionType">
        <div id="bulkActionIds"></div>
    </form>

    <script>
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

            if (confirm(`Are you sure you want to ${type} ${checked.length} selected records?`)) {
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
            }
        }

        function openDeleteModal(id, name, amount, curr) {
            document.getElementById('deleteModalId').value = id;
            document.getElementById('deleteLendingMsg').innerHTML = `Delete lending record for <strong>${name}</strong> (${curr} ${amount})? <br><span class="text-danger small">This cannot be undone.</span>`;
            new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
        }

        function openPayModal(id, name) {
            document.getElementById('payModalId').value = id;
            document.getElementById('payModalName').innerText = name;
            new bootstrap.Modal(document.getElementById('payConfirmModal')).show();
        }
    </script>
