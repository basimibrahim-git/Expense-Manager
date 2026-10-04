<?php

namespace App\Helpers;

use PDO;

/**
 * Password-reset tokens.
 *
 * The raw token only ever exists in the emailed link; the database stores its
 * SHA-256 hash, so a leaked password_resets table cannot be used to reset accounts.
 */
class PasswordResetHelper
{
    public const TTL_MINUTES = 60;

    /**
     * Max reset emails per user / per IP within the window below.
     */
    private const MAX_PER_USER = 3;
    private const MAX_PER_IP   = 10;
    private const WINDOW_MIN   = 15;

    /**
     * True when this IP or user has asked for too many reset links recently.
     */
    public static function isRateLimited(PDO $pdo, ?int $userId, string $ip): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_resets
                               WHERE request_ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL " . self::WINDOW_MIN . " MINUTE)");
        $stmt->execute([$ip]);
        if ((int) $stmt->fetchColumn() >= self::MAX_PER_IP) {
            return true;
        }
        if ($userId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_resets
                                   WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL " . self::WINDOW_MIN . " MINUTE)");
            $stmt->execute([$userId]);
            if ((int) $stmt->fetchColumn() >= self::MAX_PER_USER) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create a new token for the user (invalidating older unused ones) and return the raw token.
     */
    public static function create(PDO $pdo, int $userId, string $ip): string
    {
        $token = bin2hex(random_bytes(32));

        $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
            ->execute([$userId]);
        $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, request_ip)
                       VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . self::TTL_MINUTES . " MINUTE), ?)")
            ->execute([$userId, hash('sha256', $token), $ip]);

        return $token;
    }

    /**
     * Look up a valid (unused, unexpired) token. Returns reset id + user fields, or null.
     */
    public static function find(PDO $pdo, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT pr.id AS reset_id, u.id AS user_id, u.tenant_id, u.email, u.name
                               FROM password_resets pr
                               JOIN users u ON u.id = pr.user_id
                               WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()");
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Set the new password and burn every outstanding token for the user.
     * Returns false if the token was consumed concurrently.
     */
    public static function consume(PDO $pdo, array $reset, string $newPassword): bool
    {
        $pdo->beginTransaction();
        try {
            $burn = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
            $burn->execute([$reset['reset_id']]);
            if ($burn->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $reset['user_id']]);
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                ->execute([$reset['user_id']]);
            // A successful reset also clears the login lock-out for this account.
            $pdo->prepare("DELETE FROM login_attempts WHERE email = ? AND success = 0")
                ->execute([$reset['email']]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Absolute URL of the reset page for a token.
     * Uses APP_URL from .env when set (recommended), otherwise the current host.
     */
    public static function url(string $token): string
    {
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        if ($appUrl !== '') {
            $path = parse_url($appUrl, PHP_URL_PATH);
            $root = ($path === null || $path === '' || $path === '/') ? $appUrl . BASE_URL : $appUrl . '/';
        } else {
            $root = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL;
        }
        return $root . 'reset_password.php?token=' . $token;
    }
}
