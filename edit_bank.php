<?php
$page_title = "Edit Bank";
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;

Bootstrap::init();

Layout::header();
Layout::sidebar();

$bank_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$bank_id) {
    header("Location: my_banks.php?error=Invalid bank");
    exit();
}

// Fetch bank
$stmt = $pdo->prepare("SELECT * FROM banks WHERE id = ? AND tenant_id = ?");
$stmt->execute([$bank_id, $_SESSION['tenant_id']]);
$bank = $stmt->fetch();

if (!$bank) {
    header("Location: my_banks.php?error=Bank not found");
    exit();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="my_banks.php" class="text-decoration-none text-muted small">
            <i class="fa-solid fa-arrow-left"></i> Back to Banks
        </a>
        <h1 class="h3 fw-bold mb-0">Edit Bank Account</h1>
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
    <div class="col-md-6">
        <div class="glass-panel p-4">
            <form action="bank_actions.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="update_bank">
                <input type="hidden" name="bank_id" value="<?php echo $bank['id']; ?>">

                <div class="mb-3">
                    <label class="form-label" for="bank_name">Bank Name <span class="text-danger">*</span></label>
                    <input type="text" name="bank_name" id="bank_name" class="form-control form-control-lg"
                           value="<?php echo htmlspecialchars($bank['bank_name']); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="account_type">Account Type</label>
                    <select name="account_type" id="account_type" class="form-select">
                        <option value="Current" <?php echo $bank['account_type'] == 'Current' ? 'selected' : ''; ?>>Current Account</option>
                        <option value="Savings" <?php echo $bank['account_type'] == 'Savings' ? 'selected' : ''; ?>>Savings Account</option>
                        <option value="Salary" <?php echo $bank['account_type'] == 'Salary' ? 'selected' : ''; ?>>Salary Account</option>
                    </select>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="account_number">Account Number</label>
                        <input type="text" name="account_number" id="account_number" class="form-control"
                               value="<?php echo htmlspecialchars($bank['account_number'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="iban">IBAN</label>
                        <input type="text" name="iban" id="iban" class="form-control"
                               value="<?php echo htmlspecialchars($bank['iban'] ?? ''); ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="currency">Currency</label>
                    <select name="currency" id="currency" class="form-select">
                        <?php foreach (['AED', 'USD', 'EUR', 'GBP', 'INR'] as $cur): ?>
                            <option value="<?php echo $cur; ?>" <?php echo ($bank['currency'] ?? 'AED') == $cur ? 'selected' : ''; ?>>
                                <?php echo $cur; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea name="notes" id="notes" class="form-control" rows="2"><?php echo htmlspecialchars($bank['notes'] ?? ''); ?></textarea>
                </div>

                <div class="mb-4 p-3 bg-primary bg-opacity-10 rounded border border-primary">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_default" id="isDefault" value="1"
                               <?php echo $bank['is_default'] ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold text-primary" for="isDefault">
                            <i class="fa-solid fa-star me-1"></i> Set as Default Bank
                        </label>
                        <div class="form-text x-small">Pre-selected when adding income to balance</div>
                    </div>
                </div>

                <div class="d-grid gap-2 mb-3">
                    <button type="submit" class="btn btn-primary btn-lg fw-bold">
                        <i class="fa-solid fa-save me-2"></i> Update Bank Account
                    </button>
                </div>
            </form>
            <form action="bank_actions.php" method="POST" class="d-grid">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo $bank['id']; ?>">
                <button type="submit" class="btn btn-outline-danger py-2"
                        onclick="return confirmSubmit(this, 'Are you sure you want to delete the <?php echo addslashes(htmlspecialchars($bank['bank_name'])); ?> account?');">
                    <i class="fa-solid fa-trash me-2"></i> Delete Bank Account
                </button>
            </form>
        </div>
    </div>
</div>

<?php Layout::footer(); ?>
