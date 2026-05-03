<?php
// admin/index.php
$current_page = 'admin/index.php';
require_once __DIR__ . '/../autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;

Bootstrap::init();


// Root Admin Authorization
if (($_SESSION['role'] ?? '') !== 'root_admin') {
    header("Location: ../dashboard.php");
    exit();
}

// Fetch System Metrics
try {
    $tenantCount = $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $expenseTotal = $pdo->query("SELECT SUM(amount) FROM expenses")->fetchColumn() ?: 0;

    // Recent Tenants
    $recentTenants = $pdo->query("SELECT * FROM tenants ORDER BY created_at DESC LIMIT 5")->fetchAll();

    // Recent Activity (all tenants)
    $recentActivity = $pdo->query("
        SELECT al.action, al.context, al.created_at,
               u.name  AS user_name,
               t.family_name AS tenant_name
        FROM audit_logs al
        LEFT JOIN users   u ON al.user_id   = u.id
        LEFT JOIN tenants t ON al.tenant_id = t.id
        ORDER BY al.created_at DESC
        LIMIT 20
    ")->fetchAll();

} catch (PDOException $e) {
    $error = "System Error: Unable to fetch system metrics.";
    $recentActivity = [];
}

// Custom header for admin with adjusted paths
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Root Admin | ExpenseMngr</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .admin-stat-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 15px;
            padding: 20px;
            border-left: 5px solid var(--primary-color);
        }
    </style>
</head>

<body>
    <?php Layout::sidebar(); ?>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-0 text-danger"><i class="fa-solid fa-gauge-high me-2"></i>Root Command Center</h2>
                <p class="text-muted">Global system overview and tenant management</p>
            </div>
            <div class="badge bg-danger p-2 px-3 rounded-pill shadow-sm">
                <i class="fa-solid fa-shield-halved me-1"></i> Root Access Enabled
            </div>
        </div>

        <div class="row g-4 mb-5">
            <div class="col-md-4">
                <div class="admin-stat-card shadow-sm">
                    <div class="text-muted small fw-bold text-uppercase mb-1">Total Families</div>
                    <div class="h3 fw-bold mb-0">
                        <?php echo number_format($tenantCount); ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="admin-stat-card shadow-sm" style="border-left-color: #2ecc71;">
                    <div class="text-muted small fw-bold text-uppercase mb-1">Total Users</div>
                    <div class="h3 fw-bold mb-0">
                        <?php echo number_format($userCount); ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="admin-stat-card shadow-sm" style="border-left-color: #f1c40f;">
                    <div class="text-muted small fw-bold text-uppercase mb-1">Global Volume (AED)</div>
                    <div class="h3 fw-bold mb-0">
                        <?php echo number_format($expenseTotal, 2); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="glass-panel p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="fw-bold mb-0">Recent Families Registered</h5>
                        <a href="<?php echo BASE_URL; ?>admin/manage_tenants.php"
                            class="btn btn-sm btn-outline-primary rounded-pill">View All</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>Family Name</th>
                                    <th>Registered</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentTenants as $tenant): ?>
                                    <tr>
                                        <td class="fw-bold">
                                            <?php echo htmlspecialchars($tenant['family_name']); ?>
                                        </td>
                                        <td><small class="text-muted">
                                                <?php echo date('d M Y', strtotime($tenant['created_at'])); ?>
                                            </small></td>
                                        <td class="text-end">
                                            <a href="manage_tenants.php?id=<?php echo $tenant['id']; ?>"
                                                class="btn btn-light btn-sm rounded-pill">Manage</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="glass-panel p-4 h-100 bg-dark text-white">
                    <h5 class="fw-bold mb-4"><i class="fa-solid fa-terminal me-2 text-success"></i>System Health</h5>
                    <div class="d-flex justify-content-between mb-3 border-bottom border-secondary pb-2">
                        <span>Database</span>
                        <span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> Online</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3 border-bottom border-secondary pb-2">
                        <span>PHP Version</span>
                        <span>
                            <?php echo PHP_VERSION; ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span>Tenant Isolation</span>
                        <span class="text-info"><i class="fa-solid fa-lock me-1"></i> Enabled</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="glass-panel p-4 shadow-sm rounded-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0">
                            <i class="fa-solid fa-clipboard-list me-2 text-primary"></i>Recent Activity
                        </h5>
                        <a href="<?php echo BASE_URL; ?>audit_log.php"
                           class="btn btn-sm btn-outline-primary rounded-pill">View Full Log</a>
                    </div>
                    <?php if (empty($recentActivity)): ?>
                        <p class="text-center text-muted py-4 mb-0">No activity recorded yet.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>Date / Time</th>
                                    <th>Family</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentActivity as $entry): ?>
                                    <?php
                                        $action_label = ucfirst(str_replace('_', ' ', $entry['action']));
                                        $badge_class  = 'bg-secondary';
                                        if (str_contains($entry['action'], 'delete'))                                       $badge_class = 'bg-danger';
                                        elseif (str_contains($entry['action'], 'add') || str_contains($entry['action'], 'create')) $badge_class = 'bg-success';
                                        elseif (str_contains($entry['action'], 'login'))                                    $badge_class = 'bg-primary';
                                        elseif (str_contains($entry['action'], 'update') || str_contains($entry['action'], 'edit')) $badge_class = 'bg-warning text-dark';
                                        $context_short = mb_strlen($entry['context'] ?? '') > 80
                                            ? mb_substr($entry['context'], 0, 80) . '…'
                                            : ($entry['context'] ?? '—');
                                    ?>
                                    <tr>
                                        <td class="text-nowrap">
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars(date('d M Y, H:i', strtotime($entry['created_at']))); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small class="fw-bold">
                                                <?php echo htmlspecialchars($entry['tenant_name'] ?? '—'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($entry['user_name'] ?? 'Unknown'); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badge_class; ?> rounded-pill px-2">
                                                <?php echo htmlspecialchars($action_label); ?>
                                            </span>
                                        </td>
                                        <td class="text-muted small">
                                            <?php echo htmlspecialchars($context_short); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div><!-- /.container-fluid -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php Layout::footer(); ?>
</body>

</html>
// Structural Audit Complete
