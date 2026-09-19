<?php
declare(strict_types=1);

namespace App\Config;

final class Env
{
    private static bool $loaded = false;

    /** @var array<string, string> */
    private static array $vars = [];

    public static function load(string $rootDir): void
    {
        if (self::$loaded) {
            return;
        }

        $path = $rootDir . DIRECTORY_SEPARATOR . '.env';
        if (is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $eq = strpos($line, '=');
                if ($eq === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $eq));
                $value = trim(substr($line, $eq + 1));
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }
                self::$vars[$key] = $value;
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$vars) && self::$vars[$key] !== '') {
            return self::$vars[$key];
        }
        $fromEnv = $_ENV[$key] ?? getenv($key);
        if ($fromEnv === false || $fromEnv === null || $fromEnv === '') {
            return $default;
        }
        return (string) $fromEnv;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = strtolower((string) (self::get($key, $default ? 'true' : 'false') ?? ''));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
}
