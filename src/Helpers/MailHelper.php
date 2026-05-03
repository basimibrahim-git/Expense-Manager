<?php

namespace App\Helpers;

class MailHelper
{
    /**
     * @param string[] $to      Recipient addresses
     * @param string   $subject Email subject
     * @param string   $html    HTML body
     */
    public static function send(array $to, string $subject, string $html): bool
    {
        $from_address = $_ENV['MAIL_FROM']      ?? 'noreply@expense-manager.local';
        $from_name    = $_ENV['MAIL_FROM_NAME'] ?? 'Expense Manager';

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
