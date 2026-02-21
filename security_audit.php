<?php
// security_audit.php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Pagination
$limit = 50;
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$offset = ($page - 1) * $limit;

// Filters
$action_filter = $_GET['action_type'] ?? '';

$where = "WHERE a.tenant_id = :tenant_id";
$params = ['tenant_id' => $_SESSION['tenant_id']];

if ($action_filter) {
    $where .= " AND a.action = :action";
    $params['action'] = $action_filter;
}

try {
    // Get total count for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a $where");
    $countStmt->execute($params);
    $total_records = $countStmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Get logs with User Names
    $stmt = $pdo->prepare("
        SELECT a.*, u.name as user_name 
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        $where 
        ORDER BY a.created_at DESC 
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // Pre-format dates for JavaScript modal to ensure consistency
    foreach ($logs as &$log) {
        $log['display_time'] = date('d/m/Y, h:i:s A', strtotime($log['created_at']));
    }
    unset($log); // Break reference

    // Get unique actions for filter dropdown
    $typeStmt = $pdo->prepare("SELECT DISTINCT action FROM audit_logs WHERE tenant_id = ? ORDER BY action ASC");
    $typeStmt->execute([$_SESSION['tenant_id']]);
    $action_types = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    $error = "Failed to fetch logs: " . $e->getMessage();
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h2 class="fw-bold mb-0"><i class="fa-solid fa-shield-halved text-primary me-2"></i>Security Audit Logs</h2>
            <p class="text-muted">Monitor system actions and security events</p>
        </div>
        <div class="col-auto">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-auto">
                    <select name="action_type" class="form-select" onchange="this.form.submit()">
                        <option value="">All Actions</option>
                        <?php foreach ($action_types as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $action_filter == $type ? 'selected' : ''; ?>>
                                <?php echo ucwords(str_replace('_', ' ', $type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($action_filter): ?>
                    <div class="col-auto">
                        <a href="security_audit.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger shadow-sm border-0">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="glass-card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4 py-3">Timestamp</th>
                        <th class="py-3">User</th>
                        <th class="py-3">Action</th>
                        <th class="py-3">Context / Description</th>
                        <th class="py-3">IP Address</th>
                        <th class="py-3 pe-4 text-end">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-ghost fa-3x mb-3 d-block opacity-10"></i>
                                No audit logs found matching your criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold">
                                        <?php echo date('d M Y', strtotime($log['created_at'])); ?>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo date('h:i A', strtotime($log['created_at'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <?php echo htmlspecialchars($log['user_name'] ?? 'System / Unknown'); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $badgeClass = 'bg-secondary';
                                    if (strpos($log['action'], 'delete') !== false)
                                        $badgeClass = 'bg-danger';
                                    if (strpos($log['action'], 'login') !== false)
                                        $badgeClass = 'bg-success';
                                    if (strpos($log['action'], 'update') !== false)
                                        $badgeClass = 'bg-warning text-dark';
                                    if (strpos($log['action'], 'add') !== false)
                                        $badgeClass = 'bg-primary';
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?> rounded-pill px-3">
                                        <?php echo ucwords(str_replace('_', ' ', $log['action'])); ?>
                                    </span>
                                </td>
                                <td class="text-truncate" style="max-width: 300px;">
                                    <?php echo htmlspecialchars($log['context']); ?>
                                </td>
                                <td class="text-muted small">
                                    <code><?php echo htmlspecialchars($log['ip_address']); ?></code>
                                </td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-light btn-sm rounded-pill px-3"
                                        onclick="viewAuditDetails(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                        View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                        <a class="page-link shadow-sm border-0 rounded-3 mx-1"
                            href="?page=<?php echo $i; ?>&action_type=<?php echo urlencode($action_filter); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<!-- Details Modal -->
<div class="modal fade" id="auditDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-circle-info me-2"></i>Audit Log Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-4">
                    <div class="col-md-4">
                        <label class="text-muted small fw-bold text-uppercase d-block mb-1">Performer</label>
                        <div id="modalUserName" class="h5 fw-bold text-primary"></div>
                    </div>
                    <div class="col-md-4">
                        <label class="text-muted small fw-bold text-uppercase d-block mb-1">Action Type</label>
                        <div id="modalAction" class="h5 fw-bold"></div>
                    </div>
                    <div class="col-md-4">
                        <label class="text-muted small fw-bold text-uppercase d-block mb-1">Occurrence</label>
                        <div id="modalTime" class="h5"></div>
                    </div>
                    <div class="col-12">
                        <label class="text-muted small fw-bold text-uppercase d-block mb-1">Context /
                            Description</label>
                        <div id="modalContext" class="p-3 bg-light rounded-3 font-monospace"
                            style="word-break: break-all;"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small fw-bold text-uppercase d-block mb-1">IP Address</label>
                        <div id="modalIP" class="h6"></div>
                    </div>
                    <div class="col-12">
                        <label class="text-muted small fw-bold text-uppercase d-block mb-1">User Agent</label>
                        <div id="modalUA" class="p-2 border rounded-3 small text-muted font-monospace"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close
                    Window</button>
            </div>
        </div>
    </div>
</div>

<script>
    function viewAuditDetails(log) {
        const modal = new bootstrap.Modal(document.getElementById('auditDetailModal'));

        document.getElementById('modalUserName').textContent = log.user_name || 'System / Unknown';
        document.getElementById('modalAction').textContent = log.action.replace(/_/g, ' ').toUpperCase();
        document.getElementById('modalTime').textContent = log.display_time;
        document.getElementById('modalContext').textContent = log.context;
        document.getElementById('modalIP').textContent = log.ip_address;
        document.getElementById('modalUA').textContent = log.user_agent;

        modal.show();
    }
</script>

<?php include 'includes/footer.php'; ?>