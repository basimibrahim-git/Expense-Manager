<?php
session_start();
require_once 'config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF Check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        header("Location: index.php?error=Security session invalid. Please refresh.");
        exit();
    }

    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        header("Location: index.php?error=Please fill in all fields");
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
                header("Location: dashboard.php");
                exit();
            } else {
                header("Location: index.php?error=Invalid credentials");
                exit();
            }
        } else {
            header("Location: index.php?error=Database connection failed");
            exit();
        }
    } catch (Exception $e) {
        header("Location: index.php?error=An error occurred");
        exit();
    }
} else {
    header("Location: index.php");
    exit();
}
?>