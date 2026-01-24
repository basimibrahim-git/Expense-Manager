<?php
$page_title = "My Banks";
require_once 'config.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

// Fetch banks
try {
    $stmt = $pdo->prepare("SELECT * FROM banks WHERE user_id = :user_id ORDER BY is_default DESC, bank_name ASC");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $banks = $stmt->fetchAll();
} catch (PDOException $e) {
    $banks = [];
    $error = "Could not load banks. Please run migrate_banks.php first.";
}

// Count cards per bank
$card_counts = [];
try {
    $cstmt = $pdo->prepare("SELECT bank_id, COUNT(*) as cnt FROM cards WHERE user_id = ? AND bank_id IS NOT NULL GROUP BY bank_id");
    $cstmt->execute([$_SESSION['user_id']]);
    foreach ($cstmt->fetchAll() as $row) {
        $card_counts[$row['bank_id']] = $row['cnt'];
    }
} catch (PDOException $e) {
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">My Bank Accounts</h1>
    <a href="add_bank.php" class="btn btn-primary">
        <i class="fa-solid fa-plus me-2"></i> Add Bank
    </a>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-check-circle me-2"></i>
        <?php echo htmlspecialchars($_GET['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-exclamation-triangle me-2"></i>
        <?php echo $error; ?>
        <a href="migrate_banks.php" class="btn btn-sm btn-warning ms-2">Run Migration</a>
    </div>
<?php endif; ?>

<?php if (empty($banks)): ?>
    <div class="text-center py-5 glass-panel">
        <i class="fa-solid fa-building-columns fa-3x text-muted mb-3"></i>
        <h5>No Banks Added Yet</h5>
        <p class="text-muted mb-4">Add your bank accounts to link cards and track balances.</p>
        <a href="add_bank.php" class="btn btn-primary">
            <i class="fa-solid fa-plus me-2"></i> Add Your First Bank
        </a>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($banks as $bank): ?>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100 <?php echo $bank['is_default'] ? 'border-primary border-2' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h5 class="fw-bold mb-1">
                                    <i class="fa-solid fa-building-columns text-info me-2"></i>
                                    <?php echo htmlspecialchars($bank['bank_name']); ?>
                                </h5>
                                <?php if ($bank['is_default']): ?>
                                    <span class="badge bg-primary"><i class="fa-solid fa-star me-1"></i> Default</span>
                                <?php endif; ?>
                            </div>
                            <span class="badge bg-light text-dark">
                                <?php echo $bank['account_type']; ?>
                            </span>
                        </div>

                        <?php if ($bank['account_number']): ?>
                            <p class="text-muted small mb-1">
                                <i class="fa-solid fa-hashtag me-1"></i>
                                Account: ****
                                <?php echo substr($bank['account_number'], -4); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($bank['iban']): ?>
                            <p class="text-muted small mb-1">
                                <i class="fa-solid fa-barcode me-1"></i>
                                IBAN: ****
                                <?php echo substr($bank['iban'], -4); ?>
                            </p>
                        <?php endif; ?>

                        <p class="text-muted small mb-0">
                            <i class="fa-solid fa-credit-card me-1"></i>
                            <?php echo $card_counts[$bank['id']] ?? 0; ?> linked card(s)
                        </p>
                    </div>
                    <div class="card-footer bg-light border-0 d-flex justify-content-between">
                        <a href="edit_bank.php?id=<?php echo $bank['id']; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-pen me-1"></i> Edit
                        </a>
                        <a href="#"
                            onclick="return confirmDelete('bank_actions.php?action=delete&id=<?php echo $bank['id']; ?>', 'Delete this bank account?');"
                            class="btn btn-sm btn-outline-danger">
                            <i class="fa-solid fa-trash"></i>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>