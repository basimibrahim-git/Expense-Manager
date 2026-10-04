<?php
$page_title = "Record Card Payment";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\CardCycleHelper;

Bootstrap::init();

$tenant_id = (int) $_SESSION['tenant_id'];

// Fetch family's cards (credit cards first)
$cards_stmt = $pdo->prepare("SELECT id, bank_name, card_name, card_type FROM cards WHERE tenant_id = ? ORDER BY card_type = 'Credit' DESC, card_name ASC");
$cards_stmt->execute([$tenant_id]);
$all_cards = $cards_stmt->fetchAll();

// Preselect the card from ?card_id only when it belongs to this family
$card_id_pre = filter_input(INPUT_GET, 'card_id', FILTER_VALIDATE_INT) ?: null;
if ($card_id_pre !== null && !in_array($card_id_pre, array_map('intval', array_column($all_cards, 'id')), true)) {
    $card_id_pre = null;
}

// What is still due on each credit card's last statement (for prefill + hint)
$due_info = [];
foreach (CardCycleHelper::cycles($pdo, $tenant_id) as $cy) {
    $due_info[$cy['card_id']] = [
        'due'      => $cy['due_remaining'],
        'statement' => $cy['last_statement_amount'],
        'paid'     => $cy['paid_since_statement'],
        'dueDate'  => $cy['last_statement_due_date'] ? date('d M Y', strtotime($cy['last_statement_due_date'])) : '',
        'label'    => CardCycleHelper::dueLabel($cy['days_to_due']),
        'status'   => $cy['status'],
    ];
}
$prefill_amount = ($card_id_pre !== null && isset($due_info[$card_id_pre]) && $due_info[$card_id_pre]['due'] > 0)
    ? number_format($due_info[$card_id_pre]['due'], 2, '.', '')
    : '';

// Fetch managed banks for payment source
$banks_stmt = $pdo->prepare("SELECT id, bank_name FROM banks WHERE tenant_id = ? ORDER BY is_default DESC, bank_name ASC");
$banks_stmt->execute([$tenant_id]);
$all_banks = $banks_stmt->fetchAll();

$default_date = date('Y-m-d');

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="mb-4">
        <a href="my_cards.php" class="btn btn-sm btn-light rounded-pill px-3 mb-2 hover-lift">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Cards
        </a>
        <h1 class="h3 fw-bold mb-1 text-dark">Record Card Payment</h1>
        <p class="text-muted mb-0">Log a card payment to update outstanding limits and reconcile source accounts</p>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <div class="glass-panel-premium p-4 shadow-sm">
                <form action="card_actions.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="record_payment">

                    <!-- Target Card -->
                    <div class="mb-3">
                        <label for="targetCard" class="form-label fw-bold text-muted small">Target Credit Card <span class="text-danger">*</span></label>
                        <select name="card_id" id="targetCard" class="form-select rounded-pill px-3" required data-onchange="payCardSelected">
                            <option value="">-- Select Card --</option>
                            <?php foreach ($all_cards as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo $card_id_pre === (int) $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo Html::e($c['bank_name'] . ' - ' . $c['card_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="dueHint" class="form-text ps-1 mt-1" style="display:none;"></div>
                    </div>

                    <!-- Source Bank -->
                    <div class="mb-3">
                        <label for="sourceBank" class="form-label fw-bold text-muted small">Paid From (Source Account) <span class="text-secondary small">(Optional)</span></label>
                        <select name="bank_id" id="sourceBank" class="form-select rounded-pill px-3">
                            <option value="">-- Select Source Bank --</option>
                            <?php foreach ($all_banks as $b): ?>
                                <option value="<?php echo (int) $b['id']; ?>">
                                    <?php echo Html::e($b['bank_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted x-small ps-1 mt-1">If selected, the payment will deduct balance from this checking/savings account.</div>
                    </div>

                    <!-- Amount -->
                    <div class="mb-3">
                        <label for="paymentAmount" class="form-label fw-bold text-muted small">Payment Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold text-success rounded-start-pill px-3">AED</span>
                            <input type="number" name="amount" id="paymentAmount" class="form-control rounded-end-pill px-3"
                                step="0.01" min="0.01" placeholder="0.00" value="<?php echo Html::e($prefill_amount); ?>" required data-oninput="payAmountEdited">
                        </div>
                    </div>

                    <!-- Date -->
                    <div class="mb-4">
                        <label for="paymentDate" class="form-label fw-bold text-muted small">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" id="paymentDate" class="form-control rounded-pill px-3"
                            value="<?php echo $default_date; ?>" required>
                    </div>

                    <!-- Submit -->
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold rounded-pill shadow-sm hover-lift py-2.5">
                            Record Payment <i class="fa-solid fa-receipt ms-1"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    const PAY_DUE_INFO = <?php echo Html::json((object) $due_info); ?>;
    let payAmountTouched = false;

    function payAmountEdited() {
        payAmountTouched = true;
    }

    function payShowHint(cardId) {
        const hint = document.getElementById('dueHint');
        const info = PAY_DUE_INFO[cardId];
        hint.textContent = '';
        if (!info) {
            hint.style.display = 'none';
            return;
        }
        const fmt = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        let text;
        if (info.due > 0) {
            text = 'Statement AED ' + fmt(info.statement) + ', paid AED ' + fmt(info.paid) + ' → AED ' + fmt(info.due) + ' still due'
                + (info.dueDate ? ' by ' + info.dueDate + ' (' + info.label + ')' : '') + '.';
        } else {
            text = info.statement > 0 ? 'Last statement is fully paid.' : 'Nothing due on the last statement.';
        }
        hint.textContent = text;
        hint.className = 'form-text ps-1 mt-1 ' + (info.status === 'overdue' ? 'text-danger fw-bold' : (info.status === 'due_soon' ? 'text-warning-emphasis fw-bold' : 'text-muted'));
        hint.style.display = 'block';
    }

    function payCardSelected() {
        const cardId = this.value;
        payShowHint(cardId);
        const info = PAY_DUE_INFO[cardId];
        const amount = document.getElementById('paymentAmount');
        if (!payAmountTouched) {
            amount.value = (info && info.due > 0) ? Number(info.due).toFixed(2) : '';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        payShowHint(document.getElementById('targetCard').value);
    });
</script>

<?php Layout::footer(); ?>
