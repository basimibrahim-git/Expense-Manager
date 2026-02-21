<?php
$page_title = "Edit Income";
require_once 'config.php'; // NOSONAR
require_once 'includes/header.php'; // NOSONAR
require_once 'includes/sidebar.php'; // NOSONAR

$income_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$income_id) {
    header("Location: income.php?error=Invalid income");
    exit();
}

// Fetch income
$stmt = $pdo->prepare("SELECT * FROM income WHERE id = ? AND tenant_id = ?");
$stmt->execute([$income_id, $_SESSION['tenant_id']]);
$income = $stmt->fetch();

if (!$income) {
    header("Location: income.php?error=Income not found");
    exit();
}

$categories = [
    'Salary' => 'ðŸ’¼ Salary',
    'Incentives' => 'ðŸŽ¯ Incentives / Commission',
    'Business' => 'ðŸ¢ Business Income',
    'Bonus' => 'ðŸŽ Bonus',
    'Investment' => 'ðŸ“ˆ Investment Return',
    'Gift' => 'ðŸŽ€ Gift',
    'Other' => 'ðŸ”¹ Other'
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="monthly_income.php?month=<?php echo date('n', strtotime($income['income_date'])); ?>&year=<?php echo date('Y', strtotime($income['income_date'])); ?>"
            class="text-decoration-none text-muted small">
            <i class="fa-solid fa-arrow-left"></i> Back to Income
        </a>
        <h1 class="h3 fw-bold mb-0">Edit Income</h1>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-check-circle me-2"></i>
        <?php echo htmlspecialchars($_GET['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars($_GET['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="glass-panel p-4">
            <form action="income_actions.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="update_income">
                <input type="hidden" name="income_id" value="<?php echo $income['id']; ?>">

                <div class="mb-3">
                    <label class="form-label" for="amount">Amount <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text fw-bold text-success">AED</span>
                        <input type="number" name="amount" id="amount" class="form-control form-control-lg" step="0.01"
                            value="<?php echo $income['amount']; ?>" required autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="income_date">Date <span class="text-danger">*</span></label>
                    <input type="date" name="income_date" id="income_date" class="form-control form-control-lg"
                        value="<?php echo $income['income_date']; ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="description">Source / Description <span
                            class="text-danger">*</span></label>
                    <input type="text" name="description" id="description" class="form-control"
                        value="<?php echo htmlspecialchars($income['description']); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="category">Category <span class="text-danger">*</span></label>
                    <select name="category" id="category" class="form-select" required>
                        <?php foreach ($categories as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $income['category'] == $key ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row align-items-center mb-3 p-3 bg-light rounded mx-1">
                    <div class="col-8">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_recurring" id="isRecurring"
                                value="1" <?php echo $income['is_recurring'] ? 'checked' : ''; ?>
                                onchange="toggleRecurrence()">
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
                            value="<?php echo $income['recurrence_day'] ?? ''; ?>" placeholder="e.g. 28">
                    </div>
                </div>

                <div class="d-grid gap-2 mb-3">
                    <button type="submit" class="btn btn-primary btn-lg fw-bold">
                        <i class="fa-solid fa-save me-2"></i> Update Income
                    </button>
                </div>
            </form>
            <form action="income_actions.php" method="POST" class="d-grid"
                onsubmit="return confirmSubmit(this, 'Delete <?php echo addslashes(htmlspecialchars($income['description'])); ?> - AED <?php echo number_format($income['amount'], 2); ?> - on <?php echo date('d M Y', strtotime($income['income_date'])); ?> permanently?');">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="delete_income">
                <input type="hidden" name="id" value="<?php echo $income['id']; ?>">
                <button type="submit" class="btn btn-outline-danger py-2">
                    <i class="fa-solid fa-trash me-2"></i> Delete Income
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    function toggleRecurrence() {
        const div = document.getElementById('recurrenceDiv');
        div.style.display = document.getElementById('isRecurring').checked ? 'block' : 'none';
    }
</script>

<?php require_once 'includes/footer.php'; // NOSONAR ?>
