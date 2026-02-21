<?php
$page_title = "My Calendar";
require_once 'config.php';
$page_title = "My Calendar";
require_once 'config.php';

// 1. Auto-Healing: Create Reminders Table with Recurrence Type

// 2. Handle Actions (Logic BEFORE Header)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: calendar.php?error=Unauthorized: Read-only access");
        exit();
    }

    if ($_POST['action'] == 'add_reminder') {
        $title = $_POST['title'];
        $date = $_POST['alert_date'];
        $recur_type = $_POST['recurrence_type'] ?? 'none';
        $color = $_POST['color'] ?? 'primary';

        $stmt = $pdo->prepare("INSERT INTO reminders (user_id, tenant_id, title, alert_date, recurrence_type, color) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], $title, $date, $recur_type, $color]);

        header("Location: calendar.php?success=Reminder Added");
        exit;
    } elseif ($_POST['action'] == 'delete_reminder') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM reminders WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);
        }
        header("Location: calendar.php?deleted=1");
        exit;
    }
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');

// Navigation
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year--;
}

$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}

$month_name = date('F', mktime(0, 0, 0, $month, 10));
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$first_day_of_week = date('N', strtotime("$year-$month-01")); // 1 (Mon) - 7 (Sun)

// 1. Fetch Events
$events = [];

// A. Subscriptions
$stmt = $pdo->prepare("SELECT id, description, amount, expense_date FROM expenses WHERE tenant_id = ? AND is_subscription = 1");
$stmt->execute([$_SESSION['tenant_id']]);
$subs = $stmt->fetchAll();

foreach ($subs as $sub) {
    $day = date('d', strtotime($sub['expense_date']));
    if ($day <= $days_in_month) {
        $events[(int) $day][] = [
            'type' => 'sub',
            'title' => $sub['description'],
            'amount' => number_format($sub['amount'], 2),
            'color' => 'warning',
            'icon' => 'fa-repeat'
        ];
    }
}

// B. Card Bills
$stmt = $pdo->prepare("SELECT id, card_name, bank_name, bill_day FROM cards WHERE tenant_id = ? AND bill_day IS NOT NULL");
$stmt->execute([$_SESSION['tenant_id']]);
$cards = $stmt->fetchAll();

foreach ($cards as $card) {
    $day = $card['bill_day'];
    if ($day <= $days_in_month) {
        $events[(int) $day][] = [
            'type' => 'bill',
            'title' => $card['bank_name'] . ' Bill',
            'amount' => 'Due',
            'color' => 'danger',
            'icon' => 'fa-file-invoice-dollar'
        ];
    }
}

// C. Custom Reminders
$stmt = $pdo->prepare("SELECT * FROM reminders WHERE tenant_id = ?");
$stmt->execute([$_SESSION['tenant_id']]);
$reminders = $stmt->fetchAll();

foreach ($reminders as $rem) {
    $rDay = date('d', strtotime($rem['alert_date']));
    $rMonth = date('n', strtotime($rem['alert_date']));
    $rYear = date('Y', strtotime($rem['alert_date']));

    $should_show = false;
    // Recurrence Logic
    $recur_type = $rem['recurrence_type'] ?? ($rem['is_recurring'] ? 'monthly' : 'none'); // Fallback for old rows

    $should_show = false;
    if ($recur_type == 'monthly') {
        // Show every month on this day
        if ($rDay <= $days_in_month)
            $should_show = true;
    } elseif ($recur_type == 'yearly') {
        // Show every year on this month & day
        if ($rMonth == $month && $rDay <= $days_in_month)
            $should_show = true;
    } else {
        // One-time: Specific Date
        if ($rMonth == $month && $rYear == $year)
            $should_show = true;
    }

    if ($should_show) {
        $events[(int) $rDay][] = [
            'type' => 'reminder',
            'id' => $rem['id'],
            'title' => $rem['title'],
            'amount' => '',
            'color' => $rem['color'] ?? 'primary',
            'icon' => 'fa-bell'
        ];
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">My Calendar</h1>
    <div class="d-flex gap-2">
        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReminderModal">
                <i class="fa-solid fa-plus me-2"></i> Add Reminder
            </button>
        <?php else: ?>
            <div class="bg-light text-muted px-3 py-1 rounded-pill shadow-sm d-flex align-items-center small">
                <i class="fa-solid fa-lock me-2"></i> Read Only
            </div>
        <?php endif; ?>
        <div class="btn-group">
            <a href="?month=<?php echo htmlspecialchars($prev_month); ?>&year=<?php echo htmlspecialchars($prev_year); ?>"
                class="btn btn-light"><i class="fa-solid fa-chevron-left"></i></a>
            <span class="btn btn-light fw-bold px-3" style="cursor: default; min-width: 140px;">
                <?php echo htmlspecialchars($month_name . ' ' . $year); ?>
            </span>
            <a href="?month=<?php echo htmlspecialchars($next_month); ?>&year=<?php echo htmlspecialchars($next_year); ?>"
                class="btn btn-light"><i class="fa-solid fa-chevron-right"></i></a>
        </div>
    </div>
</div>

<div class="glass-panel p-4">
    <!-- Days Header -->
    <div class="row text-center fw-bold text-muted mb-3 d-none d-md-flex">
        <div class="col">Mon</div>
        <div class="col">Tue</div>
        <div class="col">Wed</div>
        <div class="col">Thu</div>
        <div class="col">Fri</div>
        <div class="col">Sat</div>
        <div class="col">Sun</div>
    </div>

    <div class="calendar-grid">
        <?php
        // Empty slots before 1st day
        for ($i = 1; $i < $first_day_of_week; $i++) {
            echo '<div class="calendar-day empty d-none d-md-block"></div>';
        }

        // Days
        for ($d = 1; $d <= $days_in_month; $d++) {
            $is_today = ($d == date('j') && $month == date('n') && $year == date('Y'));
            $day_events = $events[$d] ?? [];

            $bg_class = $is_today ? 'bg-primary-subtle border-primary' : '';

            echo "<div class='calendar-day $bg_class'>";
            echo "<div class='day-number " . ($is_today ? 'text-primary fw-bold' : 'text-muted') . "'>$d</div>";

            echo "<div class='d-flex flex-column gap-1'>";
            foreach ($day_events as $evt) {
                // If it's a reminder, we allow delete
                $is_rem = ($evt['type'] === 'reminder');

                echo "<div class='event-badge bg-{$evt['color']}-subtle text-{$evt['color']} small text-truncate d-flex justify-content-between align-items-center role='button' title='{$evt['title']}'>";
                echo "<span><i class='fa-solid {$evt['icon']} me-1'></i>" . htmlspecialchars($evt['title']) . "</span>";

                if ($is_rem) {
                    if (($_SESSION['permission'] ?? 'edit') !== 'read_only') {
                        echo "<button type='button' class='btn btn-link p-0 text-danger ms-1' 
                                style='font-size: 0.9em; line-height: 1;' 
                                onclick=\"confirmDeleteReminder({$evt['id']}, '" . addslashes(htmlspecialchars($evt['title'])) . "')\">
                                <i class='fa-solid fa-times'></i>
                              </button>";
                    } else {
                        echo "<i class='fa-solid fa-lock text-muted x-small ms-1'></i>";
                    }
                }
                echo "</div>";
            }
            echo "</div>"; // End flex column
        
            echo "</div>";
        }
        ?>
    </div>
</div>

<style>
    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 10px;
    }

    .calendar-day {
        min-height: 100px;
        background: rgba(255, 255, 255, 0.5);
        border-radius: 10px;
        padding: 10px;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .calendar-day.empty {
        background: none;
        border: none;
    }

    .day-number {
        font-size: 1.1em;
        margin-bottom: 5px;
    }

    .event-badge {
        font-size: 0.75em;
        padding: 3px 6px;
        border-radius: 4px;
        margin-bottom: 3px;
    }

    @media (max-width: 768px) {
        .calendar-grid {
            display: flex;
            flex-direction: column;
        }

        .calendar-day {
            min-height: auto;
        }

        .calendar-day.empty {
            display: none;
        }
    }
</style>

<!-- Add Reminder Modal -->
<div class="modal fade" id="addReminderModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Add New Reminder</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_reminder">

                    <div class="mb-3">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Passport Expiry"
                            required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="alert_date" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Recurrence</label>
                        <select name="recurrence_type" class="form-select">
                            <option value="none">One-time (No Repeat)</option>
                            <option value="monthly">Monthly (Every Month on this day)</option>
                            <option value="yearly">Yearly (Every Year on this date)</option>
                        </select>
                        <div class="form-text small">Great for subscriptions, birthdays, or annual fees.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Color Tag</label>
                        <div class="d-flex gap-2">
                            <input type="radio" class="btn-check" name="color" id="c_primary" value="primary" checked>
                            <label class="btn btn-outline-primary btn-sm rounded-pill" for="c_primary">Blue</label>

                            <input type="radio" class="btn-check" name="color" id="c_danger" value="danger">
                            <label class="btn btn-outline-danger btn-sm rounded-pill" for="c_danger">Red</label>

                            <input type="radio" class="btn-check" name="color" id="c_success" value="success">
                            <label class="btn btn-outline-success btn-sm rounded-pill" for="c_success">Green</label>

                            <input type="radio" class="btn-check" name="color" id="c_warning" value="warning">
                            <label class="btn btn-outline-warning btn-sm rounded-pill" for="c_warning">Yellow</label>
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-bold">Save Reminder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteReminderModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-panel border-0">
            <div class="modal-body text-center py-4">
                <i class="fa-solid fa-trash-can text-danger fa-3x mb-3"></i>
                <h5 class="fw-bold">Delete Reminder?</h5>
                <p id="deleteReminderMsg" class="text-muted small">This cannot be undone.</p>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_reminder">
                    <input type="hidden" name="id" id="deleteReminderId">

                    <div class="d-flex justify-content-center gap-2 mt-3">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function confirmDeleteReminder(id, title) {
        document.getElementById('deleteReminderId').value = id;
        document.getElementById('deleteReminderMsg').innerHTML = `Delete reminder: <strong>${title}</strong>? <br><span class="text-danger small">This cannot be undone.</span>`;
        new bootstrap.Modal(document.getElementById('deleteReminderModal')).show();
    }
</script>

<?php require_once 'includes/footer.php'; ?>