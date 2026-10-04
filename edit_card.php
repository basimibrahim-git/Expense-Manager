<?php
$page_title = "Edit Card";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\Categories;

Bootstrap::init();

// Get Card ID
$card_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// Redirect if ID invalid
if (!$card_id) {
    header('Location: my_cards.php');
    exit;
}

// Fetch Card Data
$stmt = $pdo->prepare("SELECT * FROM cards WHERE id = :id AND tenant_id = :tenant_id");
$stmt->execute(['id' => $card_id, 'tenant_id' => $_SESSION['tenant_id']]);
$card = $stmt->fetch();

if (!$card) {
    header('Location: my_cards.php');
    exit;
}

// Fetch all banks for the dropdown
$banks_stmt = $pdo->prepare("SELECT id, bank_name FROM banks WHERE tenant_id = ? ORDER BY is_default DESC, bank_name ASC");
$banks_stmt->execute([$_SESSION['tenant_id']]);
$all_banks = $banks_stmt->fetchAll();

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <!-- Back and Header -->
    <div class="mb-4">
        <a href="my_cards.php" class="btn btn-sm btn-light rounded-pill px-3 mb-2 hover-lift">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Cards
        </a>
        <h1 class="h3 fw-bold mb-1 text-dark">Edit Card Details</h1>
        <p class="text-muted mb-0">Modify configuration, payment schedules, and rewards for this account</p>
    </div>

    <div class="row g-4">
        <!-- Form Section -->
        <div class="col-lg-8 col-md-7">
            <div class="glass-panel-premium p-4 shadow-sm">
                <form action="card_actions.php" method="POST" id="editCardForm">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="update_card">
                    <input type="hidden" name="card_id" value="<?php echo (int) $card['id']; ?>">
                    <input type="hidden" name="card_image" id="cardImageInput" value="<?php echo htmlspecialchars($card['card_image'] ?? ''); ?>">

                    <h5 class="fw-bold mb-3 text-primary"><i class="fa-solid fa-cloud-arrow-down me-2"></i>Bank Integration</h5>
                    
                    <!-- Bank URL Auto-Detect -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-muted small" for="bankUrlInput">Bank Login URL</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-link"></i></span>
                            <input type="url" name="bank_url" id="bankUrlInput" class="form-control"
                                value="<?php echo htmlspecialchars($card['bank_url'] ?? ''); ?>" placeholder="https://...">
                            <button class="btn btn-primary" type="button" id="detectBankBtn" data-onclick="detectBankDetails">
                                <i class="fa-solid fa-sync"></i> Sync
                            </button>
                        </div>
                    </div>

                    <hr class="my-4 text-muted opacity-10">

                    <h5 class="fw-bold mb-3 text-primary"><i class="fa-solid fa-credit-card me-2"></i>Card Details</h5>

                    <div class="row g-3">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold text-muted small" for="bankSelect">Associated Balance Account <span class="text-secondary small">(Optional)</span></label>
                            <select name="bank_id" id="bankSelect" class="form-select rounded-pill px-3">
                                <option value="">-- No Bank Linked --</option>
                                <?php foreach ($all_banks as $b): ?>
                                    <option value="<?php echo (int) $b['id']; ?>" <?php echo $card['bank_id'] == $b['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['bank_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold text-muted small" for="bankNameInput">Bank Name <span class="text-danger">*</span></label>
                            <input type="text" name="bank_name" id="bankNameInput" class="form-control rounded-pill px-3"
                                value="<?php echo htmlspecialchars($card['bank_name']); ?>" required>
                        </div>
                    </div>

                    <div class="mb-3 mt-2">
                        <label class="form-label fw-bold text-muted small" for="cardNameInput">Card Nickname <span class="text-danger">*</span></label>
                        <input type="text" name="card_name" id="cardNameInput" class="form-control rounded-pill px-3"
                            value="<?php echo htmlspecialchars($card['card_name']); ?>" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small" for="cardTypeInput">Card Type <span class="text-danger">*</span></label>
                            <select name="card_type" id="cardTypeInput" class="form-select rounded-pill px-3" required>
                                <option value="Credit" <?php echo ($card['card_type'] == 'Credit') ? 'selected' : ''; ?>>Credit Card</option>
                                <option value="Debit" <?php echo ($card['card_type'] == 'Debit') ? 'selected' : ''; ?>>Debit Card</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small" for="networkInput">Network <span class="text-danger">*</span></label>
                            <select name="network" id="networkInput" class="form-select rounded-pill px-3" required>
                                <option value="Visa" <?php echo ($card['network'] == 'Visa') ? 'selected' : ''; ?>>Visa</option>
                                <option value="Mastercard" <?php echo ($card['network'] == 'Mastercard') ? 'selected' : ''; ?>>Mastercard</option>
                                <option value="Amex" <?php echo ($card['network'] == 'Amex') ? 'selected' : ''; ?>>American Express</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small" for="feeTypeInput">Fee Structure</label>
                            <select name="fee_type" id="feeTypeInput" class="form-select rounded-pill px-3">
                                <option value="LTF" <?php echo (($card['fee_type'] ?? '') == 'LTF') ? 'selected' : ''; ?>>LTF (Lifetime Free)</option>
                                <option value="Paid" <?php echo (($card['fee_type'] ?? '') == 'Paid') ? 'selected' : ''; ?>>Paid</option>
                                <option value="Spend Based" <?php echo (($card['fee_type'] ?? '') == 'Spend Based') ? 'selected' : ''; ?>>Spend Based</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small" for="tierInput">Category / Tier</label>
                            <input type="text" name="tier" id="tierInput" class="form-control rounded-pill px-3" value="<?php echo htmlspecialchars($card['tier']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small" for="limitInput">Credit Limit (AED)</label>
                            <input type="number" name="limit_amount" id="limitInput" class="form-control rounded-pill px-3" step="0.01" value="<?php echo htmlspecialchars($card['limit_amount']); ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold text-muted small" for="firstFourInput">First 4 Digits</label>
                            <input type="text" name="first_four" id="firstFourInput" class="form-control rounded-pill px-3" value="<?php echo htmlspecialchars($card['first_four'] ?? ''); ?>" maxlength="4">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold text-muted small" for="lastFourInput">Last 4 Digits</label>
                            <input type="text" name="last_four" id="lastFourInput" class="form-control rounded-pill px-3" value="<?php echo htmlspecialchars($card['last_four'] ?? ''); ?>" maxlength="4">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small" for="statementDayInput">Statement Day</label>
                            <input type="number" name="statement_day" id="statementDayInput" class="form-control rounded-pill px-3" min="1" max="31" value="<?php echo Html::e($card['statement_day'] ?? ''); ?>">
                            <div class="form-text text-muted x-small ps-1 mt-1">Day of the month the statement closes (31 = last day of the month). Credit cards only.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small" for="billDayInput">Payment Due Day</label>
                            <input type="number" name="bill_day" id="billDayInput" class="form-control rounded-pill px-3" min="1" max="31" value="<?php echo Html::e($card['bill_day'] ?? ''); ?>">
                            <div class="form-text text-muted x-small ps-1 mt-1">If it is on or before the statement day, it falls in the following month.</div>
                        </div>
                    </div>

                    <!-- Default Toggle -->
                    <div class="mb-4 p-3 bg-light rounded-4 border border-light d-flex align-items-center">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="is_default" id="isDefault" value="1" <?php echo !empty($card['is_default']) ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold text-primary mb-0" for="isDefault">
                                <i class="fa-solid fa-star me-1"></i> Set as Default Card
                            </label>
                        </div>
                    </div>

                    <!-- Cashback Category Matrix -->
                    <?php
                    $cb_struct = json_decode($card['cashback_struct'] ?? '{}', true);
                    if (!is_array($cb_struct)) {
                        $cb_struct = [];
                    }
                    ?>
                    <div class="mb-4">
                        <label class="form-label fw-bold text-muted small"><i class="fa-solid fa-percent text-primary me-2"></i>Cashback Category Matrix %</label>
                        <div class="p-3 bg-light rounded-4 border border-light">
                            <div class="row g-2 mb-2">
                                <?php
                                foreach (Categories::EXPENSE as $key => $label):
                                    $cbRate = $cb_struct[$key] ?? 0;
                                    ?>
                                    <div class="col-6 col-md-3">
                                        <label for="cb_<?php echo Html::e($key); ?>" class="x-small text-muted fw-bold mb-1 text-truncate d-block" title="<?php echo Html::e($label); ?>"><?php echo Html::e($label); ?></label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" id="cb_<?php echo Html::e($key); ?>" name="cb_<?php echo Html::e($key); ?>" class="form-control" step="0.1" min="0" max="100"
                                                value="<?php echo is_numeric($cbRate) ? (float) $cbRate : 0; ?>">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Offers & Features -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-muted small" for="featuresInput">Card Offers & Features</label>
                        <textarea name="features" id="featuresInput" class="form-control rounded-4" rows="4"
                            placeholder="Offers and features..."><?php echo htmlspecialchars($card['features'] ?? ''); ?></textarea>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-grid gap-2 mt-4">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold rounded-pill shadow-sm hover-lift py-2.5">
                            <i class="fa-solid fa-save me-1"></i> Update Card Details
                        </button>
                    </div>
                </form>

                <form action="card_actions.php" method="POST" class="d-grid mt-3">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="delete_card">
                    <input type="hidden" name="id" value="<?php echo (int) $card['id']; ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill py-2"
                        data-confirm="<?php echo Html::e('Delete ' . $card['bank_name'] . ' ' . $card['card_name'] . '? This action is permanent.'); ?>">
                        <i class="fa-solid fa-trash me-1"></i> Delete Card Account
                    </button>
                </form>
            </div>
        </div>

        <!-- Preview Column -->
        <div class="col-lg-4 col-md-5">
            <div class="sticky-top" style="top: 24px; z-index: 10;">
                <h5 class="fw-bold text-muted small mb-3">Live Card Preview</h5>
                
                <div class="credit-card-mockup" id="previewCardMockup" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
                    <div class="credit-card-brand" id="previewBrandIcon">
                        <i class="fa-brands fa-cc-visa"></i>
                    </div>
                    <div class="credit-card-chip"></div>
                    <div class="credit-card-number" id="previewDigits">
                        **** **** **** ****
                    </div>
                    <div class="d-flex justify-content-between align-items-end mt-2">
                        <div>
                            <div class="credit-card-holder mb-0" id="previewName" style="font-size: 0.85rem; font-weight: 600; text-shadow: 1px 1px 2px rgba(0,0,0,0.5);">
                                Card Name
                            </div>
                            <small id="previewBank" style="font-size: 0.65rem; opacity: 0.7; letter-spacing: 0.5px; text-transform: uppercase;">
                                Bank Name
                            </small>
                        </div>
                        <div class="text-end">
                            <span style="font-size: 0.6rem; opacity: 0.7; display: block;">TIER</span>
                            <span class="fw-bold" id="previewTier" style="font-size: 0.75rem; letter-spacing: 0.5px; opacity: 0.9; text-transform: uppercase;">
                                Standard
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    async function detectBankDetails() {
        const urlInput = document.getElementById('bankUrlInput');
        const btn = document.getElementById('detectBankBtn');
        const rawUrl = urlInput.value.trim();

        if (!rawUrl) { alert("Please enter a URL first."); return; }
        
        let url = rawUrl.toLowerCase();
        if (!url.startsWith('http')) { url = 'https://' + url; }

        const originalBtnText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        btn.disabled = true;

        try {
            const response = await fetch('fetch_url_data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': <?php echo Html::json(SecurityHelper::generateCsrfToken()); ?>
                },
                body: JSON.stringify({ url: url })
            });
            const data = await response.json();

            if (data.success && data.data) {
                if (data.data.description) {
                    document.getElementById('featuresInput').value = "✅ VERIFIED LIVE DATA:\n" + data.data.description;
                }
                if (data.data.image) {
                    document.getElementById('cardImageInput').value = data.data.image;
                }
                alert("Offers and features successfully updated from live URL.");
                updatePreview();
            } else {
                alert("Failed to synchronize features.");
            }
        } catch (e) {
            console.error(e);
            alert("Network connection error.");
        }
        
        btn.innerHTML = originalBtnText;
        btn.disabled = false;
    }

    const previewInputs = ['bankNameInput', 'cardNameInput', 'tierInput', 'cardTypeInput', 'networkInput', 'firstFourInput', 'lastFourInput'];
    previewInputs.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updatePreview);
    });

    function updatePreview() {
        const bank = document.getElementById('bankNameInput').value || 'Bank Name';
        const name = document.getElementById('cardNameInput').value || 'Card Name';
        const tier = document.getElementById('tierInput').value || 'Tier';
        const net = document.getElementById('networkInput').value;
        const f4 = document.getElementById('firstFourInput').value || '****';
        const l4 = document.getElementById('lastFourInput').value || '****';

        document.getElementById('previewBank').textContent = bank;
        document.getElementById('previewName').textContent = name;
        document.getElementById('previewTier').textContent = tier;
        document.getElementById('previewDigits').textContent = `${f4} **** **** ${l4}`;

        const brandIcon = document.getElementById('previewBrandIcon');
        if (brandIcon) {
            if (net === 'Visa') brandIcon.innerHTML = '<i class="fa-brands fa-cc-visa"></i>';
            else if (net === 'Mastercard') brandIcon.innerHTML = '<i class="fa-brands fa-cc-mastercard"></i>';
            else brandIcon.innerHTML = '<i class="fa-brands fa-cc-amex"></i>';
        }

        const mockup = document.getElementById('previewCardMockup');
        if (mockup) {
            let cardBg = "linear-gradient(135deg, #0f172a 0%, #1e293b 100%)";
            if (tier.toLowerCase().includes('infinite') || tier.toLowerCase().includes('signature') || tier.toLowerCase().includes('world')) {
                cardBg = "linear-gradient(135deg, #1e1b4b 0%, #311042 100%)";
            } else if (tier.toLowerCase().includes('platinum')) {
                cardBg = "linear-gradient(135deg, #334155 0%, #475569 100%)";
            }
            mockup.style.background = cardBg;
        }
    }

    updatePreview();
</script>

<?php Layout::footer(); ?>
