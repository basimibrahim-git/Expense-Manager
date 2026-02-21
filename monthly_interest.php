<?php
$page_title = "Monthly Interest";
include_once 'config.php';

// Handle Actions (Add/Edit/Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: monthly_interest.php?month=" . date('n', strtotime($_POST['interest_date'] ?? 'now')) . "&year=" . date('Y', strtotime($_POST['interest_date'] ?? 'now')) . "&error=Unauthorized: Read-only access");
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action == 'add_interest' || $action == 'edit_interest') {
        $title = $_POST['title'];
        $amount_input = floatval($_POST['amount']);
        $type = $_POST['type']; // 'interest' or 'payment'
        $date = $_POST['interest_date'];
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        // Calculate actual amount based on type
        // Payment = Negative, Interest = Positive
        $amount = ($type === 'payment') ? -1 * abs($amount_input) : abs($amount_input);

        if ($amount_input > 0 && !empty($title) && !empty($date)) {
            if ($action == 'add_interest') {
                $stmt = $pdo->prepare("INSERT INTO interest_tracker (user_id, tenant_id, title, amount, interest_date) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], $title, $amount, $date]);
                $msg = "Record Added";
            } else { // Edit
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE interest_tracker SET title = ?, amount = ?, interest_date = ? WHERE id = ? AND tenant_id = ?");
                    $stmt->execute([$title, $amount, $date, $id, $_SESSION['tenant_id']]);
                    $msg = "Record Updated";
                }
            }

            $month = date('n', strtotime($date));
            $year = date('Y', strtotime($date));
            // Redirect to the month of the *record* to see changes
            header("Location: monthly_interest.php?month=$month&year=$year&success=$msg");
            exit;
        }
    } elseif ($action == 'delete_interest') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM interest_tracker WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);
        }

        // Return to current view
        header("Location: monthly_interest.php?month=$month&year=$year&success=Deleted");
        exit;
    } elseif ($action == 'bulk_delete_interest' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
        $ids = array_map('intval', $_POST['ids']);
        if (!empty($ids)) {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM interest_tracker WHERE id IN ($placeholders) AND tenant_id = ?");
            $stmt->execute(array_merge($ids, [$_SESSION['tenant_id']]));
        }
        header("Location: monthly_interest.php?month=$month&year=$year&success=Bulk Deleted");
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

// Fetch Records
$stmt = $pdo->prepare("SELECT * FROM interest_tracker WHERE tenant_id = ? AND MONTH(interest_date) = ? AND YEAR(interest_date) = ? ORDER BY interest_date DESC");
$stmt->execute([$_SESSION['tenant_id'], $month, $year]);
$records = $stmt->fetchAll();

// Calculate Total
$total_interest = 0;
foreach ($records as $r) {
    $total_interest += $r['amount'];
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="interest_tracker.php?year=<?php echo htmlspecialchars($year); ?>"
            class="text-decoration-none text-muted small mb-1">
            <i class="fa-solid fa-arrow-left"></i> Back to Year
        </a>
        <h1 class="h3 fw-bold mb-0">
            <?php echo $month_name . ' ' . htmlspecialchars($year); ?>
        </h1>
    </div>
    <div class="text-end">
        <div class="small text-muted">Net Interest</div>
        <h3 class="fw-bold <?php echo $total_interest > 0 ? 'text-danger' : 'text-success'; ?> mb-0">
            AED <span class="blur-sensitive"><?php echo number_format(abs($total_interest), 2); ?></span>
            <?php if ($total_interest <= 0 && !empty($records)) {
                echo '<i class="fa-solid fa-check ms-1"></i>';
            } ?>
        </h3>
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
        <a href="export_actions.php?action=export_interest&month=<?php echo $month; ?>&year=<?php echo $year; ?>"
            class="btn btn-outline-secondary">
            <i class="fa-solid fa-file-csv me-1"></i> Export
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#interestModal">
            <i class="fa-solid fa-plus me-2"></i> Add Record
        </button>
    </div>
</div>

<!-- List View -->
<?php if (empty($records)): ?>
    <div class="text-center py-5">
        <div class="mb-3">
            <i class="fa-solid fa-piggy-bank fa-3x text-muted opacity-25"></i>
        </div>
        <h5 class="text-muted">No records for this month.</h5>
        <p class="text-muted small">Add interest or payments to track.</p>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-secondary">
                    <tr>
                        <th class="ps-4 py-3" style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                        </th>
                        <th class="py-3">Date</th>
                        <th>Description</th>
                        <th class="text-end pe-4">Income/Interest</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $r): ?>
                        <tr class="<?php echo $r['amount'] < 0 ? 'bg-danger-subtle' : ''; ?>">
                            <td class="ps-4">
                                <input type="checkbox" class="form-check-input row-checkbox" value="<?php echo $r['id']; ?>">
                            </td>
                            <td class="fw-bold">
                                <?php echo date('d', strtotime($r['interest_date'])); ?>
                                <span class="small text-muted fw-normal d-block">
                                    <?php echo date('D', strtotime($r['interest_date'])); ?>
                                </span>
                            </td>
                            <td>
                                <span class="fw-bold">
                                    <?php echo htmlspecialchars($r['title']); ?>
                                </span>
                                <?php if ($r['amount'] < 0): ?>
                                    <span class="badge bg-danger ms-2" style="font-size: 0.7em;">Payment</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4 fw-bold <?php echo $r['amount'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo $r['amount'] < 0 ? '-' : '+'; ?> AED <span class="blur-sensitive">
                                    <?php echo number_format(abs($r['amount']), 2); ?></span>
                            </td>
                            <td class="text-end pe-3">
                                <button type="button" class="btn btn-sm text-muted me-1"
                                    onclick="editInterest(<?php echo htmlspecialchars(json_encode($r)); ?>)" title="Edit">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <button type="button" class="btn btn-sm text-danger"
                                    onclick="confirmDeleteInterest(<?php echo $r['id']; ?>, '<?php echo addslashes(htmlspecialchars($r['title'])); ?>', '<?php echo number_format(abs($r['amount']), 2); ?>')"
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

<!-- Ad/Edit Modal -->
<div class="modal fade" id="interestModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="modalTitle">Add Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="interestForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" id="formAction" value="add_interest">
                    <input type="hidden" name="id" id="recordId">

                    <div class="mb-3">
                        <label for="recordType" class="form-label">Type</label>
                        <select name="type" id="recordType" class="form-select">
                            <option value="interest">Interest Accrued (Debt)</option>
                            <option value="payment">Payment (Charity)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="recordTitle" class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="recordTitle" class="form-control"
                            placeholder="e.g. Bank Savings Interest" required>
                    </div>

                    <div class="mb-3">
                        <label for="recordAmount" class="form-label">Amount <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" id="recordAmount" class="form-control"
                            placeholder="0.00" required>
                    </div>

                    <div class="mb-3">
                        <label for="recordDate" class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="interest_date" id="recordDate" class="form-control" required>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-info text-white fw-bold" id="submitBtn">Save
                            Record</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteInterestModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-panel border-0">
            <div class="modal-body text-center py-4">
                <i class="fa-solid fa-trash-can text-danger fa-3x mb-3"></i>
                <h5 class="fw-bold">Delete Record?</h5>
                <p id="deleteInterestMsg" class="text-muted small">This cannot be undone.</p>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_interest">
                    <input type="hidden" name="id" id="deleteInterestId">

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

<form id="bulkActionForm" action="monthly_interest.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>"
    method="POST" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
    <input type="hidden" name="action" id="bulkActionType">
    <div id="bulkActionIds"></div>
</form>

<script>
    const interestModal = new bootstrap.Modal(document.getElementById('interestModal'));

    function openAddModal() {
        document.getElementById('modalTitle').innerText = 'Add Record';
        document.getElementById('formAction').value = 'add_interest';
        document.getElementById('recordId').value = '';
        document.getElementById('interestForm').reset();

        // Default Date: Use current day, but clamp to max days of selected month
        <?php
        $selected_day = min(date('d'), cal_days_in_month(CAL_GREGORIAN, $month, $year));
        $default_date = sprintf('%04d-%02d-%02d', $year, $month, $selected_day);
        ?>
        document.getElementById('recordDate').value = '<?php echo htmlspecialchars($default_date); ?>';

        interestModal.show();
    }

    function editInterest(record) {
        document.getElementById('modalTitle').innerText = 'Edit Record';
        document.getElementById('formAction').value = 'edit_interest';
        document.getElementById('recordId').value = record.id;

        document.getElementById('recordTitle').value = record.title;
        document.getElementById('recordDate').value = record.interest_date;

        // Determine type and amount
        const amount = parseFloat(record.amount);
        if (amount < 0) {
            document.getElementById('recordType').value = 'payment';
            document.getElementById('recordAmount').value = Math.abs(amount);
        } else {
            document.getElementById('recordType').value = 'interest';
            document.getElementById('recordAmount').value = amount;
        }

        interestModal.show();
    }

    function confirmDeleteInterest(id, title, amount) {
        document.getElementById('deleteInterestId').value = id;
        document.getElementById('deleteInterestMsg').innerHTML = `Delete <strong>${title}</strong> (AED ${amount})? <br><span class="text-danger small">This cannot be undone.</span>`;
        new bootstrap.Modal(document.getElementById('deleteInterestModal')).show();
    }

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
        if (document.getElementById('selectAll')) document.getElementById('selectAll').checked = false;
        document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('bulkActionBar').classList.add('d-none');
    }

    function bulkAction(type) {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        if (checked.length === 0) return;

        if (confirm(`Are you sure you want to delete ${checked.length} selected records?`)) {
            const form = document.getElementById('bulkActionForm');
            document.getElementById('bulkActionType').value = 'bulk_' + type + '_interest';

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
</script>