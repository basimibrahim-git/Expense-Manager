<?php
$page_title = "Monthly Incentives";
include_once 'config.php';

// Handle Actions (Add/Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: monthly_incentives.php?month=" . date('n', strtotime($_POST['incentive_date'] ?? 'now')) . "&year=" . date('Y', strtotime($_POST['incentive_date'] ?? 'now')) . "&error=Unauthorized: Read-only access");
        exit();
    }

    if ($_POST['action'] == 'add_incentive') {
        $title = $_POST['title'];
        $amount = floatval($_POST['amount']);
        $date = $_POST['incentive_date'];

        if ($amount > 0 && !empty($title) && !empty($date)) {
            $stmt = $pdo->prepare("INSERT INTO company_incentives (user_id, tenant_id, title, amount, incentive_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], $title, $amount, $date]);

            $month = date('n', strtotime($date));
            $year = date('Y', strtotime($date));
            header("Location: monthly_incentives.php?month=$month&year=$year&success=Incentive Added");
            exit;
        }
    } elseif ($_POST['action'] == 'delete_incentive') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM company_incentives WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);
        }

        header("Location: monthly_incentives.php?month=$month&year=$year&success=Deleted");
        exit;
    } elseif ($_POST['action'] == 'bulk_delete_incentive') {
        if (isset($_POST['ids']) && is_array($_POST['ids'])) {
            $ids = array_map('intval', $_POST['ids']);
            if (!empty($ids)) {
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $stmt = $pdo->prepare("DELETE FROM company_incentives WHERE id IN ($placeholders) AND tenant_id = ?");
                $stmt->execute(array_merge($ids, [$_SESSION['tenant_id']]));
            }
        }
        header("Location: monthly_incentives.php?month=$month&year=$year&success=Bulk Deleted");
        exit;
    }
}

include_once 'includes/header.php';
include_once 'includes/sidebar.php';

$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');

$month_name = date("F", mktime(0, 0, 0, $month, 10));

// Navigation Logic
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year--;
}

$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}

// Fetch Incentives
$stmt = $pdo->prepare("SELECT * FROM company_incentives WHERE tenant_id = ? AND MONTH(incentive_date) = ? AND YEAR(incentive_date) = ? ORDER BY incentive_date DESC");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$incentives = $stmt->fetchAll();

// Calculate Total
$total_incentives = 0;
foreach ($incentives as $inc) {
    $total_incentives += $inc['amount'];
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="company_tracker.php?year=<?php echo htmlspecialchars($year); ?>"
            class="text-decoration-none text-muted small mb-1">
            <i class="fa-solid fa-arrow-left"></i> Back to Year
        </a>
        <h1 class="h3 fw-bold mb-0">
            <?php echo $month_name . ' ' . htmlspecialchars($year); ?>
        </h1>
    </div>
    <div class="text-end">
        <div class="small text-muted">Total Incentives</div>
        <h3 class="fw-bold text-success mb-0">AED <span
                class="blur-sensitive"><?php echo number_format($total_incentives, 2); ?></span></h3>
    </div>
</div>

<!-- Navigation & Actions -->
<div class="glass-panel p-3 mb-4 d-flex justify-content-between align-items-center">
    <div class="btn-group">
        <a href="?month=<?php echo htmlspecialchars($prev_month); ?>&year=<?php echo htmlspecialchars($prev_year); ?>"
            class="btn btn-outline-light text-dark"><i class="fa-solid fa-chevron-left"></i></a>
        <span class="btn btn-light fw-bold px-3" style="cursor: default; min-width: 140px;">
            <?php echo htmlspecialchars($month_name . ' ' . $year); ?>
        </span>
        <a href="?month=<?php echo htmlspecialchars($next_month); ?>&year=<?php echo htmlspecialchars($next_year); ?>"
            class="btn btn-outline-light text-dark"><i class="fa-solid fa-chevron-right"></i></a>
    </div>

    <div class="d-flex gap-2">
        <a href="export_actions.php?action=export_incentives&month=<?php echo $month; ?>&year=<?php echo $year; ?>"
            class="btn btn-outline-secondary">
            <i class="fa-solid fa-file-csv me-1"></i> Export
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addIncentiveModal">
            <i class="fa-solid fa-plus me-2"></i> Add Incentive
        </button>
    </div>
</div>

<!-- List View -->
<?php if (empty($incentives)): ?>
    <div class="text-center py-5">
        <div class="mb-3">
            <i class="fa-solid fa-briefcase fa-3x text-muted opacity-25"></i>
        </div>
        <h5 class="text-muted">No incentives found for this month.</h5>
        <p class="text-muted small">Add your first incentive to start tracking.</p>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4 py-3" style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                        </th>
                        <th class="py-3">Date</th>
                        <th>Description</th>
                        <th class="text-end pe-4">Amount</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($incentives as $r): ?>
                        <tr>
                            <td class="ps-4">
                                <input type="checkbox" class="form-check-input row-checkbox" value="<?php echo $r['id']; ?>">
                            </td>
                            <td class="fw-bold">
                                <?php echo date('d', strtotime($r['incentive_date'])); ?>
                                <span class="small text-muted fw-normal d-block">
                                    <?php echo date('D', strtotime($r['incentive_date'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($r['title']); ?>
                            </td>
                            <td class="text-end pe-4 fw-bold text-primary">
                                AED <span class="blur-sensitive"><?php echo number_format($r['amount'], 2); ?></span>
                            </td>
                            <td class="text-end pe-3">
                                <button type="button" class="btn btn-sm text-danger"
                                    onclick="confirmDeleteIncentive(<?php echo $r['id']; ?>, '<?php echo addslashes(htmlspecialchars($r['title'])); ?>', '<?php echo number_format($r['amount'], 2); ?>')"
                                    title="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Add Incentive Modal -->
<div class="modal fade" id="addIncentiveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Add Incentive</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_incentive">

                    <div class="mb-3">
                        <label for="incentiveTitle" class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="incentiveTitle" class="form-control"
                            placeholder="e.g. Sales Bonus" required>
                    </div>

                    <div class="mb-3">
                        <label for="incentiveAmount" class="form-label">Amount <span
                                class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" id="incentiveAmount" class="form-control"
                            placeholder="0.00" required>
                    </div>

                    <div class="mb-3">
                        <label for="incentiveDate" class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="incentive_date" id="incentiveDate" class="form-control"
                            value="<?php echo htmlspecialchars(date('Y') . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . date('d')); ?>"
                            required>

                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-success fw-bold">Save Incentive</button>
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
            <div class="modal-body text-center py-4">
                <i class="fa-solid fa-trash-can text-danger fa-3x mb-3"></i>
                <h5 class="fw-bold">Delete Incentive?</h5>
                <p id="deleteIncentiveMsg" class="text-muted small">This cannot be undone.</p>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_incentive">
                    <input type="hidden" name="id" id="deleteIncentiveId">

                    <div class="d-flex justify-content-center gap-2 mt-3">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once 'includes/footer.php'; ?>


<!-- Bulk Action Floating Bar -->
<div id="bulkActionBar"
    class="position-fixed bottom-0 start-50 translate-middle-x mb-4 shadow-lg glass-panel p-3 d-none animate__animated animate__fadeInUp"
    style="z-index: 1050; border-radius: 50px; min-width: 300px;">
    <div class="d-flex align-items-center justify-content-between gap-4 px-2">
        <div class="text-nowrap fw-bold">
            <span id="selectedCount">0</span> Selected
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-danger btn-sm rounded-pill px-3" onclick="bulkAction('delete')">
                <i class="fa-solid fa-trash me-1"></i> Delete
            </button>
            <button class="btn btn-link btn-sm text-muted" onclick="deselectAll()">Cancel</button>
        </div>
    </div>
</div>

<form id="bulkActionForm" action="monthly_incentives.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>"
    method="POST" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
    <input type="hidden" name="action" id="bulkActionType">
    <div id="bulkActionIds"></div>
</form>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const selectAll = document.getElementById('selectAll');
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

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                rowCheckboxes.forEach(cb => cb.checked = selectAll.checked);
                updateBulkBar();
            });
        }

        rowCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateBulkBar);
        });
    });

    function deselectAll() {
        document.getElementById('selectAll').checked = false;
        document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('bulkActionBar').classList.add('d-none');
    }

    function bulkAction(type) {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        if (checked.length === 0) return;

        if (confirm(`Are you sure you want to delete ${checked.length} selected incentives?`)) {
            const form = document.getElementById('bulkActionForm');
            document.getElementById('bulkActionType').value = 'bulk_' + type + '_incentive';

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

    function confirmDeleteIncentive(id, title, amount) {
        document.getElementById('deleteIncentiveId').value = id;
        document.getElementById('deleteIncentiveMsg').innerHTML = `Are you sure you want to delete <strong>${title}</strong> (AED ${amount})? <br><span class="text-danger small">This cannot be undone.</span>`;
        new bootstrap.Modal(document.getElementById('deleteIncentiveModal')).show();
    }
</script>