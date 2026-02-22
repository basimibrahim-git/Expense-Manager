<?php
require_once 'config.php'; // NOSONAR
use App\Helpers\AuditHelper;
const REDIRECT_ERROR = "Location: index.php?error=";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF Check
    verify_csrf_token($_POST['csrf_token'] ?? '');

    // Rate Limiting / Cooldown
    $last_attempt = $_SESSION['last_auth_attempt'] ?? 0;
    if (time() - $last_attempt < 2) { // 2 second cooldown
        header(REDIRECT_ERROR . urlencode("Too many attempts. Please slow down."));
        exit();
    }
    $_SESSION['last_auth_attempt'] = time();

    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'];

    if (!$email || empty($password)) {
        header(REDIRECT_ERROR . urlencode("Please fill in all fields correctly"));
        exit();
    }

    try {
        if (isset($pdo)) {
            // Real Database Logic
            $stmt = $pdo->prepare("SELECT id, name, password, tenant_id, role, permission FROM users WHERE email = :email");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true); // Prevent Session Fixation
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['tenant_id'] = $user['tenant_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['permission'] = $user['permission'];
                AuditHelper::log($pdo, 'login_success', "User Login: $email");
                header("Location: dashboard.php");
                exit();
            } else {
                header(REDIRECT_ERROR . urlencode("Invalid credentials"));
                exit();
            }
        } else {
            header(REDIRECT_ERROR . urlencode("Database connection failed"));
            exit();
        }
    } catch (Exception $e) {
        // Log the actual error internally
        error_log("Auth Error: " . $e->getMessage());
        header(REDIRECT_ERROR . urlencode("An error occurred"));
        exit();
    }
} else {
    header("Location: index.php");
    exit();
}
