<?php

namespace App\Helpers;

/**
 * Output-escaping helpers for templates.
 */
class Html
{
    /**
     * Escape a value for HTML text or a quoted attribute.
     */
    public static function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Build an escaped JSON argument list for a data-args attribute
     * (see assets/js/app.js). Usage: data-args="<?php echo Html::args($id, $name); ?>"
     */
    public static function args(...$args): string
    {
        return self::e(json_encode($args, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * JSON-encode a value for embedding inside a <script> block.
     */
    public static function json($value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }
}
