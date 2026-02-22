<?php
$page_title = "Subscriptions";
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;

Bootstrap::init();
Layout::header();
Layout::sidebar();

$user_id = $_SESSION['user_id'];

// 1. Fetch Unique Subscription Templates
// We group by description to treat each unique recurring bill as a "Template"
// We take the MAX(id) to get the most recent metadata (amount, category, etc.)
$query = "
    SELECT e1.*
    FROM expenses e1
    JOIN (
        SELECT MAX(id) as max_id
        FROM expenses
        WHERE tenant_id = :tenant_id AND is_subscription = 1
        GROUP BY description
    ) e2 ON e1.id = e2.max_id
    ORDER BY e1.amount DESC";
$stmt = $pdo->prepare($query);
$stmt->execute(['tenant_id' => $_SESSION['tenant_id']]);
$templates = $stmt->fetchAll();

// 2. Cross-reference with current month logs
$curr_month = date('n');
$curr_year = date('Y');
$stmt = $pdo->prepare("SELECT description FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?");
$stmt->execute([$_SESSION['tenant_id'], $curr_month, $curr_year]);
$logged_descriptions = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Calculate Monthly Burn
$monthly_burn = array_sum(array_column($templates, 'amount'));
$yearly_burn = $monthly_burn * 12;
?>

<div class="d-flex justify-content-between align-items-center mb-5">
    <div>
        <h1 class="h3 fw-bold mb-1">Subscriptions</h1>
        <p class="text-muted mb-0">Manage your recurring expenses and auto-draft logs</p>
    </div>
    <div class="text-end">
        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
            <a href="add_expense.php?subscription=true" class="btn btn-primary fw-bold px-4">
                <i class="fa-solid fa-plus me-2"></i> Add New Subscription
            </a>
        <?php endif; ?>
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
            <span class="text-muted small">Total Monthly Commitment</span>
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
            <span class="text-muted small">Estimated Annual Cost</span>
        </div>
    </div>
</div>

<!-- Subscriptions List -->
<?php if (empty($templates)): ?>
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
                        <th>Status (<?php echo date('M'); ?>)</th>
                        <th>Renewal Day</th>
                        <th class="text-end pe-4">Cost/Month</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $sub): ?>
                        <?php
                        $is_logged = in_array($sub['description'], $logged_descriptions);
                        $day = date('d', strtotime($sub['expense_date']));
                        $is_past = date('d') >= $day;
                        ?>
                        <tr class="<?php echo $is_logged ? 'opacity-75' : ''; ?>">
                            <td class="ps-4">
                                <span class="fw-bold d-block">
                                    <?php echo htmlspecialchars($sub['description']); ?>
                                </span>
                                <small class="text-muted"><?php echo htmlspecialchars($sub['category']); ?></small>
                            </td>
                            <td>
                                <?php if ($is_logged): ?>
                                    <span class="badge bg-success-subtle text-success">
                                        <i class="fa-solid fa-check-circle me-1"></i> Logged
                                    </span>
                                <?php else: ?>
                                    <span
                                        class="badge bg-<?php echo $is_past ? 'danger' : 'warning'; ?>-subtle text-<?php echo $is_past ? 'danger' : 'warning'; ?>">
                                        <i class="fa-solid fa-clock me-1"></i> <?php echo $is_past ? 'Overdue' : 'Pending'; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="text-muted small">
                                    Every <strong><?php echo $day; ?><?php
                                       if ($day == 1) {
                                           echo 'st';
                                       } elseif ($day == 2) {
                                           echo 'nd';
                                       } elseif ($day == 3) {
                                           echo 'rd';
                                       } else {
                                           echo 'th';
                                       }
                                       ?></strong>
                                </div>
                            </td>
                            <td class="text-end pe-4 fw-bold">
                                AED <span class="blur-sensitive">
                                    <?php echo number_format($sub['amount'], 2); ?>
                                </span>
                            </td>
                            <td class="text-end pe-3">
                                <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                    <div class="d-flex gap-2 justify-content-end">
                                        <?php if (!$is_logged): ?>
                                            <form action="expense_actions.php" method="POST">
                                                <input type="hidden" name="csrf_token"
                                                    value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="log_subscription">
                                                <input type="hidden" name="template_id" value="<?php echo $sub['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 fw-bold">
                                                    Log & Pay
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <form action="expense_actions.php" method="POST"
                                            onsubmit="return confirmSubmit(this, 'Stop tracking <?php echo addslashes(htmlspecialchars($sub['description'])); ?>?');">
                                            <input type="hidden" name="csrf_token"
                                                value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="delete_auto_expense">
                                            <input type="hidden" name="id" value="<?php echo $sub['id']; ?>">
                                            <button type="submit" class="btn btn-sm text-muted border-0 p-0" title="Stop Tracking">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <i class="fa-solid fa-lock text-muted small" title="Read Only"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php Layout::footer(); ?>