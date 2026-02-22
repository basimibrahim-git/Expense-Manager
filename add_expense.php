<?php
$page_title = "Add Expense";
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;

Bootstrap::init();

// Fetch family's cards for dropdown
try {
    $stmt = $pdo->prepare("SELECT id, bank_name, card_name, card_type, tier, cashback_struct, is_default FROM cards WHERE tenant_id = :tenant_id ORDER BY is_default DESC, created_at DESC");
    $stmt->execute(['tenant_id' => $_SESSION['tenant_id']]);
    $cards = $stmt->fetchAll();

    // Find default card
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

// Fetch family members for "Spent By" dropdown
$tenant_id = $_SESSION['tenant_id'] ?? null;
$family_members = [];
$family_admin_id = null;
if ($tenant_id) {
    try {
        $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE tenant_id = ? ORDER BY role DESC, name ASC");
        $stmt->execute([$tenant_id]);
        $family_members = $stmt->fetchAll();

        // Identify the family admin to set as default
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

// Pre-fill Date Logic
$pre_month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
$pre_year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);

// Default Date: Today (UAE Time via config)
$default_date = date('Y-m-d');

// If date provided from "Add Another" flow, use it
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $default_date = $_GET['date'];
}
// If month/year provided from Month View, set to 1st of that month
elseif ($pre_month && $pre_year) {
    $default_date = sprintf('%04d-%02d-01', $pre_year, $pre_month);
    // If we are currently in that month, set to today
    if ($pre_month == date('n') && $pre_year == date('Y')) {
        $default_date = date('Y-m-d');
    }
}

Layout::header();
Layout::sidebar();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">Record New Expense</h1>
    <a href="monthly_expenses.php?month=<?php echo $pre_month ?? date('n'); ?>&year=<?php echo $pre_year ?? date('Y'); ?>"
        class="btn btn-light">
        <i class="fa-solid fa-arrow-left me-2"></i> Back
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <?php if (isset($_GET['added'])): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center" role="alert">
                <i class="fa-solid fa-check-circle fa-lg me-3"></i>
                <output>
                    <strong>Expense Saved!</strong>
                    <span class="badge bg-success ms-2"><?php echo intval($_GET['count'] ?? 1); ?> added this session</span>
                </output>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="glass-panel p-4">
            <form action="expense_actions.php" method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="add_expense">
                <input type="hidden" name="add_count" value="<?php echo intval($_GET['count'] ?? 0); ?>">

                <!-- Payment Method Toggle (Moved to Top) -->
                <div class="mb-3">
                    <fieldset class="btn-group w-100" aria-labelledby="paymentMethodLabel">
                        <legend id="paymentMethodLabel" class="form-label d-block">Payment Method</legend>
                        <input type="radio" class="btn-check" name="payment_method" id="methodCash" value="Cash" checked
                            onclick="toggleCardSelect(false)"
                            onkeydown="if(event.key==='Enter') toggleCardSelect(false)">
                        <label class="btn btn-outline-primary py-2" for="methodCash">
                            <i class="fa-solid fa-coins me-2"></i> Cash
                        </label>

                        <input type="radio" class="btn-check" name="payment_method" id="methodCard" value="Card"
                            onclick="toggleCardSelect(true)" onkeydown="if(event.key==='Enter') toggleCardSelect(true)">
                        <label class="btn btn-outline-primary py-2" for="methodCard">
                            <i class="fa-solid fa-credit-card me-2"></i> Card
                        </label>
                    </fieldset>
                </div>

                <!-- Card Selection (Hidden by default unless Card selected) -->
                <div class="mb-4" id="cardSelectionDiv" style="display: none;">
                    <label class="form-label" for="cardSelect">Select Card Used <span
                            class="text-danger">*</span></label>
                    <select name="card_id" id="cardSelect" class="form-select">
                        <option value="" disabled <?php echo !$default_card_id ? 'selected' : ''; ?>>-- Choose Card --
                        </option>
                        <?php foreach ($cards as $card): ?>
                            <option value="<?php echo $card['id']; ?>" <?php echo $card['id'] == $default_card_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($card['bank_name'] . ' - ' . $card['card_name']); ?>
                                (<?php echo $card['card_type']; ?>)
                                <?php echo !empty($card['is_default']) ? '⭐' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($cards)): ?>
                        <div class="form-text text-warning mt-2">
                            <i class="fa-solid fa-exclamation-triangle"></i> You haven't added any cards yet.
                            <a href="add_card.php">Add a card first</a>.
                        </div>
                    <?php endif; ?>

                    <div class="form-check mt-3" id="deductBalanceDiv">
                        <input class="form-check-input" type="checkbox" name="deduct_balance" id="deductBalance"
                            value="1" checked>
                        <label class="form-check-label small text-muted" for="deductBalance">
                            Automatically deduct this amount from the linked Bank Balance?
                            <span class="badge bg-info-subtle text-info x-small ms-1">Debit only</span>
                        </label>
                    </div>
                </div>

                <!-- Amount & Date -->
                <div class="row">
                    <div class="col-md-7 mb-3">
                        <label class="form-label" for="expenseAmount">Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select name="currency" id="currencySelect" class="form-select bg-light fw-bold"
                                aria-label="Currency" style="max-width: 90px;" onchange="toggleExchangeRate()">
                                <option value="AED">AED</option>
                                <option value="INR">INR</option>
                            </select>
                            <input type="number" name="amount" id="expenseAmount" class="form-control form-control-lg"
                                placeholder="0.00" step="0.01"
                                value="<?php echo htmlspecialchars($_GET['amount'] ?? ''); ?>" required autofocus
                                onkeyup="calculateRewards()">
                        </div>
                    </div>

                    <div class="col-md-5 mb-3">
                        <label class="form-label" for="expenseDate">Date <span class="text-danger">*</span></label>
                        <input type="date" name="expense_date" id="expenseDate" class="form-control form-control-lg"
                            value="<?php echo htmlspecialchars($default_date); ?>" required>
                    </div>

                    <div class="col-12 mb-3">
                        <label class="form-label" for="spentByUser">Spent By <span class="text-danger">*</span></label>
                        <select name="spent_by_user_id" id="spentByUser" class="form-select">
                            <?php foreach ($family_members as $member): ?>
                                <option value="<?php echo $member['id']; ?>" <?php echo $member['id'] == $family_admin_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($member['name']); ?>
                                    <?php echo $member['role'] === 'family_admin' ? '(Admin)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small text-muted">Who incurred this cost? Defaults to Family Head.</div>
                    </div>

                    <!-- Hidden Exchange Rate Input (Now in its own row/col for space) -->
                    <div class="col-12 mb-3" id="exchangeRateDiv" style="display:none;">
                        <div
                            class="alert alert-info py-2 px-3 border-0 d-flex justify-content-between align-items-center">
                            <div>
                                <label class="form-label mb-0 small fw-bold" for="exchangeRateInput">Exchange Rate (1
                                    Foreign = ? AED)</label>
                                <input type="number" name="exchange_rate" id="exchangeRateInput"
                                    class="form-control form-control-sm mt-1" placeholder="e.g. 3.67" step="0.001"
                                    value="1.00" onkeyup="calculateRewards()">
                            </div>
                            <div class="text-end small">
                                <span class="text-muted">Auto-deduction will use converted AED.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    function toggleExchangeRate() {
                        const currency = document.getElementById('currencySelect').value;
                        const div = document.getElementById('exchangeRateDiv');
                        const rateInput = document.getElementById('exchangeRateInput');

                        if (currency !== 'AED') {
                            div.style.display = 'block';
                            // Default Rates
                            if (currency === 'USD') rateInput.value = 3.67;
                            else if (currency === 'INR') rateInput.value = 0.0417; // 1 AED = 24 INR -> 1 INR = 0.0417 AED
                            else if (currency === 'EUR') rateInput.value = 4.00;
                            else if (currency === 'GBP') rateInput.value = 4.70;
                        } else {
                            div.style.display = 'none';
                            rateInput.value = 1.00;
                        }
                    }
                </script>

                <!-- Description -->
                <div class="mb-3">
                    <label class="form-label" for="expenseDesc">Description <span class="text-danger">*</span></label>
                    <input type="text" name="description" id="expenseDesc" class="form-control"
                        placeholder="e.g. Netflix, Gym..."
                        value="<?php echo htmlspecialchars($_GET['description'] ?? ''); ?>" required>

                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="is_subscription" id="isSub" value="1"
                            <?php echo isset($_GET['subscription']) ? 'checked' : ''; ?>> <label
                            class="form-check-label text-muted small" for="isSub">
                            This is a monthly recurring subscription
                        </label>
                    </div>
                </div>

                <!-- Category -->
                <div class="mb-3">
                    <label class="form-label" for="expenseCategory">Category <span class="text-danger">*</span></label>
                    <select name="category" id="expenseCategory" class="form-select" required
                        onchange="calculateRewards()">
                        <option value="" disabled selected>Select Category</option>
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

                <div class="mb-3">
                    <label class="form-label" for="expenseTags">Tags (Optional)</label>
                    <input type="text" name="tags" id="expenseTags" class="form-control"
                        placeholder="#Vacation2026, #Office, #Family...">
                    <div class="form-text">Use comma or hash to separate tags.</div>
                </div>

                <hr class="text-muted my-4">

                <div class="row align-items-center mb-3">
                    <div class="col-md-6" id="rewardsSection" style="display: none;">
                        <label class="form-label" for="cashbackEarned">Rewards Earned <span
                                class="badge bg-success-subtle text-success x-small">Auto</span></label>
                        <div class="input-group">
                            <span class="input-group-text text-success bg-success-subtle"><i
                                    class="fa-solid fa-gift"></i></span>
                            <input type="number" name="cashback_earned" id="cashbackEarned"
                                class="form-control bg-light" placeholder="0.00" step="0.01" readonly
                                aria-label="Rewards Amount">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" name="is_fixed" id="isFixed" value="1">
                            <label class="form-check-label" for="isFixed">
                                Fixed / Essential Cost?
                                <div class="text-muted x-small">e.g. Rent, Bills (vs. Dining, Fun)</div>
                            </label>
                        </div>
                    </div>
                </div>



                <!-- Add Another Toggle -->
                <div class="form-check mb-3 p-3 bg-primary bg-opacity-10 rounded border border-primary">
                    <input class="form-check-input" type="checkbox" name="add_another" id="addAnother" value="1"
                        checked>
                    <label class="form-check-label fw-bold text-primary" for="addAnother">
                        <i class="fa-solid fa-plus me-1"></i> Add another expense after saving
                    </label>
                    <div class="form-text x-small">Stay on this page to quickly log multiple transactions.</div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-success py-3 fw-bold"
                        onclick="if(navigator.vibrate) navigator.vibrate(50);">
                        <i class="fa-solid fa-plus-circle me-2"></i> Save Expense
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<script>
    const myCards = <?php echo json_encode($cards); ?>;

    function calculateRewards() {
        const cardId = document.getElementById('cardSelect').value;
        const categoryElem = document.getElementById('expenseCategory');
        const category = categoryElem ? categoryElem.value : '';
        const amountElem = document.getElementById('expenseAmount');
        const amount = amountElem ? parseFloat(amountElem.value) || 0 : 0;
        const rewardsInput = document.getElementById('cashbackEarned');

        if (!cardId || !category || amount <= 0) {
            if (rewardsInput) rewardsInput.value = "";
            return;
        }

        const card = myCards.find(c => c.id == cardId);
        if (card && card.cashback_struct) {
            try {
                const struct = JSON.parse(card.cashback_struct);
                let rate = struct[category] || struct['Other'] || 0;

                // If it's a numeric value, calculate
                if (rate > 0) {
                    const earned = (amount * (rate / 100)).toFixed(2);
                    if (rewardsInput) {
                        rewardsInput.value = earned;
                        // Visual feedback
                        rewardsInput.parentElement.classList.add('pulse-success');
                        setTimeout(() => rewardsInput.parentElement.classList.remove('pulse-success'), 1000);
                    }
                }
            } catch (e) { console.error("Reward calculation error", e); }
        }
    }

    // Attach listeners
    const cardSelect = document.getElementById('cardSelect');
    if (cardSelect) {
        cardSelect.addEventListener('change', calculateRewards);
        cardSelect.addEventListener('change', updateDeductBalance);
    }

    function updateDeductBalance() {
        const cardId = document.getElementById('cardSelect').value;
        const deductDiv = document.getElementById('deductBalanceDiv');

        if (!cardId) {
            deductDiv.style.display = 'none';
            return;
        }

        const card = myCards.find(c => c.id == cardId);
        if (card && card.card_type === 'Debit') {
            deductDiv.style.display = 'block';
        } else {
            deductDiv.style.display = 'none';
            const deductInput = document.getElementById('deductBalance');
            if (deductInput) deductInput.checked = false;
        }
    }

    function toggleCardSelect(showCard) {
        const cardDiv = document.getElementById('cardSelectionDiv');
        const cardSelect = document.getElementById('cardSelect');
        const rewardsSec = document.getElementById('rewardsSection');

        if (showCard) {
            cardDiv.style.display = 'block';
            if (rewardsSec) rewardsSec.style.display = 'block';
            cardSelect.setAttribute('required', 'required');

            // Add slight animation
            cardDiv.style.opacity = 0;
            setTimeout(() => {
                cardDiv.style.opacity = 1;
                cardDiv.style.transition = 'opacity 0.3s';
                calculateRewards(); // Re-calc on show
                updateDeductBalance(); // Check if debit
            }, 10);
        } else {
            cardDiv.style.display = 'none';
            if (rewardsSec) rewardsSec.style.display = 'none';
            cardSelect.removeAttribute('required');
            cardSelect.value = ""; // Reset
            const rewardsInput = document.getElementById('cashbackEarned');
            if (rewardsInput) rewardsInput.value = "";
            const deductDiv = document.getElementById('deductBalanceDiv');
            if (deductDiv) deductDiv.style.display = 'none';
        }
    }
</script>

<style>
    @keyframes pulse-success {
        0% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.02);
            background-color: rgba(25, 135, 84, 0.1);
        }

        100% {
            transform: scale(1);
        }
    }

    .pulse-success {
        animation: pulse-success 0.5s ease-in-out;
    }
</style>

<?php Layout::footer(); ?>