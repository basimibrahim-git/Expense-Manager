<?php
$page_title = "Add Income";
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

// Fetch user's managed banks
$banks_stmt = $pdo->prepare("SELECT id, bank_name FROM banks WHERE user_id = ? ORDER BY is_default DESC, bank_name ASC");
$banks_stmt->execute([$_SESSION['user_id']]);
$user_banks = $banks_stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">Record Income</h1>
    <a href="monthly_income.php?month=<?php echo $pre_month ?? date('n'); ?>&year=<?php echo $pre_year ?? date('Y'); ?>"
        class="btn btn-light">
        <i class="fa-solid fa-arrow-left me-2"></i> Back
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="glass-panel p-4">
            <form action="income_actions.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="add_income">

                <div class="mb-3">
                    <label class="form-label">Amount <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <select name="currency" class="form-select fw-bold text-success" style="max-width: 100px;">
                            <option value="AED">AED</option>
                            <option value="INR">INR</option>
                        </select>
                        <input type="number" name="amount" class="form-control form-control-lg" step="0.01"
                            placeholder="0.00" value="<?php echo htmlspecialchars($_GET['amount'] ?? ''); ?>" required
                            autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Date <span class="text-danger">*</span></label>
                    <input type="date" name="income_date" class="form-control form-control-lg"
                        value="<?php echo $default_date; ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Source / Description <span class="text-danger">*</span></label>
                    <input type="text" name="description" class="form-control"
                        placeholder="e.g. Monthly Salary, Freelance Project..."
                        value="<?php echo htmlspecialchars($_GET['description'] ?? ''); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Category <span class="text-danger">*</span></label>
                    <select name="category" class="form-select" required>
                        <option value="Salary">💼 Salary</option>
                        <option value="Incentives">🎯 Incentives / Commission</option>
                        <option value="Business">🏢 Business Income</option>
                        <option value="Bonus">🎁 Bonus</option>
                        <option value="Investment">📈 Investment Return</option>
                        <option value="Gift">🎀 Gift</option>
                        <option value="Other">🔹 Other</option>
                    </select>
                </div>

                <div class="row align-items-center mb-3 p-3 bg-light rounded mx-1">
                    <div class="col-8">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_recurring" id="isRecurring"
                                value="1" onchange="toggleRecurrence()">
                            <label class="form-check-label fw-bold text-primary" for="isRecurring">
                                Monthly Recurring?
                            </label>
                            <div class="form-text x-small">Enable for Cash Flow Projections</div>
                        </div>
                    </div>
                    <div class="col-4" id="recurrenceDiv" style="display:none;">
                        <input type="number" name="recurrence_day" class="form-control" placeholder="Day (1-31)" min="1"
                            max="31">
                    </div>
                </div>

                <script>
                    function toggleRecurrence() {
                        const isChecked = document.getElementById('isRecurring').checked;
                        document.getElementById('recurrenceDiv').style.display = isChecked ? 'block' : 'none';
                    }
                </script>

                <?php if (!empty($user_banks)): ?>
                    <div class="mb-3 p-3 bg-success bg-opacity-10 rounded border border-success">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="add_to_balance" id="addToBalance"
                                value="1" onchange="toggleBankSelect()">
                            <label class="form-check-label fw-bold text-success" for="addToBalance">
                                <i class="fa-solid fa-plus-circle me-1"></i> Add to Bank Balance?
                            </label>
                            <div class="form-text x-small">Increment the bank account balance automatically</div>
                        </div>

                        <div id="bankSelectDiv" class="mt-3" style="display:none;">
                            <label class="form-label small fw-bold">Select Bank Account</label>
                            <select name="bank_id" class="form-select form-select-sm">
                                <?php foreach ($user_banks as $bank): ?>
                                    <option value="<?php echo $bank['id']; ?>">
                                        <?php echo htmlspecialchars($bank['bank_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <script>
                        function toggleBankSelect() {
                            const isChecked = document.getElementById('addToBalance').checked;
                            document.getElementById('bankSelectDiv').style.display = isChecked ? 'block' : 'none';
                        }
                    </script>
                <?php else: ?>
                    <div class="mb-3 p-3 bg-light rounded border text-center">
                        <p class="small text-muted mb-2">No managed bank accounts found.</p>
                        <a href="add_bank.php" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-plus me-1"></i> Add a Bank Account
                        </a>
                        <div class="form-text x-small mt-1">To enable automatic balance updates</div>
                    </div>
                <?php endif; ?>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-success py-3 fw-bold">
                        <i class="fa-solid fa-check me-2"></i> Save Income
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>