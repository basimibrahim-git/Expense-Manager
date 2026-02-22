<?php
$page_title = "My Cards";
require_once 'config.php'; // NOSONAR
require_once 'includes/header.php'; // NOSONAR
require_once 'includes/sidebar.php'; // NOSONAR

// Fetch cards with current month usage
try {
    $curr_month = date('n');
    $curr_year = date('Y');

    $stmt = $pdo->prepare("
        SELECT c.*, 
        (SELECT SUM(amount) FROM expenses e 
         WHERE e.card_id = c.id 
         AND e.tenant_id = c.tenant_id
         AND MONTH(e.expense_date) = :month1 
         AND YEAR(e.expense_date) = :year1) as total_expenses,
        (SELECT SUM(amount) FROM card_payments p 
         WHERE p.card_id = c.id 
         AND p.tenant_id = c.tenant_id
         AND MONTH(p.payment_date) = :month2 
         AND YEAR(p.payment_date) = :year2) as total_payments
        FROM cards c 
        WHERE c.tenant_id = :tenant_id 
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([
        'tenant_id' => $_SESSION['tenant_id'],
        'month1' => $curr_month,
        'year1' => $curr_year,
        'month2' => $curr_month,
        'year2' => $curr_year
    ]);
    $cards = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Database Error in my_cards.php: " . $e->getMessage());
    die("A system error occurred. Please contact support.");
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">My Cards</h1>
    <div class="d-flex gap-2">
        <a href="my_banks.php" class="btn btn-outline-primary">
            <i class="fa-solid fa-landmark me-2"></i> Manage Banks
        </a>
        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
            <a href="add_card.php" class="btn btn-primary">
                <i class="fa-solid fa-plus me-2"></i> Add New Card
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($cards)): ?>
    <div class="text-center py-5 glass-panel">
        <div class="mb-3 text-muted" style="font-size: 3rem;">
            <i class="fa-regular fa-credit-card"></i>
        </div>
        <h4>No cards added yet</h4>
        <p class="text-muted">Add your credit or debit cards to track your spending.</p>
        <a href="add_card.php" class="btn btn-primary mt-2">Add Card</a>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($cards as $card): ?>
            <?php
            $used = ($card['total_expenses'] ?: 0) - ($card['total_payments'] ?: 0);
            $limit = $card['limit_amount'] ?: 0;
            $usage_pct = $limit > 0 ? min(($used / $limit) * 100, 100) : 0;
            $usage_color = $usage_pct < 50 ? 'success' : ($usage_pct < 85 ? 'warning' : 'danger');

            $f4 = $card['first_four'] ?: '****';
            $l4 = $card['last_four'] ?: '****';
            ?>
            <div class="col-12 col-md-6 col-lg-4">
                <!-- Card Action Wrapper -->
                <div class="card border-0 shadow-sm text-white position-relative overflow-hidden"
                    style="background: <?php echo !empty($card['card_image']) ? "linear-gradient(rgba(0,0,0,0.6), rgba(0,0,0,0.6)), url('" . htmlspecialchars($card['card_image']) . "')" : "linear-gradient(45deg, #1e1e1e, #3a3a3a)"; ?>; background-size: cover; background-position: center; border-radius: 16px; min-height: 220px; transition: transform 0.2s;">

                    <!-- Make card clickable -->
                    <a href="view_card.php?id=<?php echo $card['id']; ?>" class="text-decoration-none text-white h-100 d-block"
                        aria-label="View details for <?php echo htmlspecialchars($card['bank_name'] . ' ' . $card['card_name']); ?>">
                        <div
                            style="position: absolute; top: -20px; right: -20px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;">
                        </div>
                        <div
                            style="position: absolute; bottom: -40px; left: -20px; width: 150px; height: 150px; background: rgba(255,255,255,0.05); border-radius: 50%;">
                        </div>

                        <div class="card-body d-flex flex-column justify-content-between p-4">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 class="mb-0 fw-bold"><?php echo htmlspecialchars($card['bank_name']); ?></h5>
                                    <small class="text-white-50"><?php echo htmlspecialchars($card['card_name']); ?></small>
                                </div>
                                <?php if ($card['network'] == 'Visa'): ?>
                                    <i class="fa-brands fa-cc-visa fa-2x"></i>
                                <?php elseif ($card['network'] == 'Mastercard'): ?>
                                    <i class="fa-brands fa-cc-mastercard fa-2x"></i>
                                <?php else: ?>
                                    <i class="fa-brands fa-cc-amex fa-2x"></i>
                                <?php endif; ?>
                            </div>

                            <div class="mt-3">
                                <div class="h5 mb-1" style="letter-spacing: 2px; font-family: monospace;">
                                    <?php echo $f4; ?> **** **** <?php echo $l4; ?>
                                </div>
                                <small class="text-white-50"><?php echo htmlspecialchars($card['tier']); ?></small>
                            </div>

                            <div class="mt-3">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="text-white-50">Monthly Usage</span>
                                    <span class="fw-bold text-<?php echo $usage_color; ?>">
                                        <?php echo number_format($usage_pct, 1); ?>%
                                    </span>
                                </div>
                                <div class="progress-wrapper mb-2">
                                    <progress class="w-100" value="<?php echo min($usage_pct, 100); ?>" max="100"
                                        title="<?php echo number_format($usage_pct, 1); ?>% usage"></progress>
                                </div>
                                <div class="d-flex justify-content-between mt-2 align-items-end">
                                    <div class="lh-1">
                                        <small class="text-white-50 x-small d-block mb-1">Spent / Limit</small>
                                        <span class="fw-bold small">
                                            AED <?php echo number_format($used, 2); ?> / <?php echo number_format($limit, 2); ?>
                                        </span>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <?php if (!empty($card['fee_type'])): ?>
                                            <span
                                                class="badge bg-warning text-dark small"><?php echo htmlspecialchars($card['fee_type']); ?></span>
                                        <?php endif; ?>
                                        <span
                                            class="badge bg-white text-dark small"><?php echo htmlspecialchars($card['card_type']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Card Actions -->
                <div class="mt-2 d-flex justify-content-end gap-2 flex-wrap">
                    <?php if (!empty($card['bank_url'])): ?>
                        <a href="<?php echo htmlspecialchars($card['bank_url']); ?>" target="_blank"
                            class="btn btn-sm btn-outline-primary" title="Visit Bank Site">
                            <i class="fa-solid fa-external-link-alt"></i> Bank
                        </a>
                    <?php endif; ?>
                    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                        <a href="pay_card.php?card_id=<?php echo $card['id']; ?>" class="btn btn-sm btn-success text-white">
                            <i class="fa-solid fa-receipt me-1"></i> Pay
                        </a>
                        <a href="edit_card.php?id=<?php echo $card['id']; ?>" class="btn btn-sm btn-light text-muted" title="Edit">
                            <i class="fa-solid fa-pen"></i>
                        </a>
                        <form action="card_actions.php" method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="action" value="delete_card">
                            <input type="hidden" name="id" value="<?php echo $card['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-light text-danger" title="Delete"
                                onclick="return confirmSubmit(this, 'Delete <?php echo addslashes(htmlspecialchars($card['bank_name'] . ' ' . $card['card_name'])); ?>? (This will permanently remove card details)');">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    <?php else: ?>
                        <span class="text-muted small align-self-center"><i class="fa-solid fa-lock me-1"></i> Read Only</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>