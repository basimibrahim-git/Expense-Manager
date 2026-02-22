<?php
$page_title = "My Reminders";
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;

Bootstrap::init();

// 1. Handle Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: reminders.php?error=Unauthorized: Read-only access");
        exit();
    }

    if ($_POST['action'] == 'add_reminder') {
        $title = htmlspecialchars($_POST['title']);
        $date = $_POST['alert_date'] . ' ' . ($_POST['alert_time'] ?? '00:00:00');
        $recur_type = $_POST['recurrence_type'] ?? 'none';
        $color = $_POST['color'] ?? 'primary';

        $stmt = $pdo->prepare("INSERT INTO reminders (user_id, tenant_id, title, alert_date, recurrence_type, color) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], $title, $date, $recur_type, $color]);

        header("Location: reminders.php?success=Reminder Added");
        exit;
    } elseif ($_POST['action'] == 'update_reminder') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $title = htmlspecialchars($_POST['title']);
        $date = $_POST['alert_date'] . ' ' . ($_POST['alert_time'] ?? '00:00:00');
        $recur_type = $_POST['recurrence_type'] ?? 'none';

        if ($id) {
            $stmt = $pdo->prepare("UPDATE reminders SET title=?, alert_date=?, recurrence_type=? WHERE id=? AND tenant_id=?");
            $stmt->execute([$title, $date, $recur_type, $id, $_SESSION['tenant_id']]);
        }
        header("Location: reminders.php?success=Reminder Updated");
        exit;
    } elseif ($_POST['action'] == 'delete_reminder') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM reminders WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);
        }
        header("Location: reminders.php?deleted=1");
        exit;
    }
}

Layout::header();
Layout::sidebar();

// Fetch Reminders
$stmt = $pdo->prepare("SELECT * FROM reminders WHERE tenant_id = ? ORDER BY alert_date ASC");
$stmt->execute([$_SESSION['tenant_id']]);
$reminders = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">My Reminders</h1>
    <div class="d-flex gap-2">
        <a href="export_actions.php?action=export_reminders" class="btn btn-outline-secondary">
            <i class="fa-solid fa-file-csv me-1"></i> Export
        </a>
        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReminderModal">
                <i class="fa-solid fa-plus me-2"></i> Add Reminder
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($reminders)): ?>
    <div class="text-center py-5 glass-panel">
        <div class="mb-3 text-muted" style="font-size: 3rem;">
            <i class="fa-regular fa-bell"></i>
        </div>
        <h4>No reminders set</h4>
        <p class="text-muted">Stay on top of expiry dates, bills, and renewals.</p>
        <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addReminderModal">Add Reminder</button>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($reminders as $rem):
            $target = strtotime($rem['alert_date']);
            $now = time();
            $diff = $target - $now;
            $days_left = floor($diff / (60 * 60 * 24));

            // Urgency Colors
            $bg_gradient = "linear-gradient(45deg, #0dcaf0, #0aa2c0)"; // Default Info/Blue
            if ($diff < 0) {
                $bg_gradient = "linear-gradient(45deg, #6c757d, #495057)"; // Expired (Grey)
                $status_text = "Expired";
            } elseif ($days_left <= 7) {
                $bg_gradient = "linear-gradient(45deg, #dc3545, #c82333)"; // Danger/Red
                $status_text = "Expiring Soon";
            } elseif ($days_left <= 30) {
                $bg_gradient = "linear-gradient(45deg, #ffc107, #e0a800)"; // Warning/Yellow
                $status_text = "Upcoming";
            } else {
                $bg_gradient = "linear-gradient(45deg, #198754, #146c43)"; // Safe/Green
                $status_text = "Active";
            }

            // Text color for yellow bg
            $text_color = ($days_left <= 30 && $days_left > 7) ? 'text-dark' : 'text-white';
            $badge_color = ($days_left <= 30 && $days_left > 7) ? 'bg-dark text-white' : 'bg-white text-dark';
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm overflow-hidden h-100 <?php echo $text_color; ?>"
                    style="background: <?php echo $bg_gradient; ?>; border-radius: 16px; min-height: 200px;">
                    <div class="card-body p-4 d-flex flex-column justify-content-between">

                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="badge <?php echo $badge_color; ?> mb-2">
                                    <?php echo $status_text; ?>
                                </span>
                                <h5 class="fw-bold mb-0">
                                    <?php echo htmlspecialchars($rem['title']); ?>
                                </h5>
                                <small class="opacity-75">
                                    <?php echo htmlspecialchars(date('d M Y, h:i A', $target)); ?>
                                </small>
                            </div>
                            <div class="d-flex gap-2">
                                <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                    <button type="button"
                                        class="btn btn-sm btn-link <?php echo htmlspecialchars($text_color); ?> p-0 opacity-50 hover-100"
                                        onclick='editReminder(<?php echo intval($rem['id']); ?>, <?php echo json_encode($rem['title']); ?>, "<?php echo htmlspecialchars(date('Y-m-d', $target)); ?>", "<?php echo htmlspecialchars(date('H:i', $target)); ?>", "<?php echo htmlspecialchars($rem['recurrence_type']); ?>")'>
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Delete this reminder?');">
                                        <input type="hidden" name="csrf_token"
                                            value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="delete_reminder">
                                        <input type="hidden" name="id" value="<?php echo intval($rem['id']); ?>">
                                        <button type="submit"
                                            class="btn btn-sm btn-link <?php echo $text_color; ?> p-0 opacity-50 hover-100">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <i class="fa-solid fa-lock opacity-50 small" title="Read Only"></i>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mt-4">
                            <?php if ($diff > 0): ?>
                                <div class="countdown-timer d-flex justify-content-between text-center"
                                    data-target="<?php echo $target * 1000; ?>">
                                    <div class="flex-fill border-end border-white border-opacity-10">
                                        <div class="h3 fw-bold mb-0 day-val">0</div>
                                        <small class="x-small text-uppercase opacity-75" style="font-size: 0.7rem;">Days</small>
                                    </div>
                                    <div class="flex-fill border-end border-white border-opacity-10">
                                        <div class="h3 fw-bold mb-0 hour-val">00</div>
                                        <small class="x-small text-uppercase opacity-75" style="font-size: 0.7rem;">Hours</small>
                                    </div>
                                    <div class="flex-fill border-end border-white border-opacity-10">
                                        <div class="h3 fw-bold mb-0 min-val">00</div>
                                        <small class="x-small text-uppercase opacity-75" style="font-size: 0.7rem;">Mins</small>
                                    </div>
                                    <div class="flex-fill">
                                        <div class="h3 fw-bold mb-0 sec-val">00</div>
                                        <small class="x-small text-uppercase opacity-75" style="font-size: 0.7rem;">Secs</small>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="h4 fw-bold mb-0">Expired</div>
                                <small class="opacity-75">
                                    <?php echo abs($days_left); ?> days ago
                                </small>
                            <?php endif; ?>
                        </div>

                        <?php if ($rem['recurrence_type'] != 'none'): ?>
                            <div class="mt-3 small opacity-75">
                                <i class="fa-solid fa-repeat me-1"></i> Repeats:
                                <?php echo ucfirst($rem['recurrence_type']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Add Reminder Modal -->
<div class="modal fade" id="addReminderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="reminderForm">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="add_reminder" id="formAction">
                <input type="hidden" name="id" id="reminderId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Add Reminder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="reminderTitle" class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="reminderTitle" class="form-control"
                            placeholder="e.g. License Expiry" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label for="reminderDate" class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" name="alert_date" id="reminderDate" class="form-control" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label for="reminderTime" class="form-label">Time</label>
                            <input type="time" name="alert_time" id="reminderTime" class="form-control" value="09:00">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="reminderRecurrence" class="form-label">Recurrence</label>
                        <select name="recurrence_type" id="reminderRecurrence" class="form-select">
                            <option value="none">One-time</option>
                            <option value="monthly">Monthly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Add Reminder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Live Countdown Logic
    setInterval(function () {
        document.querySelectorAll('.countdown-timer').forEach(function (el) {
            const target = parseInt(el.getAttribute('data-target'));
            const now = new Date().getTime();
            const diff = target - now;

            if (diff > 0) {
                const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((diff % (1000 * 60)) / 1000);

                el.querySelector('.day-val').textContent = days;
                el.querySelector('.hour-val').textContent = hours.toString().padStart(2, '0');
                el.querySelector('.min-val').textContent = minutes.toString().padStart(2, '0');
                el.querySelector('.sec-val').textContent = seconds.toString().padStart(2, '0');
            } else {
                el.innerHTML = '<div class="h4 fw-bold mb-0">Expired</div><small>Just now</small>';
            }
        });
    }, 1000);

    function editReminder(id, title, date, time, recurrence) {
        document.getElementById('formAction').value = 'update_reminder';
        document.getElementById('reminderId').value = id;
        document.querySelector('input[name="title"]').value = title;
        document.querySelector('input[name="alert_date"]').value = date;
        document.querySelector('input[name="alert_time"]').value = time;
        document.querySelector('select[name="recurrence_type"]').value = recurrence;

        document.querySelector('.modal-title').textContent = 'Edit Reminder';
        document.getElementById('submitBtn').textContent = 'Save Changes';

        const modal = new bootstrap.Modal(document.getElementById('addReminderModal'));
        modal.show();
    }

    // Reset modal on close
    document.getElementById('addReminderModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('reminderForm').reset();
        document.getElementById('formAction').value = 'add_reminder';
        document.getElementById('reminderId').value = '';
        document.querySelector('.modal-title').textContent = 'Add Reminder';
        document.getElementById('submitBtn').textContent = 'Add Reminder';
    });
</script>

<?php Layout::footer(); ?>