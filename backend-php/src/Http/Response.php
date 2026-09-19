<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param mixed $data */
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param mixed $data */
    public static function success($data = null, string $message = 'OK', int $status = 200): void
    {
        $body = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $body['data'] = $data;
        }
        self::json($body, $status);
    }

    public static function error(string $message, int $status = 500, array $extra = []): void
    {
        self::json(array_merge([
            'success' => false,
            'message' => $message,
            'error' => $message,
        ], $extra), $status);
    }

    public static function setCookieToken(string $token, int $maxAgeMs = 86400000): void
    {
        $isProd = strtolower((string) (\App\Config\Env::get('APP_ENV', 'development'))) === 'production'
            || strtolower((string) (\App\Config\Env::get('NODE_ENV', ''))) === 'production';
        $secure = $isProd;
        $sameSite = $isProd ? 'None' : 'Lax';
        setcookie('token', $token, [
            'expires' => time() + (int) ($maxAgeMs / 1000),
            'path' => '/',
            'httponly' => true,
            'secure' => $secure,
            'samesite' => $sameSite,
        ]);
    }

    public static function clearCookieToken(): void
    {
        $isProd = strtolower((string) (\App\Config\Env::get('APP_ENV', 'development'))) === 'production'
            || strtolower((string) (\App\Config\Env::get('NODE_ENV', ''))) === 'production';
        setcookie('token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'secure' => $isProd,
            'samesite' => $isProd ? 'None' : 'Lax',
        ]);
    }
}
