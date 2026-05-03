<?php
$current_page = 'net_worth.php';
$page_title = "Net Worth";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\AuditHelper;

Bootstrap::init();

// Auth Check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$tenant_id = $_SESSION['tenant_id'];

// Create tables if not exist
$pdo->exec("CREATE TABLE IF NOT EXISTS net_worth_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  type ENUM('asset','liability') NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'Other',
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255),
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS net_worth_snapshots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  snap_year SMALLINT NOT NULL,
  snap_month TINYINT NOT NULL,
  total_assets DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_liabilities DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_worth DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_snap (tenant_id, snap_year, snap_month)
)");

// ── POST Handling ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        $_SESSION['error'] = 'Unauthorized: Read-only access.';
        header("Location: net_worth.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_item') {
        $name     = trim($_POST['name'] ?? '');
        $type     = in_array($_POST['type'] ?? '', ['asset','liability']) ? $_POST['type'] : 'asset';
        $category = trim($_POST['category'] ?? 'Other');
        $amount   = floatval($_POST['amount'] ?? 0);
        $notes    = trim($_POST['notes'] ?? '');

        if ($name !== '' && $amount >= 0) {
            $stmt = $pdo->prepare("INSERT INTO net_worth_items (tenant_id, name, type, category, amount, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenant_id, $name, $type, $category, $amount, $notes]);
            AuditHelper::log($pdo, 'add_net_worth_item', "Added $type: $name (AED $amount)");
            $_SESSION['success'] = ucfirst($type) . ' added successfully.';
        } else {
            $_SESSION['error'] = 'Invalid item data.';
        }
        header("Location: net_worth.php");
        exit();

    } elseif ($action === 'update_item') {
        $id       = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $name     = trim($_POST['name'] ?? '');
        $type     = in_array($_POST['type'] ?? '', ['asset','liability']) ? $_POST['type'] : 'asset';
        $category = trim($_POST['category'] ?? 'Other');
        $amount   = floatval($_POST['amount'] ?? 0);
        $notes    = trim($_POST['notes'] ?? '');

        if ($id && $name !== '' && $amount >= 0) {
            $stmt = $pdo->prepare("UPDATE net_worth_items SET name=?, type=?, category=?, amount=?, notes=? WHERE id=? AND tenant_id=?");
            $stmt->execute([$name, $type, $category, $amount, $notes, $id, $tenant_id]);
            AuditHelper::log($pdo, 'update_net_worth_item', "Updated item ID $id: $name");
            $_SESSION['success'] = 'Item updated successfully.';
        } else {
            $_SESSION['error'] = 'Invalid item data.';
        }
        header("Location: net_worth.php");
        exit();

    } elseif ($action === 'delete_item') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM net_worth_items WHERE id=? AND tenant_id=?");
            $stmt->execute([$id, $tenant_id]);
            AuditHelper::log($pdo, 'delete_net_worth_item', "Deleted item ID $id");
            $_SESSION['success'] = 'Item deleted.';
        } else {
            $_SESSION['error'] = 'Invalid item.';
        }
        header("Location: net_worth.php");
        exit();

    } elseif ($action === 'take_snapshot') {
        // Compute totals inline for snapshot
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(balance), 0) FROM banks WHERE tenant_id = ?");
        $stmt->execute([$tenant_id]);
        $snap_bank = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(current_saved), 0) FROM sinking_funds WHERE tenant_id = ?");
        $stmt->execute([$tenant_id]);
        $snap_savings = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM lending_tracker WHERE tenant_id = ? AND status = 'Pending'");
        $stmt->execute([$tenant_id]);
        $snap_lent = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM net_worth_items WHERE tenant_id = ? AND type = 'asset'");
        $stmt->execute([$tenant_id]);
        $snap_manual_assets = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM net_worth_items WHERE tenant_id = ? AND type = 'liability'");
        $stmt->execute([$tenant_id]);
        $snap_liabilities = (float)$stmt->fetchColumn();

        $snap_total_assets = $snap_bank + $snap_savings + $snap_lent + $snap_manual_assets;
        $snap_net_worth    = $snap_total_assets - $snap_liabilities;

        $snap_year  = (int)date('Y');
        $snap_month = (int)date('n');

        $stmt = $pdo->prepare("INSERT INTO net_worth_snapshots (tenant_id, snap_year, snap_month, total_assets, total_liabilities, net_worth)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE total_assets=VALUES(total_assets), total_liabilities=VALUES(total_liabilities), net_worth=VALUES(net_worth)");
        $stmt->execute([$tenant_id, $snap_year, $snap_month, $snap_total_assets, $snap_liabilities, $snap_net_worth]);
        AuditHelper::log($pdo, 'take_net_worth_snapshot', "Snapshot for $snap_year-$snap_month: NW AED $snap_net_worth");
        $_SESSION['success'] = 'Snapshot saved for ' . date('F Y') . '.';
        header("Location: net_worth.php");
        exit();
    }
}

// ── Data Fetching ──────────────────────────────────────────────────────────────

// Auto-tracked assets
$stmt = $pdo->prepare("SELECT COALESCE(SUM(balance), 0) FROM banks WHERE tenant_id = ?");
$stmt->execute([$tenant_id]);
$bank_balances = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(current_saved), 0) FROM sinking_funds WHERE tenant_id = ?");
$stmt->execute([$tenant_id]);
$savings_goals = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM lending_tracker WHERE tenant_id = ? AND status = 'Pending'");
$stmt->execute([$tenant_id]);
$money_lent = (float)$stmt->fetchColumn();

$auto_total = $bank_balances + $savings_goals + $money_lent;

// Manual items
$stmt = $pdo->prepare("SELECT * FROM net_worth_items WHERE tenant_id = ? ORDER BY type, sort_order, id");
$stmt->execute([$tenant_id]);
$all_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$manual_assets      = array_filter($all_items, fn($i) => $i['type'] === 'asset');
$manual_liabilities = array_filter($all_items, fn($i) => $i['type'] === 'liability');

$manual_assets_total      = array_sum(array_column(iterator_to_array(new ArrayIterator($manual_assets)), 'amount'));
$manual_liabilities_total = array_sum(array_column(iterator_to_array(new ArrayIterator($manual_liabilities)), 'amount'));

$total_assets      = $auto_total + $manual_assets_total;
$total_liabilities = $manual_liabilities_total;
$net_worth         = $total_assets - $total_liabilities;

// Trend data — last 12 snapshots
$stmt = $pdo->prepare("SELECT snap_year, snap_month, net_worth FROM net_worth_snapshots WHERE tenant_id = ? ORDER BY snap_year ASC, snap_month ASC LIMIT 12");
$stmt->execute([$tenant_id]);
$snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC);

$snap_labels = [];
$snap_values = [];
foreach ($snapshots as $s) {
    $snap_labels[] = date('M Y', mktime(0, 0, 0, $s['snap_month'], 1, $s['snap_year']));
    $snap_values[] = (float)$s['net_worth'];
}

// Flash messages
$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_error   = $_SESSION['error']   ?? null; unset($_SESSION['error']);

// Also check GET-passed messages (legacy compat)
if (!$flash_success && isset($_GET['success'])) $flash_success = htmlspecialchars($_GET['success']);
if (!$flash_error   && isset($_GET['error']))   $flash_error   = htmlspecialchars($_GET['error']);

Layout::header();
Layout::sidebar();
?>

<!-- ── Page Header ─────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <h1 class="h3 fw-bold mb-1"><i class="fa-solid fa-scale-balanced me-2 text-primary"></i>Net Worth</h1>
        <p class="text-muted mb-0">Track your assets, liabilities and overall financial position.</p>
    </div>
    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
    <div class="d-flex gap-2 flex-wrap">
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="take_snapshot">
            <button type="submit" class="btn btn-outline-secondary">
                <i class="fa-solid fa-camera me-1"></i> Take Snapshot
            </button>
        </form>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#itemModal"
            onclick="openAddModal('asset')">
            <i class="fa-solid fa-plus me-1"></i> Add Asset
        </button>
        <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#itemModal"
            onclick="openAddModal('liability')">
            <i class="fa-solid fa-plus me-1"></i> Add Liability
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Flash Messages -->
<?php if ($flash_success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fa-solid fa-check-circle me-2"></i><?php echo $flash_success; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo $flash_error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Stat Cards ──────────────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-12 col-md-4">
        <div class="glass-panel p-4 position-relative overflow-hidden">
            <div class="position-absolute top-0 end-0 opacity-10 p-3">
                <i class="fa-solid fa-arrow-trend-up fa-3x text-success"></i>
            </div>
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Total Assets</h6>
            <h3 class="fw-bold text-success mb-1">AED <span class="blur-sensitive"><?php echo number_format($total_assets, 2); ?></span></h3>
            <div class="small text-muted">Auto-tracked + manual</div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="glass-panel p-4 position-relative overflow-hidden">
            <div class="position-absolute top-0 end-0 opacity-10 p-3">
                <i class="fa-solid fa-arrow-trend-down fa-3x text-danger"></i>
            </div>
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Total Liabilities</h6>
            <h3 class="fw-bold text-danger mb-1">AED <span class="blur-sensitive"><?php echo number_format($total_liabilities, 2); ?></span></h3>
            <div class="small text-muted">Manual entries</div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="glass-panel p-4 position-relative overflow-hidden">
            <div class="position-absolute top-0 end-0 opacity-10 p-3">
                <i class="fa-solid fa-wallet fa-3x <?php echo $net_worth >= 0 ? 'text-primary' : 'text-danger'; ?>"></i>
            </div>
            <h6 class="text-muted fw-bold text-uppercase small mb-2">Net Worth</h6>
            <h3 class="fw-bold <?php echo $net_worth >= 0 ? 'text-primary' : 'text-danger'; ?> mb-1">
                AED <span class="blur-sensitive"><?php echo number_format($net_worth, 2); ?></span>
            </h3>
            <div class="small text-muted">Assets minus liabilities</div>
        </div>
    </div>
</div>

<!-- ── Assets Panel ────────────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">
    <!-- Auto-tracked -->
    <div class="col-12 col-lg-5">
        <div class="glass-panel p-4 h-100">
            <h5 class="fw-bold mb-3"><i class="fa-solid fa-robot me-2 text-primary"></i>Auto-Tracked Assets</h5>
            <ul class="list-group list-group-flush">
                <li class="list-group-item bg-transparent px-0 d-flex justify-content-between align-items-center border-bottom py-3">
                    <div>
                        <i class="fa-solid fa-building-columns me-2 text-success"></i>
                        <span class="fw-semibold">Bank Balances</span>
                        <div class="small text-muted">From linked bank accounts</div>
                    </div>
                    <span class="fw-bold text-success blur-sensitive">AED <?php echo number_format($bank_balances, 2); ?></span>
                </li>
                <li class="list-group-item bg-transparent px-0 d-flex justify-content-between align-items-center border-bottom py-3">
                    <div>
                        <i class="fa-solid fa-piggy-bank me-2 text-info"></i>
                        <span class="fw-semibold">Savings Goals</span>
                        <div class="small text-muted">Sinking funds balance</div>
                    </div>
                    <span class="fw-bold text-info blur-sensitive">AED <?php echo number_format($savings_goals, 2); ?></span>
                </li>
                <li class="list-group-item bg-transparent px-0 d-flex justify-content-between align-items-center py-3">
                    <div>
                        <i class="fa-solid fa-hand-holding-dollar me-2 text-warning"></i>
                        <span class="fw-semibold">Money Lent Out</span>
                        <div class="small text-muted">Pending repayments</div>
                    </div>
                    <span class="fw-bold text-warning blur-sensitive">AED <?php echo number_format($money_lent, 2); ?></span>
                </li>
            </ul>
            <div class="border-top pt-3 mt-2 d-flex justify-content-between fw-bold">
                <span class="text-muted">Auto Total</span>
                <span class="text-success blur-sensitive">AED <?php echo number_format($auto_total, 2); ?></span>
            </div>
        </div>
    </div>

    <!-- Manual Assets -->
    <div class="col-12 col-lg-7">
        <div class="glass-panel p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0"><i class="fa-solid fa-hand-pointer me-2 text-success"></i>Manual Assets</h5>
                <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#itemModal"
                    onclick="openAddModal('asset')">
                    <i class="fa-solid fa-plus me-1"></i> Add
                </button>
                <?php endif; ?>
            </div>

            <?php if (empty($manual_assets)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-inbox fa-2x opacity-25 mb-2"></i>
                    <p class="small mb-0">No manual assets added yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Category</th>
                                <th class="text-end">Amount</th>
                                <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                <th class="text-end">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manual_assets as $item): ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold"><?php echo htmlspecialchars($item['name']); ?></span>
                                    <?php if (!empty($item['notes'])): ?>
                                    <div class="small text-muted fst-italic"><?php echo htmlspecialchars($item['notes']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-success-subtle text-success"><?php echo htmlspecialchars($item['category']); ?></span></td>
                                <td class="text-end fw-bold text-success blur-sensitive">AED <?php echo number_format($item['amount'], 2); ?></td>
                                <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary border-0"
                                        onclick="openEditModal(<?php echo (int)$item['id']; ?>, <?php echo htmlspecialchars(json_encode($item['name'])); ?>, 'asset', <?php echo htmlspecialchars(json_encode($item['category'])); ?>, <?php echo (float)$item['amount']; ?>, <?php echo htmlspecialchars(json_encode($item['notes'] ?? '')); ?>)"
                                        data-bs-toggle="modal" data-bs-target="#itemModal">
                                        <i class="fa-solid fa-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger border-0"
                                        onclick="openDeleteConfirm(<?php echo (int)$item['id']; ?>, <?php echo htmlspecialchars(json_encode($item['name'])); ?>)"
                                        data-bs-toggle="modal" data-bs-target="#deleteModal">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-success">
                                <td colspan="2" class="fw-bold">Manual Assets Total</td>
                                <td class="text-end fw-bold blur-sensitive">AED <?php echo number_format($manual_assets_total, 2); ?></td>
                                <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?><td></td><?php endif; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Liabilities Panel ───────────────────────────────────────────────────── -->
<div class="glass-panel p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0"><i class="fa-solid fa-credit-card me-2 text-danger"></i>Liabilities</h5>
        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
        <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#itemModal"
            onclick="openAddModal('liability')">
            <i class="fa-solid fa-plus me-1"></i> Add Liability
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($manual_liabilities)): ?>
        <div class="text-center py-4 text-muted">
            <i class="fa-solid fa-circle-check fa-2x opacity-25 mb-2 text-success"></i>
            <p class="small mb-0">No liabilities recorded. Great job!</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Category</th>
                        <th class="text-end">Amount</th>
                        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                        <th class="text-end">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($manual_liabilities as $item): ?>
                    <tr>
                        <td>
                            <span class="fw-semibold"><?php echo htmlspecialchars($item['name']); ?></span>
                            <?php if (!empty($item['notes'])): ?>
                            <div class="small text-muted fst-italic"><?php echo htmlspecialchars($item['notes']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-danger-subtle text-danger"><?php echo htmlspecialchars($item['category']); ?></span></td>
                        <td class="text-end fw-bold text-danger blur-sensitive">AED <?php echo number_format($item['amount'], 2); ?></td>
                        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary border-0"
                                onclick="openEditModal(<?php echo (int)$item['id']; ?>, <?php echo htmlspecialchars(json_encode($item['name'])); ?>, 'liability', <?php echo htmlspecialchars(json_encode($item['category'])); ?>, <?php echo (float)$item['amount']; ?>, <?php echo htmlspecialchars(json_encode($item['notes'] ?? '')); ?>)"
                                data-bs-toggle="modal" data-bs-target="#itemModal">
                                <i class="fa-solid fa-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger border-0"
                                onclick="openDeleteConfirm(<?php echo (int)$item['id']; ?>, <?php echo htmlspecialchars(json_encode($item['name'])); ?>)"
                                data-bs-toggle="modal" data-bs-target="#deleteModal">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-danger">
                        <td colspan="2" class="fw-bold">Total Liabilities</td>
                        <td class="text-end fw-bold blur-sensitive">AED <?php echo number_format($total_liabilities, 2); ?></td>
                        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?><td></td><?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ── Net Worth Trend Chart ───────────────────────────────────────────────── -->
<?php if (count($snapshots) >= 2): ?>
<div class="glass-panel p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0"><i class="fa-solid fa-chart-line me-2 text-primary"></i>Net Worth Trend</h5>
        <span class="badge bg-primary-subtle text-primary"><?php echo count($snapshots); ?> snapshots</span>
    </div>
    <div style="position:relative; height:280px;">
        <canvas id="nwTrendChart"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>"></script>
<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
(function() {
    const labels = <?php echo json_encode($snap_labels, JSON_UNESCAPED_UNICODE); ?>;
    const values = <?php echo json_encode($snap_values); ?>;

    const ctx = document.getElementById('nwTrendChart').getContext('2d');

    const gradient = ctx.createLinearGradient(0, 0, 0, 280);
    gradient.addColorStop(0, 'rgba(13, 110, 253, 0.3)');
    gradient.addColorStop(1, 'rgba(13, 110, 253, 0.02)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Net Worth (AED)',
                data: values,
                borderColor: 'rgba(13, 110, 253, 1)',
                backgroundColor: gradient,
                borderWidth: 2.5,
                pointBackgroundColor: 'rgba(13, 110, 253, 1)',
                pointRadius: 5,
                pointHoverRadius: 7,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return ' AED ' + ctx.parsed.y.toLocaleString('en-AE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: function(value) {
                            return 'AED ' + value.toLocaleString('en-AE', {minimumFractionDigits: 0, maximumFractionDigits: 0});
                        }
                    },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<!-- ── Add / Edit Item Modal ───────────────────────────────────────────────── -->
<div class="modal fade" id="itemModal" tabindex="-1" aria-labelledby="itemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="itemModalLabel">Add Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="itemForm">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" id="formAction" value="add_item">
                    <input type="hidden" name="id" id="formId" value="">

                    <div class="mb-3">
                        <label for="formName" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="formName" class="form-control"
                            placeholder="e.g. Home, Car Loan, HSBC Savings..." required>
                    </div>

                    <div class="mb-3">
                        <label for="formType" class="form-label">Type <span class="text-danger">*</span></label>
                        <select name="type" id="formType" class="form-select" onchange="updateCategoryOptions(this.value)">
                            <option value="asset">Asset</option>
                            <option value="liability">Liability</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="formCategory" class="form-label">Category</label>
                        <select name="category" id="formCategory" class="form-select">
                            <!-- Populated by JS -->
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="formAmount" class="form-label">Amount (AED) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">AED</span>
                            <input type="number" step="0.01" min="0" name="amount" id="formAmount"
                                class="form-control" placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="formNotes" class="form-label">Notes <span class="text-muted small">(optional)</span></label>
                        <input type="text" name="notes" id="formNotes" class="form-control"
                            placeholder="Any additional details..." maxlength="255">
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-bold" id="formSubmitBtn">Save Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── Delete Confirm Modal ────────────────────────────────────────────────── -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true" style="z-index:1060;">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-panel border-0">
            <div class="modal-body text-center p-4">
                <div class="mb-3 text-danger opacity-75">
                    <i class="fa-solid fa-trash-can fa-3x"></i>
                </div>
                <h5 class="fw-bold mb-2">Delete Item?</h5>
                <p class="text-muted small mb-4">Delete <strong id="deleteItemName"></strong>? This cannot be undone.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="delete_item">
                    <input type="hidden" name="id" id="deleteItemId">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-danger fw-bold">Yes, Delete</button>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php Layout::footer(); ?>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
const ASSET_CATEGORIES      = ['Bank Account','Savings Goal','Investment','Property','Vehicle','Cash','Other'];
const LIABILITY_CATEGORIES  = ['Credit Card','Loan','Mortgage','Other'];

function updateCategoryOptions(type, selected) {
    const sel = document.getElementById('formCategory');
    const cats = type === 'liability' ? LIABILITY_CATEGORIES : ASSET_CATEGORIES;
    sel.innerHTML = '';
    cats.forEach(function(cat) {
        const opt = document.createElement('option');
        opt.value = cat;
        opt.textContent = cat;
        if (selected && cat === selected) opt.selected = true;
        sel.appendChild(opt);
    });
}

function openAddModal(type) {
    document.getElementById('itemModalLabel').textContent = type === 'asset' ? 'Add Asset' : 'Add Liability';
    document.getElementById('formAction').value = 'add_item';
    document.getElementById('formId').value = '';
    document.getElementById('formName').value = '';
    document.getElementById('formAmount').value = '';
    document.getElementById('formNotes').value = '';
    document.getElementById('formType').value = type;
    document.getElementById('formSubmitBtn').textContent = 'Save Item';
    updateCategoryOptions(type);
}

function openEditModal(id, name, type, category, amount, notes) {
    document.getElementById('itemModalLabel').textContent = type === 'asset' ? 'Edit Asset' : 'Edit Liability';
    document.getElementById('formAction').value = 'update_item';
    document.getElementById('formId').value = id;
    document.getElementById('formName').value = name;
    document.getElementById('formType').value = type;
    document.getElementById('formAmount').value = amount;
    document.getElementById('formNotes').value = notes;
    document.getElementById('formSubmitBtn').textContent = 'Update Item';
    updateCategoryOptions(type, category);
}

function openDeleteConfirm(id, name) {
    document.getElementById('deleteItemId').value = id;
    document.getElementById('deleteItemName').textContent = name;
}

// Initialise category dropdown on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCategoryOptions('asset');
    document.getElementById('formType').addEventListener('change', function() {
        updateCategoryOptions(this.value);
    });
});
</script>
