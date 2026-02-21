<?php
require_once 'config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF Check
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'];

    if (!$email || empty($password)) {
        header("Location: index.php?error=" . urlencode("Please fill in all fields correctly"));
        exit();
    }

    try {
        if (isset($pdo)) {
            // Real Database Logic
            $stmt = $pdo->prepare("SELECT id, name, password FROM users WHERE email = :email");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true); // Prevent Session Fixation
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                log_audit('login_success', "User Login: $email");
                header("Location: dashboard.php");
                exit();
            } else {
                header("Location: index.php?error=" . urlencode("Invalid credentials"));
                exit();
            }
        } else {
            header("Location: index.php?error=" . urlencode("Database connection failed"));
            exit();
        }
    } catch (Exception $e) {
        // Log the actual error internally
        error_log("Auth Error: " . $e->getMessage());
        header("Location: index.php?error=" . urlencode("An error occurred"));
        exit();
    }
} else {
    header("Location: index.php");
    exit();
}
?>