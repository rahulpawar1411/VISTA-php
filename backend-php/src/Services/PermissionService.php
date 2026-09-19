<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;

final class PermissionService
{
    public static function clientMasterPermissionId($chamberId, string $action, string $clientName, string $extra = ''): int
    {
        $s = strtolower(trim((string) $chamberId) . '|' . $action . '|' . trim($clientName) . '|' . $extra);
        $h = 2166136261;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($s[$i]);
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }
        $id = $h % 2000000000;
        return $id ?: 1;
    }

    public static function chamberAddPermissionId(string $name): int
    {
        $s = 'add|' . strtolower(trim($name));
        $h = 2166136261;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($s[$i]);
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }
        $id = $h % 2000000000;
        return $id ?: 1;
    }

    private static function chamberNumberFromName(?string $name): ?int
    {
        if (preg_match('/^Chamber\s+(\d+)$/i', (string) $name, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * DO chamber picker: numbered Chamber 1..limit first, then custom names up to limit.
     * Ported from backend/controllers/chamberController.js pickDoChambers.
     *
     * @param list<array<string,mixed>> $allRows
     * @return list<array<string,mixed>>
     */
    public static function pickDoChambers(array $allRows, int $limit): array
    {
        $byNum = [];
        $custom = [];
        foreach ($allRows as $r) {
            $num = self::chamberNumberFromName($r['name'] ?? null);
            if ($num !== null && $num >= 1 && $num <= $limit) {
                if (!isset($byNum[$num])) {
                    $byNum[$num] = $r;
                }
            } elseif ($num === null) {
                $custom[] = $r;
            }
        }
        $result = [];
        for ($i = 1; $i <= $limit; $i++) {
            if (isset($byNum[$i])) {
                $result[] = $byNum[$i];
            }
        }
        foreach ($custom as $c) {
            if (count($result) >= $limit) {
                break;
            }
            $result[] = $c;
        }
        return $result;
    }

    public static function hasActivePermission(string $operatorEmail, string $recordType, $recordId, string $action = 'Edit'): bool
    {
        $pdo = Database::pdo();
        $configKey = $recordType . '_' . $action;
        try {
            $stmt = $pdo->prepare(
                "SELECT description FROM do_operator_activities
                 WHERE operator_email = 'system' AND log_type = 'SYSTEM_CONFIG' AND action = ?
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$configKey]);
            $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
            if (
                $cfg &&
                ($cfg['description'] ?? '') === 'Allow' &&
                !in_array($recordType, ['ChamberType', 'ClientMaster'], true)
            ) {
                return true;
            }
        } catch (\Throwable) {
        }

        $grantActionType = $action === 'Edit' ? 'GRANT_PERMISSION' : 'GRANT_DELETE';
        try {
            $stmt = $pdo->prepare(
                "SELECT action FROM do_operator_activities
                 WHERE LOWER(TRIM(operator_email)) = LOWER(TRIM(?))
                   AND log_type = ?
                   AND permission_req = ?
                   AND action IN (
                     'GRANT_PERMISSION', 'GRANT_DELETE',
                     'DENY_PERMISSION', 'DENY_DELETE',
                     'USE_EDIT_PERMISSION', 'USE_DELETE_PERMISSION'
                   )
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$operatorEmail, $recordType, $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row && ($row['action'] ?? '') === $grantActionType;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function consumeGrantedPermission(string $operatorEmail, string $recordType, $recordId, string $action = 'Edit'): bool
    {
        if ($operatorEmail === '' || $recordType === '' || $recordId === null || $recordId === '') {
            return false;
        }
        $grantActionType = $action === 'Edit' ? 'GRANT_PERMISSION' : 'GRANT_DELETE';
        $useActionType = $action === 'Edit' ? 'USE_EDIT_PERMISSION' : 'USE_DELETE_PERMISSION';
        $pdo = Database::pdo();
        try {
            $stmt = $pdo->prepare(
                'SELECT action FROM do_operator_activities
                 WHERE operator_email = ? AND log_type = ? AND permission_req = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$operatorEmail, $recordType, $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || ($row['action'] ?? '') !== $grantActionType) {
                return false;
            }
            $actionWord = $action === 'Edit' ? 'Edit' : 'Delete';
            ActivityLogger::log(
                $operatorEmail,
                $useActionType,
                $recordType,
                "{$actionWord} used · #{$recordId}",
                $recordId
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
