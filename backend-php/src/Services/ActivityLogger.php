<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;

final class ActivityLogger
{
    public static function log(
        string $email,
        string $action,
        string $logType,
        string $description,
        $permissionReq = null
    ): void {
        try {
            $pdo = Database::pdo();
            if ($permissionReq !== null) {
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO do_operator_activities
                         (operator_email, action, log_type, description, permission_req, created_at)
                         VALUES (?, ?, ?, ?, ?, NOW())'
                    );
                    $stmt->execute([$email, $action, $logType, $description, $permissionReq]);
                    return;
                } catch (\Throwable) {
                    // fall through without permission_req
                }
            }
            $stmt = $pdo->prepare(
                'INSERT INTO do_operator_activities (operator_email, action, log_type, description, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            );
            $stmt->execute([$email, $action, $logType, $description]);
        } catch (\Throwable) {
        }
    }
}
