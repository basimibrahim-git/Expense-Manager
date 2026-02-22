<?php
// admin/manage_tenants.php
$current_page = 'admin/manage_tenants.php';
require_once __DIR__ . '/../vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;

Bootstrap::init();

// Root Admin Authorization
if (($_SESSION['role'] ?? '') !== 'root_admin') {
    header("Location: ../dashboard.php");
    exit();
}

$error = "";
$success = "";

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'rename_tenant') {
        $tenantId = intval($_POST['tenant_id']);
        $newName = trim($_POST['family_name']);

        if ($tenantId > 0 && !empty($newName)) {
            try {
                $stmt = $pdo->prepare("UPDATE tenants SET family_name = ? WHERE id = ?");
                $stmt->execute([$newName, $tenantId]);
                $success = "Family name updated successfully!";
                AuditHelper::log($pdo, 'rename_tenant', "Renamed Tenant ID $tenantId to $newName");
            } catch (PDOException $e) {
                error_log("Tenant rename failed: " . $e->getMessage());
                $error = "Update failed: A system error occurred.";
            }
        }
    } elseif ($_POST['action'] === 'add_member') {
        $tenantId = intval($_POST['tenant_id']);
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $pass = $_POST['password'];
        $role = $_POST['role'] ?? 'user';

        if ($tenantId > 0 && !empty($name) && !empty($email) && !empty($pass)) {
            try {
                $hashed = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (tenant_id, name, email, password, role, permission) VALUES (?, ?, ?, ?, ?, 'edit')");
                $stmt->execute([$tenantId, $name, $email, $hashed, $role]);
                $success = "User '$name' added to the family!";
                AuditHelper::log($pdo, 'add_member_admin', "Added User $email to Tenant ID $tenantId");

                // Set session message and redirect to prevent resubmission + clear URL
                $_SESSION['success_msg'] = $success;
                header("Location: manage_tenants.php");
                exit();
            } catch (PDOException $e) {
                error_log("Add user admin failed: " . $e->getMessage());
                $error = "Failed to add user: A system error occurred.";
            }
        }
    }
}

// Handle Rename Success Redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rename_tenant' && $success) {
    $_SESSION['success_msg'] = $success;
    header("Location: manage_tenants.php");
    exit();
}

// Read and Clear Session Messages
if (isset($_SESSION['success_msg'])) {
    $success = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}
if (isset($_SESSION['error_msg'])) {
    $error = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// Fetch users for specific tenant if requested
$memberList = [];
$viewingTenantName = "";
if (isset($_GET['view_members'])) {
    $vId = intval($_GET['view_members']);
    $stmt = $pdo->prepare("SELECT name, email, role, permission FROM users WHERE tenant_id = ?");
    $stmt->execute([$vId]);
    $memberList = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT family_name FROM tenants WHERE id = ?");
    $stmt->execute([$vId]);
    $viewingTenantName = $stmt->fetchColumn();
}
try {
    $stmt = $pdo->query("
        SELECT t.*,
               (SELECT COUNT(*) FROM users WHERE tenant_id = t.id) as user_count,
               (SELECT name FROM users WHERE tenant_id = t.id AND role = 'family_admin' LIMIT 1) as admin_name
        FROM tenants t
        ORDER BY t.family_name ASC
    ");
    $tenants = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Failed to load tenants: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tenants | ExpenseMngr</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
    <?php Layout::sidebar(); ?>

    <div class="container-fluid py-4">
        <div class="mb-4">
            <h2 class="fw-bold mb-0 text-danger"><i class="fa-solid fa-people-roof me-2"></i>Tenant Management</h2>
            <p class="text-muted">Overview of all family accounts in the system</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger shadow-sm border-0 rounded-pill px-4 animate__animated animate__shakeX">
                <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success shadow-sm border-0 rounded-pill px-4 animate__animated animate__fadeIn">
                <i class="fa-solid fa-circle-check me-2"></i><?php echo $success; ?>
            </div>
        <?php endif; ?>

        <div class="glass-panel p-4 shadow-sm border-0 rounded-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Family Name</th>
                            <th>Family Admin</th>
                            <th>Members</th>
                            <th>Created On</th>
                            <th class="text-end pe-4">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tenants as $tenant): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="fw-bold">
                                        <?php echo htmlspecialchars($tenant['family_name']); ?>
                                    </span>
                                    <small class="text-muted d-block">ID: #
                                        <?php echo $tenant['id']; ?>
                                    </small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($tenant['admin_name'] ?? 'Unassigned'); ?>
                                </td>
                                <td>
                                    <a href="?view_members=<?php echo $tenant['id']; ?>" class="text-decoration-none">
                                        <span class="badge bg-primary rounded-pill cursor-pointer hover-shadow">
                                            <?php echo $tenant['user_count']; ?> Users
                                        </span>
                                    </a>
                                </td>
                                <td>
                                    <small class="text-secondary">
                                        <?php echo date('d M Y', strtotime($tenant['created_at'])); ?>
                                    </small>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end align-items-center gap-2">
                                        <button class="btn btn-sm btn-outline-success border-0" title="Add Member"
                                            onclick="openAddMemberModal(<?php echo $tenant['id']; ?>, '<?php echo addslashes(htmlspecialchars($tenant['family_name'])); ?>')">
                                            <i class="fa-solid fa-user-plus"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary border-0" title="Rename Family"
                                            onclick="openEditModal(<?php echo $tenant['id']; ?>, '<?php echo addslashes(htmlspecialchars($tenant['family_name'])); ?>')">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <span class="badge bg-success">Active</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editTenantModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-panel border-0">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Rename Family Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="rename_tenant">
                        <input type="hidden" name="tenant_id" id="editTenantId">
                        <div class="mb-3">
                            <label for="editFamilyName"
                                class="form-label small text-muted text-uppercase fw-bold">Family / Tenant
                                Name</label>
                            <input type="text" name="family_name" id="editFamilyName" class="form-control" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-danger fw-bold py-2">Update Name</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Member Modal -->
    <div class="modal fade" id="addMemberModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-panel border-0">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Add Member to <span id="addMemberFamilyName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_member">
                        <input type="hidden" name="tenant_id" id="addMemberTenantId">
                        <div class="mb-3">
                            <label for="addMemberName" class="form-label small text-muted text-uppercase fw-bold">Full
                                Name</label>
                            <input type="text" name="name" id="addMemberName" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="addMemberEmail" class="form-label small text-muted text-uppercase fw-bold">Email
                                Address</label>
                            <input type="email" name="email" id="addMemberEmail" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="addMemberPassword"
                                class="form-label small text-muted text-uppercase fw-bold">Password</label>
                            <input type="password" name="password" id="addMemberPassword" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="addMemberRole"
                                class="form-label small text-muted text-uppercase fw-bold">Role</label>
                            <select name="role" id="addMemberRole" class="form-select">
                                <option value="user">Standard User</option>
                                <option value="family_admin">Family Admin</option>
                            </select>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success fw-bold py-2">Create Account</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- View Members Modal -->
    <div class="modal fade" id="viewMembersModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-panel border-0">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Members of <?php echo htmlspecialchars($viewingTenantName); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($memberList)): ?>
                        <p class="text-center text-muted">No members found.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($memberList as $m): ?>
                                <div
                                    class="list-group-item bg-transparent border-0 px-0 d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($m['name']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($m['email']); ?></div>
                                    </div>
                                    <span
                                        class="badge <?php echo $m['role'] === 'family_admin' ? 'bg-warning' : 'bg-secondary'; ?> rounded-pill">
                                        <?php echo $m['role']; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openEditModal(id, name) {
            document.getElementById('editTenantId').value = id;
            document.getElementById('editFamilyName').value = name;
            new bootstrap.Modal(document.getElementById('editTenantModal')).show();
        }

        function openAddMemberModal(id, name) {
            document.getElementById('addMemberTenantId').value = id;
            document.getElementById('addMemberFamilyName').innerText = name;
            new bootstrap.Modal(document.getElementById('addMemberModal')).show();
        }

        // Auto-open members modal if directed
        <?php if (isset($_GET['view_members'])): ?>
            window.onload = function () {
                new bootstrap.Modal(document.getElementById('viewMembersModal')).show();
            }
        <?php endif; ?>
    </script>
    <?php Layout::footer(); ?>
</body>

</html>