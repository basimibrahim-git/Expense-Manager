<?php
$page_title = "Incentive Tracker";
require_once 'config.php'; // NOSONAR

// Auth Check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// 1. Auto-Binding - Removed for security (use install.php)
// Schema creation moved to install/install.php to prevent unexpected DDL on production requests.

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: company_tracker.php?year=" . ($_POST['year'] ?? '') . "&error=Unauthorized: Read-only access");
        exit();
    }

    // ADD NEW
    if ($_POST['action'] == 'quick_add') {
        $year = intval($_POST['year']);
        $month = intval($_POST['month']);
        $amount = $_POST['amount']; // raw input to allow "0"

        if (is_numeric($amount) && $month >= 1 && $month <= 12) {
            $date = "$year-$month-01";
            $stmt = $pdo->prepare("INSERT INTO company_incentives (user_id, tenant_id, amount, incentive_date) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], floatval($amount), $date]);
            header("Location: company_tracker.php?year=$year&success=Incentive Added");
            exit;
        }
    }

    // UPDATE EXISTING
    elseif ($_POST['action'] == 'update_incentive') {
        $id = intval($_POST['id']);
        $amount = $_POST['amount'];
        $year = intval($_POST['year']);

        if ($id > 0 && is_numeric($amount)) {
            $stmt = $pdo->prepare("UPDATE company_incentives SET amount = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([floatval($amount), $id, $_SESSION['tenant_id']]);
            header("Location: company_tracker.php?year=$year&success=Updated");
            exit;
        }
    }

    // DELETE EXISTING
    elseif ($_POST['action'] == 'delete_incentive') {
        $id = intval($_POST['id']);
        $year = intval($_POST['year']);

        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM company_incentives WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);
            header("Location: company_tracker.php?year=$year&success=Deleted");
            exit;
        }
    }
}

require_once 'includes/header.php'; // NOSONAR
require_once 'includes/sidebar.php'; // NOSONAR

// Determine View Mode: YEAR LIST vs MONTH GRID
$selected_year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
?>

<?php if (!$selected_year): ?>
    <!-- VIEW 1: YEAR OVERVIEW -->
    <?php
    // Fetch Totals for displayed years
    $years_to_show = range(2023, 2026); // Default Range

    // Check if we have data outside this range to show dynamically?
    // For now, adhere to "start 2023 end 2026" request but fetch totals.
    $stmt = $pdo->prepare("
        SELECT YEAR(incentive_date) as year, SUM(amount) as total
        FROM company_incentives
        WHERE tenant_id = ?
        GROUP BY YEAR(incentive_date)
    ");
    $stmt->execute([$_SESSION['tenant_id']]);
    $year_totals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 fw-bold mb-0">Incentive Tracker</h1>
        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="showAddYear()">
                <i class="fa-solid fa-plus me-1"></i> Add Year
            </button>
        <?php endif; ?>
    </div>

    <div class="row g-3" id="yearGrid">
        <?php foreach ($years_to_show as $y): ?>
            <?php
            $total = $year_totals[$y] ?? 0;
            $has_data = isset($year_totals[$y]);
            $card_class = $has_data ? "border-primary border-2 bg-primary-subtle" : "border-0 shadow-sm bg-white";
            ?>
            <div class="col-6 col-md-4 col-lg-3">
                <a href="?year=<?php echo $y; ?>" class="text-decoration-none text-dark">
                    <div class="card h-100 p-4 text-center transform-hover <?php echo $card_class; ?>">
                        <h2 class="fw-bold mb-1"><?php echo $y; ?></h2>
                        <?php if ($has_data): ?>
                            <div class="h5 fw-bold text-primary mb-0">AED <?php echo number_format($total, 0); ?></div>
                            <div class="small text-muted">Total Incentive</div>
                        <?php else: ?>
                            <div class="text-muted small opacity-50">- No Data -</div>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Hidden Input for Custom Year -->
    <div id="addYearDiv" class="d-none mt-4 text-center glass-panel p-3 mw-400px mx-auto">
        <h6 class="fw-bold mb-2">Jump to Year</h6>
        <form class="d-flex gap-2 justify-content-center"
            onsubmit="event.preventDefault(); window.location.href='?year=' + this.customYear.value">
            <input type="number" name="customYear" class="form-control" placeholder="YYYY" min="2000" max="2099" required>
            <button type="submit" class="btn btn-primary">Go</button>
        </form>
    </div>

    <script>
        function showAddYear() {
            document.getElementById('addYearDiv').classList.toggle('d-none');
        }
    </script>

<?php else: ?>
    <!-- VIEW 2: MONTH GRID (Selected Year) -->
    <?php
    $year = $selected_year;

    // Fetch ALL incentives for this year
    $stmt = $pdo->prepare("
        SELECT id, MONTH(incentive_date) as month, amount
        FROM company_incentives
        WHERE tenant_id = :tenant_id AND YEAR(incentive_date) = :year
        ORDER BY id ASC
    ");
    $stmt->execute(['tenant_id' => $_SESSION['tenant_id'], 'year' => $year]);
    $all_incentives = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process Data
    $monthly_data = [];
    $monthly_totals = [];

    foreach ($all_incentives as $row) {
        $m = $row['month'];
        if (!isset($monthly_data[$m])) {
            $monthly_data[$m] = [];
        }
        $monthly_data[$m][] = $row;

        if (!isset($monthly_totals[$m])) {
            $monthly_totals[$m] = 0;
        }
        $monthly_totals[$m] += $row['amount'];
    }

    $months = [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mar',
        4 => 'Apr',
        5 => 'May',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Aug',
        9 => 'Sep',
        10 => 'Oct',
        11 => 'Nov',
        12 => 'Dec'
    ];

    $year_total = array_sum($monthly_totals);
    ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <a href="company_tracker.php" class="btn btn-outline-secondary btn-sm shadow-sm">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
            <div>
                <h1 class="h4 fw-bold mb-0">Tracker <?php echo $year; ?></h1>
                <div class="small text-muted fw-bold text-success">Total: AED <?php echo number_format($year_total, 0); ?>
                </div>
            </div>
        </div>

        <!-- Year Nav -->
        <div class="btn-group btn-group-sm">
            <a href="?year=<?php echo $year - 1; ?>" class="btn btn-outline-secondary"><i
                    class="fa-solid fa-chevron-left"></i></a>
            <button type="button" class="btn btn-light fw-bold px-3"><?php echo $year; ?></button>
            <a href="?year=<?php echo $year + 1; ?>" class="btn btn-outline-secondary"><i
                    class="fa-solid fa-chevron-right"></i></a>
        </div>
    </div>

    <div class="row g-2">
        <?php foreach ($months as $num => $name): ?>
            <?php
            $total = $monthly_totals[$num] ?? 0;
            $has_data = isset($monthly_totals[$num]); // Use isset to allow 0 value to show as data if wanted, though total 0 is tricky. Logic: display "AED 0" if data exists?
            // Better logic: check if $monthly_data[$num] has entries.
            $has_entries = !empty($monthly_data[$num]);

            $bg_class = $has_entries ? "bg-success-subtle text-success-emphasis" : "bg-white text-muted";
            $border_class = $has_entries ? "border-success border-2" : "border-0 shadow-sm";
            ?>
            <div class="col-6 col-md-3 col-lg-2">
                <button type="button" onclick="openManageModal(<?php echo $num; ?>, '<?php echo $name; ?>')"
                    class="card h-100 p-2 text-center cursor-pointer w-100 <?php echo $bg_class . ' ' . $border_class; ?>"
                    style="cursor: pointer; transition: transform 0.1s; border: none; text-align: inherit; background: none;">
                    <div class="text-uppercase fw-bold small opacity-75 mb-1"><?php echo $name; ?></div>
                    <?php if ($has_entries): ?>
                        <div class="fw-bold h6 mb-0">AED <?php echo number_format($total, 0); ?></div>
                    <?php else: ?>
                        <div class="small py-1 opacity-50"><i class="fa-solid fa-plus"></i></div>
                    <?php endif; ?>
                </button>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Manage Modal -->
    <div class="modal fade" id="manageModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content glass-panel border-0">
                <div class="modal-header border-0 pb-0 justify-content-center position-relative">
                    <button type="button" class="btn-close position-absolute top-0 end-0 m-3"
                        data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center pt-0">
                    <h5 class="fw-bold mb-3">Manage <span id="modalMonthName"></span></h5>

                    <!-- Existing List -->
                    <div id="existingList" class="mb-3"></div>

                    <!-- Add New -->
                    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="action" value="quick_add">
                            <input type="hidden" name="year" value="<?php echo $year; ?>">
                            <input type="hidden" name="month" id="modalMonthNum">

                            <div class="form-floating mb-2">
                                <input type="number" step="0.01" name="amount" class="form-control text-center fw-bold"
                                    id="amountInput" placeholder="0.00" required>
                                <label for="amountInput" class="w-100 text-center">Add Amount (AED)</label>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-bold rounded-pill btn-sm">Add New</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-light small p-2 mb-0">
                            <i class="fa-solid fa-lock me-1"></i> Read Only View
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal (Stacked on top) -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content glass-panel border-0">
                <div class="modal-body text-center p-4">
                    <div class="mb-3 text-danger opacity-75">
                        <i class="fa-solid fa-trash-can fa-3x"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Delete Entry?</h5>
                    <p id="deleteEntryMsg" class="text-muted small mb-4">This action cannot be undone.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="delete_incentive">
                        <input type="hidden" name="id" id="deleteId">
                        <input type="hidden" name="year" value="<?php echo $year; ?>">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-danger fw-bold">Yes, Delete It</button>
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Pass PHP data to JS
        const monthlyData = <?php echo json_encode($monthly_data); ?>;
        const csrfToken = "<?php echo generate_csrf_token(); ?>";
        const currentYear = <?php echo $year; ?>;
        const canEdit = <?php echo (($_SESSION['permission'] ?? 'edit') !== 'read_only') ? 'true' : 'false'; ?>;
        let deleteModalInstance = null;

        function openManageModal(monthNum, monthName) {
            document.getElementById('modalMonthNum').value = monthNum;
            document.getElementById('modalMonthName').innerText = monthName;
            document.getElementById('amountInput').value = ''; // Clear add input

            // Render Existing Items
            const container = document.getElementById('existingList');
            container.innerHTML = ''; // Clear

            const items = monthlyData[monthNum] || [];

            if (items.length > 0) {
                items.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'd-flex gap-1 mb-2 align-items-center';

                    if (canEdit) {
                        div.innerHTML = `
                            <form method="POST" class="flex-grow-1" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="${csrfToken}">
                                <input type="hidden" name="action" value="update_incentive">
                                <input type="hidden" name="id" value="${item.id}">
                                <input type="hidden" name="year" value="${currentYear}">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light border-0">AED</span>
                                    <input type="number" step="0.01" name="amount" value="${item.amount}" class="form-control fw-bold border-0 bg-light" onchange="this.form.submit()">
                                </div>
                            </form>
                            <button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="confirmDelete(${item.id}, '${item.amount}')">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        `;
                    } else {
                        div.innerHTML = `
                            <div class="flex-grow-1 p-2 bg-light rounded text-start fw-bold">
                                AED ${parseFloat(item.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </div>
                        `;
                    }
                    container.appendChild(div);
                });
                container.innerHTML += `<hr class="my-2 opacity-25">`;
            }

            // Show Modal
            new bootstrap.Modal(document.getElementById('manageModal')).show();
        }

        function confirmDelete(id, amount) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteEntryMsg').innerText = `Are you sure you want to delete this incentive of AED ${amount}?`;
            new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
        }
    </script>
<?php endif; ?>

<style>
    .transform-hover:hover {
        transform: translateY(-3px);
        transition: transform 0.2s;
    }
</style>

<?php require_once 'includes/footer.php'; // NOSONAR ?>
