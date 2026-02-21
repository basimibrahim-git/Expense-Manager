<?php
$page_title = "Monthly Incentives";
include_once 'config.php';

// Handle Actions (Add/Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: monthly_incentives.php?error=Unauthorized: Read-only access");
        exit();
    }

    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add_incentive') {
            $month = $_POST['month'];
            $year = $_POST['year'];
            $title = $_POST['title'];
            $amount = $_POST['amount'];
            $status = $_POST['status'] ?? 'pending';

            $stmt = $pdo->prepare("INSERT INTO monthly_incentives (tenant_id, month, year, title, amount, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['tenant_id'], $month, $year, $title, $amount, $status]);

            log_audit('add_incentive', "Added incentive: $title (AED $amount)");
            header("Location: monthly_incentives.php?month=$month&year=$year&success=Incentive added");
            exit();
        } elseif ($_POST['action'] == 'delete_incentive') {
            $id = $_POST['id'];
            $month = $_POST['month'];
            $year = $_POST['year'];

            $stmt = $pdo->prepare("DELETE FROM monthly_incentives WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);

            log_audit('delete_incentive', "Deleted incentive ID: $id");
            header("Location: monthly_incentives.php?month=$month&year=$year&success=Incentive deleted");
            exit();
        }
    }
}

include_once 'includes/header.php';
include_once 'includes/sidebar.php';

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');

// Fetch Incentives
$stmt = $pdo->prepare("SELECT * FROM monthly_incentives WHERE tenant_id = ? AND month = ? AND year = ? ORDER BY id DESC");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$incentives = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_incentives = array_sum(array_column($incentives, 'amount'));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">Incentives Tracker</h1>
        <p class="text-muted mb-0">Manage bonuses and extra income for
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
                <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#addIncentiveModal">
                    <i class="fa-solid fa-plus me-2"></i> Add Incentive
                </button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="glass-panel p-4 h-100 border-start border-4 border-success">
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Total Incentives</h6>
            <h3 class="fw-bold text-success mb-0">AED <span class="blur-sensitive">
                    <?php echo number_format($total_incentives, 2); ?>
                </span></h3>
            <p class="text-muted small mb-0 mt-2">Extra earnings for this month</p>
        </div>
    </div>
</div>

<div class="glass-panel p-0 overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th class="ps-4">Title</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Added On</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($incentives)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-gift fa-3x mb-3 d-block opacity-25"></i>
                                No incentives recorded for this period.
                            </td>
                        </tr>
                <?php else: ?>
                        <?php foreach ($incentives as $incentive): ?>
                                <tr>
                                    <td class="ps-4 fw-bold">
                                        <?php echo htmlspecialchars($incentive['title']); ?>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-success">AED
                                            <?php echo number_format($incentive['amount'], 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span
                                            class="badge rounded-pill <?php echo $incentive['status'] == 'received' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning'; ?>">
                                            <?php echo ucfirst($incentive['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-muted small">
                                        <?php echo date('d M Y', strtotime($incentive['created_at'])); ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                                <button class="btn btn-sm btn-outline-danger border-0"
                                                    onclick="confirmDelete(<?php echo $incentive['id']; ?>, '<?php echo addslashes(htmlspecialchars($incentive['title'])); ?>', '<?php echo $incentive['amount']; ?>')">
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

<!-- Add Incentive Modal -->
<div class="modal fade" id="addIncentiveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Add New Incentive</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_incentive">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">

                    <div class="mb-3">
                        <label for="incentiveTitle" class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="incentiveTitle" class="form-control" placeholder="e.g. Sales Bonus" required>
                    </div>
                    <div class="mb-3">
                        <label for="incentiveAmount" class="form-label">Amount (AED) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" id="incentiveAmount" class="form-control" placeholder="0.00" required>
                    </div>
                    <div class="mb-4">
                        <label for="incentiveStatus" class="form-label">Status</label>
                        <select name="status" id="incentiveStatus" class="form-select">
                            <option value="received">Received</option>
                            <option value="pending" selected>Pending</option>
                        </select>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-bold py-2">Save Incentive</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteIncentiveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-panel border-0">
            <div class="modal-body p-4 text-center">
                <i class="fa-solid fa-circle-exclamation text-danger fa-3x mb-3"></i>
                <h5 class="fw-bold mb-2">Delete Record?</h5>
                <p class="text-muted small" id="deleteIncentiveMsg"></p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_incentive">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <input type="hidden" name="id" id="deleteIncentiveId">
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
        document.getElementById('deleteIncentiveId').value = id;
        document.getElementById('deleteIncentiveMsg').innerHTML = `Are you sure you want to delete <strong>${title}</strong> (AED ${amount})? <br><span class="text-danger small">This cannot be undone.</span>`;
        new bootstrap.Modal(document.getElementById('deleteIncentiveModal')).show();
    }
</script>

<?php include_once 'includes/footer.php'; ?>