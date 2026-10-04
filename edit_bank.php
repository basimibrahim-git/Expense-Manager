<?php
$page_title = "Edit Bank";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\Flash;

Bootstrap::init();

$bank_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$bank_id) {
    Flash::redirect('my_banks.php', 'error', 'Invalid bank');
}

// Fetch bank
$stmt = $pdo->prepare("SELECT * FROM banks WHERE id = ? AND tenant_id = ?");
$stmt->execute([$bank_id, $_SESSION['tenant_id']]);
$bank = $stmt->fetch();

if (!$bank) {
    Flash::redirect('my_banks.php', 'error', 'Bank not found');
}

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <!-- Back and Header -->
    <div class="mb-4">
        <a href="my_banks.php" class="btn btn-sm btn-light rounded-pill px-3 shadow-sm mb-2 hover-lift">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Banks
        </a>
        <h1 class="h3 fw-bold mb-1 text-dark">Edit Bank Account</h1>
        <p class="text-muted mb-0">Modify configuration parameters for <strong><?php echo Html::e($bank['bank_name']); ?></strong></p>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <div class="glass-panel-premium p-4 shadow-sm mb-4">
                <form action="bank_actions.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="update_bank">
                    <input type="hidden" name="bank_id" value="<?php echo (int) $bank['id']; ?>">

                    <!-- Bank Name -->
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small" for="bank_name">Bank Name <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" id="bank_name" class="form-control rounded-pill px-3"
                               value="<?php echo Html::e($bank['bank_name']); ?>" required>
                    </div>

                    <!-- Account Type -->
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small" for="account_type">Account Type</label>
                        <select name="account_type" id="account_type" class="form-select rounded-pill px-3">
                            <option value="Current" <?php echo $bank['account_type'] == 'Current' ? 'selected' : ''; ?>>Current Account</option>
                            <option value="Savings" <?php echo $bank['account_type'] == 'Savings' ? 'selected' : ''; ?>>Savings Account</option>
                            <option value="Salary" <?php echo $bank['account_type'] == 'Salary' ? 'selected' : ''; ?>>Salary Account</option>
                        </select>
                    </div>

                    <!-- Details Row -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small" for="account_number">Account Number</label>
                            <input type="text" name="account_number" id="account_number" class="form-control rounded-pill px-3"
                                   value="<?php echo Html::e($bank['account_number'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small" for="iban">IBAN</label>
                            <input type="text" name="iban" id="iban" class="form-control rounded-pill px-3"
                                   value="<?php echo Html::e($bank['iban'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Currency -->
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small" for="currency">Currency</label>
                        <select name="currency" id="currency" class="form-select rounded-pill px-3">
                            <?php foreach (['AED', 'USD', 'EUR', 'GBP', 'INR'] as $cur): ?>
                                <option value="<?php echo $cur; ?>" <?php echo ($bank['currency'] ?? 'AED') == $cur ? 'selected' : ''; ?>>
                                    <?php echo $cur; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Notes -->
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small" for="notes">Notes</label>
                        <textarea name="notes" id="notes" class="form-control rounded-4 p-3" rows="2"><?php echo Html::e($bank['notes'] ?? ''); ?></textarea>
                    </div>

                    <!-- Default Box -->
                    <div class="mb-4 p-3 bg-light rounded-4 border border-light">
                        <div class="form-check d-flex align-items-center gap-2">
                            <input class="form-check-input mt-0" type="checkbox" name="is_default" id="isDefault" value="1"
                                   style="width: 18px; height: 18px; border-radius: 4px;" <?php echo $bank['is_default'] ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold text-primary mb-0" for="isDefault">
                                <i class="fa-solid fa-star me-1 text-warning"></i> Set as Default Account
                            </label>
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="d-grid gap-2 mb-3">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold rounded-pill shadow-sm hover-lift py-2.5">
                            Update Account <i class="fa-solid fa-check ms-1"></i>
                        </button>
                    </div>
                </form>

                <hr class="border-light my-4">

                <!-- Danger Zone Delete -->
                <div class="d-grid">
                    <form action="bank_actions.php" method="POST" class="d-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int) $bank['id']; ?>">
                        <button type="submit" class="btn btn-outline-danger rounded-pill py-2.5 fw-bold hover-lift"
                                data-confirm="<?php echo Html::e('Are you sure you want to remove the ' . $bank['bank_name'] . ' account? Historical snapshots are retained.'); ?>" data-confirm-btn="Remove">
                            <i class="fa-solid fa-trash me-2"></i> Remove Bank Account
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php Layout::footer(); ?>
