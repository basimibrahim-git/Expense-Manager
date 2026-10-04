<?php

namespace App\Helpers;

/**
 * Helper class for managing common layout templates.
 */
class Layout
{
    /**
     * Allow an extra source for one CSP directive on the current page only.
     * Call before Layout::header(), e.g. Layout::allowCsp('script-src', 'https://cdn.example.com').
     */
    public static function allowCsp(string $directive, string $source): void
    {
        $allowed = ['script-src', 'connect-src', 'frame-src', 'img-src', 'style-src', 'font-src'];
        // data:/blob: are only ever accepted for frames and fetches (needed by some bank SDKs), never for scripts.
        $schemeOnly = in_array($source, ['data:', 'blob:'], true) && in_array($directive, ['frame-src', 'connect-src', 'img-src'], true);
        if (!in_array($directive, $allowed, true)
            || (!$schemeOnly && !preg_match('#^https://[A-Za-z0-9.*-]+(:\d+)?(/[\w./-]*)?$#', $source))) {
            throw new \InvalidArgumentException("Refusing CSP source {$directive} {$source}");
        }
        $GLOBALS['csp_extra'][$directive][] = $source;
    }

    /**
     * Includes the header template.
     */
    public static function header()
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . 'index.php');
            exit();
        }
        $current_page = $GLOBALS['current_page'] ?? basename($_SERVER['PHP_SELF']);
        $page_title   = $GLOBALS['page_title'] ?? '';
        require_once __DIR__ . '/../../includes/header.php';
    }

    /**
     * Includes the sidebar template.
     */
    public static function sidebar()
    {
        $current_page = $GLOBALS['current_page'] ?? basename($_SERVER['PHP_SELF']);
        $activeClass  = 'active';
        require_once __DIR__ . '/../../includes/sidebar.php';
    }

    /**
     * Includes the footer template.
     */
    public static function footer()
    {
        require_once __DIR__ . '/../../includes/footer.php';
    }

    /**
     * Opens the layout for public (logged-out) pages.
     */
    public static function authHeader(string $title)
    {
        $auth_title = $title;
        require_once __DIR__ . '/../../includes/auth_header.php';
    }

    /**
     * Closes the layout for public (logged-out) pages.
     */
    public static function authFooter()
    {
        require_once __DIR__ . '/../../includes/auth_footer.php';
    }

    /**
     * Password field with live requirement hints (works with checkPasswordStrength in app.js).
     */
    public static function passwordRequirements(): string
    {
        return '<div id="pwRequirements" class="mt-1 small" style="display:none;">'
            . '<span id="pwLen" class="me-2">&#10007; 8+ characters</span>'
            . '<span id="pwLet" class="me-2">&#10007; letter</span>'
            . '<span id="pwNum" class="me-2">&#10007; number</span>'
            . '</div>';
    }
}
