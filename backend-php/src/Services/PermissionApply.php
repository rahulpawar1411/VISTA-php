<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;

/**
 * Side-effects when Super Admin approves ChamberMaster / ChamberType / ClientMaster requests.
 * Ported from backend/controllers/permissionController.js applyApproved*.
 */
final class PermissionApply
{
    /**
     * @return array{ok:bool, reason?:string, id?:int|string|null, name?:string, chamber_limit?:int, remark?:string}
     */
    public static function applyApprovedChamberAdd(
        string $operatorEmail,
        ?string $requestDescription,
        $recordId
    ): array {
        $desc = (string) ($requestDescription ?? '');
        $name = '';
        if (preg_match('/ADD chamber "([^"]+)"/i', $desc, $nameMatch)) {
            $name = trim($nameMatch[1]);
        }
        if ($name === '') {
            return ['ok' => false, 'reason' => 'missing_name'];
        }

        $remark = '';
        if (preg_match('/Remark:\s*(.+)$/i', $desc, $remarkMatch)) {
            $remark = trim($remarkMatch[1]);
        }

        // expectedId check retained for parity (legacy / hash drift still allowed)
        PermissionService::chamberAddPermissionId($name);

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT chamber_limit, warehouse_name FROM do_operators WHERE email = ? LIMIT 1');
        $stmt->execute([$operatorEmail]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $limit = (int) ($userRow['chamber_limit'] ?? 4);
        if ($limit < 1) {
            $limit = 4;
        }
        $warehouseName = trim((string) ($userRow['warehouse_name'] ?? '')) ?: null;

        $chamberId = null;
        $dupStmt = $pdo->prepare('SELECT id, name, warehouse_name FROM chambers WHERE name = ? LIMIT 1');
        $dupStmt->execute([$name]);
        $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
        if ($dup) {
            $chamberId = $dup['id'];
            if ($warehouseName && trim((string) ($dup['warehouse_name'] ?? '')) === '') {
                $pdo->prepare('UPDATE chambers SET warehouse_name = ? WHERE id = ?')
                    ->execute([$warehouseName, $chamberId]);
            }
        } else {
            $ins = $pdo->prepare('INSERT INTO chambers (name, warehouse_name) VALUES (?, ?)');
            $ins->execute([$name, $warehouseName]);
            $chamberId = (int) $pdo->lastInsertId();
        }

        $all = $pdo->query('SELECT id, name FROM chambers ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $picked = PermissionService::pickDoChambers($all, $limit);
        $included = false;
        foreach ($picked as $c) {
            if ((int) ($c['id'] ?? 0) === (int) $chamberId) {
                $included = true;
                break;
            }
        }
        if (!$included) {
            $newLimit = min(50, max($limit + 1, count($picked) + 1));
            while ($newLimit <= 50) {
                $probe = PermissionService::pickDoChambers($all, $newLimit);
                $found = false;
                foreach ($probe as $c) {
                    if ((int) ($c['id'] ?? 0) === (int) $chamberId) {
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    break;
                }
                $newLimit += 1;
            }
            $pdo->prepare('UPDATE do_operators SET chamber_limit = ? WHERE email = ?')
                ->execute([$newLimit, $operatorEmail]);
            $limit = $newLimit;
        }

        try {
            $remarkSuffix = $remark !== '' ? ". Remark: {$remark}" : '';
            ActivityLogger::log(
                $operatorEmail,
                'ADD_CHAMBER',
                'Chamber Master',
                "Super Admin approved add of chamber \"{$name}\" (id: {$chamberId}) for {$operatorEmail}{$remarkSuffix}. Limit now {$limit}."
            );
        } catch (\Throwable) {
        }

        return [
            'ok' => true,
            'id' => $chamberId,
            'name' => $name,
            'chamber_limit' => $limit,
            'remark' => $remark,
        ];
    }

    /**
     * @return array{ok:bool, reason?:string, id?:int, name?:string, chamber_type?:string, old_type?:mixed}
     */
    public static function applyApprovedChamberTypeChange(?string $requestDescription, $recordId): array
    {
        $desc = (string) ($requestDescription ?? '');
        $nextRaw = '';
        if (preg_match('/from\s+([A-Za-z]+)\s+to\s+([A-Za-z]+)/i', $desc, $fromTo)) {
            $nextRaw = trim($fromTo[2]);
        }
        $allowed = ['Frozen', 'Chilled', 'Dry', 'Other'];
        $nextType = null;
        foreach ($allowed as $t) {
            if (strcasecmp($t, $nextRaw) === 0) {
                $nextType = $t;
                break;
            }
        }
        $id = (int) $recordId;
        if ($nextType === null || $id < 1) {
            return ['ok' => false, 'reason' => 'missing_type'];
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, name, chamber_type FROM chambers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $pdo->prepare('UPDATE chambers SET chamber_type = ? WHERE id = ?')->execute([$nextType, $id]);
        try {
            $pdo->prepare(
                "UPDATE chamber_client_assignments SET chamber_type = ? WHERE chamber_id = ? AND status = 'active'"
            )->execute([$nextType, $id]);
        } catch (\Throwable) {
        }

        $remark = '';
        if (preg_match('/Remark:\s*(.+)$/i', $desc, $remarkMatch)) {
            $remark = trim($remarkMatch[1]);
        }
        try {
            $remarkSuffix = $remark !== '' ? ". Remark: {$remark}" : '';
            ActivityLogger::log(
                'system',
                'UPDATE_CHAMBER_ZONE',
                'DO_CHANGE',
                "Super Admin approved chamber type of \"{$row['name']}\" to \"{$nextType}\"{$remarkSuffix}.",
                $id
            );
        } catch (\Throwable) {
        }

        return [
            'ok' => true,
            'id' => $id,
            'name' => $row['name'],
            'chamber_type' => $nextType,
            'old_type' => $row['chamber_type'],
        ];
    }

    /**
     * @return array{ok:bool, reason?:string, action?:string, chamber_id?:int, chamber_name?:string, client_name?:string, chamber_type?:string, old_name?:string, record_id?:mixed}
     */
    public static function applyApprovedClientMasterChange(
        string $operatorEmail,
        ?string $requestDescription,
        $recordId,
        bool $isDelete
    ): array {
        $desc = (string) ($requestDescription ?? '');
        $remark = null;
        if (preg_match('/Remark:\s*(.+)$/i', $desc, $remarkMatch)) {
            $remark = trim($remarkMatch[1]) ?: null;
        }

        $warehouse_name = null;
        $warehouse_code = null;
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT warehouse_name, warehouse_code FROM do_operators WHERE email = ? LIMIT 1');
            $stmt->execute([$operatorEmail]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($userRow) {
                $warehouse_name = $userRow['warehouse_name'] ?? null;
                $warehouse_code = $userRow['warehouse_code'] ?? null;
            }
        } catch (\Throwable) {
        }

        $resolvedWarehouse = MasterResolver::resolveWarehouseByCodeOrName(
            $warehouse_code !== null ? (string) $warehouse_code : null,
            $warehouse_name !== null ? (string) $warehouse_name : null
        );
        if ($resolvedWarehouse) {
            $warehouse_code = $resolvedWarehouse['warehouse_code'] ?? $warehouse_code;
            $warehouse_name = $resolvedWarehouse['warehouse_name'] ?? $warehouse_name;
        }

        $addMatch = null;
        $delMatch = null;
        $editMatch = null;
        if (preg_match(
            '/allow to ADD client "([^"]+)"\s*\(([^)]*)\)\s*on chamber "([^"]+)"\s*\(id:\s*(\d+)\)/i',
            $desc,
            $m
        )) {
            $addMatch = $m;
        }
        if (preg_match(
            '/allow to DELETE client "([^"]+)" from chamber "([^"]+)"\s*\(id:\s*(\d+)\)/i',
            $desc,
            $m
        )) {
            $delMatch = $m;
        }
        if (preg_match(
            '/allow to EDIT client "([^"]+)"\s*(?:→|->)\s*"([^"]+)" on chamber "([^"]+)"\s*\(id:\s*(\d+)\)/i',
            $desc,
            $m
        )) {
            $editMatch = $m;
        }

        $pdo = Database::pdo();

        if (!$isDelete && $addMatch) {
            $clientName = $addMatch[1];
            $chamberType = $addMatch[2];
            $chamberName = $addMatch[3];
            $chamberId = (int) $addMatch[4];
            // expectedId check retained for parity (legacy / hash drift still allowed)
            PermissionService::clientMasterPermissionId($chamberId, 'add', $clientName);

            $resolvedClient = MasterResolver::resolveClientByCodeOrName(
                null,
                $clientName,
                $warehouse_name !== null ? (string) $warehouse_name : null
            );
            $finalClientName = $resolvedClient['client_name'] ?? $clientName;
            $finalClientCode = $resolvedClient['client_code'] ?? null;

            $chStmt = $pdo->prepare('SELECT id, name, chamber_type FROM chambers WHERE id = ? LIMIT 1');
            $chStmt->execute([$chamberId]);
            $chRows = $chStmt->fetch(PDO::FETCH_ASSOC);
            $resolvedType = trim((string) ($chRows['chamber_type'] ?? ''))
                ?: trim((string) $chamberType)
                ?: 'Frozen';

            $pdo->prepare(
                "INSERT INTO chamber_client_assignments
                 (chamber_id, client_name, client_code, warehouse_name, warehouse_code, remark, chamber_type, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
                 ON DUPLICATE KEY UPDATE
                   client_code = VALUES(client_code),
                   warehouse_code = VALUES(warehouse_code),
                   remark = VALUES(remark),
                   chamber_type = VALUES(chamber_type),
                   status = 'active'"
            )->execute([
                $chamberId,
                $finalClientName,
                $finalClientCode,
                $warehouse_name,
                $warehouse_code,
                $remark,
                $resolvedType,
            ]);

            return [
                'ok' => true,
                'action' => 'add',
                'chamber_id' => $chamberId,
                'chamber_name' => $chRows['name'] ?? $chamberName,
                'client_name' => $finalClientName,
                'chamber_type' => $resolvedType,
            ];
        }

        if ($isDelete && $delMatch) {
            $clientName = $delMatch[1];
            $chamberName = $delMatch[2];
            $chamberId = (int) $delMatch[3];
            $wc = $warehouse_code !== null && trim((string) $warehouse_code) !== ''
                ? (string) $warehouse_code
                : null;
            $wn = $warehouse_name;

            $pdo->prepare(
                "UPDATE chamber_client_assignments
                 SET status = 'inactive', remark = ?
                 WHERE chamber_id = ?
                   AND LOWER(TRIM(client_name)) = LOWER(TRIM(?))
                   AND (
                     (? IS NOT NULL AND TRIM(?) <> '' AND warehouse_code = ?)
                     OR (? IS NULL OR TRIM(?) = '')
                       AND (LOWER(TRIM(COALESCE(warehouse_name, ''))) = LOWER(TRIM(COALESCE(?, ''))) OR warehouse_name IS NULL OR warehouse_name = '')
                   )"
            )->execute([
                $remark ?? '',
                $chamberId,
                $clientName,
                $wc,
                $wc ?? '',
                $wc,
                $wc,
                $wc ?? '',
                $wn,
            ]);

            return [
                'ok' => true,
                'action' => 'delete',
                'chamber_id' => $chamberId,
                'chamber_name' => $chamberName,
                'client_name' => $clientName,
            ];
        }

        if (!$isDelete && $editMatch) {
            $oldName = $editMatch[1];
            $newName = $editMatch[2];
            $chamberName = $editMatch[3];
            $chamberId = (int) $editMatch[4];

            $resolvedClient = MasterResolver::resolveClientByCodeOrName(
                null,
                $newName,
                $warehouse_name !== null ? (string) $warehouse_name : null
            );
            $finalNewName = $resolvedClient['client_name'] ?? $newName;
            $finalClientCode = $resolvedClient['client_code'] ?? null;

            $wc = $warehouse_code !== null && trim((string) $warehouse_code) !== ''
                ? (string) $warehouse_code
                : null;
            $wn = $warehouse_name;

            $pdo->prepare(
                "UPDATE chamber_client_assignments
                 SET client_name = ?, client_code = COALESCE(?, client_code), remark = ?
                 WHERE chamber_id = ?
                   AND LOWER(TRIM(client_name)) = LOWER(TRIM(?))
                   AND status = 'active'
                   AND (
                     (? IS NOT NULL AND TRIM(?) <> '' AND warehouse_code = ?)
                     OR (? IS NULL OR TRIM(?) = '')
                       AND (LOWER(TRIM(COALESCE(warehouse_name, ''))) = LOWER(TRIM(COALESCE(?, ''))) OR warehouse_name IS NULL OR warehouse_name = '')
                   )"
            )->execute([
                $finalNewName,
                $finalClientCode,
                $remark,
                $chamberId,
                $oldName,
                $wc,
                $wc ?? '',
                $wc,
                $wc,
                $wc ?? '',
                $wn,
            ]);

            return [
                'ok' => true,
                'action' => 'edit',
                'chamber_id' => $chamberId,
                'chamber_name' => $chamberName,
                'client_name' => $finalNewName,
                'old_name' => $oldName,
            ];
        }

        return ['ok' => false, 'reason' => 'unparsed_request', 'record_id' => $recordId];
    }
}
