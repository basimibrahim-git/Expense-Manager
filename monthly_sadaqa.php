<?php
$page_title = "Monthly Sadaqa";
require_once 'config.php';

// Handle Actions (Add/Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    if ($_POST['action'] == 'add_sadaqa') {
        $title = $_POST['title'];
        $amount = floatval($_POST['amount']);
        $date = $_POST['sadaqa_date'];

        if ($amount > 0 && !empty($title) && !empty($date)) {
            $stmt = $pdo->prepare("INSERT INTO sadaqa_tracker (user_id, title, amount, sadaqa_date) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $title, $amount, $date]);
            
            $month = date('n', strtotime($date));
            $year = date('Y', strtotime($date));
            header("Location: monthly_sadaqa.php?month=$month&year=$year&success=Sadaqa Added");
            exit;
        }
    } elseif ($_POST['action'] == 'delete_sadaqa') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM sadaqa_tracker WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $_SESSION['user_id']]);
        }
        
        // Fixed Open Redirect: Redirect to known parent page
        $back_url = "sadaqa_tracker.php?year=" . ($year ?? date('Y'));
        header("Location: $back_url");
        exit;
    }
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';

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
$stmt = $pdo->prepare("SELECT * FROM sadaqa_tracker WHERE user_id = ? AND MONTH(sadaqa_date) = ? AND YEAR(sadaqa_date) = ? ORDER BY sadaqa_date DESC");
$stmt->execute([$_SESSION['user_id'], $month, $year]);
$records = $stmt->fetchAll();

// Calculate Total
$total_sadaqa = 0;
foreach ($records as $r) {
    $total_sadaqa += $r['amount'];
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="sadaqa_tracker.php?year=<?php echo htmlspecialchars($year); ?>" class="text-decoration-none text-muted small mb-1">
            <i class="fa-solid fa-arrow-left"></i> Back to Year
        </a>
        <h1 class="h3 fw-bold mb-0">
            <?php echo $month_name . ' ' . htmlspecialchars($year); ?>
        </h1>
    </div>
    <div class="text-end">
        <div class="small text-muted">Total Sadaqa</div>
        <h3 class="fw-bold text-success mb-0">AED <span class="blur-sensitive"><?php echo number_format($total_sadaqa, 2); ?></span></h3>
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

    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addSadaqaModal">
        <i class="fa-solid fa-plus me-2"></i> Add Sadaqa
    </button>
</div>

<!-- List View -->
<?php if (empty($records)): ?>
    <div class="text-center py-5">
        <div class="mb-3">
            <i class="fa-solid fa-hand-holding-heart fa-3x text-muted opacity-25"></i>
        </div>
        <h5 class="text-muted">No donation records for this month.</h5>
        <p class="text-muted small">Add your first donation to start tracking.</p>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4 py-3">Date</th>
                        <th>Description</th>
                        <th class="text-end pe-4">Amount</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td class="ps-4 fw-bold">
                                <?php echo htmlspecialchars(date('d', strtotime($r['sadaqa_date']))); ?>
                                <span class="small text-muted fw-normal d-block">
                                    <?php echo htmlspecialchars(date('D', strtotime($r['sadaqa_date']))); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($r['title']); ?>
                            </td>
                            <td class="text-end pe-4 fw-bold text-success">
                                AED <span class="blur-sensitive"><?php echo number_format($r['amount'], 2); ?></span>
                            </td>
                            <td class="text-end pe-3">
                                <button type="button" class="btn btn-sm text-danger" 
                                    onclick="confirmDeleteSadaqa(<?php echo $r['id']; ?>)" title="Delete">
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

<!-- Add Sadaqa Modal -->
<div class="modal fade" id="addSadaqaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Add Sadaqa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_sadaqa">

                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Masjid Donation" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Amount <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="sadaqa_date" class="form-control" value="<?php echo htmlspecialchars(date('Y') . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . date('d')); ?>" required>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-success fw-bold">Save Donation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteSadaqaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-panel border-0">
            <div class="modal-body text-center py-4">
                <i class="fa-solid fa-trash-can text-danger fa-3x mb-3"></i>
                <h5 class="fw-bold">Delete Record?</h5>
                <p class="text-muted small">This cannot be undone.</p>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_sadaqa">
                    <input type="hidden" name="id" id="deleteSadaqaId">

                    <div class="d-flex justify-content-center gap-2 mt-3">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function confirmDeleteSadaqa(id) {
        document.getElementById('deleteSadaqaId').value = id;
        new bootstrap.Modal(document.getElementById('deleteSadaqaModal')).show();
    }
</script>

<?php require_once 'includes/footer.php'; ?>
