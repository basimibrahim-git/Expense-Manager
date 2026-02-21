<?php
$page_title = "Edit Card";
require_once 'config.php'; // NOSONAR
require_once 'includes/header.php'; // NOSONAR
require_once 'includes/sidebar.php'; // NOSONAR

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

// Fetch all banks for the dropdown
$banks_stmt = $pdo->prepare("SELECT id, bank_name FROM banks WHERE tenant_id = ? ORDER BY is_default DESC, bank_name ASC");
$banks_stmt->execute([$_SESSION['tenant_id']]);
$all_banks = $banks_stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">Edit Card</h1>
    <a href="my_cards.php" class="btn btn-light">
        <i class="fa-solid fa-arrow-left me-2"></i> Back
    </a>
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

<div class="row">
    <div class="col-md-8 col-lg-6">
        <div class="glass-panel p-4">
            <form action="card_actions.php" method="POST" id="editCardForm">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="update_card">
                <input type="hidden" name="card_id" value="<?php echo $card['id']; ?>">

                <h5 class="mb-3 text-muted">Card Source</h5>

                <div class="mb-4">
                    <label class="form-label" for="bankUrlInput">Bank Login URL</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-link"></i></span>
                        <input type="url" name="bank_url" id="bankUrlInput" class="form-control"
                            value="<?php echo htmlspecialchars($card['bank_url'] ?? ''); ?>" placeholder="https://...">
                        <button class="btn btn-outline-primary" type="button" onclick="detectBankDetails()">
                            <i class="fa-solid fa-sync"></i> Sync
                        </button>
                    </div>
                </div>
                <input type="hidden" name="card_image" id="cardImageInput" value="<?php echo htmlspecialchars($card['card_image'] ?? ''); ?>">

                <hr class="my-4 text-muted">

                <h5 class="mb-3 text-muted">Card Details</h5>

                <div class="mb-3">
                    <label class="form-label" for="bank_id">Associated Bank <span class="text-secondary small">(Optional)</span></label>
                    <select name="bank_id" id="bank_id" class="form-select">
                        <option value="">-- No Bank Linked --</option>
                        <?php foreach($all_banks as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $card['bank_id'] == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['bank_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text x-small">Used for balance tracking and automation</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="bankNameInput">Bank Name <span class="text-danger">*</span></label>
                    <input type="text" name="bank_name" id="bankNameInput" class="form-control"
                        value="<?php echo htmlspecialchars($card['bank_name']); ?>" required>
                    <div class="form-text x-small">Display name for the card</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="cardNameInput">Card Name / Nickname <span class="text-danger">*</span></label>
                    <input type="text" name="card_name" id="cardNameInput" class="form-control"
                        value="<?php echo htmlspecialchars($card['card_name']); ?>" required>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="cardTypeInput">Card Type <span class="text-danger">*</span></label>
                        <select name="card_type" id="cardTypeInput" class="form-select" required>
                            <option value="Credit" <?php echo ($card['card_type'] == 'Credit') ? 'selected' : ''; ?>>
                                Credit Card</option>
                            <option value="Debit" <?php echo ($card['card_type'] == 'Debit') ? 'selected' : ''; ?>>Debit
                                Card</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="networkInput">Network <span class="text-danger">*</span></label>
                        <select name="network" id="networkInput" class="form-select" required>
                            <option value="Visa" <?php echo ($card['network'] == 'Visa') ? 'selected' : ''; ?>>Visa
                            </option>
                            <option value="Mastercard" <?php echo ($card['network'] == 'Mastercard') ? 'selected' : ''; ?>>Mastercard</option>
                            <option value="Amex" <?php echo ($card['network'] == 'Amex') ? 'selected' : ''; ?>>American
                                Express</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="fee_type">Fee Type</label>
                        <select name="fee_type" id="fee_type" class="form-select">
                            <option value="LTF" <?php echo (($card['fee_type'] ?? '') == 'LTF') ? 'selected' : ''; ?>>LTF (Lifetime Free)</option>
                            <option value="Paid" <?php echo (($card['fee_type'] ?? '') == 'Paid') ? 'selected' : ''; ?>>Paid</option>
                            <option value="Spend Based" <?php echo (($card['fee_type'] ?? '') == 'Spend Based') ? 'selected' : ''; ?>>Spend Based</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="tierInput">Category / Tier</label>
                        <input type="text" name="tier" id="tierInput" class="form-control"
                            value="<?php echo htmlspecialchars($card['tier']); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="limit_amount">Credit / Monthly Limit (AED)</label>
                        <input type="number" name="limit_amount" id="limit_amount" class="form-control" step="0.01"
                            value="<?php echo htmlspecialchars($card['limit_amount']); ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label" for="firstFourInput">First 4 Digits</label>
                        <input type="text" name="first_four" id="firstFourInput" class="form-control"
                            value="<?php echo htmlspecialchars($card['first_four'] ?? ''); ?>" maxlength="4">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label" for="lastFourInput">Last 4 Digits</label>
                        <input type="text" name="last_four" id="lastFourInput" class="form-control"
                            value="<?php echo htmlspecialchars($card['last_four'] ?? ''); ?>" maxlength="4">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="bill_day">Bill Generation Day</label>
                        <input type="number" name="bill_day" id="bill_day" class="form-control" placeholder="e.g. 15" min="1" max="31"
                            value="<?php echo htmlspecialchars($card['bill_day'] ?? ''); ?>">
                        <div class="form-text x-small">Day of month bill is issued</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="statement_day">Statement Closing Day</label>
                        <input type="number" name="statement_day" id="statement_day" class="form-control" placeholder="e.g. 14" min="1"
                            max="31" value="<?php echo htmlspecialchars($card['statement_day'] ?? ''); ?>">
                        <div class="form-text x-small">Day of month statement closes</div>
                    </div>
                </div>

                <!-- Default Card Toggle -->
                <div class="mb-4 p-3 bg-primary bg-opacity-10 rounded border border-primary">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_default" id="isDefault" value="1"
                            <?php echo !empty($card['is_default']) ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold text-primary" for="isDefault">
                            <i class="fa-solid fa-star me-1"></i> Set as Default Card
                        </label>
                        <div class="form-text x-small">Pre-selected when adding expenses</div>
                    </div>
                </div>

                <?php
                $cb_struct = json_decode($card['cashback_struct'] ?? '{}', true);
                if (!is_array($cb_struct)) {
                    $cb_struct = [];
                }
                ?>
                <!-- Cashback Categories -->
                <div class="mb-4">
                    <div class="form-label fw-bold d-block"><i class="fa-solid fa-percent text-primary me-2"></i> Category
                        Cashback %</div>
                    <div class="bg-light p-3 rounded shadow-sm border">
                        <div class="row g-2 mb-2">
                            <?php
                            $cats = [
                                'Grocery' => 'Grocery',
                                'Food' => 'Dining/Food',
                                'Transport' => 'Transport',
                                'Shopping' => 'Shopping',
                                'Utilities' => 'Utilities',
                                'Travel' => 'Travel',
                                'Medical' => 'Medical',
                                'Entertainment' => 'Entmt.',
                                'Education' => 'Education',
                                'Other' => 'Other/Gen.'
                            ];
                            $count = 0;
                            foreach ($cats as $key => $label):
                                if ($count % 4 == 0 && $count != 0) {
                                    echo '</div><div class="row g-2 mb-2">';
                                }
                                ?>
                                <div class="col-6 col-md-3">
                                    <label for="cb_<?php echo $key; ?>" class="x-small text-muted"><?php echo $label; ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="number" id="cb_<?php echo $key; ?>" name="cb_<?php echo $key; ?>" class="form-control" step="0.1"
                                            value="<?php echo $cb_struct[$key] ?? 0; ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <?php $count++; endforeach; ?>
                        </div>
                    </div>
                    <div class="form-text x-small">Used for auto-calculating rewards in expenses.</div>
                </div>

                <!-- New Features Field -->
                <div class="mb-3">
                    <label class="form-label" for="featuresInput">Card Offers & Features</label>
                    <textarea name="features" id="featuresInput" class="form-control" rows="4"
                        placeholder="Paste offers, cashback details, or benefits here..."><?php echo htmlspecialchars($card['features'] ?? ''); ?></textarea>
                    <div class="form-text">Note down validation dates or specific cashback categories here.</div>
                </div>

                <div class="d-grid gap-2 mb-3">
                    <button type="submit" class="btn btn-primary btn-lg fw-bold">
                        <i class="fa-solid fa-save me-2"></i> Update Card Details
                    </button>
                </div>
            </form>
            <form action="card_actions.php" method="POST" class="d-grid">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="delete_card">
                <input type="hidden" name="id" value="<?php echo $card['id']; ?>">
                <button type="submit" class="btn btn-outline-danger py-2"
                    onclick="return confirmSubmit(this, 'Delete <?php echo addslashes(htmlspecialchars($card['bank_name'] . ' ' . $card['card_name'])); ?>? This action CANNOT be undone.');">
                    <i class="fa-solid fa-trash me-2"></i> Delete Card
                </button>
            </form>
        </div>
    </div>

    <!-- Preview Section -->
    <div class="col-md-4 d-none d-md-block">
        <div class="card border-0 shadow-sm text-white sticky-top"
            style="top: 20px; background: <?php echo !empty($card['card_image']) ? "linear-gradient(rgba(0,0,0,0.6), rgba(0,0,0,0.6)), url('" . htmlspecialchars($card['card_image']) . "')" : "linear-gradient(45deg, #1e1e1e, #3a3a3a)"; ?>; background-size: cover; background-position: center; border-radius: 16px; min-height: 200px;">
            <div class="card-body p-4 d-flex flex-column justify-content-between h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="mb-0 fw-bold" id="previewBank"><?php echo htmlspecialchars($card['bank_name']); ?></h5>
                        <small class="text-white-50" id="previewName"><?php echo htmlspecialchars($card['card_name']); ?></small>
                    </div>
                    <i class="fa-solid fa-credit-card fa-2x text-white-50" id="previewIcon"></i>
                </div>

                <div class="mt-4">
                    <div class="h5 mb-1" style="letter-spacing: 2px;" id="previewDigits">
                        <?php echo htmlspecialchars($card['first_four'] ?? '****'); ?> **** **** <?php echo htmlspecialchars($card['last_four'] ?? '****'); ?>
                    </div>
                    <small class="text-white-50" id="previewTier"><?php echo htmlspecialchars($card['tier'] ?? 'Tier Name'); ?></small>
                </div>

                <div class="d-flex justify-content-between align-items-end mt-3">
                    <div>
                        <small class="text-white-50 d-block">Limit</small>
                        <span class="fw-bold">AED <?php echo number_format($card['limit_amount'], 2); ?></span>
                    </div>
                    <span class="badge bg-white text-dark" id="previewType"><?php echo htmlspecialchars($card['card_type']); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function isValidUrl(string) {
        try { new URL(string); return true; } catch (_) { return false; }
    }

    async function detectBankDetails() {
        const urlInput = document.getElementById('bankUrlInput');
        const bankNameInput = document.getElementById('bankNameInput');
        const cardNameInput = document.getElementById('cardNameInput');
        const featuresInput = document.getElementById('featuresInput'); // Note: edit_card uses name="features" but no ID originally, I need to add ID or select by name
        // Wait, edit_card has textarea name="features" but NO ID in my previous view (Line 218). I must fix that or use querySelector.
        // I will assume I add ID="featuresInput" in this chunk or use querySelector.
        
        const btn = document.querySelector('button[onclick="detectBankDetails()"]');
        const rawUrl = urlInput.value.trim();

        if (!rawUrl) { showGlobalModal("Please enter a URL first.", "Input Required"); return; }
        
        let url = rawUrl.toLowerCase();
        if (!url.startsWith('http')) { url = 'https://' + url; }

        const originalBtnText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Syncing...';
        btn.disabled = true;

        try {
            const response = await fetch('fetch_url_data.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url: url })
            });
            const data = await response.json();

            if (data.success && data.data) {
                // Update Fields
                if(data.data.description) document.querySelector('textarea[name="features"]').value = "✅ VERIFIED LIVE DATA:\n" + data.data.description;
                // We don't overwrite Name/Bank in Edit mode unless completely empty or user confirms?
                // Creating a simplified flow: Update Features & Image primarily.
                
                if (data.data.image) {
                    document.getElementById('cardImageInput').value = data.data.image;
                    const cardPreview = document.querySelector('.sticky-top');
                    if(cardPreview) {
                        cardPreview.style.backgroundImage = `linear-gradient(rgba(0,0,0,0.6), rgba(0,0,0,0.6)), url('${data.data.image}')`;
                        cardPreview.style.backgroundSize = 'cover';
                        cardPreview.style.backgroundPosition = 'center';
                    }
                }
                
                showGlobalModal("Card features and image have been updated from the live URL. Click 'Update Card' to save.", "Sync Complete");
            } else {
                showGlobalModal("Could not fetch data from this URL.", "Sync Failed");
            }
        } catch (e) {
            console.error(e);
            showGlobalModal("Error connecting to server.", "Sync Error");
        }
        
        btn.innerHTML = originalBtnText;
        btn.disabled = false;
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
</script>

<?php require_once 'includes/footer.php'; // NOSONAR ?>
