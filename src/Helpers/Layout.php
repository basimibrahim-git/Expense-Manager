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
        require_once __DIR__ . '/../../includes/header.php';
    }

    /**
     * Includes the sidebar template.
     */
    public static function sidebar()
    {
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
