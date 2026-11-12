<?php

namespace App\Support;

/**
 * Turn the mail layer's exception into something a person can act on.
 *
 * Shared by the provider-facing "Email sender" test and the admin panel's
 * delivery test page: both open a real SMTP connection and both need
 * "wrong password" and "wrong host" to read differently.
 */
class SmtpErrors
{
    public static function readable(\Throwable $e): string
    {
        $msg = $e->getMessage();

        // Matched case-insensitively on stems: Symfony says "Failed to authenticate",
        // not "Authentication", and the exact wording moves between versions.
        return match (true) {
            stripos($msg, 'authenticat') !== false => 'The mail server rejected those credentials — check the username and password. (Gmail and Outlook need an app password, not your normal one.)',
            stripos($msg, 'could not be established') !== false,
            stripos($msg, 'refused') !== false,
            stripos($msg, 'timed out') !== false,
            stripos($msg, 'timeout') !== false,
            stripos($msg, 'getaddrinfo') !== false,
            stripos($msg, 'name or service not known') !== false => 'Could not reach that mail server on this port. Check the host, port and encryption.',
            stripos($msg, 'ssl') !== false,
            stripos($msg, 'tls') !== false,
            stripos($msg, 'certificate') !== false => 'The encryption setting does not match the server. Try TLS on 587, or SSL on 465.',
            stripos($msg, 'sender') !== false,
            stripos($msg, 'from address') !== false => 'The mail server refused this "from" address — it usually has to match the account you authenticate with.',
            stripos($msg, 'recipient') !== false => 'The mail server refused the recipient address.',
            default => 'The mail server refused the message: '.mb_strimwidth($msg, 0, 200, '…'),
        };
    }
}
