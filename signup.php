<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Html;
use App\Helpers\Layout;
use App\Helpers\SecurityHelper;
use App\Helpers\MailHelper;

Bootstrap::init();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";
$success = "";
$genericSuccess = "Thanks! If this email can be used, your account is ready and you can sign in. "
    . "If you already have an account, use \"Forgot Password?\" on the sign-in page.";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $name = trim($_POST['name'] ?? '');
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $family_name = trim($_POST['family_name'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    // Rate limiting / cooldown, kept server-side per IP (a session cookie can simply be dropped).
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $tooMany = false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `signup_attempts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ip` VARCHAR(45) NOT NULL,
            `attempted_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_signup_ip` (`ip`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Record first, then count (atomic enough: concurrent requests all see each other's rows).
        $pdo->prepare("INSERT INTO signup_attempts (ip, attempted_at) VALUES (?, NOW())")->execute([$ip]);
        $cnt = $pdo->prepare("SELECT
                SUM(attempted_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)) AS recent,
                COUNT(*) AS hourly
            FROM signup_attempts WHERE ip = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $cnt->execute([$ip]);
        $row = $cnt->fetch();
        $tooMany = ((int) ($row['recent'] ?? 0)) > 1 || ((int) ($row['hourly'] ?? 0)) > 10;
        if (mt_rand(1, 50) === 1) {
            $pdo->exec("DELETE FROM signup_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        }
    } catch (Exception $e) {
        error_log("Signup rate-limit error: " . $e->getMessage());
    }

    if ($tooMany) { // 1 attempt per 5 seconds, 10 per hour, per IP
        $error = "Too many attempts. Please slow down and try again shortly.";
    } elseif (empty($name) || !$email || empty($family_name) || $password === '') {
        $error = "Please fill in all fields correctly.";
    } else {
        // Server-side password strength validation
        $error = SecurityHelper::validatePassword($password) ?? '';
    }

    if ($error === '') {
        try {
            // Check if email exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                // Do not reveal that the account exists: same message as a successful signup.
                // The real owner is told by email instead.
                $notice = "<p>Someone tried to create an Expense Manager account with this email address, "
                    . "which already has one. If it was you, sign in, or use \"Forgot Password?\" on the sign-in page "
                    . "to set a new password. If it was not you, you can ignore this email.</p>";
                MailHelper::send([$email], 'Expense Manager sign-up attempt', $notice);
                $success = $genericSuccess;
            } else {
                $pdo->beginTransaction();

                // 1. Create Tenant
                $stmt = $pdo->prepare("INSERT INTO tenants (family_name) VALUES (?)");
                $stmt->execute([$family_name]);
                $tenant_id = $pdo->lastInsertId();

                // 2. Create User as Family Admin
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, tenant_id, permission) VALUES (?, ?, ?, 'family_admin', ?, 'edit')");
                $stmt->execute([$name, $email, $hashed_password, $tenant_id]);

                // 3. Optional: Create default User Preferences for the new user
                $new_user_id = $pdo->lastInsertId();
                $stmt = $pdo->prepare("INSERT INTO user_preferences (user_id, base_currency) VALUES (?, 'AED')");
                $stmt->execute([$new_user_id]);

                $pdo->commit();
                $success = $genericSuccess;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \PDOException && (string) $e->getCode() === '23000') {
                // Concurrent duplicate email: answer exactly like the pre-checked case.
                $success = $genericSuccess;
            } else {
                $error = "An error occurred during registration.";
            }
            error_log("Signup Error: " . $e->getMessage());
        }
    }
}

Layout::authHeader('Sign Up');
?>
                <h2 class="auth-title text-center">Join the Family</h2>
                <p class="auth-subtitle text-center">Create your family account and start tracking.</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo Html::e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo Html::e($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">

                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="nameInput" name="name" placeholder="John Doe"
                            required maxlength="100" value="<?php echo Html::e($error ? ($_POST['name'] ?? '') : ''); ?>">
                        <label for="nameInput">Full Name</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="emailInput" name="email"
                            placeholder="name@example.com" required autocomplete="username"
                            value="<?php echo Html::e($error ? ($_POST['email'] ?? '') : ''); ?>">
                        <label for="emailInput">Email address</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="familyInput" name="family_name"
                            placeholder="The Does" required maxlength="100"
                            value="<?php echo Html::e($error ? ($_POST['family_name'] ?? '') : ''); ?>">
                        <label for="familyInput">Family Name (e.g., The Ibrahim Family)</label>
                    </div>

                    <div class="mb-4">
                        <div class="form-floating">
                            <input type="password" class="form-control" id="passwordInput" name="password"
                                placeholder="Password" required minlength="8" maxlength="72" autocomplete="new-password"
                                data-oninput="checkPasswordStrength" data-args="<?php echo Html::args('$value'); ?>">
                            <label for="passwordInput">Password</label>
                        </div>
                        <?php echo Layout::passwordRequirements(); ?>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-3 mb-3">Create Family Account</button>

                    <div class="text-center">
                        <span class="text-muted small">Already have an account? </span>
                        <a href="index.php" class="text-decoration-none small fw-bold"
                            style="color: var(--primary-color);">Sign in</a>
                    </div>
                </form>
<?php Layout::authFooter(); ?>
