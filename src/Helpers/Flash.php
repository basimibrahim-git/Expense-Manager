<?php

namespace App\Helpers;

/**
 * One-time messages carried in the session across a redirect
 * (instead of ?success=…&error=… in the URL, which ends up in history and logs
 * and lets anyone craft a link that shows a fake message).
 *
 * Usage in a handler:
 *     Flash::redirect('monthly_sadaqa.php?month=3&year=2026', 'success', 'Sadaqa added');
 * Messages are rendered automatically by the layout (sidebar.php / auth_header.php).
 */
class Flash
{
    private const KEY = '_flash';
    private const TYPES = ['success', 'error', 'warning', 'info'];

    public static function set(string $type, string $message): void
    {
        if (!in_array($type, self::TYPES, true)) {
            $type = 'info';
        }
        $_SESSION[self::KEY][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void
    {
        self::set('success', $message);
    }

    public static function error(string $message): void
    {
        self::set('error', $message);
    }

    /**
     * Optionally queue a message, then redirect and stop.
     * $url is an app-relative path like 'expenses.php?month=3'.
     */
    public static function redirect(string $url, ?string $type = null, ?string $message = null): void
    {
        if ($type !== null && $message !== null && $message !== '') {
            self::set($type, $message);
        }
        header('Location: ' . $url);
        exit();
    }

    /** True when messages are waiting (e.g. to skip a page's own empty-state hint). */
    public static function has(): bool
    {
        return !empty($_SESSION[self::KEY]);
    }

    /**
     * Pull all queued messages (and clear them).
     *
     * @return array<int, array{type: string, message: string}>
     */
    public static function take(): array
    {
        $messages = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);
        return $messages;
    }

    /** Bootstrap alerts for every queued message, escaped. */
    public static function render(): string
    {
        $classes = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'];
        $icons   = ['success' => 'fa-circle-check', 'error' => 'fa-circle-exclamation', 'warning' => 'fa-triangle-exclamation', 'info' => 'fa-circle-info'];
        $html = '';
        foreach (self::take() as $m) {
            $html .= '<div class="alert alert-' . $classes[$m['type']] . ' alert-dismissible fade show shadow-sm border-0 rounded-4" role="alert">'
                . '<i class="fa-solid ' . $icons[$m['type']] . ' me-2"></i>' . Html::e($m['message'])
                . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
        }
        return $html;
    }
}
