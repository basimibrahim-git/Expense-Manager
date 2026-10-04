<?php
$page_title = "Edit Expense";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\Flash;
use App\Helpers\Categories;
use App\Helpers\SplitHelper;

Bootstrap::init();

$expense_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$expense_id) {
    Flash::redirect('expenses.php', 'error', 'Invalid expense');
}

// Fetch expense
$stmt = $pdo->prepare("SELECT id, user_id, spent_by_user_id, expense_date, currency, original_amount, amount, description, category, tags, payment_method, card_id, cashback_earned, is_fixed, is_subscription FROM expenses WHERE id = ? AND tenant_id = ?");
$stmt->execute([$expense_id, $_SESSION['tenant_id']]);
$expense = $stmt->fetch();

if (!$expense) {
    Flash::redirect('expenses.php', 'error', 'Expense not found');
}

// Fetch cards for dropdown
$cStmt = $pdo->prepare("SELECT id, bank_name, card_name, card_type FROM cards WHERE tenant_id = ? ORDER BY card_name");
$cStmt->execute([$_SESSION['tenant_id']]);
$cards = $cStmt->fetchAll();

// Amount is edited in the entry currency; amount / original_amount gives the rate used to reach AED.
// Rows with a foreign currency but no original amount (legacy) only have a trustworthy AED value.
$currencies = ['AED', 'USD', 'INR', 'EUR', 'GBP'];
$entry_currency = strtoupper($expense['currency'] ?: 'AED');
$has_original = $entry_currency !== 'AED' && $expense['original_amount'] !== null && (float) $expense['original_amount'] > 0;
if (!$has_original) {
    $entry_currency = 'AED';
}
$entry_amount = $has_original ? $expense['original_amount'] : $expense['amount'];
$entry_rate = $has_original ? round((float) $expense['amount'] / (float) $expense['original_amount'], 6) : '';

$expense_ts = strtotime($expense['expense_date']);

// ---- Family split (needs the family_split migration and at least two members) ----
$family_members = [];
$split_ready = false;
$splits = [];             // user id => stored AED share
$payer_id = (int) ($expense['spent_by_user_id'] ?? $expense['user_id']);
try {
    $mStmt = $pdo->prepare("SELECT id, name FROM users WHERE tenant_id = ? ORDER BY name ASC");
    $mStmt->execute([$_SESSION['tenant_id']]);
    $family_members = $mStmt->fetchAll();
    if (count($family_members) > 1) {
        $sStmt = $pdo->prepare("SELECT user_id, share_amount FROM expense_splits WHERE expense_id = ? AND tenant_id = ?");
        $sStmt->execute([$expense_id, $_SESSION['tenant_id']]);
        foreach ($sStmt->fetchAll() as $s) {
            $splits[(int) $s['user_id']] = (float) $s['share_amount'];
        }
        $split_ready = true;
    }
} catch (PDOException $e) {
    $split_ready = false; // migration not run yet
}
$split_on = !empty($splits);
$split_mode = ($split_on && !SplitHelper::isEqualSplit($splits, (float) $expense['amount'], $payer_id)) ? 'custom' : 'equal';
$payer_name = 'the person who paid';
foreach ($family_members as $m) {
    if ((int) $m['id'] === $payer_id) {
        $payer_name = $m['name'];
    }
}

// Custom amounts are edited in the entry currency; convert the stored AED shares back and let
// the payer (or the largest share) absorb the rounding so the prefill adds up exactly.
$split_prefill = [];
if ($split_on) {
    foreach ($splits as $uid => $aed) {
        $split_prefill[$uid] = $has_original ? round($aed / (float) $entry_rate, 2) : $aed;
    }
    $diff = round((float) $entry_amount - array_sum($split_prefill), 2);
    if ($diff != 0.0) {
        $absorb = isset($split_prefill[$payer_id]) ? $payer_id : array_keys($split_prefill, max($split_prefill))[0];
        $split_prefill[$absorb] = round($split_prefill[$absorb] + $diff, 2);
    }
}

Layout::header();
Layout::sidebar();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="monthly_expenses.php?month=<?php echo date('n', $expense_ts); ?>&year=<?php echo date('Y', $expense_ts); ?>" class="text-decoration-none text-muted small">
            <i class="fa-solid fa-arrow-left"></i> Back to Expenses
        </a>
        <h1 class="h3 fw-bold mb-0">Edit Expense</h1>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="glass-panel p-4">
            <form action="expense_actions.php" method="POST" id="editExpenseForm">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="update_expense">
                <input type="hidden" name="expense_id" value="<?php echo (int) $expense['id']; ?>">

                <!-- Amount & Date -->
                <div class="row">
                    <div class="col-md-7 mb-3">
                        <label class="form-label" for="amount">Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select name="currency" id="currencySelect" class="form-select bg-light fw-bold" style="max-width: 90px;"
                                data-onchange="toggleRateField">
                                <?php foreach ($currencies as $cur): ?>
                                    <option value="<?php echo $cur; ?>" <?php echo $entry_currency === $cur ? 'selected' : ''; ?>>
                                        <?php echo $cur; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="amount" id="amount" class="form-control form-control-lg"
                                   step="0.01" min="0.01" value="<?php echo Html::e($entry_amount); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label" for="expense_date">Date <span class="text-danger">*</span></label>
                        <input type="date" name="expense_date" id="expense_date" class="form-control form-control-lg"
                               value="<?php echo Html::e($expense['expense_date']); ?>" required>
                    </div>
                </div>

                <!-- Exchange Rate (only for non-AED amounts) -->
                <div class="mb-3" id="rateDiv" style="<?php echo $entry_currency === 'AED' ? 'display:none;' : ''; ?>">
                    <label class="form-label" for="exchangeRate">Exchange Rate (1 <span id="rateCurrency"><?php echo Html::e($entry_currency); ?></span> = ? AED)</label>
                    <input type="number" name="exchange_rate" id="exchangeRate" class="form-control"
                           step="any" min="0.000001" value="<?php echo Html::e($entry_rate); ?>"
                           placeholder="Leave empty to use the current market rate">
                    <div class="form-text">
                        Currently stored as AED <?php echo number_format((float) $expense['amount'], 2); ?>.
                    </div>
                </div>

                <!-- Description -->
                <div class="mb-3">
                    <label class="form-label" for="description">Description <span class="text-danger">*</span></label>
                    <input type="text" name="description" id="description" class="form-control form-control-lg"
                           value="<?php echo Html::e($expense['description']); ?>" required>
                </div>

                <!-- Category -->
                <div class="mb-3">
                    <label class="form-label" for="category">Category <span class="text-danger">*</span></label>
                    <select name="category" id="category" class="form-select form-select-lg" required>
                        <?php // A legacy category stays selectable so saving does not silently change it ?>
                        <?php echo Categories::expenseOptions($expense['category']); ?>
                    </select>
                </div>

                <!-- Tags -->
                <div class="mb-3">
                    <label class="form-label" for="tags">Tags (Optional)</label>
                    <input type="text" name="tags" id="tags" class="form-control"
                           placeholder="#Vacation2026, #Office..."
                           value="<?php echo Html::e($expense['tags'] ?? ''); ?>">
                </div>

                <!-- Payment Method -->
                <div class="mb-3">
                    <label class="form-label" for="paymentMethod">Payment Method</label>
                    <select name="payment_method" id="paymentMethod" class="form-select" data-onchange="toggleCardSelect">
                        <option value="Cash" <?php echo $expense['payment_method'] == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="Card" <?php echo $expense['payment_method'] == 'Card' ? 'selected' : ''; ?>>Card</option>
                    </select>
                </div>

                <!-- Card Selection -->
                <div class="mb-3" id="cardSelectDiv" style="<?php echo $expense['payment_method'] != 'Card' ? 'display:none;' : ''; ?>">
                    <label class="form-label" for="cardSelect">Select Card</label>
                    <select name="card_id" id="cardSelect" class="form-select">
                        <option value="">-- Choose Card --</option>
                        <?php foreach ($cards as $card): ?>
                            <option value="<?php echo (int) $card['id']; ?>" <?php echo $expense['card_id'] == $card['id'] ? 'selected' : ''; ?>>
                                <?php echo Html::e($card['bank_name'] . ' - ' . $card['card_name']); ?>
                                (<?php echo Html::e($card['card_type']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Rewards & Fixed -->
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="cashback_earned">Rewards Earned</label>
                        <input type="number" name="cashback_earned" id="cashback_earned" class="form-control" step="0.01"
                               value="<?php echo Html::e($expense['cashback_earned'] ?? 0); ?>">
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

                <?php if ($split_ready): ?>
                <!-- Split with family -->
                <input type="hidden" name="split_present" value="1">
                <div class="border rounded-3 p-3 mb-4">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" name="split_enabled" id="splitEnabled"
                            value="1" data-onchange="toggleSplit" <?php echo $split_on ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="splitEnabled">
                            <i class="fa-solid fa-people-arrows me-1 text-primary"></i> Split with family
                        </label>
                    </div>
                    <div class="form-text mt-1">Paid by <?php echo Html::e($payer_name); ?>, who is owed the other members' shares.</div>

                    <div id="splitPanel" class="mt-3" style="<?php echo $split_on ? '' : 'display:none;'; ?>">
                        <div class="btn-group btn-group-sm mb-3" role="group" aria-label="Split mode">
                            <input type="radio" class="btn-check" name="split_mode" id="splitModeEqual" value="equal"
                                <?php echo $split_mode === 'equal' ? 'checked' : ''; ?> data-onchange="updateSplitPreview">
                            <label class="btn btn-outline-primary" for="splitModeEqual">Equal</label>
                            <input type="radio" class="btn-check" name="split_mode" id="splitModeCustom" value="custom"
                                <?php echo $split_mode === 'custom' ? 'checked' : ''; ?> data-onchange="updateSplitPreview">
                            <label class="btn btn-outline-primary" for="splitModeCustom">Custom amounts</label>
                        </div>

                        <?php foreach ($family_members as $member):
                            $mid = (int) $member['id'];
                            $checked = $split_on ? isset($splits[$mid]) : true; ?>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <div class="form-check mb-0 flex-grow-1">
                                    <input class="form-check-input split-user" type="checkbox" name="split_users[]"
                                        id="splitUser<?php echo $mid; ?>" value="<?php echo $mid; ?>"
                                        <?php echo $checked ? 'checked' : ''; ?> data-onchange="updateSplitPreview">
                                    <label class="form-check-label" for="splitUser<?php echo $mid; ?>">
                                        <?php echo Html::e($member['name']); ?>
                                        <?php if ($mid === $payer_id): ?><span class="badge bg-success-subtle text-success ms-1">paid</span><?php endif; ?>
                                    </label>
                                </div>
                                <input type="number" name="split_amounts[<?php echo $mid; ?>]"
                                    class="form-control form-control-sm split-amount" style="max-width:140px;"
                                    data-user="<?php echo $mid; ?>" step="0.01" min="0" placeholder="0.00"
                                    value="<?php echo isset($split_prefill[$mid]) ? Html::e(number_format($split_prefill[$mid], 2, '.', '')) : ''; ?>"
                                    aria-label="<?php echo Html::e('Share for ' . $member['name']); ?>"
                                    data-oninput="updateSplitPreview">
                            </div>
                        <?php endforeach; ?>
                        <div class="small text-muted" id="splitHint"></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Submit -->
                <div class="d-grid gap-2 mb-3">
                    <button type="submit" class="btn btn-primary btn-lg fw-bold">
                        <i class="fa-solid fa-save me-2"></i> Update Expense
                    </button>
                </div>
            </form>
            <form action="expense_actions.php" method="POST" class="d-grid"
                data-confirm="<?php echo Html::e('Delete ' . $expense['description'] . ' - AED ' . number_format((float) $expense['amount'], 2) . ' - on ' . date('d M Y', $expense_ts) . ' permanently?'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="delete_expense">
                <input type="hidden" name="id" value="<?php echo (int) $expense['id']; ?>">
                <button type="submit" class="btn btn-outline-danger py-2">
                    <i class="fa-solid fa-trash me-2"></i> Delete Expense
                </button>
            </form>
        </div>
    </div>
</div>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
function toggleCardSelect() {
    const method = document.getElementById('paymentMethod').value;
    document.getElementById('cardSelectDiv').style.display = method === 'Card' ? 'block' : 'none';
}

const STORED_CURRENCY = <?php echo Html::json($entry_currency); ?>;
const STORED_RATE = <?php echo Html::json((string) $entry_rate); ?>;

// Show the exchange-rate field for non-AED amounts; a different currency needs its own rate
function toggleRateField() {
    const currency = document.getElementById('currencySelect').value;
    document.getElementById('rateDiv').style.display = currency === 'AED' ? 'none' : 'block';
    document.getElementById('rateCurrency').textContent = currency;
    document.getElementById('exchangeRate').value = currency === STORED_CURRENCY ? STORED_RATE : '';
}

// ---- Split with family ----
function splitOn() {
    return !!document.getElementById('splitEnabled')?.checked;
}

function toggleSplit() {
    const panel = document.getElementById('splitPanel');
    if (panel) panel.style.display = splitOn() ? 'block' : 'none';
    updateSplitPreview();
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
    const custom = !!document.getElementById('splitModeCustom')?.checked;
    document.querySelectorAll('.split-amount').forEach(inp => {
        const cb = document.getElementById('splitUser' + inp.dataset.user);
        inp.style.display = custom ? '' : 'none';
        inp.disabled = !custom || !(cb && cb.checked);
    });
    const people = document.querySelectorAll('.split-user:checked').length;
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    hint.classList.remove('text-danger');
    if (!splitOn()) {
        hint.textContent = '';
    } else if (people === 0) {
        hint.textContent = 'Tick at least one family member.';
        hint.classList.add('text-danger');
    } else if (!custom) {
        hint.textContent = 'About ' + (amount / people).toFixed(2) + ' each between ' + people + ' member(s); any rounding cent goes to the payer.';
    } else {
        const sum = customSplitSum();
        const remaining = Math.round((amount - sum) * 100) / 100;
        hint.textContent = 'Assigned ' + sum.toFixed(2) + ' of ' + amount.toFixed(2)
            + (Math.abs(remaining) > 0.01 ? ', ' + remaining.toFixed(2) + ' left to assign.' : ' (adds up).');
        if (Math.abs(remaining) > 0.01) hint.classList.add('text-danger');
    }
}

document.getElementById('amount')?.addEventListener('input', updateSplitPreview);
document.getElementById('editExpenseForm')?.addEventListener('submit', function (e) {
    if (!splitOn() || !document.getElementById('splitHint')) return;
    if (document.querySelectorAll('.split-user:checked').length === 0) {
        e.preventDefault();
        alert('Tick at least one family member to split with, or turn the split off.');
        return;
    }
    if (document.getElementById('splitModeCustom')?.checked) {
        const amount = parseFloat(document.getElementById('amount').value) || 0;
        if (Math.abs(Math.round(amount * 100) - Math.round(customSplitSum() * 100)) > 1) {
            e.preventDefault();
            alert('Custom split amounts must add up to the expense amount.');
        }
    }
});
updateSplitPreview();
</script>

<?php Layout::footer(); ?>
