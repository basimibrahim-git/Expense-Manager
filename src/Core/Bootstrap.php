<?php

namespace App\Core;

/**
 * Bootstrap class to handle project-wide configuration and initialization.
 */
class Bootstrap
{
    /**
     * Entry points reachable without a logged-in session (paths relative to the project root).
     */
    private const PUBLIC_SCRIPTS = [
        'index.php',
        'auth.php',
        'signup.php',
        'logout.php',
        'forgot_password.php',
        'reset_password.php',
        'cron/reminder_emails.php', // protected by CRON_SECRET / CLI
    ];

    /**
     * Endpoints called via fetch(); they get a 401 JSON response instead of a redirect.
     */
    private const JSON_SCRIPTS = [
        'fetch_url_data.php',
    ];

    /**
     * Initializes the application by loading configuration and starting sessions.
     * This replaces the legacy top-level require_once 'config.php' statements.
     */
    public static function init()
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;

        // Define global variables that the legacy procedural code expects
        global $pdo;

        // Load the core configuration
        // Using __DIR__ to ensure consistent paths from any call site
        require_once __DIR__ . '/../../config.php'; // NOSONAR

        self::enforceLogin();
    }

    /**
     * Central login guard: every web entry point except the public ones requires a session.
     * Runs before any page code, so POST handlers can never execute for anonymous visitors.
     */
    private static function enforceLogin(): void
    {
        if (PHP_SAPI === 'cli' || isset($_SESSION['user_id'])) {
            return;
        }

        $script = self::currentScript();
        if (in_array($script, self::PUBLIC_SCRIPTS, true)) {
            return;
        }

        if (in_array($script, self::JSON_SCRIPTS, true)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not logged in']);
            exit();
        }

        header('Location: ' . BASE_URL . 'index.php');
        exit();
    }

    /**
     * Path of the executing script relative to the project root, using forward slashes.
     */
    private static function currentScript(): string
    {
        $root   = realpath(__DIR__ . '/../..');
        $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($root === false || $script === false || strpos($script, $root) !== 0) {
            return '';
        }
        return ltrim(str_replace('\\', '/', substr($script, strlen($root))), '/');
    }
}
