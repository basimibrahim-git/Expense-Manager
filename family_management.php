<?php
// family_management.php
$current_page = 'family_management.php';
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'family_admin') {
    header("Location: dashboard.php");
    exit();
}

$tenant_id = $_SESSION['tenant_id'];
$error = "";
$success = "";

// Handle User Creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'add_member') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $name = trim($_POST['name']);
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'];
    $permission = $_POST['permission'] ?? 'read_only';

    if (empty($name) || !$email || empty($password)) {
        $error = "All fields are required.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $error = "User with this email already exists.";
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, tenant_id, permission) VALUES (?, ?, ?, 'user', ?, ?)");
                $stmt->execute([$name, $email, $hashed, $tenant_id, $permission]);

                // create basic prefs
                $new_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO user_preferences (user_id) VALUES (?)")->execute([$new_id]);

                $success = "Member added successfully!";
                log_audit('add_family_member', "Added Member: $email (Permission: $permission)");
            }
        } catch (Exception $e) {
            $error = "Failed to add member: " . $e->getMessage();
        }
    }
}

// Fetch Family Members
$stmt = $pdo->prepare("SELECT id, name, email, role, permission, created_at FROM users WHERE tenant_id = ? ORDER BY role DESC, name ASC");
$stmt->execute([$tenant_id]);
$members = $stmt->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="row mb-4">
    <div class="col">
        <h2 class="fw-bold mb-0"><i class="fa-solid fa-users-gear text-primary me-2"></i>Family Management</h2>
        <p class="text-muted">Manage family members and their access levels</p>
    </div>
    <div class="col-auto">
        <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal"
            data-bs-target="#addMemberModal">
            <i class="fa-solid fa-user-plus me-2"></i> Add Member
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm border-0">
        <?php echo $error; ?>
    </div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success shadow-sm border-0">
        <?php echo $success; ?>
    </div>
<?php endif; ?>

<div class="glass-card shadow-sm border-0 rounded-4 overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th class="ps-4">Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Permission</th>
                    <th class="text-end pe-4">Added On</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($members as $member): ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold">
                                <?php echo htmlspecialchars($member['name']); ?>
                            </div>
                            <?php if ($member['id'] == $_SESSION['user_id']): ?>
                                <span class="badge bg-info-subtle text-info small">You</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted font-monospace small">
                            <?php echo htmlspecialchars($member['email']); ?>
                        </td>
                        <td>
                            <span
                                class="badge <?php echo $member['role'] == 'family_admin' ? 'bg-primary' : 'bg-secondary'; ?> rounded-pill">
                                <?php echo ucwords(str_replace('_', ' ', $member['role'])); ?>
                            </span>
                        </td>
                        <td>
                            <span
                                class="badge <?php echo $member['permission'] == 'edit' ? 'bg-success' : 'bg-warning text-dark'; ?> rounded-pill">
                                <i
                                    class="fa-solid <?php echo $member['permission'] == 'edit' ? 'fa-pen-to-square' : 'fa-eye'; ?> me-1"></i>
                                <?php echo ucwords($member['permission']); ?>
                            </span>
                        </td>
                        <td class="text-end pe-4 text-muted small">
                            <?php echo date('d M Y', strtotime($member['created_at'])); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Member Modal -->
<div class="modal fade" id="addMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Add Family Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="add_member">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Sara Ibrahim">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" required placeholder="name@example.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                        <div class="form-text small">Give them a temporary password they can change later.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Access Permission</label>
                        <select name="permission" class="form-select">
                            <option value="read_only" selected>Read-Only (View charts and logs)</option>
                            <option value="edit">Edit Access (Add expenses/income)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4"
                        data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Add Member</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>