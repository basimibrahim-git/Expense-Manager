<?php
/**
 * Database Configuration
 */
define('BASE_URL', '/expenses/');

// Load .env variables
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue; // skip malformed
        }
        $name = trim($parts[0]);
        $value = trim($parts[1]);
        $value = trim($value, "\"'");
        if ($name === '') {
            continue;
        }
        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name] = $value;
    }
}

// Force HTTPS in production
if (($_ENV['APP_ENV'] ?? 'production') === 'production'
    && PHP_SAPI !== 'cli'
    && empty($_SERVER['HTTPS'])
    && ($_SERVER['SERVER_PORT'] ?? 80) != 443) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $host . $uri, true, 301);
    exit();
}

// Set Timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Dubai');

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

    // Sync DB timezone with PHP timezone
    $now = new DateTime();
    $mins = $now->getOffset() / 60;
    $sgn = ($mins < 0 ? -1 : 1);
    $mins = abs($mins);
    $hrs = floor($mins / 60);
    $mins -= $hrs * 60;
    $offset = sprintf('%+d:%02d', $hrs * $sgn, $mins);
    $pdo->prepare("SET time_zone = ?")->execute([$offset]);

} catch (\PDOException $e) {
    // Production Error Handling
    error_log("Database Connection Error: " . $e->getMessage()); // Log to server error log
    die("Global Finance Error: Service temporarily unavailable. Please try again later.");
}

// Production Settings
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
// Secure Error Logging
// Attempt to log outside webroot, or fallback to hidden file
$logDataDir = dirname(__DIR__); // Parent of project root
$logFile = $logDataDir . '/expense_manager_errors.log';

// If parent is not writable, fallback to project root but hidden
if (!is_writable($logDataDir) && !is_writable($logFile)) {
    $logFile = __DIR__ . '/.error.log';
}

ini_set('log_errors', 1);
ini_set('error_log', $logFile);

// Global exception handler — logs the error, shows a generic message to the user
set_exception_handler(function (Throwable $e): void {
    error_log('[Uncaught] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
    }
    echo 'An unexpected error occurred. Please try again later.';
    exit(1);
});

// CSRF Protection & Session Hardening
if (session_status() === PHP_SESSION_NONE) {
    // secure session cookie params
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// Server-side session idle timeout (1 hour)
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 3600) {
        session_unset();
        session_destroy();
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('Location: index.php?error=' . urlencode('Session expired'));
            exit();
        }
    } else {
        $_SESSION['last_activity'] = time();
    }
}


// Load Composer Autoloader
require_once __DIR__ . '/autoload.php';

// procedural version removed in favor of App\Helpers\AuditHelper

// Load User Preferences into Session
if (isset($_SESSION['user_id']) && !isset($_SESSION['preferences'])) {
    try {
        $prefStmt = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = ?");
        $prefStmt->execute([$_SESSION['user_id']]);
        $prefs = $prefStmt->fetch();

        if (!$prefs) {
            // Create default
            $pdo->prepare("INSERT INTO user_preferences (user_id) VALUES (?)")->execute([$_SESSION['user_id']]);
            $prefs = ['base_currency' => 'AED', 'theme_preference' => 'dark', 'notifications_enabled' => 1];
        }
        $_SESSION['preferences'] = $prefs;
    } catch (Exception $e) {
        $_SESSION['preferences'] = ['base_currency' => 'AED', 'theme_preference' => 'dark'];
    }
}
