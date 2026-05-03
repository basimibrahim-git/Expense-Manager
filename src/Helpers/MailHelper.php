<?php

namespace App\Helpers;

class MailHelper
{
    /**
     * Strip characters that could be used for email header injection.
     */
    private static function sanitizeHeader(string $val): string
    {
        return substr(str_replace(["\r", "\n", "\0"], '', trim($val)), 0, 255);
    }

    /**
     * @param string[] $to      Recipient addresses
     * @param string   $subject Email subject
     * @param string   $html    HTML body
     */
    public static function send(array $to, string $subject, string $html): bool
    {
        $raw_from_address = $_ENV['MAIL_FROM']      ?? 'noreply@expense-manager.local';
        $raw_from_name    = $_ENV['MAIL_FROM_NAME'] ?? 'Expense Manager';

        // Validate sender address; fall back to a safe default if invalid.
        if (!filter_var($raw_from_address, FILTER_VALIDATE_EMAIL)) {
            error_log("[MailHelper] Invalid MAIL_FROM address, using fallback.");
            $raw_from_address = 'noreply@expense-manager.local';
        }

        $from_address = self::sanitizeHeader($raw_from_address);
        $from_name    = self::sanitizeHeader($raw_from_name);
        $subject      = self::sanitizeHeader($subject);

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$from_name} <{$from_address}>\r\n";
        $headers .= "Reply-To: {$from_address}\r\n";
        $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

        $all_ok = true;
        foreach ($to as $address) {
            $address = trim($address);
            if ($address === '') continue;
            $ok = mail($address, $subject, $html, $headers);
            if (!$ok) {
                error_log("[MailHelper] mail() failed for: {$address}");
                $all_ok = false;
            }
        }
        return $all_ok;
    }
}
