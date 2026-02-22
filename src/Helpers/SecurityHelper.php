<?php

namespace App\Helpers;

/**
 * Helper class for security-related operations like CSRF protection.
 */
class SecurityHelper
{
    /**
     * Generates or retrieves a CSRF token from the session.
     *
     * @return string
     */
    public static function generateCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verifies a CSRF token against the session.
     *
     * @param mixed $token
     * @return bool
     */
    public static function verifyCsrfToken($token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $token)) {
            error_log("CSRF Mismatch for session id: " . session_id() . " (token not logged)");
            return false;
        }
        return true;
    }
}
