<?php
// family_management.php
$current_page = 'family_management.php';
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\AuditHelper;
use App\Helpers\Html;
use App\Helpers\Layout;
use App\Helpers\SecurityHelper;

Bootstrap::init();
SecurityHelper::requireRole(['family_admin', 'root_admin']);

$tenant_id = $_SESSION['tenant_id'];
$error = "";
$success = "";
$allowed_permissions = ['read_only', 'edit'];

/**
 * Load a member of this family that the current admin may manage (never themselves;
 * family admins may only manage regular members). Returns null otherwise.
 */
function findManageableMember(PDO $pdo, $tenant_id, $member_id): ?array
{
    if (!$member_id || $member_id == $_SESSION['user_id']) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$member_id, $tenant_id]);
    $member = $stmt->fetch();
    if (!$member) {
        return null;
    }
    if ($_SESSION['role'] !== 'root_admin' && $member['role'] !== 'user') {
        return null;
    }
    return $member;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action == 'add_member') {
        $name = trim($_POST['name'] ?? '');
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');
        $permission = in_array($_POST['permission'] ?? '', $allowed_permissions, true) ? $_POST['permission'] : 'read_only';

        if (empty($name) || !$email || $password === '') {
            $error = "All fields are required.";
        } elseif ($pwError = SecurityHelper::validatePassword($password)) {
            $error = $pwError;
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
                    AuditHelper::log($pdo, 'add_family_member', "Added Member: $email (Permission: $permission)");
                }
            } catch (Exception $e) {
                error_log("Add family member failed: " . $e->getMessage());
                $error = "Failed to add member: A system error occurred.";
            }
        }
    } elseif ($action == 'update_member') {
        $member = findManageableMember($pdo, $tenant_id, filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT));
        $name = trim($_POST['name'] ?? '');
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $permission = $_POST['permission'] ?? '';

        if (!$member) {
            $error = "You can't edit that member.";
        } elseif ($name === '' || mb_strlen($name) > 100 || !$email || !in_array($permission, $allowed_permissions, true)) {
            $error = "Name, a valid email and a permission are required.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
                $stmt->execute([$email, $member['id']]);
                if ($stmt->fetchColumn() > 0) {
                    $error = "Another account already uses that email.";
                } else {
                    $pdo->prepare("UPDATE users SET name = ?, email = ?, permission = ? WHERE id = ? AND tenant_id = ?")
                        ->execute([$name, $email, $permission, $member['id'], $tenant_id]);
                    $success = "Member updated.";
                    AuditHelper::log($pdo, 'update_family_member', "Updated Member #{$member['id']}: $email (Permission: $permission)");
                }
            } catch (Exception $e) {
                error_log("Update family member failed: " . $e->getMessage());
                $error = "Failed to update member: A system error occurred.";
            }
        }
    } elseif ($action == 'reset_member_password') {
        $member = findManageableMember($pdo, $tenant_id, filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT));
        $password = (string) ($_POST['password'] ?? '');

        if (!$member) {
            $error = "You can't reset that member's password.";
        } elseif ($pwError = SecurityHelper::validatePassword($password)) {
            $error = $pwError;
        } else {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND tenant_id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $member['id'], $tenant_id]);
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                ->execute([$member['id']]);
            $pdo->prepare("DELETE FROM login_attempts WHERE email = ? AND success = 0")->execute([$member['email']]);
            $success = "Password reset for {$member['name']}. Share the new temporary password with them; they can change it under My Profile.";
            AuditHelper::log($pdo, 'reset_member_password', "Reset password for member #{$member['id']} ({$member['email']})");
        }
    } elseif ($action == 'revoke_member') {
        // Members are never deleted: every table's user_id foreign key cascades, so deleting
        // a user would also delete all expenses, cards, banks etc. they entered. Revoking replaces
        // the password with a random one nobody knows; "Reset password" restores access.
        $member = findManageableMember($pdo, $tenant_id, filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT));
        if (!$member) {
            $error = "You can't revoke that member.";
        } else {
            $pdo->prepare("UPDATE users SET password = ?, permission = 'read_only' WHERE id = ? AND tenant_id = ?")
                ->execute([password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $member['id'], $tenant_id]);
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                ->execute([$member['id']]);
            $success = "Access revoked for {$member['name']}. Their records are kept; use Reset Password to let them back in.";
            AuditHelper::log($pdo, 'revoke_family_member', "Revoked access for member #{$member['id']} ({$member['email']})");
        }
    }
}

// Fetch Family Members
$stmt = $pdo->prepare("SELECT id, name, email, role, permission, created_at FROM users WHERE tenant_id = ? ORDER BY role DESC, name ASC");
$stmt->execute([$tenant_id]);
$members = $stmt->fetchAll();

Layout::header();
Layout::sidebar();
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
        <?php echo Html::e($error); ?>
    </div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success shadow-sm border-0">
        <?php echo Html::e($success); ?>
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
                    <th>Added On</th>
                    <th class="text-end pe-4">Actions</th>
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
                                <?php echo Html::e(ucwords(str_replace('_', ' ', $member['permission']))); ?>
                            </span>
                        </td>
                        <td class="text-muted small">
                            <?php echo date('d M Y', strtotime($member['created_at'])); ?>
                        </td>
                        <td class="text-end pe-4">
                            <?php
                            $can_manage = $member['id'] != $_SESSION['user_id']
                                && ($_SESSION['role'] === 'root_admin' || $member['role'] === 'user');
                            ?>
                            <?php if ($can_manage): ?>
                                <div class="d-inline-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary" title="Edit member"
                                        data-onclick="openEditMember"
                                        data-args="<?php echo Html::args((int) $member['id'], $member['name'], $member['email'], $member['permission']); ?>">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-warning" title="Reset password"
                                        data-onclick="openResetMemberPassword"
                                        data-args="<?php echo Html::args((int) $member['id'], $member['name']); ?>">
                                        <i class="fa-solid fa-key"></i>
                                    </button>
                                    <form method="POST" class="d-inline"
                                        data-confirm="<?php echo Html::e('Revoke access for ' . $member['name'] . '? They will no longer be able to sign in. Their records are kept.'); ?>"
                                        data-confirm-btn="Revoke access">
                                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="revoke_member">
                                        <input type="hidden" name="member_id" value="<?php echo (int) $member['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Revoke access">
                                            <i class="fa-solid fa-user-slash"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php elseif ($member['id'] == $_SESSION['user_id']): ?>
                                <a href="profile.php" class="btn btn-sm btn-light">My Profile</a>
                            <?php endif; ?>
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
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">

                    <div class="mb-3">
                        <label for="memberName" class="form-label">Full Name</label>
                        <input type="text" name="name" id="memberName" class="form-control" required
                            placeholder="e.g. Sara Ibrahim">
                    </div>
                    <div class="mb-3">
                        <label for="memberEmail" class="form-label">Email Address</label>
                        <input type="email" name="email" id="memberEmail" class="form-control" required
                            placeholder="name@example.com">
                    </div>
                    <div class="mb-3">
                        <label for="memberPassword" class="form-label">Password</label>
                        <input type="password" name="password" id="memberPassword" class="form-control" required
                            minlength="8" maxlength="72" autocomplete="new-password">
                        <div class="form-text small">At least 8 characters with a letter and a number. Give them this temporary password; they can change it under My Profile.</div>
                    </div>
                    <div class="mb-0">
                        <label for="memberPermission" class="form-label">Access Permission</label>
                        <select name="permission" id="memberPermission" class="form-select">
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

<!-- Edit Member Modal -->
<div class="modal fade" id="editMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Edit Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="update_member">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="member_id" id="editMemberId">

                    <div class="mb-3">
                        <label for="editMemberName" class="form-label">Full Name</label>
                        <input type="text" name="name" id="editMemberName" class="form-control" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label for="editMemberEmail" class="form-label">Email Address</label>
                        <input type="email" name="email" id="editMemberEmail" class="form-control" required>
                    </div>
                    <div class="mb-0">
                        <label for="editMemberPermission" class="form-label">Access Permission</label>
                        <select name="permission" id="editMemberPermission" class="form-select">
                            <option value="read_only">Read-Only (View charts and logs)</option>
                            <option value="edit">Edit Access (Add expenses/income)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Member Password Modal -->
<div class="modal fade" id="resetMemberPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Reset Password for <span id="resetMemberName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" autocomplete="off">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="reset_member_password">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="member_id" id="resetMemberId">

                    <label for="resetMemberPassword" class="form-label">New temporary password</label>
                    <input type="password" name="password" id="resetMemberPassword" class="form-control" required
                        minlength="8" maxlength="72" autocomplete="new-password"
                        data-oninput="checkPasswordStrength" data-args="<?php echo Html::args('$value'); ?>">
                    <?php echo Layout::passwordRequirements(); ?>
                    <div class="form-text small">They'll be signed out of any open sessions and can change it under My Profile.</div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-bold">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    function openEditMember(id, name, email, permission) {
        document.getElementById('editMemberId').value = id;
        document.getElementById('editMemberName').value = name;
        document.getElementById('editMemberEmail').value = email;
        document.getElementById('editMemberPermission').value = permission;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editMemberModal')).show();
    }

    function openResetMemberPassword(id, name) {
        document.getElementById('resetMemberId').value = id;
        document.getElementById('resetMemberName').textContent = name;
        document.getElementById('resetMemberPassword').value = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('resetMemberPasswordModal')).show();
    }
</script>

<?php Layout::footer(); ?>
