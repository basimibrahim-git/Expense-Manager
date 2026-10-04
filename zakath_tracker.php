<?php
$page_title = "Zakath Tracker";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Html;
use App\Helpers\Layout;

Bootstrap::init();

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

<!-- Premium Header Banner -->
<div class="row mb-4">
    <div class="col-12">
        <div class="gradient-card-primary p-4 rounded-4 hover-lift position-relative overflow-hidden shadow-sm" style="border-radius: 16px;">
            <div class="position-absolute top-0 end-0 p-3 opacity-10">
                <i class="fa-solid fa-hand-holding-heart fa-9x"></i>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 position-relative" style="z-index: 2;">
                <div>
                    <h1 class="h3 fw-bold mb-1 text-white">Zakath Tracker</h1>
                    <p class="text-white text-opacity-75 mb-0">Monitor your annual Zakath (obligatory alms) cycles and payments</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="export_actions.php?action=export_zakath" class="btn btn-white text-primary border-0 rounded-pill px-3 py-1.5 fw-bold shadow-sm hover-lift">
                        <i class="fa-solid fa-file-csv me-1"></i> Export Data
                    </a>
                    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                        <a href="zakath_calculator.php" class="btn btn-dark border-0 rounded-pill px-4 py-1.5 fw-bold shadow-sm hover-lift">
                            <i class="fa-solid fa-calculator me-1"></i> New Calculation
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Pending collection display if present -->
<?php if ($total_pending > 0): ?>
    <div class="glass-panel-premium p-4 mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3 border-start border-4 border-danger" style="border-left: 4px solid #f43f5e !important;">
        <div>
            <h6 class="text-muted fw-bold text-uppercase small mb-1">Total Outstanding Zakath</h6>
            <p class="text-muted small mb-0">Remaining unpaid obligations across all active cycles</p>
        </div>
        <div class="text-end">
            <h3 class="fw-bold text-danger mb-0">
                <small class="fs-6 text-muted">AED</small> 
                <span class="blur-sensitive"><?php echo number_format($total_pending, 2); ?></span>
            </h3>
        </div>
    </div>
<?php endif; ?>

<!-- Records List -->
<?php if (empty($records)): ?>
    <div class="glass-panel-premium p-5 text-center">
        <div class="mb-3 text-muted opacity-25">
            <i class="fa-solid fa-hand-holding-heart fa-4x"></i>
        </div>
        <h5 class="fw-bold text-dark mb-1">No Calculation Records</h5>
        <p class="text-muted small mb-4">Start by running your first asset calculations for this cycle.</p>
        <a href="zakath_calculator.php" class="btn btn-primary rounded-pill px-4 py-2 shadow-sm hover-lift">Calculate Now</a>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($records as $rec): ?>
            <div class="col-md-6 col-lg-4">
                <div class="glass-panel-premium p-4 h-100 position-relative hover-lift d-flex flex-column" 
                     style="border-left: 4px solid <?php echo $rec['status'] == 'Paid' ? '#10b981' : '#f59e0b'; ?> !important;">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h5 class="fw-bold mb-1 text-dark"><?php echo htmlspecialchars($rec['cycle_name']); ?></h5>
                            <small class="text-muted"><i class="fa-solid fa-calendar me-1"></i> <?php echo date('M d, Y', strtotime($rec['created_at'])); ?></small>
                        </div>
                        <?php if ($rec['status'] == 'Paid'): ?>
                            <span class="badge rounded-pill bg-success-subtle text-success px-3 py-1 fw-bold">Paid</span>
                        <?php else: ?>
                            <span class="badge rounded-pill bg-warning-subtle text-warning px-3 py-1 fw-bold">Pending</span>
                        <?php endif; ?>
                    </div>

                    <div class="mb-4">
                        <h3 class="fw-bold text-primary mb-1">
                            <small class="fs-6 text-muted">AED</small> 
                            <span class="blur-sensitive"><?php echo number_format($rec['total_zakath'], 2); ?></span>
                        </h3>
                        <small class="text-muted">Calculated obligation @ 2.5% of net wealth</small>
                    </div>

                    <div class="small text-muted mb-4 flex-grow-1">
                        <div class="d-flex justify-content-between py-2 border-bottom border-light">
                            <span>Cash & Bank balances</span>
                            <span class="fw-bold text-dark blur-sensitive">AED <?php echo number_format($rec['cash_balance'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom border-light">
                            <span>Gold & Silver</span>
                            <span class="fw-bold text-dark blur-sensitive">AED <?php echo number_format($rec['gold_silver'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom border-light">
                            <span>Investments</span>
                            <span class="fw-bold text-dark blur-sensitive">AED <?php echo number_format($rec['investments'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between py-2 text-danger">
                            <span>Immediate Liabilities</span>
                            <span class="fw-bold blur-sensitive">-AED <?php echo number_format($rec['liabilities'], 2); ?></span>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                            <?php if ($rec['status'] == 'Pending'): ?>
                                <form action="" method="POST" class="flex-grow-1">
                                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="id" value="<?php echo $rec['id']; ?>">
                                    <button type="submit" class="btn btn-success btn-sm w-100 rounded-pill shadow-sm py-2 hover-lift"
                                        data-confirm="<?php echo Html::e('Mark ' . $rec['cycle_name'] . ' as paid (AED ' . number_format($rec['total_zakath'], 2) . ')?'); ?>" data-confirm-btn="Mark Paid">
                                        <i class="fa-solid fa-check me-1"></i> Mark Paid
                                    </button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-light btn-sm flex-grow-1 rounded-pill py-2 text-muted" disabled>
                                    <i class="fa-solid fa-check-double me-1"></i> Slipped/Settled
                                </button>
                            <?php endif; ?>

                            <form action="" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="delete_zakath">
                                <input type="hidden" name="id" value="<?php echo $rec['id']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm rounded-circle p-2"
                                    data-confirm="<?php echo Html::e('Delete Zakath entry for ' . $rec['cycle_name'] . '? This cannot be undone.'); ?>"
                                    style="width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center;">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="badge bg-light text-muted w-100 py-2"><i class="fa-solid fa-lock me-1"></i> Read Only</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php Layout::footer(); ?>
