<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Html;
use App\Helpers\Layout;
use App\Helpers\SecurityHelper;

Bootstrap::init();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $name = trim($_POST['name'] ?? '');
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $family_name = trim($_POST['family_name'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    // Rate Limiting / Cooldown
    $last_attempt = $_SESSION['last_signup_attempt'] ?? 0;
    if (time() - $last_attempt < 5) { // 5 second cooldown for registration
        $error = "Too many attempts. Please slow down.";
    } elseif (empty($name) || !$email || empty($family_name) || $password === '') {
        $error = "Please fill in all fields correctly.";
    } else {
        // Server-side password strength validation
        $error = SecurityHelper::validatePassword($password) ?? '';
    }
    $_SESSION['last_signup_attempt'] = time();

    if ($error === '') {
        try {
            // Check if email exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Email already registered.";
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
                $success = "Registration successful! You can now sign in.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Signup Error: " . $e->getMessage());
            $error = "An error occurred during registration.";
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
