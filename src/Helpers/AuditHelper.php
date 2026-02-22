<?php

namespace App\Helpers;

use Exception;
use PDO;

/**
 * Centralized logic for recording system actions and security events.
 */
class AuditHelper
{
    /**
     * Records an action or event into the audit_logs table.
     *
     * @param PDO $pdo The database connection instance.
     * @param string $action The type of action (e.g., 'bulk_delete', 'login_success')
     * @param mixed $context Additional data, description, or IDs (will be JSON encoded)
     * @return bool
     */
    public static function log(PDO $pdo, string $action, $context = null): bool
    {
        // Ensure session is active and user is logged in
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        try {
            $userId = $_SESSION['user_id'];
            $tenantId = $_SESSION['tenant_id'] ?? null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

            // Convert context to string if array/object
            $contextStr = is_string($context) ? $context : json_encode($context);

            $stmt = $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_id, action, context, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
            return $stmt->execute([$tenantId, $userId, $action, $contextStr, $ip, $ua]);
        } catch (Exception $e) {
            // Fail silently in production to avoid breaking the main user flow,
            // but log to error_log if configured.
            error_log("Audit Logging Failed: " . $e->getMessage());
            return false;
        }
    }
}
