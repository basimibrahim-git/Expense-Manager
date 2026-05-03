<?php
$current_page = 'audit_log.php';
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;
use App\Helpers\SecurityHelper;

Bootstrap::init();

$user_id   = $_SESSION['user_id'];
$tenant_id = $_SESSION['tenant_id'];
$role      = $_SESSION['role'] ?? 'member';
$is_admin  = in_array($role, ['admin', 'family_admin', 'root_admin']);

// ── Filters ────────────────────────────────────────────────────────────────
$filter_action  = trim($_GET['action_filter'] ?? '');
$filter_from    = trim($_GET['date_from'] ?? '');
$filter_to      = trim($_GET['date_to'] ?? '');
$filter_keyword = trim($_GET['keyword'] ?? '');
$export_csv     = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['export'] ?? '') === 'csv';
$page           = max(1, intval($_GET['page'] ?? 1));
$per_page       = 25;
$offset         = ($page - 1) * $per_page;

// ── Build WHERE clause ─────────────────────────────────────────────────────
$where  = ['al.tenant_id = ?'];
$params = [$tenant_id];

if (!$is_admin) {
    $where[]  = 'al.user_id = ?';
    $params[] = $user_id;
}

if ($filter_action !== '') {
    $where[]  = 'al.action = ?';
    $params[] = $filter_action;
}

if ($filter_from !== '') {
    $where[]  = 'DATE(al.created_at) >= ?';
    $params[] = $filter_from;
}

if ($filter_to !== '') {
    $where[]  = 'DATE(al.created_at) <= ?';
    $params[] = $filter_to;
}

if ($filter_keyword !== '') {
    $where[]  = 'al.context LIKE ?';
    $params[] = '%' . $filter_keyword . '%';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// ── Distinct action values for dropdown ───────────────────────────────────
$action_stmt = $pdo->prepare(
    "SELECT DISTINCT action FROM audit_logs al $where_sql ORDER BY action ASC"
);
// Use only the tenant/user params (no filters) for the dropdown
$dropdown_params = [$tenant_id];
if (!$is_admin) {
    $dropdown_params[] = $user_id;
}
$action_stmt_plain = $pdo->prepare(
    "SELECT DISTINCT action FROM audit_logs al WHERE al.tenant_id = ?" .
    (!$is_admin ? " AND al.user_id = ?" : "") .
    " ORDER BY action ASC"
);
$action_stmt_plain->execute($dropdown_params);
$distinct_actions = $action_stmt_plain->fetchAll(PDO::FETCH_COLUMN);

// ── CSV Export ─────────────────────────────────────────────────────────────
if ($export_csv) {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $csv_sql = "SELECT al.created_at, al.action, al.context, u.name AS user_name
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                $where_sql
                ORDER BY al.created_at DESC";
    $csv_stmt = $pdo->prepare($csv_sql);
    $csv_stmt->execute($params);
    $rows = $csv_stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="activity_log_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date/Time', 'Action', 'Details', 'User']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['created_at'],
            ucfirst(str_replace('_', ' ', $row['action'])),
            $row['context'],
            $row['user_name'] ?? 'Unknown',
        ]);
    }
    fclose($out);
    exit;
}

// ── Total count for pagination ─────────────────────────────────────────────
$count_sql  = "SELECT COUNT(*) FROM audit_logs al $where_sql";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_rows  = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_rows / $per_page));

// ── Main data query ────────────────────────────────────────────────────────
$data_sql = "SELECT al.*, u.name AS user_name
             FROM audit_logs al
             LEFT JOIN users u ON al.user_id = u.id
             $where_sql
             ORDER BY al.created_at DESC
             LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$data_stmt = $pdo->prepare($data_sql);
$data_stmt->execute($params);
$log_entries = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Helper: relative time ──────────────────────────────────────────────────
function relative_time(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)       return 'just now';
    if ($diff < 3600)     return floor($diff / 60) . ' min ago';
    if ($diff < 86400)    return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800)   return floor($diff / 86400) . ' days ago';
    return date('d M Y', strtotime($datetime));
}

// ── CSRF token for export link ─────────────────────────────────────────────
$csrf = SecurityHelper::generateCsrfToken();

// ── Build query string helper (preserves filters) ─────────────────────────
function build_qs(array $overrides = []): string {
    $base = [
        'action_filter' => $_GET['action_filter'] ?? '',
        'date_from'     => $_GET['date_from'] ?? '',
        'date_to'       => $_GET['date_to'] ?? '',
        'keyword'       => $_GET['keyword'] ?? '',
        'page'          => $_GET['page'] ?? 1,
    ];
    $merged = array_merge($base, $overrides);
    return http_build_query(array_filter($merged, fn($v) => $v !== '' && $v !== null));
}

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="fw-bold mb-0">
                <i class="fa-solid fa-clipboard-list me-2 text-primary"></i>Activity Log
            </h2>
            <p class="text-muted mb-0">
                <?php echo $is_admin ? 'All tenant activity' : 'Your personal activity history'; ?>
            </p>
        </div>
        <form method="POST" action="audit_log.php?<?php echo build_qs(['page' => '']); ?>" class="d-inline">
            <input type="hidden" name="export" value="csv">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <button type="submit" class="btn btn-outline-success btn-sm rounded-pill shadow-sm">
                <i class="fa-solid fa-file-csv me-1"></i> Export CSV
            </button>
        </form>
    </div>

    <!-- Filters -->
    <div class="glass-panel p-3 mb-4 shadow-sm rounded-4">
        <form method="GET" action="" class="row g-2 align-items-end">
            <!-- Action filter -->
            <div class="col-12 col-md-3">
                <label class="form-label small text-muted fw-bold text-uppercase mb-1">Action Type</label>
                <select name="action_filter" class="form-select form-select-sm">
                    <option value="">All Actions</option>
                    <?php foreach ($distinct_actions as $act): ?>
                        <option value="<?php echo htmlspecialchars($act); ?>"
                            <?php echo $filter_action === $act ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $act))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Date from -->
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted fw-bold text-uppercase mb-1">From Date</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($filter_from); ?>">
            </div>
            <!-- Date to -->
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted fw-bold text-uppercase mb-1">To Date</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($filter_to); ?>">
            </div>
            <!-- Keyword -->
            <div class="col-12 col-md-3">
                <label class="form-label small text-muted fw-bold text-uppercase mb-1">Search Details</label>
                <input type="text" name="keyword" class="form-control form-control-sm"
                       placeholder="Keyword in details..."
                       value="<?php echo htmlspecialchars($filter_keyword); ?>">
            </div>
            <!-- Buttons -->
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                    <i class="fa-solid fa-filter me-1"></i> Filter
                </button>
                <a href="audit_log.php" class="btn btn-outline-secondary btn-sm flex-shrink-0" title="Reset">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Results count -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <small class="text-muted">
            Showing <?php echo number_format(min($offset + 1, $total_rows)); ?>–<?php echo number_format(min($offset + $per_page, $total_rows)); ?>
            of <?php echo number_format($total_rows); ?> entries
        </small>
    </div>

    <!-- Table -->
    <div class="glass-panel p-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4 py-3">Date / Time</th>
                        <th class="py-3">Action</th>
                        <th class="py-3">Details</th>
                        <?php if ($is_admin): ?>
                        <th class="py-3">User</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($log_entries)): ?>
                        <tr>
                            <td colspan="<?php echo $is_admin ? 4 : 3; ?>" class="text-center text-muted py-5">
                                <i class="fa-solid fa-inbox fa-2x mb-2 d-block opacity-25"></i>
                                No activity records found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($log_entries as $entry): ?>
                            <tr>
                                <td class="ps-4 text-nowrap">
                                    <div class="fw-bold small">
                                        <?php echo htmlspecialchars(date('d M Y, H:i', strtotime($entry['created_at']))); ?>
                                    </div>
                                    <div class="text-muted" style="font-size:0.75rem;">
                                        <?php echo relative_time($entry['created_at']); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                        $action_label = ucfirst(str_replace('_', ' ', $entry['action']));
                                        $badge_class  = 'bg-secondary';
                                        if (str_contains($entry['action'], 'delete'))  $badge_class = 'bg-danger';
                                        elseif (str_contains($entry['action'], 'add') || str_contains($entry['action'], 'create')) $badge_class = 'bg-success';
                                        elseif (str_contains($entry['action'], 'login'))  $badge_class = 'bg-primary';
                                        elseif (str_contains($entry['action'], 'update') || str_contains($entry['action'], 'edit')) $badge_class = 'bg-warning text-dark';
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?> rounded-pill px-2">
                                        <?php echo htmlspecialchars($action_label); ?>
                                    </span>
                                </td>
                                <td class="text-muted small" style="max-width:400px; word-break:break-word;">
                                    <?php echo htmlspecialchars($entry['context'] ?? '—'); ?>
                                </td>
                                <?php if ($is_admin): ?>
                                <td class="small">
                                    <i class="fa-solid fa-user me-1 text-muted"></i>
                                    <?php echo htmlspecialchars($entry['user_name'] ?? 'Unknown'); ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Activity log pagination">
        <ul class="pagination pagination-sm justify-content-center flex-wrap gap-1">
            <!-- Previous -->
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link rounded-pill" href="?<?php echo build_qs(['page' => $page - 1]); ?>">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
            </li>

            <?php
            $start = max(1, $page - 2);
            $end   = min($total_pages, $page + 2);
            if ($start > 1): ?>
                <li class="page-item">
                    <a class="page-link rounded-pill" href="?<?php echo build_qs(['page' => 1]); ?>">1</a>
                </li>
                <?php if ($start > 2): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $start; $p <= $end; $p++): ?>
                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                    <a class="page-link rounded-pill" href="?<?php echo build_qs(['page' => $p]); ?>"><?php echo $p; ?></a>
                </li>
            <?php endfor; ?>

            <?php if ($end < $total_pages): ?>
                <?php if ($end < $total_pages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
                <li class="page-item">
                    <a class="page-link rounded-pill" href="?<?php echo build_qs(['page' => $total_pages]); ?>">
                        <?php echo $total_pages; ?>
                    </a>
                </li>
            <?php endif; ?>

            <!-- Next -->
            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                <a class="page-link rounded-pill" href="?<?php echo build_qs(['page' => $page + 1]); ?>">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>

</div>

<?php Layout::footer(); ?>
