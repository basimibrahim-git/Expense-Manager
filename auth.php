<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;
use App\Helpers\MailHelper;

Bootstrap::init();
const REDIRECT_ERROR = "Location: index.php?error=";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF Check — verifyCsrfToken() exits with 403 on failure; no return value
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'];

    if (!$email || empty($password)) {
        header(REDIRECT_ERROR . urlencode("Please fill in all fields correctly"));
        exit();
    }

    try {
        if (!isset($pdo)) {
            header(REDIRECT_ERROR . urlencode("Database connection failed"));
            exit();
        }

        // Ensure login_attempts table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS `login_attempts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ip` VARCHAR(45) NOT NULL,
            `email` VARCHAR(255) NOT NULL DEFAULT '',
            `attempted_at` DATETIME NOT NULL,
            `success` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            INDEX `idx_ip_attempted` (`ip`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // IP-based rate limiting: count failed attempts in last 15 minutes
        $rateLimitStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip = ? AND success = 0
             AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $rateLimitStmt->execute([$ip]);
        $failedCount = (int) $rateLimitStmt->fetchColumn();

        if ($failedCount >= 5) {
            AuditHelper::log($pdo, 'login_blocked', "Rate-limited login attempt for $email from $ip");
            header(REDIRECT_ERROR . urlencode("Too many failed attempts. Try again in 15 minutes."));
            exit();
        }

        // Real Database Logic
        $stmt = $pdo->prepare("SELECT id, name, email, password, tenant_id, role, permission FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Record successful attempt
            $pdo->prepare("INSERT INTO login_attempts (ip, email, attempted_at, success) VALUES (?, ?, NOW(), 1)")
                ->execute([$ip, $email]);

            // Fix 6 — Email alert on new login from unknown IP
            $priorSuccessStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE ip = ? AND email = ? AND success = 1
                 AND id < (SELECT MAX(id) FROM login_attempts WHERE ip = ? AND email = ? AND success = 1)"
            );
            $priorSuccessStmt->execute([$ip, $email, $ip, $email]);
            $priorCount = (int) $priorSuccessStmt->fetchColumn();

            if ($priorCount === 0) {
                $mailRecipients = $_ENV['MAIL_RECIPIENTS'] ?? '';
                if ($mailRecipients !== '') {
                    $recipients = array_filter(array_map('trim', explode(',', $mailRecipients)));
                    if (!empty($recipients)) {
                        $loginTime = date('Y-m-d H:i:s');
                        $alertHtml = "<p>Hello,</p>"
                            . "<p>A new login to your Expense Manager account was detected from IP: <strong>" . htmlspecialchars($ip) . "</strong> at <strong>" . htmlspecialchars($loginTime) . "</strong>.</p>"
                            . "<p>If this was not you, please contact your administrator immediately.</p>";
                        MailHelper::send($recipients, 'New Login Detected - Expense Manager', $alertHtml);
                    }
                }
            }

            session_regenerate_id(true); // Prevent Session Fixation
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['tenant_id'] = $user['tenant_id'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['permission'] = $user['permission'];
            AuditHelper::log($pdo, 'login_success', "Login: $email from $ip");
            header("Location: dashboard.php");
            exit();
        } else {
            // Record failed attempt
            $pdo->prepare("INSERT INTO login_attempts (ip, email, attempted_at, success) VALUES (?, ?, NOW(), 0)")
                ->execute([$ip, $email]);

            AuditHelper::log($pdo, 'login_failed', "Failed login: $email from $ip");
            header(REDIRECT_ERROR . urlencode("Invalid credentials"));
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
