<?php
$page_title = "Subscriptions";
require_once 'config.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$user_id = $_SESSION['user_id'];

// Fetch Subscriptions
// We treat any expense marked is_subscription=1 as a recurring monthly cost
$query = "
    SELECT * FROM expenses 
    WHERE user_id = :user_id 
    AND is_subscription = 1 
    ORDER BY amount DESC";
$stmt = $pdo->prepare($query);
$stmt->execute(['user_id' => $user_id]);
$subs = $stmt->fetchAll();

// Calculate Monthly Burn
$monthly_burn = array_sum(array_column($subs, 'amount'));

// Calculate Yearly Burn
$yearly_burn = $monthly_burn * 12;
?>

<div class="d-flex justify-content-between align-items-center mb-5">
    <div>
        <h1 class="h3 fw-bold mb-1">Subscriptions</h1>
        <p class="text-muted mb-0">Manage your recurring expenses</p>
    </div>
    <div class="text-end">
        <a href="add_expense.php?subscription=true" class="btn btn-primary fw-bold px-4">
            <i class="fa-solid fa-plus me-2"></i> Add Subscription
        </a>
    </div>
</div>

<!-- Stats Row -->
<div class="row g-4 mb-5">
    <!-- Monthly Burn -->
    <div class="col-md-6">
        <div class="glass-panel p-4 h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div class="rounded-circle bg-danger-subtle p-3 text-danger">
                    <i class="fa-solid fa-fire fa-xl"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-1">AED <span class="blur-sensitive">
                    <?php echo number_format($monthly_burn, 2); ?>
                </span></h3>
            <span class="text-muted small">Monthly Burn Rate</span>
        </div>
    </div>

    <!-- Yearly Burn -->
    <div class="col-md-6">
        <div class="glass-panel p-4 h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div class="rounded-circle bg-warning-subtle p-3 text-warning">
                    <i class="fa-solid fa-calendar-check fa-xl"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-1">AED <span class="blur-sensitive">
                    <?php echo number_format($yearly_burn, 2); ?>
                </span></h3>
            <span class="text-muted small">Yearly Cost</span>
        </div>
    </div>
</div>

<!-- Subscriptions List -->
<?php if (empty($subs)): ?>
    <div class="glass-panel p-5 text-center">
        <div class="mb-3 text-muted opacity-50">
            <i class="fa-solid fa-repeat fa-4x"></i>
        </div>
        <h5 class="fw-bold">No Subscriptions Found</h5>
        <p class="text-muted mb-4">Track recurring payments like Netflix, Gym, or Rent here.</p>
        <a href="add_expense.php?subscription=true" class="btn btn-outline-primary px-4">Add First Subscription</a>
    </div>
<?php else: ?>
    <div class="glass-panel p-0 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4 py-3">Service</th>
                        <th>Category</th>
                        <th>Next Renewal</th>
                        <th class="text-end pe-4">Cost/Month</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subs as $sub): ?>
                        <tr>
                            <td class="ps-4">
                                <span class="fw-bold d-block">
                                    <?php echo htmlspecialchars($sub['description']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">
                                    <?php echo htmlspecialchars($sub['category']); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                // Calculate next renewal (Assumes same day every month)
                                // If today > renewal day, it's next month. Else this month.
                                $day = date('d', strtotime($sub['expense_date']));
                                $next_date = date('Y-m-') . $day;
                                if (strtotime($next_date) < time()) {
                                    $next_date = date('Y-m-', strtotime('+1 month')) . $day;
                                }

                                // Simple logic: Highlight if coming up in 3 days
                                $days_left = ceil((strtotime($next_date) - time()) / 86400);
                                $status_class = $days_left <= 3 ? "text-danger fw-bold" : "text-muted";
                                ?>
                                <div class="<?php echo $status_class; ?>">
                                    <i class="fa-solid fa-clock me-1 small"></i>
                                    <?php echo date('d M Y', strtotime($next_date)); ?>
                                </div>
                            </td>
                            <td class="text-end pe-4 fw-bold">
                                AED <span class="blur-sensitive">
                                    <?php echo number_format($sub['amount'], 2); ?>
                                </span>
                            </td>
                            <td class="text-end pe-3">
                                <form action="expense_actions.php" method="POST" class="d-inline"
                                    onsubmit="return confirmSubmit(this, 'Stop tracking <?php echo addslashes(htmlspecialchars($sub['description'])); ?> - AED <?php echo number_format($sub['amount'], 2); ?>/month?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="delete_auto_expense">
                                    <input type="hidden" name="id" value="<?php echo $sub['id']; ?>">
                                    <button type="submit" class="btn btn-sm text-danger border-0 p-0" title="Stop Tracking">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>