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
            Flash::error('Your session token expired. Please try again.');
            header('Location: ' . $base . $target, true, 303);
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
            Flash::error('You do not have access to that page.');
            header('Location: ' . $base . $redirect);
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
            // Reject backslashes (browsers treat "/\evil.com" as "//evil.com") and control chars/whitespace tricks.
            if (strpos($url, chr(92)) !== false || preg_match('/[\x00-\x1F\x7F]/', $url)) {
                return $default;
            }
            // Protocol-relative ("//host") is external.
            if (strpos($url, '//') === 0) {
                return $default;
            }
            $parsed = parse_url($url);
            if ($parsed === false) {
                return $default;
            }
            if (isset($parsed['scheme']) || isset($parsed['host'])) {
                // Absolute URL: only accept our own configured host (never the request Host header).
                $appHost = parse_url((string) ($_ENV['APP_URL'] ?? ''), PHP_URL_HOST);
                $isInternal = $appHost && isset($parsed['host'])
                    && in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true)
                    && strcasecmp($parsed['host'], $appHost) === 0;
            } else {
                $isInternal = true;
            }

            if ($isInternal) {
                $redirect = $url;
            }
        }

        return $redirect;
    }
}



