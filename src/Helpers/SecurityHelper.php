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
    public static function verifyCsrfToken($token): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $token)) {
            error_log("CSRF Mismatch for session id: " . session_id() . " (token not logged)");
            // A 403 with a Location header is not followed by browsers (blank page), so use a 303 redirect.
            $target = isset($_SESSION['user_id']) ? 'dashboard.php' : 'index.php';
            $base   = defined('BASE_URL') ? BASE_URL : '/';
            header('Location: ' . $base . $target . '?error=' . urlencode('Your session token expired. Please try again.'), true, 303);
            exit();
        }
    }

    /**
     * Stop the request unless the current user has one of the given roles.
     *
     * @param string[] $roles
     */
    public static function requireRole(array $roles, string $redirect = 'dashboard.php'): void
    {
        if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
            $base = defined('BASE_URL') ? BASE_URL : '/';
            header('Location: ' . $base . $redirect . '?error=' . urlencode('You do not have access to that page.'));
            exit();
        }
    }

    /**
     * Password policy shared by signup, change-password and reset-password.
     * Returns an error message, or null when the password is acceptable.
     */
    public static function validatePassword(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters long.';
        }
        if (strlen($password) > 72) {
            return 'Password must be at most 72 characters long.';
        }
        if (!preg_match('/[A-Za-z]/', $password)) {
            return 'Password must contain at least one letter.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number.';
        }
        return null;
    }

    /**
     * Sanitizes a redirect URL to prevent Open Redirect vulnerabilities.
     * Ensures the link is internal to our domain.
     *
     * @param string|null $url
     * @param string $default
     * @return string
     */

    public static function getSafeRedirect(?string $url, string $default = 'dashboard.php'): string
    {
        $redirect = $default;

        if (!empty($url)) {
            $parsed = parse_url($url);
            $isInternal = !isset($parsed['host']) || (isset($_SERVER['HTTP_HOST']) && $parsed['host'] === $_SERVER['HTTP_HOST']);

            if ($isInternal) {
                $redirect = $url;
            }
        }

        return $redirect;
    }
}



