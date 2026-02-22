<?php
$page_title = "Card Details";
require_once 'config.php'; // NOSONAR
require_once 'includes/header.php'; // NOSONAR
require_once 'includes/sidebar.php'; // NOSONAR

// Get Card ID
$card_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$card_id) {
    header('Location: my_cards.php');
    exit;
}

// Fetch Card Data
$stmt = $pdo->prepare("SELECT * FROM cards WHERE id = :id AND user_id = :user_id");
$stmt->execute(['id' => $card_id, 'user_id' => $_SESSION['user_id']]);
$card = $stmt->fetch();

if (!$card) {
    header('Location: my_cards.php');
    exit;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">Card Details</h1>
    <div>
        <a href="my_cards.php" class="btn btn-light me-2">
            <i class="fa-solid fa-arrow-left me-2"></i> Back
        </a>
        <a href="edit_card.php?id=<?php echo $card['id']; ?>" class="btn btn-primary">
            <i class="fa-solid fa-edit me-2"></i> Edit
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Card Visual -->
    <div class="col-md-5 col-lg-4">
        <div class="card border-0 shadow-sm text-white mb-4"
            style="background: linear-gradient(45deg, #1e1e1e, #3a3a3a); border-radius: 16px; min-height: 220px; position: relative; overflow: hidden;">

            <div
                style="position: absolute; top: -20px; right: -20px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;">
            </div>
            <div
                style="position: absolute; bottom: -40px; left: -20px; width: 150px; height: 150px; background: rgba(255,255,255,0.05); border-radius: 50%;">
            </div>

            <div class="card-body d-flex flex-column justify-content-between p-4">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h4 class="mb-0 fw-bold">
                            <?php echo htmlspecialchars($card['bank_name']); ?>
                        </h4>
                        <small class="text-white-50">
                            <?php echo htmlspecialchars($card['card_name']); ?>
                        </small>
                    </div>
                    <?php if ($card['network'] == 'Visa'): ?>
                        <i class="fa-brands fa-cc-visa fa-3x"></i>
                    <?php elseif ($card['network'] == 'Mastercard'): ?>
                        <i class="fa-brands fa-cc-mastercard fa-3x"></i>
                    <?php else: ?>
                        <i class="fa-brands fa-cc-amex fa-3x"></i>
                    <?php endif; ?>
                </div>

                <div class="mt-4">
                    <div class="h4 mb-1" style="letter-spacing: 3px;">**** **** **** ****</div>
                    <small class="text-white-50">
                        <?php echo htmlspecialchars($card['tier']); ?>
                    </small>
                </div>

                <div class="d-flex justify-content-between align-items-end mt-3">
                    <div>
                        <small class="text-white-50 d-block">Limit</small>
                        <span class="fw-bold fs-5">
                            <?php echo htmlspecialchars($card['currency']) . ' ' . number_format($card['limit_amount'], 2); ?>
                        </span>
                    </div>
                    <span class="badge bg-white text-dark">
                        <?php echo htmlspecialchars($card['card_type']); ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if (!empty($card['bank_url'])): ?>
            <div class="d-grid">
                <a href="<?php echo htmlspecialchars($card['bank_url']); ?>" target="_blank"
                    class="btn btn-outline-primary py-3">
                    <i class="fa-solid fa-external-link-alt me-2"></i> Visit Bank Website
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Details Section -->
    <div class="col-md-7 col-lg-8">
        <div class="glass-panel p-4 h-100">
            <h5 class="fw-bold mb-4"><i class="fa-solid fa-gift me-2 text-primary"></i> Offers & Features</h5>

            <?php if (!empty($card['features'])): ?>
                <div class="p-3 bg-light rounded-3 border" style="white-space: pre-wrap; line-height: 1.6;">
                    <?php echo htmlspecialchars($card['features']); ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-clipboard-list fa-3x mb-3 opacity-25"></i>
                    <p>No offers or features added yet.</p>
                    <a href="edit_card.php?id=<?php echo $card['id']; ?>" class="btn btn-sm btn-outline-primary">Add
                        Details</a>
                </div>
            <?php endif; ?>

            <hr class="my-4">

            <div class="row g-3">
                <div class="col-6 col-md-4">
                    <div class="text-muted small">Card Type</div>
                    <div class="fw-bold">
                        <?php echo htmlspecialchars($card['card_type']); ?>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="text-muted small">Network</div>
                    <div class="fw-bold">
                        <?php echo htmlspecialchars($card['network']); ?>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="text-muted small">Currency</div>
                    <div class="fw-bold">
                        <?php echo htmlspecialchars($card['currency']); ?>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="text-muted small">Added On</div>
                    <div class="fw-bold">
                        <?php echo date('d M Y', strtotime($card['created_at'])); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?> // NOSONAR