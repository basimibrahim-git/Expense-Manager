<?php
$page_title = "Record Card Payment";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;

Bootstrap::init();

Layout::header();
Layout::sidebar();

// Get Card ID from URL if redirected from My Cards
$card_id_pre = filter_input(INPUT_GET, 'card_id', FILTER_VALIDATE_INT);

// Fetch family's cards
$cards_stmt = $pdo->prepare("SELECT id, bank_name, card_name FROM cards WHERE tenant_id = ? ORDER BY card_name ASC");
$cards_stmt->execute([$_SESSION['tenant_id']]);
$all_cards = $cards_stmt->fetchAll();

// Fetch managed banks for payment source
$banks_stmt = $pdo->prepare("SELECT id, bank_name FROM banks WHERE tenant_id = ? ORDER BY is_default DESC, bank_name ASC");
$banks_stmt->execute([$_SESSION['tenant_id']]);
$all_banks = $banks_stmt->fetchAll();

$default_date = date('Y-m-d');
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

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger shadow-sm rounded-4 d-flex align-items-center mb-4">
            <i class="fa-solid fa-circle-exclamation me-2 fa-lg"></i>
            <div><?php echo htmlspecialchars($_GET['error']); ?></div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success shadow-sm rounded-4 d-flex align-items-center mb-4">
            <i class="fa-solid fa-circle-check me-2 fa-lg"></i>
            <div><?php echo htmlspecialchars($_GET['success']); ?></div>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <div class="glass-panel-premium p-4 shadow-sm">
                <form action="card_actions.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="record_payment">

                    <!-- Target Card -->
                    <div class="mb-3">
                        <label for="targetCard" class="form-label fw-bold text-muted small">Target Credit Card <span class="text-danger">*</span></label>
                        <select name="card_id" id="targetCard" class="form-select rounded-pill px-3" required>
                            <option value="">-- Select Card --</option>
                            <?php foreach ($all_cards as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo $card_id_pre == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['bank_name'] . ' - ' . $c['card_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Source Bank -->
                    <div class="mb-3">
                        <label for="sourceBank" class="form-label fw-bold text-muted small">Paid From (Source Account) <span class="text-secondary small">(Optional)</span></label>
                        <select name="bank_id" id="sourceBank" class="form-select rounded-pill px-3">
                            <option value="">-- Select Source Bank --</option>
                            <?php foreach ($all_banks as $b): ?>
                                <option value="<?php echo $b['id']; ?>">
                                    <?php echo htmlspecialchars($b['bank_name']); ?>
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
                                step="0.01" placeholder="0.00" required>
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

<?php Layout::footer(); ?>
