<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\Env;
use App\Http\Request;
use App\Http\Response;

final class HealthController
{
    public static function live(Request $req, array $params = []): void
    {
        Response::json([
            'success' => true,
            'message' => 'ReeferON CRM PHP API running smoothly.',
            'data' => [
                'status' => 'Online',
                'uptimeSeconds' => (int) (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))),
                'timestamp' => gmdate('c'),
                'version' => 'php-0.1.0',
                'engine' => 'php',
            ],
        ]);
    }

    public static function db(Request $req, array $params = []): void
    {
        $info = Database::health();
        $ok = $info['connected'];
        Response::json([
            'success' => $ok,
            'message' => $ok ? 'Database connected.' : 'Database disconnected.',
            'data' => $info,
        ], $ok ? 200 : 503);
    }
}
