<?php
$page_title = "Add Expense";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\Categories;

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

// "Split with family" needs the family_split migration and at least two members
$split_ready = false;
if (count($family_members) > 1) {
    try {
        $pdo->query("SELECT 1 FROM expense_splits LIMIT 0");
        $split_ready = true;
    } catch (PDOException $e) {
        $split_ready = false;
    }
}

$pre_month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: null;
$pre_year  = filter_input(INPUT_GET, 'year',  FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: null;
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
$monthly_url  = 'monthly_expenses.php?month=' . ($pre_month ?? date('n')) . '&year=' . ($pre_year ?? date('Y'));
?>

<form action="expense_actions.php" method="POST" id="bulkExpenseForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
    <input type="hidden" name="action" value="add_expense">
    <input type="hidden" name="month" value="<?php echo intval($pre_month ?? date('n')); ?>">
    <input type="hidden" name="year"  value="<?php echo intval($pre_year  ?? date('Y')); ?>">

    <!-- Shared Settings Panel -->
    <div class="glass-panel p-4 mb-3">
        <h6 class="fw-bold text-muted text-uppercase small mb-1">Shared Settings</h6>
        <p class="text-muted x-small mb-3">
            These apply to every row. Date and Card are per-row below — set the defaults here, then override any row individually.
        </p>

        <div class="row g-3">
            <!-- Default Date (seeds new rows) -->
            <div class="col-md-3 col-6">
                <label class="form-label small fw-bold" for="defaultDate">Default Date</label>
                <input type="date" id="defaultDate" class="form-control"
                    value="<?php echo htmlspecialchars($default_date); ?>">
            </div>

            <!-- Payment Method -->
            <div class="col-md-3 col-6">
                <label class="form-label small fw-bold d-block">Payment Method</label>
                <div class="btn-group w-100">
                    <input type="radio" class="btn-check" name="payment_method" id="methodCash" value="Cash"
                        data-onclick="toggleCardSelect" data-args="<?php echo Html::args(false); ?>">
                    <label class="btn btn-outline-primary btn-sm py-2" for="methodCash">
                        <i class="fa-solid fa-coins me-1"></i> Cash
                    </label>
                    <input type="radio" class="btn-check" name="payment_method" id="methodCard" value="Card" checked
                        data-onclick="toggleCardSelect" data-args="<?php echo Html::args(true); ?>">
                    <label class="btn btn-outline-primary btn-sm py-2" for="methodCard">
                        <i class="fa-solid fa-credit-card me-1"></i> Card
                    </label>
                </div>
            </div>

            <!-- Currency -->
            <div class="col-md-2 col-6">
                <label class="form-label small fw-bold" for="currencySelect">Currency</label>
                <select name="currency" id="currencySelect" class="form-select" data-onchange="toggleExchangeRate">
                    <option value="AED">AED</option>
                    <option value="INR">INR</option>
                </select>
            </div>

            <!-- Spent By -->
            <div class="col-md-4 col-6">
                <label class="form-label small fw-bold" for="spentByUser">Spent By</label>
                <select name="spent_by_user_id" id="spentByUser" class="form-select" data-onchange="updateSplitPreview">
                    <?php foreach ($family_members as $member): ?>
                        <option value="<?php echo (int) $member['id']; ?>"
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

            <!-- Default Card (seeds new rows) + deduct option -->
            <div class="col-12" id="cardSelectionDiv">
                <label class="form-label small fw-bold" for="defaultCardSelect">Default Card</label>
                <select id="defaultCardSelect" class="form-select">
                    <option value="" disabled <?php echo !$default_card_id ? 'selected' : ''; ?>>-- Choose Card --</option>
                    <?php foreach ($cards as $card): ?>
                        <option value="<?php echo (int) $card['id']; ?>"
                            <?php echo $card['id'] == $default_card_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($card['bank_name'] . ' – ' . $card['card_name']); ?>
                            (<?php echo Html::e($card['card_type']); ?>)
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
                        <span class="badge bg-info-subtle text-info x-small ms-1">Debit rows only</span>
                    </label>
                </div>
            </div>

            <?php if ($split_ready): ?>
            <!-- Split with family (off by default; applies to every row) -->
            <div class="col-12">
                <div class="border rounded-3 p-3">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" name="split_enabled" id="splitEnabled"
                            value="1" data-onchange="toggleSplit">
                        <label class="form-check-label small fw-bold" for="splitEnabled">
                            <i class="fa-solid fa-people-arrows me-1 text-primary"></i> Split with family
                        </label>
                        <span class="text-muted x-small ms-1">(the person in "Spent By" is owed the other members' shares)</span>
                    </div>

                    <div id="splitPanel" class="mt-3" style="display:none;">
                        <div class="btn-group btn-group-sm mb-3" role="group" aria-label="Split mode">
                            <input type="radio" class="btn-check" name="split_mode" id="splitModeEqual" value="equal" checked
                                data-onchange="updateSplitPreview">
                            <label class="btn btn-outline-primary" for="splitModeEqual">Equal</label>
                            <input type="radio" class="btn-check" name="split_mode" id="splitModeCustom" value="custom"
                                data-onchange="updateSplitPreview">
                            <label class="btn btn-outline-primary" for="splitModeCustom">Custom amounts</label>
                        </div>

                        <div class="row g-2">
                            <?php foreach ($family_members as $member): ?>
                                <div class="col-md-4 col-sm-6">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="form-check mb-0 flex-grow-1">
                                            <input class="form-check-input split-user" type="checkbox" name="split_users[]"
                                                id="splitUser<?php echo (int) $member['id']; ?>"
                                                value="<?php echo (int) $member['id']; ?>" checked
                                                data-onchange="updateSplitPreview">
                                            <label class="form-check-label small" for="splitUser<?php echo (int) $member['id']; ?>">
                                                <?php echo Html::e($member['name']); ?>
                                            </label>
                                        </div>
                                        <input type="number" name="split_amounts[<?php echo (int) $member['id']; ?>]"
                                            class="form-control form-control-sm split-amount" style="max-width:120px; display:none;"
                                            data-user="<?php echo (int) $member['id']; ?>" disabled
                                            step="0.01" min="0" placeholder="0.00"
                                            aria-label="<?php echo Html::e('Share for ' . $member['name']); ?>"
                                            data-oninput="updateSplitPreview">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="small text-muted mt-2" id="splitHint"></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Expense Rows -->
    <div class="glass-panel p-4 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold text-muted text-uppercase small mb-0">Expenses</h6>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted small">Total: <strong id="grandTotal" class="text-success">0.00</strong></span>
                <button type="button" class="btn btn-sm btn-outline-success" data-onclick="addRow">
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
            data-onclick="removeRow" data-args="<?php echo Html::args('$this'); ?>" title="Remove">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="row g-2 align-items-start">
            <!-- Per-row Date -->
            <div class="col-md-2 col-6">
                <input type="date" name="expenses[IDX][date]" class="form-control form-control-sm row-date"
                    required title="Expense date">
            </div>
            <!-- Description -->
            <div class="col-md-3 col-6">
                <input type="text" name="expenses[IDX][description]" class="form-control form-control-sm"
                    placeholder="Description *" required autofocus>
            </div>
            <!-- Amount -->
            <div class="col-md-2 col-6">
                <div class="input-group input-group-sm">
                    <span class="input-group-text text-muted small px-2">AED</span>
                    <input type="number" name="expenses[IDX][amount]" class="form-control row-amount"
                        placeholder="0.00" step="0.01" min="0.01" required
                        data-oninput="onRowAmountInput">
                </div>
            </div>
            <!-- Category -->
            <div class="col-md-2 col-6">
                <select name="expenses[IDX][category]" class="form-select form-select-sm row-category" required
                    data-onchange="calcRowReward" data-args="<?php echo Html::args('$this'); ?>">
                    <option value="" disabled selected>Category *</option>
                    <?php echo Categories::expenseOptions(); ?>
                </select>
            </div>
            <!-- Per-row Card -->
            <div class="col-md-3 col-6 row-card-wrap">
                <select name="expenses[IDX][card_id]" class="form-select form-select-sm row-card"
                    data-onchange="calcRowReward" data-args="<?php echo Html::args('$this'); ?>" title="Card used">
                    <option value="" disabled selected>-- Card --</option>
                    <?php foreach ($cards as $card): ?>
                        <option value="<?php echo (int) $card['id']; ?>">
                            <?php echo htmlspecialchars($card['bank_name'] . ' – ' . $card['card_name']); ?>
                            (<?php echo Html::e($card['card_type']); ?>)
                            <?php echo !empty($card['is_default']) ? '⭐' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Tags + flags -->
            <div class="col-12 d-flex flex-wrap gap-3 align-items-center mt-1">
                <input type="text" name="expenses[IDX][tags]" class="form-control form-control-sm"
                    placeholder="Tags (optional)" style="max-width:220px">
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

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    const myCards = <?php echo Html::json($cards); ?>;
    let rowIndex = 0;

    // Amount field: refresh the grand total and this row's cashback
    function onRowAmountInput() {
        updateTotal();
        calcRowReward(this);
    }

    function isCardMethod() {
        return document.getElementById('methodCard')?.checked;
    }

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

        const rows = document.querySelectorAll('.expense-row');
        const last = rows[rows.length - 1];

        // Seed the new row with the shared defaults
        const dateEl = last.querySelector('.row-date');
        if (dateEl) dateEl.value = document.getElementById('defaultDate').value;

        const cardEl = last.querySelector('.row-card');
        if (cardEl) cardEl.value = document.getElementById('defaultCardSelect').value || '';

        applyMethodToRow(last);

        // Focus the description field of the new row
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
        updateSplitPreview();
    }

    // ---- Split with family ----
    function splitOn() {
        return !!document.getElementById('splitEnabled')?.checked;
    }

    function splitCustom() {
        return !!document.getElementById('splitModeCustom')?.checked;
    }

    function toggleSplit() {
        const panel = document.getElementById('splitPanel');
        if (panel) panel.style.display = splitOn() ? 'block' : 'none';
        updateSplitPreview();
    }

    // Amounts of the complete rows (description, amount, category)
    function completeRowAmounts() {
        const amounts = [];
        document.querySelectorAll('.expense-row').forEach(row => {
            const desc = row.querySelector('input[type="text"]')?.value.trim();
            const amt  = parseFloat(row.querySelector('.row-amount')?.value) || 0;
            const cat  = row.querySelector('.row-category')?.value;
            if (desc && amt > 0 && cat) amounts.push(amt);
        });
        return amounts;
    }

    function selectedSplitUsers() {
        return Array.from(document.querySelectorAll('.split-user:checked')).map(cb => cb.value);
    }

    function customSplitSum() {
        let sum = 0;
        document.querySelectorAll('.split-amount').forEach(inp => {
            const cb = document.getElementById('splitUser' + inp.dataset.user);
            if (cb && cb.checked) sum += parseFloat(inp.value) || 0;
        });
        return Math.round(sum * 100) / 100;
    }

    function updateSplitPreview() {
        const hint = document.getElementById('splitHint');
        if (!hint) return;
        const custom = splitCustom();
        document.querySelectorAll('.split-amount').forEach(inp => {
            const cb = document.getElementById('splitUser' + inp.dataset.user);
            inp.style.display = custom ? '' : 'none';
            inp.disabled = !custom || !(cb && cb.checked);
        });
        if (!splitOn()) { hint.textContent = ''; return; }

        const people = selectedSplitUsers().length;
        const total = parseFloat(document.getElementById('grandTotal').textContent) || 0;
        hint.classList.remove('text-danger');
        if (people === 0) {
            hint.textContent = 'Tick at least one family member.';
            hint.classList.add('text-danger');
        } else if (!custom) {
            hint.textContent = 'Each row is split equally between ' + people + ' member(s)'
                + (total > 0 ? ', about ' + (total / people).toFixed(2) + ' each for the total of ' + total.toFixed(2) : '')
                + '. Any rounding cent goes to the person who paid.';
        } else {
            const sum = customSplitSum();
            const rows = completeRowAmounts();
            const target = rows.length ? rows[0] : total;
            const remaining = Math.round((target - sum) * 100) / 100;
            hint.textContent = 'Assigned ' + sum.toFixed(2) + ' of ' + target.toFixed(2)
                + (Math.abs(remaining) > 0.01 ? ', ' + remaining.toFixed(2) + ' left to assign.' : ' (adds up).')
                + (rows.length > 1 ? ' Custom amounts apply to every row, so every row needs this same amount.' : '');
            if (Math.abs(remaining) > 0.01) hint.classList.add('text-danger');
        }
    }

    function calcRowReward(triggerEl) {
        const row = triggerEl.closest('.expense-row');
        const amountEl = row.querySelector('.row-amount');
        const categoryEl = row.querySelector('.row-category');
        const cardEl = row.querySelector('.row-card');
        const cashbackBadge = row.querySelector('.row-cashback');
        const cashbackInput = row.querySelector('.row-cashback-input');
        const cashbackValueEl = row.querySelector('.cashback-value');

        const cardId = (isCardMethod() && cardEl) ? cardEl.value : null;
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

    // Show/hide a single row's card selector based on the shared payment method
    function applyMethodToRow(row) {
        const wrap = row.querySelector('.row-card-wrap');
        const cardEl = row.querySelector('.row-card');
        if (!wrap || !cardEl) return;
        if (isCardMethod()) {
            wrap.style.display = '';
            cardEl.setAttribute('required', 'required');
        } else {
            wrap.style.display = 'none';
            cardEl.removeAttribute('required');
        }
        calcRowReward(cardEl);
    }

    // Changing the default card seeds only rows still left blank
    document.getElementById('defaultCardSelect')?.addEventListener('change', function () {
        document.querySelectorAll('.expense-row').forEach(row => {
            const cardEl = row.querySelector('.row-card');
            if (cardEl && !cardEl.value) {
                cardEl.value = this.value || '';
                calcRowReward(cardEl);
            }
        });
    });

    // Changing the default date seeds only rows still on the previous default
    document.getElementById('defaultDate')?.addEventListener('change', function () {
        document.querySelectorAll('.row-date').forEach(dateEl => {
            if (!dateEl.value) dateEl.value = this.value;
        });
    });

    function toggleCardSelect(showCard) {
        const cardDiv = document.getElementById('cardSelectionDiv');
        cardDiv.style.display = showCard ? 'block' : 'none';
        document.querySelectorAll('.expense-row').forEach(applyMethodToRow);
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

    // Validate rows before submit: each complete row needs date (and a card when paying by card)
    document.getElementById('bulkExpenseForm').addEventListener('submit', function(e) {
        const rows = document.querySelectorAll('.expense-row');
        let anyComplete = false;
        let missing = false;
        rows.forEach(row => {
            const desc = row.querySelector('input[type="text"]')?.value.trim();
            const amt  = parseFloat(row.querySelector('.row-amount')?.value) || 0;
            const cat  = row.querySelector('.row-category')?.value;
            const date = row.querySelector('.row-date')?.value;
            const card = row.querySelector('.row-card')?.value;

            const complete = desc && amt > 0 && cat;
            if (complete) {
                anyComplete = true;
                if (!date) missing = true;
                if (isCardMethod() && !card) missing = true;
            }
        });
        if (!anyComplete) {
            e.preventDefault();
            alert('Please fill in at least one complete expense row (description, amount, category).');
            return;
        }
        if (missing) {
            e.preventDefault();
            alert('Every expense row needs a date' + (isCardMethod() ? ' and a card' : '') + '.');
            return;
        }
        if (splitOn()) {
            if (selectedSplitUsers().length === 0) {
                e.preventDefault();
                alert('Tick at least one family member to split with.');
                return;
            }
            if (splitCustom()) {
                const sum = customSplitSum();
                const bad = completeRowAmounts().some(a => Math.abs(Math.round(a * 100) - Math.round(sum * 100)) > 1);
                if (bad) {
                    e.preventDefault();
                    alert('Custom split amounts must add up to the expense amount (' + sum.toFixed(2) + ' assigned so far).');
                }
            }
        }
    });

    // Init: start with one row
    addRow();
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
                <p class="mb-4">Do you want to add more expenses?</p>
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
<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    document.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('addedModal')).show();
    });
</script>
<?php endif; ?>

<?php Layout::footer(); ?>
