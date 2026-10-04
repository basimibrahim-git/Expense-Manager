<?php
$page_title = "My Bank Accounts";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\BalanceHelper;

Bootstrap::init();

$tenant_id = (int) $_SESSION['tenant_id'];
$can_edit  = ($_SESSION['permission'] ?? 'edit') !== 'read_only';
define('BANK_DEFAULT_COLOR', '#0ea5e9');

// Fetch the tenant's banks; balances come from BalanceHelper (banks without snapshots show 0.00)
$banks = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM banks WHERE tenant_id = ? ORDER BY bank_name ASC");
    $stmt->execute([$tenant_id]);
    $banks = $stmt->fetchAll();

    $balances = [];
    foreach (BalanceHelper::latestPerBank($pdo, $tenant_id) as $row) {
        $balances[(int) $row['bank_id']] = (float) $row['amount'];
    }
    foreach ($banks as &$b) {
        $b['current_balance'] = $balances[(int) $b['id']] ?? 0.0;
    }
    unset($b);
} catch (PDOException $e) {
    error_log("Error fetching banks: " . $e->getMessage());
}

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <!-- Header with controls -->
    <div class="row align-items-center mb-4 g-3">
        <div class="col-md-6">
            <h1 class="h3 fw-bold mb-1 text-dark">My Bank Accounts</h1>
            <p class="text-muted mb-0">Monitor balances and reconcile statements across connected institutions</p>
        </div>
        <div class="col-md-6">
            <div class="d-flex justify-content-md-end gap-2">
                <a href="bank_balances.php" class="btn btn-outline-primary rounded-pill px-4 shadow-sm hover-lift">
                    <i class="fa-solid fa-chart-line me-1"></i> Net Worth Map
                </a>
                <?php if ($can_edit): ?>
                    <a href="add_bank.php" class="btn btn-primary rounded-pill px-4 shadow-sm hover-lift">
                        <i class="fa-solid fa-plus me-1"></i> Add Account
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4" role="alert">
            <i class="fa-solid fa-check-circle me-2"></i> <?php echo Html::e($_GET['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4" role="alert">
            <i class="fa-solid fa-exclamation-circle me-2"></i> <?php echo Html::e($_GET['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Accounts Grid -->
    <div class="row g-4">
        <?php if (empty($banks)): ?>
            <div class="col-12 text-center py-5">
                <div class="glass-panel-premium p-5 max-width-600 mx-auto">
                    <i class="fa-solid fa-building-columns fa-4x mb-3 text-muted opacity-25"></i>
                    <h5 class="fw-bold text-dark mb-1">No Connected Banks</h5>
                    <p class="text-muted small mb-4">Link your bank accounts to enable balance tracking and monthly net worth snapshots.</p>
                    <a href="add_bank.php" class="btn btn-primary rounded-pill px-4 shadow-sm">Add First Bank</a>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($banks as $bank): ?>
                <?php 
                $bank_color = $bank['color'] ?? BANK_DEFAULT_COLOR; 
                // Generate a rich dark/metallic gradient based on bank theme color
                $gradient = "linear-gradient(135deg, " . $bank_color . ", " . adjustBrightness($bank_color, -40) . ")";
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="glass-panel-premium p-4 h-100 hover-lift d-flex flex-column position-relative overflow-hidden" 
                         style="border-radius: 20px; min-height: 220px; background: <?php echo $gradient; ?>; color: #ffffff; border: 1px solid rgba(255,255,255,0.15) !important;">
                        
                        <!-- Decorative bank watermark vector -->
                        <div class="position-absolute end-0 bottom-0 opacity-10 p-2" style="transform: translate(15%, 15%);">
                            <i class="fa-solid fa-building-columns" style="font-size: 10rem;"></i>
                        </div>

                        <!-- Card Header -->
                        <div class="d-flex justify-content-between align-items-start position-relative mb-4" style="z-index: 2;">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle p-2 bg-white bg-opacity-20 d-flex align-items-center justify-content-center me-3" style="width: 46px; height: 46px; backdrop-filter: blur(8px);">
                                    <i class="fa-solid fa-building-columns fa-lg text-white"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold mb-0 text-white"><?php echo Html::e($bank['bank_name']); ?></h5>
                                    <small class="text-white-50"><?php echo Html::e($bank['account_number'] ? '•••• ' . substr($bank['account_number'], -4) : ($bank['account_type'] ?: 'Current') . ' Account'); ?></small>
                                </div>
                            </div>
                            <?php if ($can_edit): ?>
                            <div class="dropdown">
                                <button class="btn btn-link text-white text-opacity-75 p-0 hover-lift" data-bs-toggle="dropdown" style="box-shadow: none;">
                                    <i class="fa-solid fa-ellipsis-vertical fa-lg"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg" style="border-radius: 12px;">
                                    <li>
                                        <a class="dropdown-item py-2" href="edit_bank.php?id=<?php echo (int) $bank['id']; ?>">
                                            <i class="fa-solid fa-pen text-muted me-2"></i> Edit Account
                                        </a>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item py-2 text-danger"
                                            data-onclick="confirmDelete" data-args="<?php echo Html::args((int) $bank['id'], $bank['bank_name']); ?>">
                                            <i class="fa-solid fa-trash me-2"></i> Remove
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card Body (Balance) -->
                        <div class="mt-auto position-relative" style="z-index: 2;">
                            <span class="text-white-50 small text-uppercase tracking-wider">Current Balance</span>
                            <h3 class="fw-bold text-white mb-2 mt-1">
                                <small class="text-white-50" style="font-size: 0.6em"><?php echo Html::e($bank['currency'] ?: 'AED'); ?></small> 
                                <span class="blur-sensitive"><?php echo number_format((float) $bank['current_balance'], 2); ?></span>
                            </h3>
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top border-white border-opacity-15">
                                <a href="monthly_balances.php" class="text-white text-opacity-90 text-decoration-none small fw-bold hover-underline">
                                    History Ledger <i class="fa-solid fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-panel-premium border-0 shadow-lg">
            <div class="modal-body p-4 text-center">
                <div class="rounded-circle bg-danger bg-opacity-10 text-danger p-3 d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                    <i class="fa-solid fa-triangle-exclamation fa-2x"></i>
                </div>
                <h5 class="fw-bold mb-2 text-dark">Remove Bank?</h5>
                <p id="deleteMsg" class="text-muted small mb-4"></p>
                <form id="deleteForm" method="POST" action="bank_actions.php">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteId">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light rounded-pill w-100 py-2" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-pill w-100 py-2">Remove</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
/**
 * Helper to adjust hex color brightness for secondary gradients
 */
function adjustBrightness($hex, $steps) {
    // Steps range from -255 to 255
    $steps = max(-255, min(255, $steps));

    // Normalize hex value
    $hex = str_replace('#', '', $hex);
    if (strlen($hex) == 3) {
        $hex = str_repeat(substr($hex, 0, 1), 2) . str_repeat(substr($hex, 1, 1), 2) . str_repeat(substr($hex, 2, 1), 2);
    }

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    $r = max(0, min(255, $r + $steps));
    $g = max(0, min(255, $g + $steps));
    $b = max(0, min(255, $b + $steps));

    $r_hex = str_pad(dechex($r), 2, '0', STR_PAD_LEFT);
    $g_hex = str_pad(dechex($g), 2, '0', STR_PAD_LEFT);
    $b_hex = str_pad(dechex($b), 2, '0', STR_PAD_LEFT);

    return '#' . $r_hex . $g_hex . $b_hex;
}
?>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    function confirmDelete(id, name) {
        const box = document.getElementById('deleteMsg');
        const strong = document.createElement('strong');
        strong.textContent = name;
        const note = document.createElement('span');
        note.className = 'text-muted small';
        note.textContent = 'Historical snapshots will be preserved, but the account will be hidden.';
        box.replaceChildren('Are you sure you want to remove ', strong, '?', document.createElement('br'), note);
        document.getElementById('deleteId').value = id;
        new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
    }
</script>

<?php Layout::footer(); ?>
