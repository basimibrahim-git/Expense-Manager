<?php
$page_title = "Edit Income";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\Flash;
use App\Helpers\Categories;

Bootstrap::init();

$income_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$income_id) {
    Flash::redirect('income.php', 'error', 'Invalid income');
}

// Fetch income
$stmt = $pdo->prepare("SELECT id, income_date, amount, currency, description, category, is_recurring, recurrence_day FROM income WHERE id = ? AND tenant_id = ?");
$stmt->execute([$income_id, $_SESSION['tenant_id']]);
$income = $stmt->fetch();

if (!$income) {
    Flash::redirect('income.php', 'error', 'Income not found');
}

$currency = strtoupper($income['currency'] ?: 'AED');
$currencies = ['AED', 'INR'];
if (!in_array($currency, $currencies, true)) {
    $currencies[] = $currency;
}

$income_ts = strtotime($income['income_date']);

Layout::header();
Layout::sidebar();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="monthly_income.php?month=<?php echo date('n', $income_ts); ?>&year=<?php echo date('Y', $income_ts); ?>"
            class="text-decoration-none text-muted small">
            <i class="fa-solid fa-arrow-left"></i> Back to Income
        </a>
        <h1 class="h3 fw-bold mb-0">Edit Income</h1>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="glass-panel p-4">
            <form action="income_actions.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="update_income">
                <input type="hidden" name="income_id" value="<?php echo (int) $income['id']; ?>">

                <div class="mb-3">
                    <label class="form-label" for="amount">Amount <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <select name="currency" id="incomeCurrency" class="form-select fw-bold text-success"
                            style="max-width: 100px;" aria-label="Currency">
                            <?php foreach ($currencies as $cur): ?>
                                <option value="<?php echo Html::e($cur); ?>" <?php echo $currency === $cur ? 'selected' : ''; ?>><?php echo Html::e($cur); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="amount" id="amount" class="form-control form-control-lg" step="0.01" min="0.01"
                            value="<?php echo Html::e($income['amount']); ?>" required autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="income_date">Date <span class="text-danger">*</span></label>
                    <input type="date" name="income_date" id="income_date" class="form-control form-control-lg"
                        value="<?php echo Html::e($income['income_date']); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="description">Source / Description <span
                            class="text-danger">*</span></label>
                    <input type="text" name="description" id="description" class="form-control"
                        value="<?php echo Html::e($income['description']); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="category">Category <span class="text-danger">*</span></label>
                    <select name="category" id="category" class="form-select" required>
                        <?php /* a legacy category stays selectable so saving does not silently change it */ ?>
                        <?php echo Categories::incomeOptions((string) $income['category']); ?>
                    </select>
                </div>

                <div class="row align-items-center mb-3 p-3 bg-light rounded mx-1">
                    <div class="col-8">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_recurring" id="isRecurring"
                                value="1" <?php echo $income['is_recurring'] ? 'checked' : ''; ?>
                                data-onchange="toggleRecurrence">
                            <label class="form-check-label fw-bold text-primary" for="isRecurring">
                                Monthly Recurring?
                            </label>
                            <div class="form-text x-small">Enable for Cash Flow Projections</div>
                        </div>
                    </div>
                    <div class="col-4" id="recurrenceDiv"
                        style="<?php echo $income['is_recurring'] ? '' : 'display:none;'; ?>">
                        <label class="small text-muted" for="recurrence_day">Pay Day</label>
                        <input type="number" name="recurrence_day" id="recurrence_day"
                            class="form-control form-control-sm" min="1" max="31"
                            value="<?php echo Html::e($income['recurrence_day'] ?? ''); ?>" placeholder="e.g. 28">
                    </div>
                </div>

                <div class="d-grid gap-2 mb-3">
                    <button type="submit" class="btn btn-primary btn-lg fw-bold">
                        <i class="fa-solid fa-save me-2"></i> Update Income
                    </button>
                </div>
            </form>
            <form action="income_actions.php" method="POST" class="d-grid"
                data-confirm="<?php echo Html::e('Delete ' . $income['description'] . ' - ' . $currency . ' ' . number_format((float) $income['amount'], 2) . ' - on ' . date('d M Y', $income_ts) . ' permanently?'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="delete_income">
                <input type="hidden" name="id" value="<?php echo (int) $income['id']; ?>">
                <input type="hidden" name="month" value="<?php echo date('n', $income_ts); ?>">
                <input type="hidden" name="year" value="<?php echo date('Y', $income_ts); ?>">
                <button type="submit" class="btn btn-outline-danger py-2">
                    <i class="fa-solid fa-trash me-2"></i> Delete Income
                </button>
            </form>
        </div>
    </div>
</div>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    function toggleRecurrence() {
        const div = document.getElementById('recurrenceDiv');
        div.style.display = document.getElementById('isRecurring').checked ? 'block' : 'none';
    }
</script>

<?php Layout::footer(); ?>
