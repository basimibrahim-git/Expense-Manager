<?php
$page_title = "Add Bank";
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;

Bootstrap::init();

Layout::header();
Layout::sidebar();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="my_banks.php" class="text-decoration-none text-muted small">
            <i class="fa-solid fa-arrow-left"></i> Back to Banks
        </a>
        <h1 class="h3 fw-bold mb-0">Add Bank Account</h1>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="glass-panel p-4">
            <form action="bank_actions.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="add_bank">

                <div class="mb-3">
                    <label for="bank_name" class="form-label">Bank Name <span class="text-danger">*</span></label>
                    <input type="text" name="bank_name" id="bank_name" class="form-control form-control-lg"
                        placeholder="e.g. Emirates NBD, ADCB, FAB..." required autofocus>
                </div>

                <div class="mb-3">
                    <label for="account_type" class="form-label">Account Type</label>
                    <select name="account_type" id="account_type" class="form-select">
                        <option value="Current">Current Account</option>
                        <option value="Savings">Savings Account</option>
                        <option value="Salary">Salary Account</option>
                    </select>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="account_number" class="form-label">Account Number</label>
                        <input type="text" name="account_number" id="account_number" class="form-control"
                            placeholder="Optional">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="iban" class="form-label">IBAN</label>
                        <input type="text" name="iban" id="iban" class="form-control" placeholder="Optional">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="currency" class="form-label">Currency</label>
                    <select name="currency" id="currency" class="form-select">
                        <option value="AED">AED - UAE Dirham</option>
                        <option value="USD">USD - US Dollar</option>
                        <option value="EUR">EUR - Euro</option>
                        <option value="GBP">GBP - British Pound</option>
                        <option value="INR">INR - Indian Rupee</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="notes" class="form-label">Notes</label>
                    <textarea name="notes" id="notes" class="form-control" rows="2"
                        placeholder="Optional notes..."></textarea>
                </div>

                <div class="mb-4 p-3 bg-primary bg-opacity-10 rounded border border-primary">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_default" id="isDefault" value="1">
                        <label class="form-check-label fw-bold text-primary" for="isDefault">
                            <i class="fa-solid fa-star me-1"></i> Set as Default Bank
                        </label>
                        <div class="form-text x-small">Pre-selected when adding income to balance</div>
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary py-3 fw-bold">
                        <i class="fa-solid fa-save me-2"></i> Save Bank Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php Layout::footer(); ?>
// Structural Audit Complete
