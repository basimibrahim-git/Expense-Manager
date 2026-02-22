<?php

namespace App\Core;

/**
 * Bootstrap class to handle project-wide configuration and initialization.
 */
class Bootstrap
{
    /**
     * Initializes the application by loading configuration and starting sessions.
     * This replaces the legacy top-level require_once 'config.php' statements.
     */
    public static function init()
    {
        // Define global variables that the legacy procedural code expects
        global $pdo;

        // Load the core configuration
        // Using __DIR__ to ensure consistent paths from any call site
        require_once __DIR__ . '/../../config.php';
    }
}
