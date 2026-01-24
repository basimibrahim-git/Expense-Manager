<?php
/**
 * Database Configuration
 * 
 * Please update the following constants with your remote database credentials.
 */

// Set Timezone
date_default_timezone_set('Asia/Dubai');

// Load .env variables
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // Remove quotes if present
        $value = trim($value, '"\'');
        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name] = $value;
    }
}

// Database Host (e.g., localhost or IP address)
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');

// Database Name
define('DB_NAME', $_ENV['DB_NAME'] ?? '');

// Database Username
define('DB_USER', $_ENV['DB_USER'] ?? '');

// Database Password
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

// Charset
define('DB_CHARSET', 'utf8mb4');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (\PDOException $e) {
    // Production Error Handling
    error_log("Database Connection Error: " . $e->getMessage()); // Log to server error log
    die("Global Finance Error: Service temporarily unavailable. Please try again later.");
}

// Production Settings
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function generate_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token)
{
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        error_log("CSRF Mismatch - Session: " . ($_SESSION['csrf_token'] ?? 'NONE') . " vs Submitted: " . $token);
        // Always redirect to dashboard for security - no dynamic redirects
        header("Location: dashboard.php?error=Security session expired. Please try again.");
        exit();
    }
    return true;
}
