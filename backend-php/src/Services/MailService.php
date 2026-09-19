<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/** Minimal SMTP sender (no Composer) — credentials emails. */
final class MailService
{
    /**
     * @return array{ok: bool, error: ?string}
     */
    public static function sendCredentials(string $toEmail, string $fullName, string $roleLabel, string $password): array
    {
        $host = Env::get('SMTP_HOST', 'smtp.gmail.com') ?: 'smtp.gmail.com';
        $port = (int) (Env::get('SMTP_PORT', '465') ?: 465);
        $user = Env::get('SMTP_USER', '') ?: '';
        $pass = Env::get('SMTP_PASS', '') ?: '';
        $from = $user !== '' ? $user : 'noreply@reeferon.local';

        if ($user === '' || $pass === '') {
            return ['ok' => false, 'error' => 'SMTP_USER / SMTP_PASS not configured.'];
        }

        $subject = "ReeferON CRM — your {$roleLabel} login";
        $loginUrl = Env::get('APP_LOGIN_URL', 'http://localhost:3000') ?: 'http://localhost:3000';
        $body = "Hello {$fullName},\n\n"
            . "Your ReeferON CRM {$roleLabel} account is ready.\n\n"
            . "Email: {$toEmail}\n"
            . "Temporary password: {$password}\n"
            . "Login: {$loginUrl}\n\n"
            . "Please change your password after first login.\n";

        try {
            self::smtpSend($host, $port, $user, $pass, $from, $toEmail, $subject, $body);
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function smtpSend(
        string $host,
        int $port,
        string $user,
        string $pass,
        string $from,
        string $to,
        string $subject,
        string $body
    ): void {
        $remote = ($port === 465 ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, 20);

        $expect = static function ($fp, array $codes) {
            $line = '';
            while ($chunk = fgets($fp, 512)) {
                $line .= $chunk;
                if (isset($chunk[3]) && $chunk[3] === ' ') {
                    break;
                }
            }
            $code = (int) substr($line, 0, 3);
            if (!in_array($code, $codes, true)) {
                throw new \RuntimeException('SMTP unexpected: ' . trim($line));
            }
            return $line;
        };
        $cmd = static function ($fp, string $c) {
            fwrite($fp, $c . "\r\n");
        };

        $expect($fp, [220]);
        $cmd($fp, 'EHLO localhost');
        $expect($fp, [250]);

        if ($port !== 465) {
            $cmd($fp, 'STARTTLS');
            $expect($fp, [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('SMTP STARTTLS failed');
            }
            $cmd($fp, 'EHLO localhost');
            $expect($fp, [250]);
        }

        $cmd($fp, 'AUTH LOGIN');
        $expect($fp, [334]);
        $cmd($fp, base64_encode($user));
        $expect($fp, [334]);
        $cmd($fp, base64_encode($pass));
        $expect($fp, [235]);

        $cmd($fp, 'MAIL FROM:<' . $from . '>');
        $expect($fp, [250]);
        $cmd($fp, 'RCPT TO:<' . $to . '>');
        $expect($fp, [250, 251]);
        $cmd($fp, 'DATA');
        $expect($fp, [354]);

        $headers = "From: ReeferON CRM <{$from}>\r\n"
            . "To: <{$to}>\r\n"
            . "Subject: {$subject}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n";
        $payload = $headers . str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $body) . "\r\n.";
        $cmd($fp, $payload);
        $expect($fp, [250]);
        $cmd($fp, 'QUIT');
        fclose($fp);
    }
}
