<?php
$page_title = "My Bank Accounts";
include_once 'config.php'; // NOSONAR
include_once 'includes/header.php'; // NOSONAR
include_once 'includes/sidebar.php'; // NOSONAR

$tenant_id = $_SESSION['tenant_id'];
define('BANK_DEFAULT_COLOR', '#0d6efd');

// Fetch Banks
try {
    $stmt = $pdo->prepare("SELECT * FROM my_banks WHERE tenant_id = ? ORDER BY bank_name ASC");
    $stmt->execute([$tenant_id]);
    $banks = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching banks: " . $e->getMessage());
    $banks = [];
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">My Bank Accounts</h1>
        <p class="text-muted mb-0">Manage your connected bank accounts and balances</p>
    </div>
    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
        <a href="add_bank.php" class="btn btn-primary fw-bold">
            <i class="fa-solid fa-plus me-2"></i> Add Bank
        </a>
    <?php endif; ?>
</div>

<div class="row g-4">
    <?php if (empty($banks)): ?>
        <div class="col-12 text-center py-5">
            <div class="glass-panel p-5">
                <i class="fa-solid fa-building-columns fa-4x mb-3 opacity-25"></i>
                <h4 class="fw-bold">No Banks Added</h4>
                <p class="text-muted">Start by adding your first bank account to track balances.</p>
                <a href="add_bank.php" class="btn btn-primary mt-2">Add My First Bank</a>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($banks as $bank): ?>
            <?php $bank_color = $bank['color'] ?? BANK_DEFAULT_COLOR; ?>
            <div class="col-md-6 col-lg-4">
                <div class="glass-panel bank-card h-100 p-4 border-top border-4"
                    style="border-top-color: <?php echo $bank_color; ?> !important;">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="bank-icon-wrapper rounded-circle p-3 d-flex align-items-center justify-content-center"
                            style="background: <?php echo $bank_color . '15'; ?>; color: <?php echo $bank_color; ?>; width: 60px; height: 60px;">
                            <i class="fa-solid fa-building-columns fa-xl"></i>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-link text-muted p-0" data-bs-toggle="dropdown">
                                <i class="fa-solid fa-ellipsis-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end border-0 shadow">
                                <li><a class="dropdown-item" href="edit_bank.php?id=<?php echo $bank['id']; ?>"><i
                                            class="fa-solid fa-pen me-2"></i> Edit Account</a></li>
                                <li>
                                    <button type="button" class="dropdown-item text-danger"
                                        onclick="confirmDelete(<?php echo $bank['id']; ?>, '<?php echo addslashes($bank['bank_name']); ?>')">
                                        <i class="fa-solid fa-trash me-2"></i> Remove
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-1">
                        <?php echo htmlspecialchars($bank['bank_name']); ?>
                    </h5>
                    <p class="text-muted small mb-3">
                        <?php echo htmlspecialchars($bank['account_number'] ? '****' . substr($bank['account_number'], -4) : 'Savings Account'); ?>
                    </p>

                    <div class="mt-auto">
                        <h4 class="fw-bold mb-0 text-dark">
                            <small class="text-muted" style="font-size: 0.6em">AED</small>
                            <span class="blur-sensitive">
                                <?php echo number_format($bank['current_balance'], 2); ?>
                            </span>
                        </h4>
                        <div class="mt-2">
                            <a href="bank_balances.php?bank_id=<?php echo $bank['id']; ?>"
                                class="text-decoration-none small fw-bold">View History <i
                                    class="fa-solid fa-arrow-right ms-1"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
    function confirmDelete(id, name) {
        if (confirm(`Are you sure you want to remove "${name}"? This will not delete your transaction history, but the bank will no longer appear in your active lists.`)) {
            window.location.href = `bank_actions.php?action=delete&id=${id}&csrf_token=<?php echo generate_csrf_token(); ?>`;
        }
    }
</script>

<?php include_once 'includes/footer.php'; // NOSONAR ?>