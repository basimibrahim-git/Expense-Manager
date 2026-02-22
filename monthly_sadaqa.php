<?php
$page_title = "Monthly Sadaqa";
include_once 'config.php'; // NOSONAR

// Handle Actions (Add/Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: monthly_sadaqa.php?error=Unauthorized: Read-only access");
        exit();
    }

    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add_sadaqa') {
            $month = $_POST['month'];
            $year = $_POST['year'];
            $title = $_POST['title'];
            $amount = $_POST['amount'];
            $category = $_POST['category'] ?? 'General';
            $date = date('Y-m-d');

            $stmt = $pdo->prepare("INSERT INTO monthly_sadaqa (tenant_id, month, year, title, amount, category, record_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['tenant_id'], $month, $year, $title, $amount, $category, $date]);

            log_audit('add_sadaqa', "Added sadaqa: $title (AED $amount)");
            header("Location: monthly_sadaqa.php?month=$month&year=$year&success=Sadaqa added");
            exit();
        } elseif ($_POST['action'] == 'delete_sadaqa') {
            $id = $_POST['id'];
            $month = $_POST['month'];
            $year = $_POST['year'];

            $stmt = $pdo->prepare("DELETE FROM monthly_sadaqa WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);

            log_audit('delete_sadaqa', "Deleted sadaqa ID: $id");
            header("Location: monthly_sadaqa.php?month=$month&year=$year&success=Sadaqa deleted");
            exit();
        }
    }
}

include_once 'includes/header.php'; // NOSONAR
include_once 'includes/sidebar.php'; // NOSONAR

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');

// Fetch Records
$stmt = $pdo->prepare("SELECT * FROM monthly_sadaqa WHERE tenant_id = ? AND month = ? AND year = ? ORDER BY record_date ASC");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_sadaqa = array_sum(array_column($records, 'amount'));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">Sadaqa Tracker</h1>
        <p class="text-muted mb-0">Track charitable donations for
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
            <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#addSadaqaModal">
                <i class="fa-solid fa-plus me-2"></i> Add Sadaqa
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="glass-panel p-4 h-100 border-start border-4 border-success">
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Total Sadaqa</h6>
            <h3 class="fw-bold text-success mb-0">AED <span class="blur-sensitive">
                    <?php echo number_format($total_sadaqa, 2); ?>
                </span></h3>
            <p class="text-muted small mb-0 mt-2">Personal donations for this month</p>
        </div>
    </div>
</div>

<div class="glass-panel p-0 overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th class="ps-4">Description</th>
                    <th>Category</th>
                    <th>Amount</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="4" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-heart fa-3x mb-3 d-block opacity-25"></i>
                            No sadaqa entries recorded for this period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td class="ps-4 fw-bold">
                                <?php echo htmlspecialchars($r['title']); ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">
                                    <?php echo htmlspecialchars($r['category'] ?? 'General'); ?>
                                </span>
                            </td>
                            <td>
                                <span class="fw-bold text-success">AED
                                    <?php echo number_format($r['amount'], 2); ?>
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

<!-- Add Sadaqa Modal -->
<div class="modal fade" id="addSadaqaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Add New Sadaqa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_sadaqa">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">

                    <div class="mb-3">
                        <label for="sadaqaTitle" class="form-label">Description <span
                                class="text-danger">*</span></label>
                        <input type="text" name="title" id="sadaqaTitle" class="form-control"
                            placeholder="e.g. Masjid Donation" required>
                    </div>
                    <div class="mb-3">
                        <label for="sadaqaCategory" class="form-label">Category</label>
                        <select name="category" id="sadaqaCategory" class="form-select">
                            <option value="General">General</option>
                            <option value="Masjid">Masjid</option>
                            <option value="Education">Education</option>
                            <option value="Poor/Needy">Poor/Needy</option>
                            <option value="Family">Family</option>
                            <option value="Emergency">Emergency</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="sadaqaAmount" class="form-label">Amount (AED) <span
                                class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" id="sadaqaAmount" class="form-control"
                            placeholder="0.00" required>
                    </div>
                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary fw-bold py-2">Save Sadaqa</button>
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
            <span class="fw-bold">Selected</span>
        </div>
        <div class="d-flex gap-2">
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-primary dropdown-toggle rounded-pill" type="button"
                    data-bs-toggle="dropdown">
                    Change Category
                </button>
                <ul class="dropdown-menu border-0 shadow">
                    <li><button class="dropdown-item" type="button" onclick="submitBulkChange('Masjid')">Masjid</button>
                    </li>
                    <li><button class="dropdown-item" type="button"
                            onclick="submitBulkChange('Poor/Needy')">Poor/Needy</button></li>
                    <li><button class="dropdown-item" type="button" onclick="submitBulkChange('Family')">Family</button>
                    </li>
                    <li><button class="dropdown-item" type="button"
                            onclick="submitBulkChange('General')">General</button></li>
                </ul>
            </div>
            <button type="button" class="btn btn-sm btn-light rounded-pill px-3"
                onclick="clearSelection()">Cancel</button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteSadaqaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-panel border-0">
            <div class="modal-body p-4 text-center">
                <i class="fa-solid fa-circle-exclamation text-danger fa-3x mb-3"></i>
                <h5 class="fw-bold mb-2">Delete Entry?</h5>
                <p class="text-muted small" id="deleteSadaqaMsg"></p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_sadaqa">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <input type="hidden" name="id" id="deleteSadaqaId">
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
        document.getElementById('deleteSadaqaId').value = id;
        document.getElementById('deleteSadaqaMsg').innerHTML = `Delete <strong>${title}</strong> (AED ${amount})? <br><span class="text-danger small">This cannot be undone.</span>`;
        new bootstrap.Modal(document.getElementById('deleteSadaqaModal')).show();
    }
</script>

<?php include_once 'includes/footer.php'; // NOSONAR ?>
