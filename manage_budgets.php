<?php
// manage_budgets.php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;

Bootstrap::init();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Permission Check
if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
    header("Location: budget.php?error=Unauthorized: Read-only access");
    exit();
}

$tenant_id = $_SESSION['tenant_id'];
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');

// If copying from a previous month, load those values as defaults (but keep current month in the form)
$copy_month = filter_input(INPUT_GET, 'copy_month', FILTER_VALIDATE_INT);
$copy_year  = filter_input(INPUT_GET, 'copy_year',  FILTER_VALIDATE_INT);

$auto_carried = false;

if ($copy_month && $copy_year) {
    $stmt = $pdo->prepare("SELECT category, amount, currency FROM budgets WHERE tenant_id = ? AND month = ? AND year = ?");
    $stmt->execute([$tenant_id, $copy_month, $copy_year]);
    $existing_budgets = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT category, amount, currency FROM budgets WHERE tenant_id = ? AND month = ? AND year = ?");
    $stmt->execute([$tenant_id, $month, $year]);
    $existing_budgets = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    // Auto-carry forward: if no budgets set for this month, pre-fill from previous month
    if (empty($existing_budgets)) {
        $prev_month = $month == 1 ? 12 : $month - 1;
        $prev_year  = $month == 1 ? $year - 1 : $year;
        $stmt = $pdo->prepare("SELECT category, amount, currency FROM budgets WHERE tenant_id = ? AND month = ? AND year = ?");
        $stmt->execute([$tenant_id, $prev_month, $prev_year]);
        $carried = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        if (!empty($carried)) {
            $existing_budgets = $carried;
            $auto_carried = true;
        }
    }
}

// predefined categories for easy setup
$categories = [
    'Grocery',
    'Food',
    'Medical',
    'Shopping',
    'Utilities',
    'Transport',
    'Travel',
    'Entertainment',
    'Education',
    'Other'
];

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <!-- Header Section -->
    <div class="row mb-4 align-items-center justify-content-between g-3">
        <div class="col-md-6">
            <h2 class="fw-bold mb-1 text-dark">🎯 Configure Budgets</h2>
            <p class="text-muted mb-0">Define target boundaries for automated expenditure analytics</p>
        </div>
        <div class="col-md-6">
            <form class="d-flex gap-2 justify-content-md-end" method="GET">
                <select name="month" class="form-select rounded-pill px-3" style="max-width: 140px; border: 1px solid rgba(0,0,0,0.1);">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <select name="year" class="form-select rounded-pill px-3" style="max-width: 120px; border: 1px solid rgba(0,0,0,0.1);">
                    <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">
                    Filter <i class="fa-solid fa-arrow-right ms-1"></i>
                </button>
            </form>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="glass-panel-premium shadow-sm border-0 rounded-4 overflow-hidden mb-4 p-0">
                <?php if ($auto_carried): ?>
                    <div class="alert alert-info mb-0 rounded-0 border-0 py-3 px-4 d-flex align-items-center">
                        <i class="fa-solid fa-circle-info me-2 fa-lg text-info"></i>
                        <span>No targets found for <strong><?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></strong>. Carried forward values from last active period.</span>
                    </div>
                <?php endif; ?>
                
                <div class="p-4 border-bottom border-light bg-light bg-opacity-50 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0 fw-bold text-dark">
                        Targets for <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>
                    </h5>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm hover-lift" data-onclick="copyLastMonth">
                        <i class="fa-solid fa-copy me-1"></i> Pre-fill Last Month
                    </button>
                </div>
                
                <div class="p-4">
                    <form action="budget_actions.php" method="POST">
                        <input type="hidden" name="action" value="save_budgets">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="month" value="<?php echo $month; ?>">
                        <input type="hidden" name="year" value="<?php echo $year; ?>">

                        <div class="table-responsive">
                            <table class="table table-borderless align-middle">
                                <thead>
                                    <tr class="text-muted small text-uppercase" style="border-bottom: 1px solid rgba(0,0,0,0.05);">
                                        <th class="pb-3">Category</th>
                                        <th class="pb-3" style="width: 280px;">Monthly limit</th>
                                        <th class="pb-3 text-end">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $cat): ?>
                                        <tr style="border-bottom: 1px solid rgba(0,0,0,0.03);">
                                            <td class="py-3">
                                                <div class="d-flex align-items-center">
                                                    <div class="category-icon me-3 rounded-circle d-flex align-items-center justify-content-center"
                                                         style="width: 42px; height: 42px; background: rgba(var(--primary-rgb, 99, 102, 241), 0.06); color: var(--primary-color);">
                                                        <i class="fa-solid <?php
                                                        echo match ($cat) {
                                                            'Grocery' => 'fa-cart-shopping',
                                                            'Food' => 'fa-utensils',
                                                            'Medical' => 'fa-heart-pulse',
                                                            'Shopping' => 'fa-bag-shopping',
                                                            'Utilities' => 'fa-bolt',
                                                            'Transport' => 'fa-car',
                                                            'Travel' => 'fa-plane',
                                                            'Entertainment' => 'fa-clapperboard',
                                                            'Education' => 'fa-graduation-cap',
                                                            default => 'fa-tag'
                                                        };
                                                        ?>"></i>
                                                    </div>
                                                    <span class="fw-bold text-dark-emphasis">
                                                        <?php echo $cat; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="input-group">
                                                    <span class="input-group-text bg-light text-muted border-end-0" style="border-top-left-radius: 12px; border-bottom-left-radius: 12px;">AED</span>
                                                    <input type="number" step="0.01" name="budgets[<?php echo $cat; ?>]"
                                                           class="form-control bg-white" placeholder="0.00" style="border-top-right-radius: 12px; border-bottom-right-radius: 12px;"
                                                           value="<?php echo $existing_budgets[$cat]['amount'] ?? ''; ?>">
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <?php if (isset($existing_budgets[$cat])): ?>
                                                    <span class="badge bg-success-subtle text-success px-2 py-1 rounded-pill fw-bold">
                                                        <i class="fa-solid fa-check me-1"></i> Active
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted px-2 py-1 rounded-pill">
                                                        Not Configured
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center">
                            <a href="budget.php" class="btn btn-light rounded-pill px-4 hover-lift">Cancel</a>
                            <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm hover-lift">
                                Save Configuration <i class="fa-solid fa-floppy-disk ms-2"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    function copyLastMonth() {
        if (confirm('This will pre-fill values from the previous month. The form will still save to <?php echo date('F Y', mktime(0,0,0,$month,1,$year)); ?>. Continue?')) {
            const copyMonth = <?php echo $month == 1 ? 12 : $month - 1; ?>;
            const copyYear  = <?php echo $month == 1 ? $year - 1 : $year; ?>;
            window.location.href = `manage_budgets.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>&copy_month=${copyMonth}&copy_year=${copyYear}`;
        }
    }
</script>

<?php Layout::footer(); ?>
