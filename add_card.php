<?php
$page_title = "Add Card";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\Categories;

Bootstrap::init();

Layout::header();
Layout::sidebar();

// Fetch all banks for the dropdown
$banks_stmt = $pdo->prepare("SELECT id, bank_name FROM banks WHERE tenant_id = ? ORDER BY is_default DESC, bank_name ASC");
$banks_stmt->execute([$_SESSION['tenant_id']]);
$all_banks = $banks_stmt->fetchAll();
?>

<div class="container-fluid py-4">
    <!-- Back and Header -->
    <div class="mb-4 d-flex justify-content-between align-items-center">
        <div>
            <a href="my_cards.php" class="btn btn-sm btn-light rounded-pill px-3 mb-2 hover-lift">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Cards
            </a>
            <h1 class="h3 fw-bold mb-0 text-dark">Add New Card</h1>
        </div>
    </div>

    <div class="row g-4">
        <!-- Form Section -->
        <div class="col-lg-8 col-md-7">
            <div class="glass-panel-premium p-4 shadow-sm">
                <form action="card_actions.php" method="POST" id="addCardForm">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="add_card">
                    <input type="hidden" name="card_image" id="cardImageInput">

                    <h5 class="fw-bold mb-3 text-primary"><i class="fa-solid fa-cloud-arrow-down me-2"></i>Bank Integration</h5>
                    
                    <!-- Bank URL Auto-Detect -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-muted small" for="bankUrlInput">Bank Login URL <span class="text-secondary small">(Optional)</span> <span class="badge bg-info text-dark rounded-pill ms-2">Smart Auto-Detect</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-link"></i></span>
                            <input type="url" name="bank_url" id="bankUrlInput" class="form-control"
                                placeholder="Paste bank URL here (e.g. https://online.adcb.com...)">
                            <button class="btn btn-primary" type="button" id="detectBankBtn" data-onclick="detectBankDetails">
                                <i class="fa-solid fa-magic-wand-sparkles"></i> Auto-Fill
                            </button>
                        </div>
                        <div class="form-text text-muted x-small ps-1 mt-1">Paste the URL to automatically populate details and active rewards.</div>
                    </div>

                    <hr class="my-4 text-muted opacity-10">

                    <h5 class="fw-bold mb-3 text-primary"><i class="fa-solid fa-credit-card me-2"></i>Card Details</h5>

                    <div class="row g-3">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold text-muted small" for="bankSelect">Associated Balance Account <span class="text-secondary small">(Optional)</span></label>
                            <select name="bank_id" id="bankSelect" class="form-select rounded-pill px-3">
                                <option value="">-- No Bank Linked --</option>
                                <?php foreach ($all_banks as $b): ?>
                                    <option value="<?php echo (int) $b['id']; ?>">
                                        <?php echo Html::e($b['bank_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-muted x-small ps-1 mt-1">Links to a managed cash account for payments.</div>
                        </div>

                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold text-muted small" for="bankNameInput">Bank Name <span class="text-danger">*</span></label>
                            <input type="text" name="bank_name" id="bankNameInput" class="form-control rounded-pill px-3"
                                placeholder="e.g. ADCB, ENBD, FAB" required>
                        </div>
                    </div>

                    <div class="mb-3 mt-2">
                        <label class="form-label fw-bold text-muted small" for="cardNameInput">Card Name / Nickname <span class="text-danger">*</span></label>
                        <input type="text" name="card_name" id="cardNameInput" class="form-control rounded-pill px-3"
                            placeholder="e.g. 365 Cashback, Traveler Infinite" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small" for="cardTypeInput">Card Type <span class="text-danger">*</span></label>
                            <select name="card_type" id="cardTypeInput" class="form-select rounded-pill px-3" required>
                                <option value="Credit">Credit Card</option>
                                <option value="Debit">Debit Card</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small" for="networkInput">Network <span class="text-danger">*</span></label>
                            <select name="network" id="networkInput" class="form-select rounded-pill px-3" required>
                                <option value="Visa">Visa</option>
                                <option value="Mastercard">Mastercard</option>
                                <option value="Amex">American Express</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-muted small" for="feeTypeInput">Fee Structure</label>
                            <select name="fee_type" id="feeTypeInput" class="form-select rounded-pill px-3">
                                <option value="LTF">LTF (Lifetime Free)</option>
                                <option value="Paid">Paid</option>
                                <option value="Spend Based">Spend Based</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small" for="tierInput">Category / Tier</label>
                            <input type="text" name="tier" id="tierInput" class="form-control rounded-pill px-3" placeholder="e.g. Platinum, Infinite">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small" for="limitInput">Credit Limit (AED)</label>
                            <input type="number" name="limit_amount" id="limitInput" class="form-control rounded-pill px-3" placeholder="0.00" step="0.01">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold text-muted small" for="firstFourInput">First 4 Digits</label>
                            <input type="text" name="first_four" id="firstFourInput" class="form-control rounded-pill px-3" placeholder="1234" maxlength="4">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold text-muted small" for="lastFourInput">Last 4 Digits</label>
                            <input type="text" name="last_four" id="lastFourInput" class="form-control rounded-pill px-3" placeholder="5678" maxlength="4">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small" for="statementDayInput">Statement Day</label>
                            <input type="number" name="statement_day" id="statementDayInput" class="form-control rounded-pill px-3" placeholder="e.g. 14" min="1" max="31">
                            <div class="form-text text-muted x-small ps-1 mt-1">Day of the month the statement closes (31 = last day of the month). Credit cards only.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small" for="billDayInput">Payment Due Day</label>
                            <input type="number" name="bill_day" id="billDayInput" class="form-control rounded-pill px-3" placeholder="e.g. 8" min="1" max="31">
                            <div class="form-text text-muted x-small ps-1 mt-1">Day the payment is due. If it is on or before the statement day, it falls in the following month.</div>
                        </div>
                    </div>

                    <!-- Cashback Category Matrix -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-muted small"><i class="fa-solid fa-percent text-primary me-2"></i>Cashback Category Matrix %</label>
                        <div class="p-3 bg-light rounded-4 border border-light">
                            <div class="row g-2">
                                <?php foreach (Categories::EXPENSE as $cbKey => $cbLabel): ?>
                                    <div class="col-6 col-md-3">
                                        <label class="x-small text-muted fw-bold mb-1 text-truncate d-block" for="cb<?php echo Html::e($cbKey); ?>" title="<?php echo Html::e($cbLabel); ?>"><?php echo Html::e($cbLabel); ?></label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" name="cb_<?php echo Html::e($cbKey); ?>" id="cb<?php echo Html::e($cbKey); ?>" class="form-control" step="0.1" min="0" max="100" value="0">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-text text-muted x-small ps-1 mt-1">Rates are applied to new card expenses by category ("Other" is used for categories without a rate).</div>
                    </div>

                    <!-- Offers & Features -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-muted small" for="featuresInput">
                            Card Offers & Features
                            <span class="badge bg-success text-white rounded-pill ms-2" id="smartBadge" style="display:none;">Smart-Filled</span>
                            <span class="badge bg-primary text-white rounded-pill ms-2" id="liveBadge" style="display:none;"><i class="fa-solid fa-globe me-1"></i> Live Data</span>
                        </label>
                        <textarea name="features" id="featuresInput" class="form-control rounded-4" rows="4"
                            placeholder="Offers and features will appear here automatically when detected..."></textarea>
                    </div>

                    <!-- Confirm Button -->
                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold rounded-pill shadow-sm hover-lift py-2.5">
                            <i class="fa-solid fa-check-circle me-1"></i> Confirm & Add Card
                        </button>
                    </div>
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
    function isValidUrl(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    }

    async function detectBankDetails() {
        const urlInput = document.getElementById('bankUrlInput');
        const bankNameInput = document.getElementById('bankNameInput');
        const cardNameInput = document.getElementById('cardNameInput');
        const featuresInput = document.getElementById('featuresInput');
        const smartBadge = document.getElementById('smartBadge');
        const liveBadge = document.getElementById('liveBadge');
        const btn = document.getElementById('detectBankBtn');

        const rawUrl = urlInput.value.trim();

        if (!rawUrl) {
            alert("Please enter a URL first.");
            return;
        }

        let url = rawUrl.toLowerCase();
        if (!url.startsWith('http')) {
            url = 'https://' + url;
        }

        if (!isValidUrl(url)) {
            alert("Please enter a valid URL (e.g. https://www.bank.com/...)");
            return;
        }

        const originalBtnText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        btn.disabled = true;
        smartBadge.style.display = 'none';
        liveBadge.style.display = 'none';

        let detected = false;
        let bankName = "";
        let cardName = "";
        let features = "";
        let isLive = false;

        const featureAnalysis = {
            'cashback': `• 3% Cashback on non-AED spend\n• 2% Cashback on Grocery & Supermarkets\n• 1% Cashback on all other retail spends\n• No Annual Fee for the first year`,
            'infinite': `• Complimentary access to 1000+ airport lounges\n• Multi-trip travel insurance\n• Golf privileges at various clubs across UAE\n• Concierge Service 24/7`,
            'platinum': `• Buy 1 Get 1 Free movie tickets at Vox Cinemas\n• 20% off on Careem rides\n• Purchase Protection & Extended Warranty\n• 2 free airport transfers per year`,
            'rewards': `• Earn Reward Points for every AED 1 spent\n• Redeem points for flights, hotels, or electronics\n• Access to exclusive 'Buy 1, Get 1' offers`,
            'miles': `• Earn Miles per USD spend\n• Redeem on airlines globally\n• Free travel insurance`,
            'touchpoints': `• Earn TouchPoints for every AED 1 spent\n• 20% off on talabat orders twice a month\n• Buy 1 Get 1 Free coffee at Costa`,
            'standard': `• Standard shopping protection\n• SMS alerts for transactions\n• Online banking access`
        };

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

            if (data.success && data.data.description && data.data.description.length > 20) {
                features = "✅ VERIFIED LIVE DATA FROM BANK WEBSITE:\n" + data.data.description;
                if (data.data.title) {
                    features = "Card: " + data.data.title + "\n" + features;
                }
                isLive = true;
            }
        } catch (e) {
            console.log("Live fetch failed, falling back to library.");
        }

        if (url.includes('citibank') || url.includes('citi.com')) { bankName = 'Citibank'; detected = true; }
        else if (url.includes('adcb')) { bankName = 'ADCB'; detected = true; }
        else if (url.includes('emiratesnbd') || url.includes('enbd')) { bankName = 'Emirates NBD'; detected = true; }
        else if (url.includes('fab') || url.includes('firstabudhabi')) { bankName = 'FAB'; detected = true; }
        else if (url.includes('mashreq')) { bankName = 'Mashreq'; detected = true; }
        else if (url.includes('rakbank')) { bankName = 'RAKBANK'; detected = true; }
        else if (url.includes('hsbc')) { bankName = 'HSBC'; detected = true; }
        else if (url.includes('adib')) { bankName = 'ADIB'; detected = true; }
        else if (url.includes('dib') || url.includes('dubaiislamicbank')) { bankName = 'DIB'; detected = true; }
        else if (url.includes('cbd')) { bankName = 'CBD'; detected = true; }

        if (detected) {
            let typeKey = 'standard';
            if (url.includes('cashback')) typeKey = 'cashback';
            else if (url.includes('infinite') || url.includes('signature')) typeKey = 'infinite';
            else if (url.includes('platinum')) typeKey = 'platinum';
            else if (url.includes('rewards')) typeKey = 'rewards';
            else if (url.includes('miles')) typeKey = 'miles';

            cardName = bankName + ' ' + typeKey.charAt(0).toUpperCase() + typeKey.slice(1);
            if (!isLive) {
                features = featureAnalysis[typeKey];
            }
            
            bankNameInput.value = bankName;
            cardNameInput.value = cardName;
            featuresInput.value = features;

            if (isLive) {
                liveBadge.style.display = 'inline-block';
            } else {
                smartBadge.style.display = 'inline-block';
            }
            updatePreview();
        } else {
            alert("Auto-fill couldn't resolve details, please key them in manually.");
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

        // Live color updating logic
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

    document.getElementById('bankUrlInput').addEventListener('paste', (event) => {
        setTimeout(detectBankDetails, 100);
    });
</script>

<?php Layout::footer(); ?>
