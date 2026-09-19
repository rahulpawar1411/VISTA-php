<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use RuntimeException;

/** HS256 JWT compatible with Node jsonwebtoken (same JWT_SECRET). */
final class JwtService
{
    public static function secret(): string
    {
        $secret = Env::get('JWT_SECRET');
        if ($secret === null || trim($secret) === '') {
            $err = new RuntimeException(
                'JWT_SECRET is not configured. Set JWT_SECRET in backend-php/.env and restart the server.'
            );
            $err->statusCode = 500;
            $err->type = 'ConfigError';
            throw $err;
        }
        return trim($secret);
    }

    /** @param array<string, mixed> $payload */
    public static function sign(array $payload, int $ttlSeconds = 86400): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $now = time();
        $payload['iat'] = $payload['iat'] ?? $now;
        $payload['exp'] = $payload['exp'] ?? ($now + $ttlSeconds);

        $segments = [
            self::b64(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signing = implode('.', $segments);
        $sig = hash_hmac('sha256', $signing, self::secret(), true);
        $segments[] = self::b64($sig);
        return implode('.', $segments);
    }

    /** @return array<string, mixed> */
    public static function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw self::tokenError('JsonWebTokenError', 'Invalid token format');
        }

        [$h64, $p64, $s64] = $parts;
        $signing = $h64 . '.' . $p64;
        $expected = self::b64(hash_hmac('sha256', $signing, self::secret(), true));
        if (!hash_equals($expected, $s64)) {
            throw self::tokenError('JsonWebTokenError', 'Invalid signature');
        }

        $payload = json_decode(self::ub64($p64), true);
        if (!is_array($payload)) {
            throw self::tokenError('JsonWebTokenError', 'Invalid payload');
        }

        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            throw self::tokenError('TokenExpiredError', 'jwt expired');
        }

        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function ub64(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $out = base64_decode(strtr($data, '-_', '+/'), true);
        return $out === false ? '' : $out;
    }

    private static function tokenError(string $name, string $message): RuntimeException
    {
        $e = new RuntimeException($message);
        $e->name = $name;
        return $e;
    }
}
