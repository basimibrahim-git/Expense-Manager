<?php
$page_title = "Add Balance";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;

Bootstrap::init();

Layout::header();
Layout::sidebar();

$pre_month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: null;
$pre_year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: null;
$default_date = date('Y-m-d');

if ($pre_month && $pre_year) {
    $default_date = sprintf('%04d-%02d-01', $pre_year, $pre_month);
    if ($pre_month == date('n') && $pre_year == date('Y')) {
        $default_date = date('Y-m-d');
    }
}

// Fetch managed banks
$banks_stmt = $pdo->prepare("SELECT id, bank_name, currency FROM banks WHERE tenant_id = ? ORDER BY is_default DESC, bank_name ASC");
$banks_stmt->execute([$_SESSION['tenant_id']]);
$all_banks = $banks_stmt->fetchAll();
?>

<div class="container-fluid py-4">
    <!-- Back and Header -->
    <div class="mb-4">
        <a href="monthly_balances.php?month=<?php echo (int) ($pre_month ?? date('n')); ?>&year=<?php echo (int) ($pre_year ?? date('Y')); ?>" 
           class="btn btn-sm btn-light rounded-pill px-3 shadow-sm mb-2 hover-lift">
            <i class="fa-solid fa-arrow-left me-1"></i> Back
        </a>
        <h1 class="h3 fw-bold mb-1 text-dark">Record Bank Balance</h1>
        <p class="text-muted mb-0">Manually update the ending balance snapshot for a connected bank account</p>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <div class="glass-panel-premium p-4 shadow-sm">
                <form action="balance_actions.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="add_balance">

                    <!-- Bank Name Selection -->
                    <div class="mb-3">
                        <label for="bank_id" class="form-label fw-bold text-muted small">Bank Name <span class="text-danger">*</span></label>
                        <select name="bank_id" id="bank_id" class="form-select rounded-pill px-3" required>
                            <option value="">-- Select Bank --</option>
                            <?php foreach ($all_banks as $b): ?>
                                <option value="<?php echo (int) $b['id']; ?>" data-currency="<?php echo Html::e($b['currency']); ?>">
                                    <?php echo Html::e($b['bank_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted x-small ps-1 mt-1">
                            Only connected accounts are available. Add new institutions via <a href="my_banks.php" class="text-decoration-none">My Banks</a>.
                        </div>
                    </div>

                    <!-- Balance Amount -->
                    <div class="mb-3">
                        <label for="amount" class="form-label fw-bold text-muted small">Balance Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select name="currency" id="currency_selector" class="form-select fw-bold text-primary rounded-start-pill px-3" style="max-width: 100px;">
                                <option value="AED">AED</option>
                                <option value="INR">INR</option>
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                            </select>
                            <input type="number" name="amount" id="amount" class="form-control rounded-end-pill px-3" step="0.01"
                                placeholder="0.00" required>
                        </div>
                    </div>

                    <!-- Snapshot Date -->
                    <div class="mb-4">
                        <label for="balance_date" class="form-label fw-bold text-muted small">Snapshot Date <span class="text-danger">*</span></label>
                        <input type="date" name="balance_date" id="balance_date" class="form-control rounded-pill px-3"
                            value="<?php echo $default_date; ?>" required>
                        <div class="form-text text-muted x-small ps-1 mt-1">The calendar date that matches this statement balance.</div>
                    </div>

                    <!-- Submit Button -->
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold rounded-pill shadow-sm hover-lift py-2.5">
                            Save Snapshot <i class="fa-solid fa-save ms-1"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    // Auto-update currency selector based on bank default currency
    document.getElementById('bank_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const defaultCurrency = selectedOption.getAttribute('data-currency');
        if (defaultCurrency) {
            const currencySelector = document.getElementById('currency_selector');
            if (currencySelector) {
                currencySelector.value = defaultCurrency;
            }
        }
    });
</script>

<?php Layout::footer(); ?>
