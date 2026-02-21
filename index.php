<?php
include_once 'config.php';
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Expense Manager</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
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

                <h2 class="auth-title text-center">Welcome Back</h2>
                <p class="auth-subtitle text-center">Please enter your details to sign in.</p>

                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_GET['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form action="auth.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="emailInput" name="email"
                            placeholder="name@example.com" required>
                        <label for="emailInput">Email address</label>
                    </div>
                    <div class="form-floating mb-4">
                        <input type="password" class="form-control" id="passwordInput" name="password"
                            placeholder="Password" required>
                        <label for="passwordInput">Password</label>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="" id="rememberMe">
                            <label class="form-check-label text-muted small" for="rememberMe">
                                Remember me
                            </label>
                        </div>
                        <a href="#" class="text-decoration-none small fw-bold"
                            style="color: var(--primary-color);">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-3 mb-3">Sign in</button>

                    <div class="text-center">
                        <span class="text-muted small">Don't have an account? </span>
                        <a href="signup.php" class="text-decoration-none small fw-bold"
                            style="color: var(--primary-color);">Sign up</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>