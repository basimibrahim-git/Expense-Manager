<?php
$page_title = "Add Expense";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;

Bootstrap::init();

try {
    $stmt = $pdo->prepare("SELECT id, bank_name, card_name, card_type, tier, cashback_struct, is_default FROM cards WHERE tenant_id = :tenant_id ORDER BY is_default DESC, created_at DESC");
    $stmt->execute(['tenant_id' => $_SESSION['tenant_id']]);
    $cards = $stmt->fetchAll();

    $default_card_id = null;
    foreach ($cards as $card) {
        if (!empty($card['is_default'])) {
            $default_card_id = $card['id'];
            break;
        }
    }
} catch (PDOException $e) {
    $cards = [];
    $default_card_id = null;
}

$tenant_id = $_SESSION['tenant_id'] ?? null;
$family_members = [];
$family_admin_id = null;
if ($tenant_id) {
    try {
        $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE tenant_id = ? ORDER BY role DESC, name ASC");
        $stmt->execute([$tenant_id]);
        $family_members = $stmt->fetchAll();
        foreach ($family_members as $member) {
            if ($member['role'] === 'family_admin') {
                $family_admin_id = $member['id'];
                break;
            }
        }
    } catch (PDOException $e) {
        $family_members = [];
    }
}

$pre_month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
$pre_year  = filter_input(INPUT_GET, 'year',  FILTER_VALIDATE_INT);
$default_date = date('Y-m-d');
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $default_date = $_GET['date'];
} elseif ($pre_month && $pre_year) {
    $default_date = sprintf('%04d-%02d-01', $pre_year, $pre_month);
    if ($pre_month == date('n') && $pre_year == date('Y')) {
        $default_date = date('Y-m-d');
    }
}

Layout::header();
Layout::sidebar();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">Record Expenses</h1>
    <a href="monthly_expenses.php?month=<?php echo $pre_month ?? date('n'); ?>&year=<?php echo $pre_year ?? date('Y'); ?>"
        class="btn btn-light">
        <i class="fa-solid fa-arrow-left me-2"></i> Back
    </a>
</div>

<?php
$saved_count  = isset($_GET['added']) ? intval($_GET['added']) : 0;
$saved_date   = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : null;
$monthly_url  = 'monthly_expenses.php?month=' . ($pre_month ?? date('n')) . '&year=' . ($pre_year ?? date('Y'));
?>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo htmlspecialchars($_GET['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form action="expense_actions.php" method="POST" id="bulkExpenseForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
    <input type="hidden" name="action" value="add_expense">
    <input type="hidden" name="month" value="<?php echo intval($pre_month ?? date('n')); ?>">
    <input type="hidden" name="year"  value="<?php echo intval($pre_year  ?? date('Y')); ?>">

    <!-- Shared Settings Panel -->
    <div class="glass-panel p-4 mb-3">
        <h6 class="fw-bold text-muted text-uppercase small mb-3">Shared Settings</h6>

        <div class="row g-3">
            <!-- Date -->
            <div class="col-md-3 col-6">
                <label class="form-label small fw-bold" for="expenseDate">Date</label>
                <input type="date" name="expense_date" id="expenseDate" class="form-control"
                    value="<?php echo htmlspecialchars($default_date); ?>" required>
            </div>

            <!-- Payment Method -->
            <div class="col-md-3 col-6">
                <label class="form-label small fw-bold d-block">Payment Method</label>
                <div class="btn-group w-100">
                    <input type="radio" class="btn-check" name="payment_method" id="methodCash" value="Cash"
                        onclick="toggleCardSelect(false)">
                    <label class="btn btn-outline-primary btn-sm py-2" for="methodCash">
                        <i class="fa-solid fa-coins me-1"></i> Cash
                    </label>
                    <input type="radio" class="btn-check" name="payment_method" id="methodCard" value="Card" checked
                        onclick="toggleCardSelect(true)">
                    <label class="btn btn-outline-primary btn-sm py-2" for="methodCard">
                        <i class="fa-solid fa-credit-card me-1"></i> Card
                    </label>
                </div>
            </div>

            <!-- Currency -->
            <div class="col-md-2 col-6">
                <label class="form-label small fw-bold" for="currencySelect">Currency</label>
                <select name="currency" id="currencySelect" class="form-select" onchange="toggleExchangeRate()">
                    <option value="AED">AED</option>
                    <option value="INR">INR</option>
                </select>
            </div>

            <!-- Spent By -->
            <div class="col-md-4 col-6">
                <label class="form-label small fw-bold" for="spentByUser">Spent By</label>
                <select name="spent_by_user_id" id="spentByUser" class="form-select">
                    <?php foreach ($family_members as $member): ?>
                        <option value="<?php echo $member['id']; ?>"
                            <?php echo $member['id'] == $family_admin_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($member['name']); ?>
                            <?php echo $member['role'] === 'family_admin' ? '(Admin)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Exchange Rate (hidden unless non-AED) -->
            <div class="col-12" id="exchangeRateDiv" style="display:none;">
                <div class="alert alert-info py-2 px-3 border-0 d-flex align-items-center gap-3">
                    <label class="form-label mb-0 small fw-bold text-nowrap" for="exchangeRateInput">
                        1 Foreign = ? AED
                    </label>
                    <input type="number" name="exchange_rate" id="exchangeRateInput"
                        class="form-control form-control-sm" placeholder="e.g. 3.67" step="0.001" value="1.00">
                </div>
            </div>

            <!-- Card Selection -->
            <div class="col-12" id="cardSelectionDiv">
                <label class="form-label small fw-bold" for="cardSelect">Card Used</label>
                <select name="card_id" id="cardSelect" class="form-select">
                    <option value="" disabled <?php echo !$default_card_id ? 'selected' : ''; ?>>-- Choose Card --</option>
                    <?php foreach ($cards as $card): ?>
                        <option value="<?php echo $card['id']; ?>"
                            <?php echo $card['id'] == $default_card_id ? 'selected' : ''; ?>
                            data-type="<?php echo htmlspecialchars($card['card_type']); ?>"
                            data-cashback="<?php echo htmlspecialchars($card['cashback_struct']); ?>">
                            <?php echo htmlspecialchars($card['bank_name'] . ' – ' . $card['card_name']); ?>
                            (<?php echo $card['card_type']; ?>)
                            <?php echo !empty($card['is_default']) ? '⭐' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($cards)): ?>
                    <div class="form-text text-warning mt-1">
                        <i class="fa-solid fa-triangle-exclamation"></i> No cards added yet.
                        <a href="add_card.php">Add a card first</a>.
                    </div>
                <?php endif; ?>

                <div class="form-check mt-2" id="deductBalanceDiv">
                    <input class="form-check-input" type="checkbox" name="deduct_balance" id="deductBalance" value="1" checked>
                    <label class="form-check-label small text-muted" for="deductBalance">
                        Deduct from linked bank balance
                        <span class="badge bg-info-subtle text-info x-small ms-1">Debit only</span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- Expense Rows -->
    <div class="glass-panel p-4 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold text-muted text-uppercase small mb-0">Expenses</h6>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted small">Total: <strong id="grandTotal" class="text-success">0.00</strong></span>
                <button type="button" class="btn btn-sm btn-outline-success" onclick="addRow()">
                    <i class="fa-solid fa-plus me-1"></i> Add Row
                </button>
            </div>
        </div>

        <div id="expenseRows"></div>
    </div>

    <div class="d-grid">
        <button type="submit" class="btn btn-success py-3 fw-bold" id="submitBtn">
            <i class="fa-solid fa-floppy-disk me-2"></i>
            Save <span id="rowCount">1</span> Expense(s)
        </button>
    </div>
</form>

<!-- Row Template (hidden) -->
<template id="rowTemplate">
    <div class="expense-row border rounded-3 p-3 mb-2 position-relative">
        <button type="button" class="btn btn-sm btn-link text-danger remove-row position-absolute top-0 end-0 p-2"
            onclick="removeRow(this)" title="Remove">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="row g-2 align-items-start">
            <div class="col-md-4 col-12">
                <input type="text" name="expenses[IDX][description]" class="form-control"
                    placeholder="Description *" required autofocus>
            </div>
            <div class="col-md-2 col-6">
                <div class="input-group">
                    <span class="input-group-text text-muted small px-2">AED</span>
                    <input type="number" name="expenses[IDX][amount]" class="form-control row-amount"
                        placeholder="0.00" step="0.01" min="0.01" required
                        oninput="updateTotal(); calcRowReward(this)">
                </div>
            </div>
            <div class="col-md-3 col-6">
                <select name="expenses[IDX][category]" class="form-select row-category" required
                    onchange="calcRowReward(this)">
                    <option value="" disabled selected>Category *</option>
                    <option value="Grocery">Grocery & Supermarkets</option>
                    <option value="Medical">Medical & Healthcare</option>
                    <option value="Food">Food & Dining</option>
                    <option value="Utilities">Bills & Utilities</option>
                    <option value="Transport">Transport & Fuel</option>
                    <option value="Shopping">Shopping & Apparel</option>
                    <option value="Entertainment">Entertainment</option>
                    <option value="Travel">Travel</option>
                    <option value="Education">Education</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="col-md-3 col-12">
                <input type="text" name="expenses[IDX][tags]" class="form-control"
                    placeholder="Tags (optional)">
            </div>
            <div class="col-12 d-flex flex-wrap gap-3 align-items-center mt-1">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" name="expenses[IDX][is_fixed]" value="1">
                    <label class="form-check-label small">Fixed Cost</label>
                </div>
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" name="expenses[IDX][is_subscription]" value="1">
                    <label class="form-check-label small">Subscription</label>
                </div>
                <span class="row-cashback badge bg-success-subtle text-success ms-auto" style="display:none">
                    <i class="fa-solid fa-gift me-1"></i><span class="cashback-value">0.00</span> cashback
                </span>
                <input type="hidden" name="expenses[IDX][cashback_earned]" class="row-cashback-input" value="0">
            </div>
        </div>
    </div>
</template>

<script>
    const myCards = <?php echo json_encode($cards); ?>;
    let rowIndex = 0;

    function addRow() {
        const template = document.getElementById('rowTemplate');
        const clone = template.content.cloneNode(true);

        // Replace IDX placeholder with real index
        clone.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace('IDX', rowIndex);
        });

        document.getElementById('expenseRows').appendChild(clone);
        rowIndex++;
        updateRowCount();

        // Focus first input of new row
        const rows = document.querySelectorAll('.expense-row');
        const last = rows[rows.length - 1];
        last.querySelector('input[type="text"]')?.focus();
    }

    function removeRow(btn) {
        const rows = document.querySelectorAll('.expense-row');
        if (rows.length <= 1) return; // keep at least one
        btn.closest('.expense-row').remove();
        updateTotal();
        updateRowCount();
    }

    function updateRowCount() {
        const count = document.querySelectorAll('.expense-row').length;
        document.getElementById('rowCount').textContent = count;
    }

    function updateTotal() {
        let total = 0;
        document.querySelectorAll('.row-amount').forEach(inp => {
            total += parseFloat(inp.value) || 0;
        });
        document.getElementById('grandTotal').textContent = total.toFixed(2);
    }

    function calcRowReward(triggerEl) {
        const row = triggerEl.closest('.expense-row');
        const amountEl = row.querySelector('.row-amount');
        const categoryEl = row.querySelector('.row-category');
        const cashbackBadge = row.querySelector('.row-cashback');
        const cashbackInput = row.querySelector('.row-cashback-input');
        const cashbackValueEl = row.querySelector('.cashback-value');

        const cardSelect = document.getElementById('cardSelect');
        const cardId = cardSelect ? cardSelect.value : null;
        const amount = parseFloat(amountEl?.value) || 0;
        const category = categoryEl?.value || '';

        if (!cardId || !category || amount <= 0) {
            cashbackBadge.style.display = 'none';
            if (cashbackInput) cashbackInput.value = 0;
            return;
        }

        const card = myCards.find(c => c.id == cardId);
        if (card && card.cashback_struct) {
            try {
                const struct = JSON.parse(card.cashback_struct);
                const rate = struct[category] || struct['Other'] || 0;
                if (rate > 0) {
                    const earned = (amount * rate / 100).toFixed(2);
                    cashbackValueEl.textContent = earned;
                    if (cashbackInput) cashbackInput.value = earned;
                    cashbackBadge.style.display = 'inline-flex';
                    return;
                }
            } catch (e) {}
        }
        cashbackBadge.style.display = 'none';
        if (cashbackInput) cashbackInput.value = 0;
    }

    // Recalculate all rows when card changes
    document.getElementById('cardSelect')?.addEventListener('change', () => {
        document.querySelectorAll('.expense-row').forEach(row => {
            const amountEl = row.querySelector('.row-amount');
            if (amountEl) calcRowReward(amountEl);
        });
        updateDeductBalance();
    });

    function updateDeductBalance() {
        const cardSelect = document.getElementById('cardSelect');
        const deductDiv = document.getElementById('deductBalanceDiv');
        const deductInput = document.getElementById('deductBalance');
        const cardId = cardSelect?.value;
        if (!cardId) { deductDiv.style.display = 'none'; return; }
        const card = myCards.find(c => c.id == cardId);
        if (card && card.card_type === 'Debit') {
            deductDiv.style.display = 'block';
        } else {
            deductDiv.style.display = 'none';
            if (deductInput) deductInput.checked = false;
        }
    }

    function toggleCardSelect(showCard) {
        const cardDiv = document.getElementById('cardSelectionDiv');
        const cardSelect = document.getElementById('cardSelect');
        if (showCard) {
            cardDiv.style.display = 'block';
            cardSelect.setAttribute('required', 'required');
            updateDeductBalance();
        } else {
            cardDiv.style.display = 'none';
            cardSelect.removeAttribute('required');
            cardSelect.value = '';
            document.getElementById('deductBalanceDiv').style.display = 'none';
        }
    }

    function toggleExchangeRate() {
        const currency = document.getElementById('currencySelect').value;
        const div = document.getElementById('exchangeRateDiv');
        const rateInput = document.getElementById('exchangeRateInput');
        if (currency !== 'AED') {
            div.style.display = 'block';
            if (currency === 'USD') rateInput.value = 3.67;
            else if (currency === 'INR') rateInput.value = 0.0417;
            else if (currency === 'EUR') rateInput.value = 4.00;
            else if (currency === 'GBP') rateInput.value = 4.70;
        } else {
            div.style.display = 'none';
            rateInput.value = 1.00;
        }
    }

    // Validate at least one valid row before submit
    document.getElementById('bulkExpenseForm').addEventListener('submit', function(e) {
        const rows = document.querySelectorAll('.expense-row');
        let valid = false;
        rows.forEach(row => {
            const desc = row.querySelector('input[type="text"]')?.value.trim();
            const amt  = parseFloat(row.querySelector('.row-amount')?.value) || 0;
            const cat  = row.querySelector('.row-category')?.value;
            if (desc && amt > 0 && cat) valid = true;
        });
        if (!valid) {
            e.preventDefault();
            alert('Please fill in at least one complete expense row (description, amount, category).');
        }
    });

    // Init: start with one row
    addRow();
    updateDeductBalance();
</script>

<!-- "Add another?" modal -->
<div class="modal fade" id="addedModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0 rounded-4">
            <div class="modal-body text-center py-5 px-4">
                <div class="mb-3" style="font-size:2.5rem">✅</div>
                <h5 class="fw-bold mb-1">
                    <?php echo $saved_count; ?> expense(s) saved!
                </h5>
                <?php if ($saved_date): ?>
                    <p class="text-muted small mb-4">for <?php echo htmlspecialchars($saved_date); ?></p>
                <?php endif; ?>
                <p class="mb-4">Do you want to add more expenses for this day?</p>
                <div class="d-flex gap-3 justify-content-center">
                    <button type="button" class="btn btn-success px-4 fw-bold"
                        data-bs-dismiss="modal">
                        <i class="fa-solid fa-plus me-2"></i>Yes, add more
                    </button>
                    <a href="<?php echo htmlspecialchars($monthly_url); ?>" class="btn btn-outline-secondary px-4">
                        <i class="fa-solid fa-check me-2"></i>No, I'm done
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($saved_count > 0): ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('addedModal')).show();
    });
</script>
<?php endif; ?>

<?php Layout::footer(); ?>
