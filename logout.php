<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;

Bootstrap::init();

// Logout must be submitted via POST with a valid CSRF token to prevent
// CSRF-triggered logout attacks (e.g. via image tags or prefetch).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Show a confirmation page with a POST form instead of acting on GET.
    $token = SecurityHelper::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout | Expense Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container-fluid">
        <div class="auth-wrapper">
            <div class="glass-panel auth-card text-center">
                <h2 class="auth-title mb-3">Sign Out</h2>
                <p class="text-muted mb-4">Are you sure you want to log out?</p>
                <form method="POST" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
                    <button type="submit" class="btn btn-danger w-100 py-2 mb-3">Yes, Log Me Out</button>
                </form>
                <a href="javascript:history.back()" class="text-decoration-none small">Cancel</a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
    exit();
}

// POST: verify CSRF token before destroying session
SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

session_unset();
session_destroy();
header("Location: index.php");
exit();
