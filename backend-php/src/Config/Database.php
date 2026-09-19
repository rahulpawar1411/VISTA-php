<?php
declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $url = Env::get('DATABASE_URL');
        if ($url) {
            $parts = parse_url($url);
            if (!$parts || empty($parts['host'])) {
                throw new RuntimeException('Invalid DATABASE_URL');
            }
            $host = $parts['host'];
            $port = (string) ($parts['port'] ?? 3306);
            $user = $parts['user'] ?? '';
            $pass = $parts['pass'] ?? '';
            $db = ltrim($parts['path'] ?? '', '/');
        } else {
            $host = Env::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
            $port = Env::get('DB_PORT', '3306') ?? '3306';
            $user = Env::get('DB_USER', 'root') ?? 'root';
            $pass = Env::get('DB_PASSWORD', '') ?? '';
            $db = Env::get('DB_NAME', 'reeferon_crm_db') ?? 'reeferon_crm_db';
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '+05:30'");
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    /** @return array{connected: bool, database: string, dbHost: string, dbName: string, databaseError: ?string} */
    public static function health(): array
    {
        $host = Env::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
        $name = Env::get('DB_NAME', 'reeferon_crm_db') ?? 'reeferon_crm_db';
        try {
            $pdo = self::pdo();
            $pdo->query('SELECT 1');
            return [
                'connected' => true,
                'database' => 'connected',
                'dbHost' => $host,
                'dbName' => $name,
                'dbKind' => 'mysql',
                'databaseError' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'database' => 'disconnected',
                'dbHost' => $host,
                'dbName' => $name,
                'dbKind' => 'mysql',
                'databaseError' => $e->getMessage(),
            ];
        }
    }
}
