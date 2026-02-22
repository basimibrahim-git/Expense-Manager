<?php
$page_title = "Record Card Payment";
require_once __DIR__ . '/vendor/autoload.php';
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

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">Record Card Payment</h1>
</div>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger shadow-sm border-0 d-flex align-items-center mb-4">
        <i class="fa-solid fa-circle-exclamation me-2 fa-lg"></i>
        <div><?php echo htmlspecialchars($_GET['error']); ?></div>
    </div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success shadow-sm border-0 d-flex align-items-center mb-4">
        <i class="fa-solid fa-circle-check me-2 fa-lg"></i>
        <div><?php echo htmlspecialchars($_GET['success']); ?></div>
    </div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="glass-panel p-4">
            <form action="card_actions.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="record_payment">

                <!-- Target Card -->
                <div class="mb-3">
                    <label for="targetCard" class="form-label">Which card are you paying? <span
                            class="text-danger">*</span></label>
                    <select name="card_id" id="targetCard" class="form-select form-select-lg" required>
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
                    <label for="sourceBank" class="form-label">Paid From (Source Account) <span
                            class="text-secondary small">(Optional)</span></label>
                    <select name="bank_id" id="sourceBank" class="form-select">
                        <option value="">-- Select Source Bank --</option>
                        <?php foreach ($all_banks as $b): ?>
                            <option value="<?php echo $b['id']; ?>">
                                <?php echo htmlspecialchars($b['bank_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text x-small">If selected, the amount will be deducted from this bank's balance.
                    </div>
                </div>

                <!-- Amount -->
                <div class="mb-3">
                    <label for="paymentAmount" class="form-label">Payment Amount <span
                            class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text fw-bold text-success">AED</span>
                        <input type="number" name="amount" id="paymentAmount" class="form-control form-control-lg"
                            step="0.01" placeholder="0.00" required>
                    </div>
                </div>

                <!-- Date -->
                <div class="mb-3">
                    <label for="paymentDate" class="form-label">Payment Date <span class="text-danger">*</span></label>
                    <input type="date" name="payment_date" id="paymentDate" class="form-control"
                        value="<?php echo $default_date; ?>" required>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-primary py-3 fw-bold">
                        <i class="fa-solid fa-receipt me-2"></i> Record Payment
                    </button>
                    <div class="form-text text-center mt-2">Recording a payment updates your remaining card limit.</div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php Layout::footer(); ?>