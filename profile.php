<?php
$page_title = "My Profile";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\AuditHelper;
use App\Helpers\Html;
use App\Helpers\Layout;
use App\Helpers\SecurityHelper;

Bootstrap::init();

$user_id = (int) $_SESSION['user_id'];
const PROFILE_MAX_PW_FAILS = 5;
const PROFILE_LOCK_SECONDS = 900;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'update_name') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            header("Location: profile.php?error=" . urlencode("Name must be between 1 and 100 characters."));
            exit();
        }
        $pdo->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$name, $user_id]);
        $_SESSION['user_name'] = $name;
        AuditHelper::log($pdo, 'profile_update', "Changed display name");
        header("Location: profile.php?success=" . urlencode("Name updated."));
        exit();
    }

    if ($action === 'change_password') {
        // Throttle guessing of the current password from a hijacked session.
        $fails = $_SESSION['pw_change_fails'] ?? ['count' => 0, 'since' => time()];
        if (time() - $fails['since'] > PROFILE_LOCK_SECONDS) {
            $fails = ['count' => 0, 'since' => time()];
        }
        if ($fails['count'] >= PROFILE_MAX_PW_FAILS) {
            header("Location: profile.php?error=" . urlencode("Too many wrong attempts. Try again in 15 minutes."));
            exit();
        }

        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $hash = (string) $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $fails['count']++;
            $_SESSION['pw_change_fails'] = $fails;
            AuditHelper::log($pdo, 'password_change_failed', "Wrong current password");
            header("Location: profile.php?error=" . urlencode("Your current password is incorrect."));
            exit();
        }

        $error = SecurityHelper::validatePassword($new);
        if ($error === null && !hash_equals($new, $confirm)) {
            $error = "The new passwords do not match.";
        }
        if ($error === null && password_verify($new, $hash)) {
            $error = "The new password must be different from the current one.";
        }
        if ($error !== null) {
            header("Location: profile.php?error=" . urlencode($error));
            exit();
        }

        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $user_id]);
        // Keep this session signed in; every other session for this account is signed out (see config.php).
        $_SESSION['pw_fp'] = hash('sha256', $newHash);
        // Outstanding reset links are no longer needed.
        $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
            ->execute([$user_id]);

        unset($_SESSION['pw_change_fails']);
        session_regenerate_id(true);
        AuditHelper::log($pdo, 'password_changed', "Password changed from profile");
        header("Location: profile.php?success=" . urlencode("Password changed successfully."));
        exit();
    }

    header("Location: profile.php");
    exit();
}

$stmt = $pdo->prepare("SELECT u.name, u.email, u.role, u.permission, u.created_at, t.family_name
                       FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id
                       WHERE u.id = ?");
$stmt->execute([$user_id]);
$me = $stmt->fetch();

$role_labels = ['root_admin' => 'Root Admin', 'family_admin' => 'Family Admin', 'user' => 'Member'];

Layout::header();
Layout::sidebar();
?>

<div class="row mb-4">
    <div class="col">
        <h2 class="fw-bold mb-0"><i class="fa-solid fa-user-gear text-primary me-2"></i>My Profile</h2>
        <p class="text-muted">Your account details and password</p>
    </div>
</div>

<?php if (!empty($_GET['success'])): ?>
    <div class="alert alert-success shadow-sm border-0"><i class="fa-solid fa-check-circle me-2"></i><?php echo Html::e($_GET['success']); ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger shadow-sm border-0"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo Html::e($_GET['error']); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="glass-panel p-4 h-100">
            <h5 class="fw-bold mb-3">Account</h5>
            <dl class="row small mb-4">
                <dt class="col-5 text-muted">Email</dt>
                <dd class="col-7"><?php echo Html::e($me['email']); ?></dd>
                <dt class="col-5 text-muted">Family</dt>
                <dd class="col-7"><?php echo Html::e($me['family_name'] ?? '—'); ?></dd>
                <dt class="col-5 text-muted">Role</dt>
                <dd class="col-7"><?php echo Html::e($role_labels[$me['role']] ?? $me['role']); ?></dd>
                <dt class="col-5 text-muted">Access</dt>
                <dd class="col-7"><?php echo $me['permission'] === 'edit' ? 'Edit' : 'Read-only'; ?></dd>
                <dt class="col-5 text-muted">Member since</dt>
                <dd class="col-7"><?php echo Html::e(date('d M Y', strtotime($me['created_at']))); ?></dd>
            </dl>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="update_name">
                <label for="profileName" class="form-label fw-bold small text-muted">Display name</label>
                <div class="input-group">
                    <input type="text" name="name" id="profileName" class="form-control" maxlength="100" required
                        value="<?php echo Html::e($me['name']); ?>">
                    <button type="submit" class="btn btn-outline-primary">Save</button>
                </div>
                <div class="form-text small">To change your email, ask your family admin.</div>
            </form>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="glass-panel p-4 h-100">
            <h5 class="fw-bold mb-3">Change Password</h5>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="mb-3">
                    <label for="currentPassword" class="form-label fw-bold small text-muted">Current password</label>
                    <input type="password" name="current_password" id="currentPassword" class="form-control" required autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label for="newPassword" class="form-label fw-bold small text-muted">New password</label>
                    <input type="password" name="new_password" id="newPassword" class="form-control" required
                        minlength="8" maxlength="72" autocomplete="new-password"
                        data-oninput="checkPasswordStrength" data-args="<?php echo Html::args('$value'); ?>">
                    <?php echo Layout::passwordRequirements(); ?>
                </div>
                <div class="mb-4">
                    <label for="confirmPassword" class="form-label fw-bold small text-muted">Confirm new password</label>
                    <input type="password" name="confirm_password" id="confirmPassword" class="form-control" required
                        minlength="8" maxlength="72" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-key me-2"></i>Update password</button>
            </form>
        </div>
    </div>
</div>

<?php Layout::footer(); ?>
