<?php
$page_title = "Zakath Tracker";
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;

Bootstrap::init();

// 1. Auto-Healing: Create zakath_calculations Table (Handled by install.php)

// Handle Status Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: zakath_tracker.php?error=Unauthorized: Read-only access");
        exit();
    }

    if ($_POST['action'] == 'mark_paid') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $pdo->prepare("UPDATE zakath_calculations SET status = 'Paid' WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);
            header("Location: zakath_tracker.php?success=Marked as Paid");
            exit;
        }
    } elseif ($_POST['action'] == 'delete_zakath') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM zakath_calculations WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);
            header("Location: zakath_tracker.php?deleted=1");
            exit;
        }
    }
}

Layout::header();
Layout::sidebar();

// Fetch Records
$stmt = $pdo->prepare("SELECT * FROM zakath_calculations WHERE tenant_id = ? ORDER BY created_at DESC");
$stmt->execute([$_SESSION['tenant_id']]);
$records = $stmt->fetchAll();

$total_pending = 0;
foreach ($records as $r) {
    if ($r['status'] == 'Pending') {
        $total_pending += $r['total_zakath'];
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-0">Zakath Tracker</h1>
        <p class="text-muted small mb-0">Track and calculate your annual Zakath obligations.</p>
    </div>
    <div class="d-flex gap-3 align-items-center">
        <?php if ($total_pending > 0): ?>
            <div class="text-end">
                <div class="small text-muted">Pending Due</div>
                <h4 class="fw-bold text-danger mb-0">AED <?php echo number_format($total_pending, 2); ?></h4>
            </div>
        <?php endif; ?>
        <a href="export_actions.php?action=export_zakath" class="btn btn-outline-secondary">
            <i class="fa-solid fa-file-csv me-1"></i> Export
        </a>
        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
            <a href="zakath_calculator.php" class="btn btn-primary d-flex align-items-center gap-2">
                <i class="fa-solid fa-calculator"></i> New Calculation
            </a>
        <?php endif; ?>
    </div>
</div>
</div>

<?php if (empty($records)): ?>
    <div class="glass-panel p-5 text-center">
        <div class="mb-3">
            <i class="fa-solid fa-hand-holding-heart fa-4x text-primary opacity-25"></i>
        </div>
        <h4 class="fw-bold">No Zakath Records Found</h4>
        <p class="text-muted">Start by calculating your Zakath for this year.</p>
        <a href="zakath_calculator.php" class="btn btn-outline-primary mt-2">Calculate Now</a>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($records as $rec): ?>
            <div class="col-md-6 col-lg-4">
                <div class="glass-panel p-4 h-100 position-relative">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h5 class="fw-bold mb-0"><?php echo htmlspecialchars($rec['cycle_name']); ?></h5>
                        <?php if ($rec['status'] == 'Paid'): ?>
                            <span class="badge bg-success-subtle text-success"><i class="fa-solid fa-check me-1"></i> Paid</span>
                        <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning">Pending</span>
                        <?php endif; ?>
                    </div>

                    <div class="mb-4">
                        <h2 class="fw-bold text-primary mb-0">AED <?php echo number_format($rec['total_zakath'], 2); ?></h2>
                        <small class="text-muted">Payable @ 2.5%</small>
                    </div>

                    <div class="small text-muted mb-3">
                        <div class="d-flex justify-content-between border-bottom pb-1 mb-1">
                            <span>Cash & Bank</span>
                            <span><?php echo number_format($rec['cash_balance'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between border-bottom pb-1 mb-1">
                            <span>Gold/Silver</span>
                            <span><?php echo number_format($rec['gold_silver'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between border-bottom pb-1 mb-1">
                            <span>Investments</span>
                            <span><?php echo number_format($rec['investments'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between text-danger">
                            <span>Liabilities</span>
                            <span>-<?php echo number_format($rec['liabilities'], 2); ?></span>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-auto">
                        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                            <?php if ($rec['status'] == 'Pending'): ?>
                                <form action="" method="POST" class="flex-grow-1">
                                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="id" value="<?php echo $rec['id']; ?>">
                                    <button type="submit" class="btn btn-success btn-sm w-100"
                                        onclick="return confirmSubmit(this, 'Mark <?php echo addslashes(htmlspecialchars($rec['cycle_name'])); ?> (AED <?php echo number_format($rec['total_zakath'], 2); ?>) as Paid?');">
                                        <i class="fa-solid fa-check me-1"></i> Mark Paid
                                    </button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-light btn-sm flex-grow-1" disabled>Paid on
                                    <?php echo date('M d, Y', strtotime($rec['created_at'])); ?></button>
                            <?php endif; ?>

                            <form action="" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="delete_zakath">
                                <input type="hidden" name="id" value="<?php echo $rec['id']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm"
                                    onclick="return confirmSubmit(this, 'Delete Zakath record for <?php echo addslashes(htmlspecialchars($rec['cycle_name'])); ?>? This cannot be undone.');">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="text-muted small w-100 text-center py-1">
                                <i class="fa-solid fa-lock me-1"></i> Read Only
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="position-absolute top-0 end-0 m-3 text-muted small" style="font-size: 0.7rem;">
                        <?php echo date('M d, Y', strtotime($rec['created_at'])); ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php Layout::footer(); ?>