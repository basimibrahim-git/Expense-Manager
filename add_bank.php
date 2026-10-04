<?php
$page_title = "Add Bank";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;

Bootstrap::init();

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <!-- Back and Header -->
    <div class="mb-4">
        <a href="my_banks.php" class="btn btn-sm btn-light rounded-pill px-3 shadow-sm mb-2 hover-lift">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Banks
        </a>
        <h1 class="h3 fw-bold mb-1 text-dark">Add Bank Account</h1>
        <p class="text-muted mb-0">Connect a new bank account to track balances and cash flows</p>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <div class="glass-panel-premium p-4 shadow-sm">
                <form action="bank_actions.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="add_bank">

                    <!-- Bank Name -->
                    <div class="mb-3">
                        <label for="bank_name" class="form-label fw-bold text-muted small">Bank Name <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" id="bank_name" class="form-control rounded-pill px-3"
                            placeholder="e.g. Emirates NBD, ADCB, FAB, Mashreq..." required autofocus>
                    </div>

                    <!-- Account Type -->
                    <div class="mb-3">
                        <label for="account_type" class="form-label fw-bold text-muted small">Account Type</label>
                        <select name="account_type" id="account_type" class="form-select rounded-pill px-3">
                            <option value="Current">Current Account</option>
                            <option value="Savings">Savings Account</option>
                            <option value="Salary">Salary Account</option>
                        </select>
                    </div>

                    <!-- Details Row -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="account_number" class="form-label fw-bold text-muted small">Account Number</label>
                            <input type="text" name="account_number" id="account_number" class="form-control rounded-pill px-3"
                                placeholder="Last 4 digits or full number">
                        </div>
                        <div class="col-md-6">
                            <label for="iban" class="form-label fw-bold text-muted small">IBAN</label>
                            <input type="text" name="iban" id="iban" class="form-control rounded-pill px-3" placeholder="Optional IBAN">
                        </div>
                    </div>

                    <!-- Currency -->
                    <div class="mb-3">
                        <label for="currency" class="form-label fw-bold text-muted small">Base Currency</label>
                        <select name="currency" id="currency" class="form-select rounded-pill px-3">
                            <option value="AED">AED - UAE Dirham</option>
                            <option value="USD">USD - US Dollar</option>
                            <option value="EUR">EUR - Euro</option>
                            <option value="GBP">GBP - British Pound</option>
                            <option value="INR">INR - Indian Rupee</option>
                        </select>
                    </div>

                    <!-- Notes -->
                    <div class="mb-3">
                        <label for="notes" class="form-label fw-bold text-muted small">Notes / Description</label>
                        <textarea name="notes" id="notes" class="form-control rounded-4 p-3" rows="2"
                            placeholder="Optional notes or description..."></textarea>
                    </div>

                    <!-- Default flag checkbox widget -->
                    <div class="mb-4 p-3 bg-light rounded-4 border border-light">
                        <div class="form-check d-flex align-items-center gap-2">
                            <input class="form-check-input mt-0" type="checkbox" name="is_default" id="isDefault" value="1" style="width: 18px; height: 18px; border-radius: 4px;">
                            <label class="form-check-label fw-bold text-primary mb-0" for="isDefault">
                                <i class="fa-solid fa-star me-1 text-warning"></i> Set as Default Account
                            </label>
                        </div>
                        <div class="form-text text-muted x-small mt-1 ps-4">This bank will be automatically pre-selected when logging new transactions.</div>
                    </div>

                    <!-- Submit -->
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold rounded-pill shadow-sm hover-lift py-2.5">
                            Save Bank Account <i class="fa-solid fa-check ms-1"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php Layout::footer(); ?>
