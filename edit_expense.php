<?php
$page_title = "Edit Expense";
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;

Bootstrap::init();

Layout::header();
Layout::sidebar();

$expense_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$expense_id) {
    header("Location: expenses.php?error=Invalid expense");
    exit();
}

// Fetch expense
$stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND tenant_id = ?");
$stmt->execute([$expense_id, $_SESSION['tenant_id']]);
$expense = $stmt->fetch();

if (!$expense) {
    header("Location: expenses.php?error=Expense not found");
    exit();
}

// Fetch cards for dropdown
$cStmt = $pdo->prepare("SELECT id, bank_name, card_name, card_type FROM cards WHERE tenant_id = ? ORDER BY card_name");
$cStmt->execute([$_SESSION['tenant_id']]);
$cards = $cStmt->fetchAll();

$categories = ['Grocery', 'Food', 'Transport', 'Shopping', 'Utilities', 'Travel', 'Medical', 'Entertainment', 'Education', 'Other'];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="monthly_expenses.php?month=<?php echo date('n', strtotime($expense['expense_date'])); ?>&year=<?php echo date('Y', strtotime($expense['expense_date'])); ?>" class="text-decoration-none text-muted small">
            <i class="fa-solid fa-arrow-left"></i> Back to Expenses
        </a>
        <h1 class="h3 fw-bold mb-0">Edit Expense</h1>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-check-circle me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($_GET['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="glass-panel p-4">
            <form action="expense_actions.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="update_expense">
                <input type="hidden" name="expense_id" value="<?php echo $expense['id']; ?>">

                <!-- Amount & Date -->
                <div class="row">
                    <div class="col-md-7 mb-3">
                        <label class="form-label" for="amount">Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select name="currency" class="form-select bg-light fw-bold" style="max-width: 90px;">
                                <?php foreach (['AED', 'USD', 'INR', 'EUR', 'GBP'] as $cur): ?>
                                    <option value="<?php echo $cur; ?>" <?php echo ($expense['currency'] ?? 'AED') == $cur ? 'selected' : ''; ?>>
                                        <?php echo $cur; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="amount" id="amount" class="form-control form-control-lg"
                                   step="0.01" value="<?php echo $expense['original_amount'] ?? $expense['amount']; ?>" required>
                        </div>
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label" for="expense_date">Date <span class="text-danger">*</span></label>
                        <input type="date" name="expense_date" id="expense_date" class="form-control form-control-lg"
                               value="<?php echo $expense['expense_date']; ?>" required>
                    </div>
                </div>

                <!-- Description -->
                <div class="mb-3">
                    <label class="form-label" for="description">Description <span class="text-danger">*</span></label>
                    <input type="text" name="description" id="description" class="form-control form-control-lg"
                           value="<?php echo htmlspecialchars($expense['description']); ?>" required>
                </div>

                <!-- Category -->
                <div class="mb-3">
                    <label class="form-label" for="category">Category <span class="text-danger">*</span></label>
                    <select name="category" id="category" class="form-select form-select-lg" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat; ?>" <?php echo $expense['category'] == $cat ? 'selected' : ''; ?>>
                                <?php echo $cat; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Tags -->
                <div class="mb-3">
                    <label class="form-label" for="tags">Tags (Optional)</label>
                    <input type="text" name="tags" id="tags" class="form-control"
                           placeholder="#Vacation2026, #Office..."
                           value="<?php echo htmlspecialchars($expense['tags'] ?? ''); ?>">
                </div>

                <!-- Payment Method -->
                <div class="mb-3">
                    <label class="form-label" for="paymentMethod">Payment Method</label>
                    <select name="payment_method" id="paymentMethod" class="form-select" onchange="toggleCardSelect()">
                        <option value="Cash" <?php echo $expense['payment_method'] == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="Card" <?php echo $expense['payment_method'] == 'Card' ? 'selected' : ''; ?>>Card</option>
                        <option value="Bank Transfer" <?php echo $expense['payment_method'] == 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                    </select>
                </div>

                <!-- Card Selection -->
                <div class="mb-3" id="cardSelectDiv" style="<?php echo $expense['payment_method'] != 'Card' ? 'display:none;' : ''; ?>">
                    <label class="form-label" for="cardSelect">Select Card</label>
                    <select name="card_id" id="cardSelect" class="form-select">
                        <option value="">-- Choose Card --</option>
                        <?php foreach ($cards as $card): ?>
                            <option value="<?php echo $card['id']; ?>" <?php echo $expense['card_id'] == $card['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($card['bank_name'] . ' - ' . $card['card_name']); ?>
                                <span class="text-muted">(<?php echo $card['card_type']; ?>)</span>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Rewards & Fixed -->
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="cashback_earned">Rewards Earned</label>
                        <input type="number" name="cashback_earned" id="cashback_earned" class="form-control" step="0.01"
                               value="<?php echo $expense['cashback_earned'] ?? 0; ?>">
                    </div>
                    <div class="col-md-6 mb-3 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_fixed" id="isFixed" value="1"
                                   <?php echo $expense['is_fixed'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="isFixed">Fixed/Essential Cost</label>
                        </div>
                    </div>
                </div>

                <!-- Subscription -->
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="is_subscription" id="isSub" value="1"
                           <?php echo $expense['is_subscription'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="isSub">This is a monthly recurring subscription</label>
                </div>

                <!-- Submit -->
                <div class="d-grid gap-2 mb-3">
                    <button type="submit" class="btn btn-primary btn-lg fw-bold">
                        <i class="fa-solid fa-save me-2"></i> Update Expense
                    </button>
                </div>
            </form>
            <form action="expense_actions.php" method="POST" class="d-grid"
                onsubmit="return confirmSubmit(this, 'Delete <?php echo addslashes(htmlspecialchars($expense['description'])); ?> - AED <?php echo number_format($expense['amount'], 2); ?> - on <?php echo date('d M Y', strtotime($expense['expense_date'])); ?> permanently?');">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="delete_expense">
                <input type="hidden" name="id" value="<?php echo $expense['id']; ?>">
                <button type="submit" class="btn btn-outline-danger py-2">
                    <i class="fa-solid fa-trash me-2"></i> Delete Expense
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function toggleCardSelect() {
    const method = document.getElementById('paymentMethod').value;
    document.getElementById('cardSelectDiv').style.display = method === 'Card' ? 'block' : 'none';
}
</script>

<?php Layout::footer(); ?>
