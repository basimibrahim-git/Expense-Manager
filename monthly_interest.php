<?php
$page_title = "Monthly Interest";
include_once 'config.php'; // NOSONAR

// Handle Actions (Add/Edit/Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: monthly_interest.php?error=Unauthorized: Read-only access");
        exit();
    }

    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add_record') {
            $month = $_POST['month'];
            $year = $_POST['year'];
            $type = $_POST['type']; // interest or payment
            $title = $_POST['title'];
            $amount = $_POST['amount'];
            $date = date('Y-m-d');

            $stmt = $pdo->prepare("INSERT INTO monthly_interest (tenant_id, month, year, type, title, amount, record_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['tenant_id'], $month, $year, $type, $title, $amount, $date]);

            // If it's a payment, we log it as an expense for the tracker too?
            // For now, interest tracker is separate.

            log_audit('interest_entry', "Added $type: $title (AED $amount)");
            header("Location: monthly_interest.php?month=$month&year=$year&success=Record added");
            exit();
        } elseif ($_POST['action'] == 'delete_record') {
            $id = $_POST['id'];
            $month = $_POST['month'];
            $year = $_POST['year'];

            $stmt = $pdo->prepare("DELETE FROM monthly_interest WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);

            log_audit('interest_delete', "Deleted interest record ID: $id");
            header("Location: monthly_interest.php?month=$month&year=$year&success=Record deleted");
            exit();
        }
    }
}

include_once 'includes/header.php'; // NOSONAR
include_once 'includes/sidebar.php'; // NOSONAR

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');

// Fetch Records
$stmt = $pdo->prepare("SELECT * FROM monthly_interest WHERE tenant_id = ? AND month = ? AND year = ? ORDER BY record_date ASC");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_interest = 0;
$total_payment = 0;
foreach ($records as $r) {
    if ($r['type'] == 'interest') {
        $total_interest += $r['amount'];
    } else {
        $total_payment += $r['amount'];
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-3">
        <div>
            <h1 class="h3 fw-bold mb-1">Interest Tracker</h1>
            <p class="text-muted mb-0">Interest vs Payments for
                <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>
            </p>
        </div>
        <div class="h3 mb-0">
            <?php if ($total_interest <= 0 && !empty($records)) {
                echo '<i class="fa-solid fa-check ms-1"></i>';
            } ?>
        </div>
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
            <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#addRecordModal">
                <i class="fa-solid fa-plus me-2"></i> Add Record
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="glass-panel p-4 h-100 border-start border-4 border-danger">
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Interest Accrued (Debt)</h6>
            <h3 class="fw-bold text-danger mb-0">AED <span class="blur-sensitive">
                    <?php echo number_format($total_interest, 2); ?>
                </span></h3>
            <p class="text-muted small mb-0 mt-2">Interest charged on credit cards/loans</p>
        </div>
    </div>
    <div class="col-md-6">
        <div class="glass-panel p-4 h-100 border-start border-4 border-success">
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Interest Payments (Charity)</h6>
            <h3 class="fw-bold text-success mb-0">AED <span class="blur-sensitive">
                    <?php echo number_format($total_payment, 2); ?>
                </span></h3>
            <p class="text-muted small mb-0 mt-2">Payments made to clear interest (donated)</p>
        </div>
    </div>
</div>

<!-- Comparison Graph or Bar -->
<div class="glass-panel p-4 mb-4">
    <h6 class="fw-bold mb-3">Balance Progress</h6>
    <?php
    $progress = ($total_interest > 0) ? ($total_payment / $total_interest) * 100 : 100;
    $progress = min(100, $progress);
    ?>
    <div class="progress" style="height: 12px;">
        <div class="progress-bar <?php echo $progress >= 100 ? 'bg-success' : 'bg-warning'; ?>"
            style="width: <?php echo $progress; ?>%"></div>
    </div>
    <div class="d-flex justify-content-between mt-2 small text-muted">
        <span>Paid: <?php echo number_format($progress, 1); ?>%</span>
        <span>Remaining: AED <?php echo number_format(max(0, $total_interest - $total_payment), 2); ?></span>
    </div>
</div>

<div class="glass-panel p-0 overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th class="ps-4">Description</th>
                    <th>Amount</th>
                    <th>Type</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="4" class="text-center py-5 text-muted">
                            No records found for this period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td class="ps-4 fw-bold">
                                <?php echo htmlspecialchars($r['title']); ?>
                            </td>
                            <td>
                                <span class="fw-bold <?php echo $r['type'] == 'interest' ? 'text-danger' : 'text-success'; ?>">
                                    AED <?php echo number_format($r['amount'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span
                                    class="badge rounded-pill <?php echo $r['type'] == 'interest' ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'; ?>">
                                    <?php echo ucfirst($r['type']); ?>
                                </span>
                            </td>
                            <td class="text-end pe-4">
                                <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                    <button class="btn btn-sm btn-outline-danger border-0"
                                        onclick="confirmDelete(<?php echo $r['id']; ?>, '<?php echo addslashes(htmlspecialchars($r['title'])); ?>', '<?php echo $r['amount']; ?>')">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
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
</div>

<!-- Add Record Modal -->
<div class="modal fade" id="addRecordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Add Interest Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_record">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">

                    <div class="mb-3">
                        <label for="recordType" class="form-label">Type</label>
                        <select name="type" id="recordType" class="form-select">
                            <option value="interest">Interest Accrued (Debt)</option>
                            <option value="payment">Payment (Charity)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="recordTitle" class="form-label">Description <span
                                class="text-danger">*</span></label>
                        <input type="text" name="title" id="recordTitle" class="form-control"
                            placeholder="e.g. Mashreq Credit Card Jan" required>
                    </div>
                    <div class="mb-3">
                        <label for="recordAmount" class="form-label">Amount (AED) <span
                                class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" id="recordAmount" class="form-control"
                            placeholder="0.00" required>
                    </div>
                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary fw-bold py-2">Save Record</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Action Floating Bar -->
<div id="bulkActionBar"
    class="position-fixed bottom-0 start-50 translate-middle-x mb-4 glass-panel p-3 border shadow-lg d-none"
    style="z-index: 1050; min-width: 400px;">
    <div class="d-flex align-items-center justify-content-between">
        <div class="me-3">
            <span id="selectedCount" class="badge bg-primary rounded-pill me-2">0</span>
            <span class="fw-bold">Selected Records</span>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3"
                onclick="submitBulkDelete()">Delete Selected</button>
            <button type="button" class="btn btn-sm btn-light rounded-pill px-3"
                onclick="clearSelection()">Cancel</button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-panel border-0">
            <div class="modal-body p-4 text-center">
                <i class="fa-solid fa-circle-exclamation text-danger fa-3x mb-3"></i>
                <h5 class="fw-bold mb-2">Delete Record?</h5>
                <p class="text-muted small" id="deleteMsg"></p>
                <form id="deleteForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_record">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <input type="hidden" name="id" id="deleteId">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light w-100" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger w-100">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function confirmDelete(id, title, amount) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteMsg').innerHTML = `Are you sure you want to delete <strong>${title}</strong> (AED ${amount})?`;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    function submitBulkDelete() {
        if (confirm('Delete all selected records? This cannot be undone.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'interest_actions.php';

            const ids = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb => cb.value);

            const inputAction = document.createElement('input');
            inputAction.type = 'hidden';
            inputAction.name = 'action';
            inputAction.value = 'bulk_delete';
            form.appendChild(inputAction);

            const inputIds = document.createElement('input');
            inputIds.type = 'hidden';
            inputIds.name = 'ids';
            inputIds.value = JSON.stringify(ids);
            form.appendChild(inputIds);

            const inputCsrf = document.createElement('input');
            inputCsrf.type = 'hidden';
            inputCsrf.name = 'csrf_token';
            inputCsrf.value = '<?php echo generate_csrf_token(); ?>';
            form.appendChild(inputCsrf);

            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

<?php include_once 'includes/footer.php'; // NOSONAR ?>