<?php

namespace App\Helpers;

/**
 * Helper class for managing common layout templates.
 */
class Layout
{
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
}
