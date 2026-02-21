<?php
$page_title = "Add Balance";
require_once 'config.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$pre_month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
$pre_year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
$default_date = date('Y-m-d');

if ($pre_month && $pre_year) {
    $default_date = sprintf('%04d-%02d-01', $pre_year, $pre_month);
    if ($pre_month == date('n') && $pre_year == date('Y'))
        $default_date = date('Y-m-d');
}

// Fetch managed banks
$banks_stmt = $pdo->prepare("SELECT id, bank_name FROM banks WHERE tenant_id = ? ORDER BY is_default DESC, bank_name ASC");
$banks_stmt->execute([$_SESSION['tenant_id']]);
$all_banks = $banks_stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">Record Bank Balance</h1>
    <a href="monthly_balances.php?month=<?php echo $pre_month ?? date('n'); ?>&year=<?php echo $pre_year ?? date('Y'); ?>"
        class="btn btn-light">
        <i class="fa-solid fa-arrow-left me-2"></i> Back
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="glass-panel p-4">
            <form action="balance_actions.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="add_balance">

                <div class="mb-3">
                    <label class="form-label">Bank Name <span class="text-danger">*</span></label>
                    <select name="bank_id" class="form-select form-select-lg" required>
                        <option value="">-- Select Bank --</option>
                        <?php foreach ($all_banks as $b): ?>
                            <option value="<?php echo $b['id']; ?>">
                                <?php echo htmlspecialchars($b['bank_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text x-small">Managed banks only. Add via <a href="my_banks.php">My Banks</a>.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Balance Amount <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <select name="currency" class="form-select fw-bold text-info" style="max-width: 100px;">
                            <option value="AED">AED</option>
                            <option value="INR">INR</option>
                        </select>
                        <input type="number" name="amount" class="form-control form-control-lg" step="0.01"
                            placeholder="0.00" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Snapshot Date <span class="text-danger">*</span></label>
                    <input type="date" name="balance_date" class="form-control form-control-lg"
                        value="<?php echo $default_date; ?>" required>
                    <div class="form-text">The date this balance was recorded.</div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-info text-white py-3 fw-bold">
                        <i class="fa-solid fa-save me-2"></i> Save Balance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
