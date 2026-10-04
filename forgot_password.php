<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\AuditHelper;
use App\Helpers\Html;
use App\Helpers\Layout;
use App\Helpers\MailHelper;
use App\Helpers\PasswordResetHelper;
use App\Helpers\SecurityHelper;

Bootstrap::init();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";
$sent = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!$email) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, tenant_id, name, email FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (PasswordResetHelper::isRateLimited($pdo, $user ? (int) $user['id'] : null, $ip)) {
                $error = "Too many reset requests. Please wait 15 minutes and try again.";
            } else {
                if ($user) {
                    $token = PasswordResetHelper::create($pdo, (int) $user['id'], $ip);
                    $link = PasswordResetHelper::url($token);

                    $html = "<p>Hello " . Html::e($user['name']) . ",</p>"
                        . "<p>We received a request to reset the password for your Expense Manager account.</p>"
                        . "<p><a href=\"" . Html::e($link) . "\" style=\"display:inline-block;padding:10px 18px;background:#4f46e5;color:#fff;border-radius:6px;text-decoration:none;\">Reset my password</a></p>"
                        . "<p>Or copy this link into your browser:<br>" . Html::e($link) . "</p>"
                        . "<p>This link expires in " . PasswordResetHelper::TTL_MINUTES . " minutes and can be used once. "
                        . "If you did not ask for a reset, you can ignore this email — your password will not change.</p>";

                    if (!MailHelper::send([$user['email']], 'Reset your Expense Manager password', $html)) {
                        error_log("Password reset email failed for user #" . $user['id']);
                    }
                    AuditHelper::logFor($pdo, (int) $user['id'], $user['tenant_id'], 'password_reset_requested', "Reset link requested from $ip");
                }
                // Same response whether or not the account exists (no account enumeration).
                $sent = true;
            }
        } catch (Exception $e) {
            error_log("Forgot password error: " . $e->getMessage());
            $error = "Something went wrong. Please try again later.";
        }
    }
}

Layout::authHeader('Forgot Password');
?>
                <h2 class="auth-title text-center">Forgot Password?</h2>

                <?php if ($sent): ?>
                    <div class="alert alert-success" role="alert">
                        If an account exists for that email, a reset link is on its way. It expires in
                        <?php echo PasswordResetHelper::TTL_MINUTES; ?> minutes. Check your spam folder if you don't see it.
                    </div>
                    <div class="text-center">
                        <a href="index.php" class="text-decoration-none small fw-bold" style="color: var(--primary-color);">
                            <i class="fa-solid fa-arrow-left me-1"></i> Back to sign in
                        </a>
                    </div>
                <?php else: ?>
                    <p class="auth-subtitle text-center">Enter your account email and we'll send you a link to set a new password.</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert"><?php echo Html::e($error); ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <div class="form-floating mb-4">
                            <input type="email" class="form-control" id="emailInput" name="email"
                                placeholder="name@example.com" required autofocus
                                value="<?php echo Html::e($_POST['email'] ?? ''); ?>">
                            <label for="emailInput">Email address</label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-3 mb-3">Send reset link</button>

                        <div class="text-center">
                            <a href="index.php" class="text-decoration-none small fw-bold" style="color: var(--primary-color);">
                                <i class="fa-solid fa-arrow-left me-1"></i> Back to sign in
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
<?php Layout::authFooter(); ?>
