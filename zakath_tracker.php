<?php
$page_title = "Zakath Tracker";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Html;
use App\Helpers\Layout;
use App\Helpers\Flash;
use App\Helpers\ZakathHelper;

Bootstrap::init();

$tenant_id = (int) $_SESSION['tenant_id'];

// Handle Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        Flash::redirect('zakath_tracker.php', 'error', 'Unauthorized: Read-only access');
    }

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($_POST['action'] === 'mark_paid' && $id) {
        $stmt = $pdo->prepare("UPDATE zakath_calculations SET status = 'Paid' WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenant_id]);
        Flash::redirect('zakath_tracker.php', 'success', 'Marked as Paid');
    } elseif ($_POST['action'] === 'delete_zakath' && $id) {
        $stmt = $pdo->prepare("DELETE FROM zakath_calculations WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenant_id]);
        Flash::redirect('zakath_tracker.php', 'success', 'Zakath entry deleted');
    }
    Flash::redirect('zakath_tracker.php', 'error', 'Invalid request');
}

// Nisab / hawl status
$z_settings = ZakathHelper::settings($pdo, $tenant_id);
$z_prices   = ZakathHelper::prices($pdo, $z_settings);
$z_nisab    = ZakathHelper::nisab($z_settings, $z_prices);
$z_hawl     = ZakathHelper::hawl($z_settings['hawl_start_date']);
$z_auto     = ZakathHelper::autoAssets($pdo, $tenant_id);
$z_estimate = ZakathHelper::calculate(
    $z_auto['cash']['amount'],
    $z_auto['gold_silver']['amount'],
    $z_auto['investments']['amount'],
    $z_auto['receivables']['amount'],
    $z_auto['liabilities']['amount'],
    $z_nisab['value']
);
$z_cycle_calc = $z_hawl ? ZakathHelper::calculationForDue($pdo, $tenant_id, $z_hawl['due']) : null;

Layout::header();
Layout::sidebar();

// Fetch Records
$stmt = $pdo->prepare("SELECT * FROM zakath_calculations WHERE tenant_id = ? ORDER BY created_at DESC, id DESC");
$stmt->execute([$tenant_id]);
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
                <div class="d-flex gap-2 flex-wrap">
                    <a href="zakath_settings.php" class="btn btn-white text-primary border-0 rounded-pill px-3 py-1.5 fw-bold shadow-sm hover-lift">
                        <i class="fa-solid fa-gear me-1"></i> Settings
                    </a>
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

<!-- Hawl & nisab status -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="glass-panel-premium p-4 h-100">
            <h6 class="text-muted fw-bold text-uppercase small mb-2"><i class="fa-solid fa-moon me-1"></i> Next Zakath due</h6>
            <?php if ($z_hawl): ?>
                <h3 class="fw-bold text-dark mb-1"><?php echo Html::e(date('M d, Y', strtotime($z_hawl['due']))); ?></h3>
                <?php if ($z_hawl['due_hijri']): ?>
                    <div class="text-muted small"><?php echo Html::e($z_hawl['due_hijri']); ?></div>
                <?php endif; ?>
                <div class="mt-2">
                    <?php $dl = (int) $z_hawl['days_left']; ?>
                    <span class="badge rounded-pill px-3 py-1 <?php echo $dl === 0 ? 'bg-danger' : ($dl <= 30 ? 'bg-warning-subtle text-warning' : 'bg-primary-subtle text-primary'); ?>">
                        <?php echo $dl === 0 ? 'Due today' : $dl . ' day' . ($dl === 1 ? '' : 's') . ' left'; ?>
                    </span>
                </div>
                <div class="text-muted small mt-2">Hawl started <?php echo Html::e(date('M d, Y', strtotime($z_hawl['start']))); ?> · lunar year = 354 days</div>
            <?php else: ?>
                <h5 class="fw-bold text-muted mb-1">Not set</h5>
                <p class="text-muted small mb-0">Set the date your wealth first reached the nisab to track due dates and get email reminders.</p>
                <a href="zakath_settings.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 mt-2">Set hawl date</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-4">
        <div class="glass-panel-premium p-4 h-100">
            <h6 class="text-muted fw-bold text-uppercase small mb-2"><i class="fa-solid fa-scale-balanced me-1"></i> Nisab (<?php echo Html::e($z_nisab['basis']); ?>)</h6>
            <?php if ($z_nisab['value'] !== null): ?>
                <h3 class="fw-bold text-dark mb-1"><small class="fs-6 text-muted">AED</small> <?php echo number_format($z_nisab['value'], 2); ?></h3>
                <div class="text-muted small"><?php echo Html::e(number_format($z_nisab['grams'], 2) . ' g × AED ' . number_format($z_nisab['price_per_gram'], 4) . '/g — ' . $z_prices[$z_nisab['basis']]['source']); ?></div>
            <?php else: ?>
                <h5 class="fw-bold text-danger mb-1">Price unavailable</h5>
                <p class="text-muted small mb-0">Enter a manual <?php echo Html::e($z_nisab['basis']); ?> price in <a href="zakath_settings.php">settings</a>.</p>
            <?php endif; ?>
            <div class="small mt-2">
                <span class="text-muted">Your zakatable wealth now (estimate):</span>
                <span class="fw-bold blur-sensitive">AED <?php echo number_format($z_estimate['net'], 2); ?></span>
            </div>
            <?php if ($z_estimate['meets_nisab'] === true): ?>
                <span class="badge rounded-pill bg-success-subtle text-success px-3 py-1 mt-2">Above nisab</span>
            <?php elseif ($z_estimate['meets_nisab'] === false): ?>
                <span class="badge rounded-pill bg-secondary-subtle text-secondary px-3 py-1 mt-2">Below nisab</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-4">
        <div class="glass-panel-premium p-4 h-100 d-flex flex-column">
            <h6 class="text-muted fw-bold text-uppercase small mb-2"><i class="fa-solid fa-calculator me-1"></i> This cycle</h6>
            <?php if ($z_cycle_calc): ?>
                <h3 class="fw-bold text-primary mb-1"><small class="fs-6 text-muted">AED</small> <span class="blur-sensitive"><?php echo number_format((float) $z_cycle_calc['total_zakath'], 2); ?></span></h3>
                <div class="text-muted small"><?php echo Html::e($z_cycle_calc['cycle_name']); ?> ·
                    <?php echo $z_cycle_calc['status'] === 'Paid' ? '<span class="text-success fw-bold">Paid</span>' : '<span class="text-warning fw-bold">Pending</span>'; ?></div>
            <?php else: ?>
                <p class="text-muted small mb-1">No calculation saved for <?php echo $z_hawl ? 'the hawl ending ' . Html::e(date('M d, Y', strtotime($z_hawl['due']))) : 'this cycle'; ?> yet.</p>
                <div class="small">Estimated Zakath now: <span class="fw-bold blur-sensitive">AED <?php echo number_format($z_estimate['zakat'], 2); ?></span></div>
            <?php endif; ?>
            <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                <div class="mt-auto pt-3">
                    <a href="zakath_calculator.php" class="btn btn-sm btn-primary rounded-pill px-3"><i class="fa-solid fa-calculator me-1"></i> Open calculator</a>
                </div>
            <?php endif; ?>
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
                            <h5 class="fw-bold mb-1 text-dark"><?php echo Html::e($rec['cycle_name']); ?></h5>
                            <small class="text-muted"><i class="fa-solid fa-calendar me-1"></i> <?php echo date('M d, Y', strtotime($rec['created_at'])); ?></small>
                            <?php if (!empty($rec['due_date'])): ?>
                                <small class="text-muted d-block"><i class="fa-solid fa-moon me-1"></i> Hawl due <?php echo Html::e(date('M d, Y', strtotime($rec['due_date']))); ?></small>
                            <?php endif; ?>
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
                        <small class="text-muted">
                            <?php if (isset($rec['nisab_value']) && $rec['nisab_value'] !== null): ?>
                                Nisab (<?php echo Html::e($rec['nisab_basis'] ?? ''); ?>) AED <?php echo number_format((float) $rec['nisab_value'], 2); ?> ·
                                <?php echo ((float) $rec['total_zakath'] > 0) ? '2.5% of net wealth' : 'below nisab'; ?>
                            <?php else: ?>
                                Calculated obligation @ 2.5% of net wealth
                            <?php endif; ?>
                        </small>
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
                        <?php if ((float) ($rec['receivables'] ?? 0) > 0): ?>
                            <div class="d-flex justify-content-between py-2 border-bottom border-light">
                                <span>Money owed to you</span>
                                <span class="fw-bold text-dark blur-sensitive">AED <?php echo number_format((float) $rec['receivables'], 2); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between py-2 text-danger">
                            <span>Debts due this year</span>
                            <span class="fw-bold blur-sensitive">-AED <?php echo number_format($rec['liabilities'], 2); ?></span>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                            <?php if ($rec['status'] == 'Pending'): ?>
                                <form action="" method="POST" class="flex-grow-1">
                                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="id" value="<?php echo (int) $rec['id']; ?>">
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
                                <input type="hidden" name="id" value="<?php echo (int) $rec['id']; ?>">
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
