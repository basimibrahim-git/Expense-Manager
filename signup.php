<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
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

    // Rate Limiting / Cooldown
    $last_attempt = $_SESSION['last_signup_attempt'] ?? 0;
    if (time() - $last_attempt < 5) { // 5 second cooldown for registration
        $error = "Too many attempts. Please slow down.";
    } else {
        $_SESSION['last_signup_attempt'] = time();
        $name = trim($_POST['name']);
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $family_name = trim($_POST['family_name']);
        $password = $_POST['password'];

        // Server-side password strength validation
        if (!empty($password)) {
            if (strlen($password) < 8) {
                $error = "Password must be at least 8 characters long.";
            } elseif (!preg_match('/[A-Za-z]/', $password)) {
                $error = "Password must contain at least one letter.";
            } elseif (!preg_match('/[0-9]/', $password)) {
                $error = "Password must contain at least one number.";
            }
        }
    }

    if (!empty($error)) {
        // already set above — fall through to display
    } elseif (empty($name) || !$email || empty($family_name) || empty($password)) {
        $error = "Please fill in all fields correctly.";
    } else {
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | Expense Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <div class="container-fluid">
        <div class="auth-wrapper">
            <div class="glass-panel auth-card">
                <div class="text-center mb-4">
                    <div class="brand-logo justify-content-center mb-0">
                        <i class="fa-solid fa-wallet"></i> ExpenseMngr
                    </div>
                </div>

                <h2 class="auth-title text-center">Join the Family</h2>
                <p class="auth-subtitle text-center">Create your family account and start tracking.</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">

                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="nameInput" name="name" placeholder="John Doe"
                            required>
                        <label for="nameInput">Full Name</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="emailInput" name="email"
                            placeholder="name@example.com" required>
                        <label for="emailInput">Email address</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="familyInput" name="family_name"
                            placeholder="The Does" required>
                        <label for="familyInput">Family Name (e.g., The Ibrahim Family)</label>
                    </div>

                    <div class="mb-4">
                        <div class="form-floating">
                            <input type="password" class="form-control" id="passwordInput" name="password"
                                placeholder="Password" required oninput="checkPasswordStrength(this.value)">
                            <label for="passwordInput">Password</label>
                        </div>
                        <div id="pwRequirements" class="mt-1 small" style="display:none;">
                            <span id="pwLen"  class="me-2">&#10007; 8+ characters</span>
                            <span id="pwLet"  class="me-2">&#10007; letter</span>
                            <span id="pwNum"  class="me-2">&#10007; number</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-3 mb-3">Create Family Account</button>


                    <div class="text-center">
                        <span class="text-muted small">Already have an account? </span>
                        <a href="index.php" class="text-decoration-none small fw-bold"
                            style="color: var(--primary-color);">Sign in</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
        var _pwLabels = { pwLen: '8+ characters', pwLet: 'letter', pwNum: 'number' };

        function checkPasswordStrength(val) {
            var box = document.getElementById('pwRequirements');
            box.style.display = val.length > 0 ? 'block' : 'none';

            var checks = {
                pwLen: val.length >= 8,
                pwLet: /[A-Za-z]/.test(val),
                pwNum: /[0-9]/.test(val)
            };

            for (var id in checks) {
                var ok = checks[id];
                var el = document.getElementById(id);
                el.style.color = ok ? 'green' : 'red';
                el.textContent = (ok ? '✓' : '✗') + ' ' + _pwLabels[id];
            }
        }
    </script>
</body>

</html>
