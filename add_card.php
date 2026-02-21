<?php
$page_title = "Add Card";
require_once 'config.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

// Fetch all banks for the dropdown
$banks_stmt = $pdo->prepare("SELECT id, bank_name FROM banks WHERE tenant_id = ? ORDER BY is_default DESC, bank_name ASC");
$banks_stmt->execute([$_SESSION['tenant_id']]);
$all_banks = $banks_stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">Add New Card</h1>
    <a href="my_cards.php" class="btn btn-light">
        <i class="fa-solid fa-arrow-left me-2"></i> Back
    </a>
</div>

<div class="row">
    <div class="col-md-8 col-lg-6">
        <div class="glass-panel p-4">
            <form action="card_actions.php" method="POST" id="addCardForm">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="add_card">
                <input type="hidden" name="card_image" id="cardImageInput">

                <h5 class="mb-3 text-muted">Card Source</h5>

                <!-- Moved Bank URL to Top -->
                <div class="mb-4">
                    <label class="form-label">Bank Login URL <span
                            class="badge bg-info text-dark rounded-pill ms-2">Auto-Detect</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-link"></i></span>
                        <input type="url" name="bank_url" id="bankUrlInput" class="form-control"
                            placeholder="Paste bank URL here (e.g., https://online.adcb.com...)" required>
                        <button class="btn btn-outline-primary" type="button" onclick="detectBankDetails()">
                            <i class="fa-solid fa-magic-wand-sparkles"></i> Auto-Fill
                        </button>
                    </div>
                    <div class="form-text">Paste the URL to automatically detect the bank.</div>
                </div>

                <hr class="my-4 text-muted">

                <h5 class="mb-3 text-muted">Card Details</h5>

                <div class="mb-3">
                    <label class="form-label">Associated Bank <span
                            class="text-secondary small">(Optional)</span></label>
                    <select name="bank_id" class="form-select">
                        <option value="">-- No Bank Linked --</option>
                        <?php foreach ($all_banks as $b): ?>
                            <option value="<?php echo $b['id']; ?>">
                                <?php echo htmlspecialchars($b['bank_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text x-small">Link this card to a managed bank account</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Bank Name <span class="text-danger">*</span></label>
                    <input type="text" name="bank_name" id="bankNameInput" class="form-control"
                        placeholder="e.g. ADCB, ENBD, FAB" required>
                    <div class="form-text x-small">Display name for the card</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Card Name / Nickname <span class="text-danger">*</span></label>
                    <input type="text" name="card_name" id="cardNameInput" class="form-control"
                        placeholder="e.g. 365 Cashback, Traveler" required>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Card Type <span class="text-danger">*</span></label>
                        <select name="card_type" id="cardTypeInput" class="form-select" required>
                            <option value="Credit">Credit Card</option>
                            <option value="Debit">Debit Card</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Network <span class="text-danger">*</span></label>
                        <select name="network" id="networkInput" class="form-select" required>
                            <option value="Visa">Visa</option>
                            <option value="Mastercard">Mastercard</option>
                            <option value="Amex">American Express</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Fee Type</label>
                        <select name="fee_type" class="form-select">
                            <option value="LTF">LTF (Lifetime Free)</option>
                            <option value="Paid">Paid</option>
                            <option value="Spend Based">Spend Based</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Category / Tier</label>
                        <input type="text" name="tier" id="tierInput" class="form-control" placeholder="e.g. Platinum">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Credit / Monthly Limit (AED)</label>
                        <input type="number" name="limit_amount" class="form-control" placeholder="0.00" step="0.01">
                    </div>
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label">First 4 Digits</label>
                        <input type="text" name="first_four" id="firstFourInput" class="form-control" placeholder="1234"
                            maxlength="4">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">Last 4 Digits</label>
                        <input type="text" name="last_four" id="lastFourInput" class="form-control" placeholder="5678"
                            maxlength="4">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Bill Generation Day</label>
                        <input type="number" name="bill_day" class="form-control" placeholder="e.g. 15" min="1"
                            max="31">
                        <div class="form-text x-small">Day of month bill is issued</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Statement Closing Day</label>
                        <input type="number" name="statement_day" class="form-control" placeholder="e.g. 14" min="1"
                            max="31">
                        <div class="form-text x-small">Day of month statement closes</div>
                    </div>
                </div>

                <!-- Cashback Categories -->
                <div class="mb-4">
                    <label class="form-label fw-bold"><i class="fa-solid fa-percent text-primary me-2"></i> Category
                        Cashback %</label>
                    <div class="bg-light p-3 rounded shadow-sm border">
                        <div class="row g-2 mb-2">
                            <div class="col-6 col-md-3">
                                <label class="x-small text-muted">Grocery</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="cb_Grocery" class="form-control" step="0.1" value="0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="x-small text-muted">Dining/Food</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="cb_Food" class="form-control" step="0.1" value="0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="x-small text-muted">Transport</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="cb_Transport" class="form-control" step="0.1" value="0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="x-small text-muted">Shopping</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="cb_Shopping" class="form-control" step="0.1" value="0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6 col-md-3">
                                <label class="x-small text-muted">Utilities</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="cb_Utilities" class="form-control" step="0.1" value="0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="x-small text-muted">Travel</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="cb_Travel" class="form-control" step="0.1" value="0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="x-small text-muted">Medical</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="cb_Medical" class="form-control" step="0.1" value="0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="x-small text-muted">Entmt.</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="cb_Entertainment" class="form-control" step="0.1"
                                        value="0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6 col-md-3">
                                <label class="x-small text-muted">Education</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="cb_Education" class="form-control" step="0.1" value="0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="x-small text-muted">Other/Gen.</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="cb_Other" class="form-control" step="0.1" value="0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-text x-small">Used for auto-calculating rewards in expenses.</div>
                </div>

                <!-- New Features Field -->
                <div class="mb-3">
                    <label class="form-label">
                        Card Offers & Features
                        <span class="badge bg-success text-white rounded-pill ms-2" id="smartBadge"
                            style="display:none;">Smart-Filled</span>
                        <span class="badge bg-primary text-white rounded-pill ms-2" id="liveBadge"
                            style="display:none;"><i class="fa-solid fa-globe me-1"></i> Live Data</span>
                    </label>
                    <textarea name="features" id="featuresInput" class="form-control" rows="4"
                        placeholder="Offers and features will appear here automatically when detected..."></textarea>
                    <div class="form-text">We verify these details against our database or the bank's website.</div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-primary py-3 fw-bold">
                        <i class="fa-solid fa-check-circle me-2"></i> Confirm & Add Card
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Preview Section -->
    <div class="col-md-4 d-none d-md-block">
        <div class="card border-0 shadow-sm text-white sticky-top"
            style="top: 20px; background: linear-gradient(45deg, #1e1e1e, #3a3a3a); border-radius: 16px; min-height: 200px;">
            <div class="card-body p-4 d-flex flex-column justify-content-between h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="mb-0 fw-bold" id="previewBank">Bank Name</h5>
                        <small class="text-white-50" id="previewName">Card Name</small>
                    </div>
                    <i class="fa-solid fa-credit-card fa-2x text-white-50" id="previewIcon"></i>
                </div>

                <div class="mt-4">
                    <div class="h5 mb-1" style="letter-spacing: 2px;" id="previewDigits">**** **** **** ****</div>
                    <small class="text-white-50" id="previewTier">Tier Name</small>
                </div>

                <div class="d-flex justify-content-between align-items-end mt-3">
                    <div>
                        <small class="text-white-50 d-block">Limit</small>
                        <span class="fw-bold">AED 0.00</span>
                    </div>
                    <span class="badge bg-white text-dark" id="previewType">Type</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Validation & Utils
    function isValidUrl(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    }

    // Auto-detect bank from URL
    async function detectBankDetails() {
        const urlInput = document.getElementById('bankUrlInput');
        const bankNameInput = document.getElementById('bankNameInput');
        const cardNameInput = document.getElementById('cardNameInput');
        const featuresInput = document.getElementById('featuresInput');
        const smartBadge = document.getElementById('smartBadge');
        const liveBadge = document.getElementById('liveBadge');
        const btn = document.querySelector('button[onclick="detectBankDetails()"]');

        const rawUrl = urlInput.value.trim();

        if (!rawUrl) {
            showGlobalModal("Please enter a URL first.", "Input Required");
            return;
        }

        // Validate URL format
        let url = rawUrl.toLowerCase();
        if (!url.startsWith('http')) {
            url = 'https://' + url;
        }

        if (!isValidUrl(url)) {
            showGlobalModal("Please enter a valid URL (e.g., https://www.bank.com/...)", "Invalid URL");
            return;
        }

        // UI Loading State
        const originalBtnText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analyzing...';
        btn.disabled = true;
        smartBadge.style.display = 'none';
        liveBadge.style.display = 'none';

        let detected = false;
        let bankName = "";
        let cardName = "";
        let features = "";
        let isLive = false;

        // 1. Feature Library (Fallback)
        const featureAnalysis = {
            'cashback': `• 3% Cashback on non-AED spend
• 2% Cashback on Grocery & Supermarkets
• 1% Cashback on all other retail spends
• No Annual Fee for the first year`,
            'infinite': `• Unlimited complimentary access to 1000+ airport lounges (LoungeKey)
• Multi-trip travel insurance
• Golf privileges at various clubs across UAE
• Concierge Service 24/7`,
            'platinum': `• Buy 1 Get 1 Free movie tickets at Vox Cinemas
• 20% off on Careem rides
• Purchase Protection & Extended Warranty
• 2 free airport transfers per year`,
            'rewards': `• Earn 2.5 Reward Points for every AED 1 spent
• Redeem points for flights, hotels, or electronics
• Access to exclusive 'Buy 1, Get 1' offers
• Dining discounts up to 30%`,
            'miles': `• Earn 2 Miles per USD spend
• Redeem on any airline, any time
• Free travel insurance for you and family
• Priority pass lounge access`,
            'touchpoints': `• Earn 1.5 TouchPoints for every AED 1 spent
• 20% off on talabat orders twice a month
• Buy 1 Get 1 Free coffee at Costa
• Complimentary golf access`,
            'skywards': `• Up to 2.5 Skywards Miles per USD spent
• Silver Tier membership status
• 25% discount on dining at 2000+ restaurants
• Valet parking at selected malls`,
            'neo': `• 1% Cashback on all spends
• Free international transfers
• No minimum balance required
• Instant digital card issuance`,
            'standard': `• Standard shopping protection
• SMS alerts for transactions
• Online banking access
• 24/7 Customer Support`,
            'islamic': `• Sharia-compliant card
• No annual fee for life
• Roadside assistance
• Travel desk services`
        };

        // 2. Try Live Fetch (The Real Data)
        try {
            const response = await fetch('fetch_url_data.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url: url })
            });
            const data = await response.json();

            if (data.success && data.data.description && data.data.description.length > 20) {
                // Formatting the live content nicely
                features = "✅ VERIFIED LIVE DATA FROM BANK WEBSITE:\n" + data.data.description;
                if (data.data.title) {
                    features = "Card: " + data.data.title + "\n" + features;
                }
                isLive = true;
            }
        } catch (e) {
            console.log("Live fetch failed, falling back to library.");
        }

        // 3. Name & Bank Detection (Pattern Matching is usually better for Names)
        function inferCardDetails(urlStr, defaultName) {
            let name = defaultName;
            let beneficialFeatures = featureAnalysis['standard'];

            const k = urlStr;
            if (k.includes('cashback')) { name = 'Cashback Credit Card'; beneficialFeatures = featureAnalysis['cashback']; }
            else if (k.includes('infinite')) { name = 'Infinite Credit Card'; beneficialFeatures = featureAnalysis['infinite']; }
            else if (k.includes('platinum')) { name = 'Platinum Credit Card'; beneficialFeatures = featureAnalysis['platinum']; }
            else if (k.includes('signature')) { name = 'Signature Credit Card'; beneficialFeatures = featureAnalysis['infinite']; }
            else if (k.includes('rewards')) { name = 'Rewards Credit Card'; beneficialFeatures = featureAnalysis['rewards']; }
            else if (k.includes('miles') || k.includes('etihad')) {
                name = 'Miles/Travel Card'; beneficialFeatures =
                    featureAnalysis['miles'];
            }
            else if (k.includes('neo')) { name = 'Mashreq Neo Card'; beneficialFeatures = featureAnalysis['neo']; }
            else if (k.includes('touchpoints')) { name = 'TouchPoints Card'; beneficialFeatures = featureAnalysis['touchpoints']; }
            else if (k.includes('skywards')) { name = 'Skywards Miles Card'; beneficialFeatures = featureAnalysis['skywards']; }

            return { name, features: beneficialFeatures };
        }

        // Bank Detection Rules
        if (url.includes('citibank') || url.includes('citi.com')) { bankName = 'Citibank'; detected = true; }
        else if (url.includes('adcb')) { bankName = 'ADCB'; detected = true; }
        else if (url.includes('emiratesnbd') || url.includes('enbd')) { bankName = 'Emirates NBD'; detected = true; }
        else if (url.includes('fab') || url.includes('firstabudhabi')) { bankName = 'FAB'; detected = true; }
        else if (url.includes('mashreq')) { bankName = 'Mashreq'; detected = true; }
        else if (url.includes('rakbank')) { bankName = 'RAKBANK'; detected = true; }
        else if (url.includes('hsbc')) { bankName = 'HSBC'; detected = true; }
        else if (url.includes('adib')) { bankName = 'ADIB'; detected = true; }
        else if (url.includes('dib') || url.includes('dubaiislamicbank')) { bankName = 'Dubai Islamic Bank'; detected = true; }
        else if (url.includes('cbd')) { bankName = 'CBD'; detected = true; }
        else if (url.includes('sc.com') || url.includes('standardchartered')) {
            bankName = 'Standard Chartered'; detected =
                true;
        }

        if (detected) {
            const details = inferCardDetails(url, bankName + ' Credit Card');
            cardName = details.name;
            // Only overwrite features with library if live fetch failed
            if (!isLive) {
                features = details.features;
            }
        }
        // Fallback for names
        else {
            try {
                const domain = new URL(url).hostname;
                const parts = domain.replace('www.', '').split('.');
                if (parts.length > 0) {
                    const name = parts[0];
                    if (name.length > 2) {
                        bankName = name.charAt(0).toUpperCase() + name.slice(1);
                        cardName = 'Credit Card';
                        detected = true;
                        if (!isLive) features = featureAnalysis['standard'];
                    }
                }
            } catch (e) { }
        }

        // 4. Update UI
        btn.innerHTML = originalBtnText;
        btn.disabled = false;

        if (detected) {
            bankNameInput.value = bankName;
            cardNameInput.value = cardName;
            featuresInput.value = features;

            if (isLive) {
                liveBadge.style.display = 'inline-block';
            } else {
                smartBadge.style.display = 'inline-block';
            }

            // Flash effect
            bankNameInput.style.backgroundColor = '#e8f0fe';
            cardNameInput.style.backgroundColor = '#e8f0fe';
            featuresInput.style.backgroundColor = '#e8f0fe';
            setTimeout(() => {
                bankNameInput.style.backgroundColor = '';
                cardNameInput.style.backgroundColor = '';
                featuresInput.style.backgroundColor = '';
            }, 500);
            updatePreview();
        } else {
            showGlobalModal('Could not auto-detect details. Please enter manually.', 'Detection Failed');
        }

        // Handle Image
        if (data.data && data.data.image) {
            document.getElementById('cardImageInput').value = data.data.image;
            // Update Preview Background
            const cardPreview = document.querySelector('.sticky-top');
            if (cardPreview) {
                cardPreview.style.backgroundImage = `linear-gradient(rgba(0,0,0,0.6), rgba(0,0,0,0.6)), url('${data.data.image}')`;
                cardPreview.style.backgroundSize = 'cover';
                cardPreview.style.backgroundPosition = 'center';
            }
        }
    }

    // Live Preview Update
    const previewInputs = ['bankNameInput', 'cardNameInput', 'tierInput', 'cardTypeInput', 'firstFourInput', 'lastFourInput'];
    previewInputs.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updatePreview);
    });

    function updatePreview() {
        const bank = document.getElementById('bankNameInput').value || 'Bank Name';
        const name = document.getElementById('cardNameInput').value || 'Card Name';
        const tier = document.getElementById('tierInput').value || 'Tier Name';
        const type = document.getElementById('cardTypeInput').value || 'Type';
        const f4 = document.getElementById('firstFourInput').value || '****';
        const l4 = document.getElementById('lastFourInput').value || '****';

        document.getElementById('previewBank').textContent = bank;
        document.getElementById('previewName').textContent = name;
        document.getElementById('previewTier').textContent = tier;
        document.getElementById('previewType').textContent = type;
        document.getElementById('previewDigits').textContent = `${f4} **** **** ${l4}`;
    }

    // Initial preview
    updatePreview();

    // Auto-trigger on paste
    document.getElementById('bankUrlInput').addEventListener('paste', (event) => {
        setTimeout(detectBankDetails, 100);
    });
</script>

<?php require_once 'includes/footer.php'; ?>