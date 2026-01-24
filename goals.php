<?php
$page_title = "Financial Goals";
require_once 'config.php';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$user_id = $_SESSION['user_id'];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    if ($_POST['action'] == 'add_goal') {
        $name = $_POST['name'];
        $target = $_POST['target_amount'];
        $saved = $_POST['current_saved'];
        $date = $_POST['target_date'];

        $stmt = $pdo->prepare("INSERT INTO sinking_funds (user_id, name, target_amount, current_saved, target_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $name, $target, $saved, $date]);

        echo "<script>window.location.href='goals.php';</script>";
        exit;
    } elseif ($_POST['action'] == 'add_funds') {
        $id = $_POST['goal_id'];
        $amount = $_POST['amount'];

        $stmt = $pdo->prepare("UPDATE sinking_funds SET current_saved = current_saved + ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$amount, $id, $user_id]);

        // Optional: Also log this as an expense? 
        // For now, we act as if the money is just moved to a savings pot, so it might not be an expense per se, 
        // but for "Net Worth" logic, if it's in a bank account, it's still an asset.

        echo "<script>window.location.href='goals.php';</script>";
        exit;
    } elseif ($_POST['action'] == 'delete_goal') {
        $id = $_POST['goal_id'];
        $stmt = $pdo->prepare("DELETE FROM sinking_funds WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);

        echo "<script>window.location.href='goals.php';</script>";
        exit;
    }
}

// Fetch Goals
$stmt = $pdo->prepare("SELECT * FROM sinking_funds WHERE user_id = ? ORDER BY target_date ASC");
$stmt->execute([$user_id]);
$goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_saved = array_sum(array_column($goals, 'current_saved'));
$total_target = array_sum(array_column($goals, 'target_amount'));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">Financial Goals</h1>
        <p class="text-muted mb-0">Sinking Funds & Big Purchases</p>
    </div>
    <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#addGoalModal">
        <i class="fa-solid fa-plus me-2"></i> New Goal
    </button>
</div>

<!-- Summary Row -->
<div class="row g-4 mb-5">
    <div class="col-md-4">
        <div class="glass-panel p-4 h-100">
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Total Saved</h6>
            <h3 class="fw-bold text-success mb-0">AED <span class="blur-sensitive">
                    <?php echo number_format($total_saved, 2); ?>
                </span></h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="glass-panel p-4 h-100">
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Total Target</h6>
            <h3 class="fw-bold text-primary mb-0">AED <span class="blur-sensitive">
                    <?php echo number_format($total_target, 2); ?>
                </span></h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="glass-panel p-4 h-100">
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Active Goals</h6>
            <h3 class="fw-bold text-dark mb-0">
                <?php echo count($goals); ?>
            </h3>
        </div>
    </div>
</div>

<!-- Goals Grid -->
<div class="row g-4">
    <?php if (empty($goals)): ?>
        <div class="col-12 text-center py-5">
            <i class="fa-solid fa-bullseye empty-state-icon"></i>
            <h5 class="fw-bold text-muted">No goals set yet</h5>
            <p class="text-muted mb-4">Start saving for that dream car, house, or vacation!</p>
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addGoalModal">Create
                Goal</button>
        </div>
    <?php else: ?>
        <?php foreach ($goals as $goal): ?>
            <?php
            $pct = ($goal['target_amount'] > 0) ? ($goal['current_saved'] / $goal['target_amount']) * 100 : 0;
            $pct = min(100, $pct);

            // Days left
            $days_left = ceil((strtotime($goal['target_date']) - time()) / 86400);
            $status_color = ($days_left < 30 && $pct < 100) ? 'text-danger' : 'text-muted';

            // Monthly contribution needed
            $needed = $goal['target_amount'] - $goal['current_saved'];
            $months_left = max(1, ceil($days_left / 30));
            $monthly_contrib = ($needed > 0) ? $needed / $months_left : 0;
            ?>
            <div class="col-md-6 col-xl-4">
                <div class="glass-panel p-4 h-100 position-relative">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-primary-subtle p-3 text-primary">
                                <i class="fa-solid <?php echo htmlspecialchars($goal['icon']); ?> fa-lg"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0">
                                    <?php echo htmlspecialchars($goal['name']); ?>
                                </h5>
                                <div class="small <?php echo $status_color; ?>">
                                    <?php echo $days_left > 0 ? $days_left . ' days left' : 'Due Passed'; ?>
                                </div>
                            </div>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-link text-muted p-0" data-bs-toggle="dropdown">
                                <i class="fa-solid fa-ellipsis-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end border-0 shadow">
                                <li>
                                    <form method="POST" onsubmit="return confirmSubmit(this, 'Delete this goal?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <input type="hidden" name="action" value="delete_goal">
                                        <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                                        <button type="submit" class="dropdown-item text-danger"><i
                                                class="fa-solid fa-trash me-2"></i> Delete</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between small fw-bold mb-1">
                            <span class="text-primary">AED
                                <?php echo number_format($goal['current_saved'], 2); ?>
                            </span>
                            <span class="text-muted">of AED
                                <?php echo number_format($goal['target_amount'], 2); ?>
                            </span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $pct; ?>%"></div>
                        </div>
                    </div>

                    <?php if ($pct < 100): ?>
                        <div class="alert alert-light border-0 small mb-3 p-2 text-center text-muted">
                            <i class="fa-solid fa-piggy-bank me-1 text-success"></i> Save <b>AED
                                <?php echo number_format($monthly_contrib, 2); ?>/mo
                            </b> to hit goal
                        </div>

                        <form method="POST" class="d-flex gap-2">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="action" value="add_funds">
                            <input type="hidden" name="goal_id" value="<?php echo $goal['id']; ?>">
                            <input type="number" name="amount" class="form-control form-control-sm" placeholder="Add Amount"
                                required>
                            <button type="submit" class="btn btn-sm btn-success px-3"><i class="fa-solid fa-plus"></i></button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-success border-0 small mb-0 text-center fw-bold">
                            <i class="fa-solid fa-check-circle me-1"></i> Goal Achieved!
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add Goal Modal -->
<div class="modal fade" id="addGoalModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">New Financial Goal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_goal">
                    <div class="mb-3">
                        <label class="form-label">Goal Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. New Car, Europe Trip..."
                            required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label">Target Amount</label>
                            <input type="number" name="target_amount" class="form-control" placeholder="10000" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Already Saved</label>
                            <input type="number" name="current_saved" class="form-control" value="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Target Date</label>
                        <input type="date" name="target_date" class="form-control" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-bold py-2">Create Goal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>