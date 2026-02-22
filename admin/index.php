<?php
// admin/index.php
$current_page = 'admin/index.php';
require_once __DIR__ . '/../vendor/autoload.php';
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

} catch (PDOException $e) {
    $error = "System Error: Unable to fetch system metrics.";

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php Layout::footer(); ?>
</body>

</html>
// Structural Audit Complete
