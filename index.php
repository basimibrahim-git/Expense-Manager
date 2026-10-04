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

Layout::authHeader('Login');
?>
                <h2 class="auth-title text-center">Welcome Back</h2>
                <p class="auth-subtitle text-center">Please enter your details to sign in.</p>

                <form action="auth.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="emailInput" name="email"
                            placeholder="name@example.com" required autocomplete="username">
                        <label for="emailInput">Email address</label>
                    </div>
                    <div class="form-floating mb-2">
                        <input type="password" class="form-control" id="passwordInput" name="password"
                            placeholder="Password" required autocomplete="current-password">
                        <label for="passwordInput">Password</label>
                    </div>

                    <div class="text-end mb-4">
                        <a href="forgot_password.php" class="text-decoration-none small fw-bold"
                            style="color: var(--primary-color);">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-3 mb-3">Sign in</button>

                    <div class="text-center">
                        <span class="text-muted small">Don't have an account? </span>
                        <a href="signup.php" class="text-decoration-none small fw-bold"
                            style="color: var(--primary-color);">Sign up</a>
                    </div>
                </form>
<?php Layout::authFooter(); ?>
