// manage_budgets.php
require_once 'config.php';

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
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?: date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?: date('Y');

// Fetch current budgets for the selected period
$stmt = $pdo->prepare("SELECT category, amount, currency FROM budgets WHERE tenant_id = ? AND month = ? AND year = ?");
$stmt->execute([$tenant_id, $month, $year]);
$existing_budgets = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

// predefined categories for easy setup
$categories = ['Grocery', 'Food', 'Medical', 'Shopping', 'Utilities', 'Transport', 'Travel', 'Entertainment',
'Education', 'Other'];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h2 class="fw-bold mb-0">🎯 Manage Budgets</h2>
            <p class="text-muted">Set monthly spending limits per category</p>
        </div>
        <div class="col-auto">
            <form class="d-flex gap-2" method="GET">
                <select name="month" class="form-select">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <select name="year" class="form-select">
                    <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <button type="submit" class="btn btn-primary">Go</button>
            </form>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="glass-card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="p-4 bg-light border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold">Budget Configuration:
                        <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>
                    </h5>
                    <button class="btn btn-outline-primary btn-sm rounded-pill px-3" onclick="copyLastMonth()">
                        <i class="fa-solid fa-copy me-1"></i> Copy from Last Month
                    </button>
                </div>
                <div class="p-4">
                    <form action="budget_actions.php" method="POST">
                        <input type="hidden" name="action" value="save_budgets">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="month" value="<?php echo $month; ?>">
                        <input type="hidden" name="year" value="<?php echo $year; ?>">

                        <div class="table-responsive">
                            <table class="table table-borderless align-middle">
                                <thead>
                                    <tr class="text-muted small text-uppercase">
                                        <th>Category</th>
                                        <th style="width: 250px;">Monthly Limit (AED)</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $cat): ?>
                                        <tr class="border-bottom-dashed">
                                            <td class="py-3">
                                                <div class="d-flex align-items-center">
                                                    <div class="category-icon me-3 bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center"
                                                        style="width: 40px; height: 40px;">
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
                                                    <span class="fw-bold">
                                                        <?php echo $cat; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="input-group">
                                                    <span class="input-group-text bg-white border-end-0">AED</span>
                                                    <input type="number" step="0.01" name="budgets[<?php echo $cat; ?>]"
                                                        class="form-control border-start-0" placeholder="0.00"
                                                        value="<?php echo $existing_budgets[$cat]['amount'] ?? ''; ?>">
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <?php if (isset($existing_budgets[$cat])): ?>
                                                    <span class="text-success small"><i class="fa-solid fa-check"></i>
                                                        Saved</span>
                                                <?php else: ?>
                                                    <span class="text-muted small italic">Not set</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 pt-3 border-top d-flex justify-content-between">
                            <a href="budget.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                            <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow">
                                Save All Budgets <i class="fa-solid fa-floppy-disk ms-2"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .border-bottom-dashed {
        border-bottom: 1px dashed #dee2e6;
    }

    .border-bottom-dashed:last-child {
        border-bottom: none;
    }
</style>

<script>
    function copyLastMonth() {
        if (confirm('This will load budget values from the previous month. Unsaved changes will be lost. Continue?')) {
            const lastMonth = <?php echo $month == 1 ? 12 : $month - 1; ?>;
            const lastYear = <?php echo $month == 1 ? $year - 1 : $year; ?>;
            window.location.href = `manage_budgets.php?month=${lastMonth}&year=${lastYear}&copy_source=1`;
            // In a real app, we'd probably use AJAX or a specific POST action to copy.
            // For simplicity here, we'll let the user navigate and they can save.
        }
    }
</script>

<?php require_once 'includes/footer.php'; ?>
