<?php
$page_title = "Incentive Tracker";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Html;
use App\Helpers\Layout;

Bootstrap::init();

// 1. Auto-Binding - Removed for security (use install.php)
// Schema creation moved to install/install.php to prevent unexpected DDL on production requests.

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $year = filter_var($_POST['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');
    $back = "company_tracker.php?year=" . (int) $year;

    // Permission Check
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        header("Location: $back&error=" . urlencode('Unauthorized: Read-only access'));
        exit();
    }

    $amount = $_POST['amount'] ?? ''; // raw input to allow "0"
    $amount_ok = is_numeric($amount) && abs((float) $amount) <= 99999999.99;

    // ADD NEW
    if ($_POST['action'] == 'quick_add') {
        $month = filter_var($_POST['month'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);

        if ($amount_ok && $month) {
            $date = sprintf('%04d-%02d-01', $year, $month);
            // title is NOT NULL without a default, so it must be supplied
            $stmt = $pdo->prepare("INSERT INTO company_incentives (user_id, tenant_id, title, amount, incentive_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], 'Incentive', (float) $amount, $date]);
            header("Location: $back&success=" . urlencode('Incentive Added'));
            exit;
        }
    }

    // UPDATE EXISTING
    elseif ($_POST['action'] == 'update_incentive') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id > 0 && $amount_ok) {
            $stmt = $pdo->prepare("UPDATE company_incentives SET amount = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([(float) $amount, $id, $_SESSION['tenant_id']]);
            header("Location: $back&success=" . urlencode('Updated'));
            exit;
        }
    }

    // DELETE EXISTING
    elseif ($_POST['action'] == 'delete_incentive') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM company_incentives WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $_SESSION['tenant_id']]);
            header("Location: $back&success=" . urlencode('Deleted'));
            exit;
        }
    }

    header("Location: $back&error=" . urlencode('Please enter a valid amount.'));
    exit;
}

Layout::header();
Layout::sidebar();

// Determine View Mode: YEAR LIST vs MONTH GRID
$selected_year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
?>

<?php if (!empty($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo Html::e($_GET['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo Html::e($_GET['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

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
            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" data-onclick="showAddYear">
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
        <form class="d-flex gap-2 justify-content-center" method="GET">
            <input type="number" name="year" class="form-control" placeholder="YYYY" min="2000" max="2100" required>
            <button type="submit" class="btn btn-primary">Go</button>
        </form>
    </div>

    <script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
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
                <button type="button" data-onclick="openManageModal" data-args="<?php echo Html::args($num, $name); ?>"
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
                            <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
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
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
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

    <script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
        // Pass PHP data to JS
        const monthlyData = <?php echo Html::json($monthly_data); ?>;
        const csrfToken = <?php echo Html::json(SecurityHelper::generateCsrfToken()); ?>;
        const currentYear = <?php echo (int) $year; ?>;
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

            // Small DOM helper: element with attributes (no HTML parsing of data)
            function el(tag, attrs, children) {
                const node = document.createElement(tag);
                Object.keys(attrs || {}).forEach(k => node.setAttribute(k, attrs[k]));
                (children || []).forEach(c => node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c));
                return node;
            }

            if (items.length > 0) {
                items.forEach(item => {
                    const div = el('div', { 'class': 'd-flex gap-1 mb-2 align-items-center' });

                    if (canEdit) {
                        const form = el('form', { method: 'POST', 'class': 'flex-grow-1', style: 'margin:0;' }, [
                            el('input', { type: 'hidden', name: 'csrf_token', value: csrfToken }),
                            el('input', { type: 'hidden', name: 'action', value: 'update_incentive' }),
                            el('input', { type: 'hidden', name: 'id', value: String(item.id) }),
                            el('input', { type: 'hidden', name: 'year', value: String(currentYear) }),
                            el('div', { 'class': 'input-group input-group-sm' }, [
                                el('span', { 'class': 'input-group-text bg-light border-0' }, ['AED']),
                                el('input', { type: 'number', step: '0.01', name: 'amount', value: String(item.amount), 'class': 'form-control fw-bold border-0 bg-light', 'data-autosubmit': '' })
                            ])
                        ]);
                        const delBtn = el('button', {
                            type: 'button',
                            'class': 'btn btn-outline-danger btn-sm border-0',
                            'data-onclick': 'confirmDelete',
                            'data-args': JSON.stringify([item.id, String(item.amount)])
                        }, [el('i', { 'class': 'fa-solid fa-trash' })]);
                        div.appendChild(form);
                        div.appendChild(delBtn);
                    } else {
                        div.appendChild(el('div', { 'class': 'flex-grow-1 p-2 bg-light rounded text-start fw-bold' }, [
                            'AED ' + parseFloat(item.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                        ]));
                    }
                    container.appendChild(div);
                });
                container.appendChild(el('hr', { 'class': 'my-2 opacity-25' }));
            }

            // Show Modal
            bootstrap.Modal.getOrCreateInstance(document.getElementById('manageModal')).show();
        }

        function confirmDelete(id, amount) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteEntryMsg').innerText = `Are you sure you want to delete this incentive of AED ${amount}?`;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteConfirmModal')).show();
        }
    </script>
<?php endif; ?>

<style>
    .transform-hover:hover {
        transform: translateY(-3px);
        transition: transform 0.2s;
    }
</style>

<?php Layout::footer(); ?>
