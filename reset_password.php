<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\AuditHelper;
use App\Helpers\Html;
use App\Helpers\Layout;
use App\Helpers\PasswordResetHelper;
use App\Helpers\SecurityHelper;

Bootstrap::init();

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$error = "";
$done = false;

$reset = null;
try {
    $reset = PasswordResetHelper::find($pdo, $token);
} catch (Exception $e) {
    error_log("Reset token lookup failed: " . $e->getMessage());
}

if ($reset && $_SERVER["REQUEST_METHOD"] == "POST") {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    $error = SecurityHelper::validatePassword($password) ?? '';
    if ($error === '' && !hash_equals($password, $confirm)) {
        $error = "The two passwords do not match.";
    }

    if ($error === '') {
        try {
            if (PasswordResetHelper::consume($pdo, $reset, $password)) {
                AuditHelper::logFor($pdo, (int) $reset['user_id'], $reset['tenant_id'], 'password_reset_completed', "Password reset via email link");
                // Any session open in this browser belongs to whoever was here before — start clean.
                $_SESSION = [];
                session_regenerate_id(true);
                $done = true;
            } else {
                $reset = null; // used concurrently — show the "invalid link" state
            }
        } catch (Exception $e) {
            error_log("Password reset failed: " . $e->getMessage());
            $error = "Something went wrong. Please try again.";
        }
    }
}

Layout::authHeader('Reset Password');
?>
                <h2 class="auth-title text-center">Set a New Password</h2>

                <?php if ($done): ?>
                    <div class="alert alert-success" role="alert">
                        Your password has been changed. You can now sign in with your new password.
                    </div>
                    <a href="index.php" class="btn btn-primary w-100 py-3">Go to sign in</a>

                <?php elseif (!$reset): ?>
                    <div class="alert alert-warning" role="alert">
                        This reset link is invalid or has expired. Links work once and expire after
                        <?php echo PasswordResetHelper::TTL_MINUTES; ?> minutes.
                    </div>
                    <a href="forgot_password.php" class="btn btn-primary w-100 py-3 mb-3">Request a new link</a>
                    <div class="text-center">
                        <a href="index.php" class="text-decoration-none small fw-bold" style="color: var(--primary-color);">Back to sign in</a>
                    </div>

                <?php else: ?>
                    <p class="auth-subtitle text-center">
                        Choose a new password for <strong><?php echo Html::e($reset['email']); ?></strong>.
                    </p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert"><?php echo Html::e($error); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="reset_password.php">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="token" value="<?php echo Html::e($token); ?>">

                        <div class="mb-3">
                            <div class="form-floating">
                                <input type="password" class="form-control" id="passwordInput" name="password"
                                    placeholder="New password" required minlength="8" maxlength="72" autocomplete="new-password"
                                    data-oninput="checkPasswordStrength" data-args="<?php echo Html::args('$value'); ?>">
                                <label for="passwordInput">New password</label>
                            </div>
                            <?php echo Layout::passwordRequirements(); ?>
                        </div>
                        <div class="form-floating mb-4">
                            <input type="password" class="form-control" id="passwordConfirmInput" name="password_confirm"
                                placeholder="Confirm password" required minlength="8" maxlength="72" autocomplete="new-password">
                            <label for="passwordConfirmInput">Confirm new password</label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-3">Save new password</button>
                    </form>
                <?php endif; ?>
<?php Layout::authFooter(); ?>
