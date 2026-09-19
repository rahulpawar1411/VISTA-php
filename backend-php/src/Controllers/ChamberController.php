<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Multipart;
use App\Http\Request;
use App\Http\Response;
use App\Services\ActivityLogger;
use App\Services\DateTimeUtil;
use App\Services\FileUpload;
use App\Services\LogAttribution;
use App\Services\MasterResolver;
use App\Services\PermissionService;
use PDO;

/**
 * Operational chambers + assignments + native inspections.
 * DO permission gates for create/assignment → Phase 6 (SA/Sub Admin work now).
 */
final class ChamberController
{
    public static function ensureNumberedChambers(int $limit): int
    {
        $n = max(1, min($limit ?: 4, 500));
        $pdo = Database::pdo();
        for ($i = 1; $i <= $n; $i++) {
            $name = 'Chamber ' . $i;
            $stmt = $pdo->prepare('SELECT id FROM chambers WHERE name = ? LIMIT 1');
            $stmt->execute([$name]);
            if (!$stmt->fetch()) {
                $ins = $pdo->prepare('INSERT INTO chambers (name) VALUES (?)');
                $ins->execute([$name]);
            }
        }
        return $n;
    }

    private static function chamberNumberFromName(?string $name): ?int
    {
        if (preg_match('/^Chamber\s+(\d+)$/i', (string) $name, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /** @param list<array<string,mixed>> $allRows */
    private static function pickDoChambers(array $allRows, int $limit): array
    {
        return PermissionService::pickDoChambers($allRows, $limit);
    }

    private static function resolveChamberForAssignment($chamberId): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, name, chamber_type FROM chambers WHERE id = ? LIMIT 1');
        $stmt->execute([$chamberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $stmt = $pdo->prepare(
            'SELECT id, name, chamber_type FROM chambers
             WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) OR LOWER(TRIM(name)) = LOWER(TRIM(?))
             LIMIT 1'
        );
        $stmt->execute(['Chamber ' . $chamberId, (string) $chamberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function normalizeAssignmentStatus($raw): string
    {
        $s = strtolower(trim((string) ($raw ?: 'active')));
        if (in_array($s, ['inactive', 'deactive', 'deactivated', 'disabled', '0', 'false'], true)) {
            return 'inactive';
        }
        return 'active';
    }

    public static function getChambers(Request $req, array $params = []): void
    {
        try {
            $pdo = Database::pdo();
            $user = $req->user;
            $filteredRows = [];
            $appliedLimit = null;

            if ($user && ($user['role'] ?? '') === 'do_operator') {
                $limit = 4;
                $warehouseName = trim((string) ($user['warehouse_name'] ?? ''));
                $stmt = $pdo->prepare('SELECT chamber_limit, warehouse_name FROM do_operators WHERE email = ? LIMIT 1');
                $stmt->execute([$user['email']]);
                $urow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($urow) {
                    $limit = (int) ($urow['chamber_limit'] ?: 4);
                    if (!empty($urow['warehouse_name'])) {
                        $warehouseName = trim((string) $urow['warehouse_name']);
                    }
                }
                if ($limit < 1) {
                    $limit = 4;
                }
                $appliedLimit = $limit;

                $warehouseChambers = [];
                if ($warehouseName !== '') {
                    $stmt = $pdo->prepare(
                        'SELECT DISTINCT c.id, c.name, c.chamber_type
                         FROM chambers c
                         WHERE LOWER(TRIM(IFNULL(c.warehouse_name, \'\'))) = LOWER(TRIM(?))
                            OR c.id IN (
                              SELECT cca.chamber_id FROM chamber_client_assignments cca
                              WHERE LOWER(TRIM(cca.warehouse_name)) = LOWER(TRIM(?))
                                AND (cca.status IS NULL OR cca.status = \'active\')
                            )
                         ORDER BY c.name ASC'
                    );
                    $stmt->execute([$warehouseName, $warehouseName]);
                    $warehouseChambers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }

                if ($warehouseChambers) {
                    $stmt = $pdo->prepare(
                        'SELECT id FROM chambers WHERE LOWER(TRIM(IFNULL(warehouse_name, \'\'))) = LOWER(TRIM(?))'
                    );
                    $stmt->execute([$warehouseName]);
                    $ownedIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
                    $owned = array_values(array_filter($warehouseChambers, static fn($c) => in_array((int) $c['id'], $ownedIds, true)));
                    $rest = array_values(array_filter($warehouseChambers, static fn($c) => !in_array((int) $c['id'], $ownedIds, true)));
                    $maxKeep = max($limit, count($owned));
                    $filteredRows = array_slice(array_merge($owned, $rest), 0, $maxKeep);
                } else {
                    $all = $pdo->query('SELECT id, name, chamber_type FROM chambers ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
                    $picked = self::pickDoChambers($all, $limit);
                    $filteredRows = array_values(array_filter($picked, static fn($c) => self::chamberNumberFromName($c['name'] ?? null) !== null));
                    if (!$filteredRows) {
                        self::ensureNumberedChambers($limit);
                        $all = $pdo->query('SELECT id, name, chamber_type FROM chambers ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
                        $filteredRows = array_values(array_filter(
                            self::pickDoChambers($all, $limit),
                            static fn($c) => self::chamberNumberFromName($c['name'] ?? null) !== null
                        ));
                    }
                }
            } else {
                $filteredRows = $pdo->query(
                    'SELECT id, name, chamber_type, warehouse_name FROM chambers ORDER BY name ASC'
                )->fetchAll(PDO::FETCH_ASSOC);
            }

            Response::json([
                'success' => true,
                'data' => $filteredRows,
                'chamber_limit' => $appliedLimit,
            ]);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to fetch chambers.');
        }
    }

    public static function getAssignments(Request $req, array $params = []): void
    {
        try {
            $pdo = Database::pdo();
            $user = $req->user;
            $isSpecial = $user && in_array($user['role'] ?? '', ['super_admin', 'sub_admin'], true);
            $warehouse_name = $isSpecial
                ? ($req->query('warehouse_name') ?: null)
                : ($user['warehouse_name'] ?? null);
            $includeInactive = $isSpecial;

            if ($warehouse_name === null && $isSpecial) {
                $sql = 'SELECT cca.chamber_id, c.name AS chamber_name, cca.client_name, cca.client_code, cca.warehouse_name, cca.warehouse_code,
                        COALESCE(NULLIF(TRIM(c.chamber_type), \'\'), NULLIF(TRIM(cca.chamber_type), \'\'), \'Frozen\') AS chamber_type,
                        COALESCE(NULLIF(TRIM(cca.status), \'\'), \'active\') AS status
                 FROM chamber_client_assignments cca
                 JOIN chambers c ON cca.chamber_id = c.id
                 WHERE 1=1 ' . ($includeInactive ? '' : "AND cca.status = 'active'") . '
                 ORDER BY COALESCE(cca.warehouse_name, \'\'), c.name ASC, cca.client_name ASC';
                $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $sql = 'SELECT cca.chamber_id, c.name AS chamber_name, cca.client_name, cca.client_code, cca.warehouse_name, cca.warehouse_code,
                        COALESCE(NULLIF(TRIM(c.chamber_type), \'\'), NULLIF(TRIM(cca.chamber_type), \'\'), \'Frozen\') AS chamber_type,
                        COALESCE(NULLIF(TRIM(cca.status), \'\'), \'active\') AS status
                 FROM chamber_client_assignments cca
                 JOIN chambers c ON cca.chamber_id = c.id
                 WHERE (
                   (? IS NULL AND (cca.warehouse_name IS NULL OR cca.warehouse_name = \'\'))
                   OR LOWER(TRIM(cca.warehouse_name)) = LOWER(TRIM(?))
                   OR (
                     (cca.warehouse_name IS NULL OR cca.warehouse_name = \'\')
                     AND NOT EXISTS (
                       SELECT 1 FROM chamber_client_assignments x
                       WHERE x.chamber_id = cca.chamber_id
                         AND LOWER(TRIM(x.client_name)) = LOWER(TRIM(cca.client_name))
                         AND LOWER(TRIM(x.warehouse_name)) = LOWER(TRIM(?))
                     )
                   )
                 ) ' . ($includeInactive ? '' : "AND cca.status = 'active'") . '
                 ORDER BY c.name ASC, cca.client_name ASC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$warehouse_name, $warehouse_name, $warehouse_name]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $filterWh = strtolower(trim((string) ($warehouse_name ?? '')));
            $seen = [];
            foreach ($rows ?: [] as $row) {
                $clientKey = strtolower(trim((string) ($row['client_name'] ?? '')));
                if ($clientKey === '') {
                    continue;
                }
                $status = self::normalizeAssignmentStatus($row['status'] ?? null);
                $key = $row['chamber_id'] . '|' . $clientKey;
                $wh = strtolower(trim((string) ($row['warehouse_name'] ?? '')));
                $exactWh = $filterWh !== '' ? ($wh === $filterWh ? 2 : ($wh !== '' ? 1 : 0)) : ($wh !== '' ? 1 : 0);
                $rank = $exactWh * 10 + ($status === 'inactive' ? 1 : 0);
                $next = array_merge($row, ['status' => $status, '_rank' => $rank]);
                if (!isset($seen[$key]) || $rank >= ($seen[$key]['_rank'] ?? 0)) {
                    $seen[$key] = $next;
                }
            }
            $deduped = array_map(static function ($v) {
                unset($v['_rank']);
                return $v;
            }, array_values($seen));

            if ($user && ($user['role'] ?? '') === 'do_operator') {
                $limit = (int) ($user['chamber_limit'] ?? 4) ?: 4;
                $stmt = $pdo->prepare('SELECT chamber_limit FROM do_operators WHERE email = ? LIMIT 1');
                $stmt->execute([$user['email']]);
                $urow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($urow) {
                    $limit = (int) ($urow['chamber_limit'] ?: 4);
                }
                $deduped = array_values(array_filter($deduped, static function ($row) use ($limit) {
                    $n = self::chamberNumberFromName($row['chamber_name'] ?? null);
                    return $n === null || $n <= $limit;
                }));
            }

            Response::json(['success' => true, 'data' => $deduped]);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to fetch chamber client assignments.');
        }
    }

    public static function addAssignment(Request $req, array $params = []): void
    {
        try {
            Multipart::mergeInto($req);
            $body = $req->body();
            $user = $req->user;
            $isSpecial = $user && in_array($user['role'] ?? '', ['super_admin', 'sub_admin'], true);
            $chamber_id = $body['chamber_id'] ?? null;
            $client_name = $body['client_name'] ?? null;
            $bodyClientCode = $body['client_code'] ?? null;
            $remark = $body['remark'] ?? null;
            $chamber_type = $body['chamber_type'] ?? null;

            $warehouse_name = $isSpecial ? ($body['warehouse_name'] ?? null) : ($user['warehouse_name'] ?? null);
            $warehouse_code = $isSpecial ? ($body['warehouse_code'] ?? null) : null;

            if (!$chamber_id || (!$client_name && !$bodyClientCode)) {
                Response::error('Chamber ID and Client Name (or Client Code) are required.', 400);
                return;
            }

            $resolvedWarehouse = MasterResolver::resolveWarehouseByCodeOrName($warehouse_code, $warehouse_name);
            if ($resolvedWarehouse) {
                $warehouse_code = $resolvedWarehouse['warehouse_code'];
                $warehouse_name = $resolvedWarehouse['warehouse_name'];
            }

            $resolvedClient = MasterResolver::resolveClientByCodeOrName($bodyClientCode, $client_name, $warehouse_name);
            $finalClientName = $resolvedClient['client_name'] ?? $client_name;
            $finalClientCode = $resolvedClient['client_code'] ?? (trim((string) $bodyClientCode) ?: null);

            $chamberRow = self::resolveChamberForAssignment($chamber_id);
            if (!$chamberRow) {
                Response::error('Chamber not found.', 404);
                return;
            }
            $resolvedChamberId = (int) $chamberRow['id'];
            $chamber_name = $chamberRow['name'];
            $resolvedType = trim((string) ($chamberRow['chamber_type'] ?? '')) ?: (trim((string) $chamber_type) ?: 'Frozen');

            $pdo = Database::pdo();

            if (($user['role'] ?? '') === 'do_operator' && $finalClientName) {
                $stmt = $pdo->prepare(
                    'SELECT chamber_id FROM chamber_client_assignments
                     WHERE chamber_id = ?
                       AND LOWER(TRIM(client_name)) = LOWER(TRIM(?))
                       AND (status IS NULL OR status = \'active\')
                       AND (
                         (? IS NOT NULL AND TRIM(?) <> \'\' AND LOWER(TRIM(COALESCE(warehouse_name, \'\'))) = LOWER(TRIM(?)))
                         OR ((? IS NULL OR TRIM(?) = \'\') AND (warehouse_name IS NULL OR warehouse_name = \'\'))
                       )
                     LIMIT 1'
                );
                $wn = $warehouse_name ?: null;
                $stmt->execute([$resolvedChamberId, $finalClientName, $wn, $wn ?: '', $wn ?: '', $wn, $wn ?: '']);
                if ($stmt->fetch()) {
                    Response::json([
                        'success' => true,
                        'message' => 'Assignment already active.',
                        'chamber_type' => $resolvedType,
                        'chamber_name' => $chamber_name,
                        'already_exists' => true,
                    ]);
                    return;
                }
                $nameForPerm = (string) ($finalClientName ?: $client_name ?: $bodyClientCode ?: '');
                $permIds = [
                    PermissionService::clientMasterPermissionId($resolvedChamberId, 'add', $nameForPerm),
                    PermissionService::clientMasterPermissionId($chamber_id, 'add', $nameForPerm),
                ];
                $allowed = false;
                foreach (array_unique($permIds) as $permId) {
                    if (PermissionService::hasActivePermission((string) $user['email'], 'ClientMaster', $permId, 'Edit')) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) {
                    Response::error('Super Admin approval is required before adding this client.', 403);
                    return;
                }
            }

            $ins = $pdo->prepare(
                'INSERT INTO chamber_client_assignments
                 (chamber_id, client_name, client_code, warehouse_name, warehouse_code, remark, chamber_type, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, \'active\')
                 ON DUPLICATE KEY UPDATE
                   client_code = VALUES(client_code),
                   warehouse_code = VALUES(warehouse_code),
                   remark = VALUES(remark),
                   chamber_type = VALUES(chamber_type),
                   status = \'active\''
            );
            $ins->execute([
                $resolvedChamberId,
                $finalClientName,
                $finalClientCode,
                $warehouse_name,
                $warehouse_code,
                $remark ?: null,
                $resolvedType,
            ]);

            if (($user['role'] ?? '') === 'do_operator') {
                $nameForPerm = (string) ($finalClientName ?: $client_name ?: $bodyClientCode ?: '');
                foreach ([
                    PermissionService::clientMasterPermissionId($resolvedChamberId, 'add', $nameForPerm),
                    PermissionService::clientMasterPermissionId($chamber_id, 'add', $nameForPerm),
                ] as $permId) {
                    PermissionService::consumeGrantedPermission((string) $user['email'], 'ClientMaster', $permId, 'Edit');
                }
            }

            $skipActivity = !empty($body['skip_activity']);
            if (!$skipActivity) {
                $activityEmail = $isSpecial && !empty($body['operator_email'])
                    ? strtolower(trim((string) $body['operator_email']))
                    : (string) ($user['email'] ?? 'system');
                $whLabel = $warehouse_name ? " (Warehouse: {$warehouse_name})" : '';
                ActivityLogger::log(
                    $activityEmail,
                    'ADD_CLIENT',
                    'DO_CHANGE',
                    "Admin{$whLabel} Added client \"{$finalClientName}\"" . ($finalClientCode ? " [{$finalClientCode}]" : '') . " to {$chamber_name}. Remark: " . (trim((string) $remark) ?: 'None')
                );
            }

            Response::json([
                'success' => true,
                'message' => 'Assignment added successfully.',
                'chamber_type' => $resolvedType,
                'chamber_name' => $chamber_name,
            ], 201);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to add assignment.');
        }
    }

    public static function deleteAssignment(Request $req, array $params = []): void
    {
        try {
            Multipart::mergeInto($req);
            $body = $req->body();
            $user = $req->user;
            $isSpecial = $user && in_array($user['role'] ?? '', ['super_admin', 'sub_admin'], true);
            $chamber_id = $body['chamber_id'] ?? $req->query('chamber_id');
            $client_name = $body['client_name'] ?? $req->query('client_name');
            $warehouse_name = $isSpecial
                ? ($body['warehouse_name'] ?? $req->query('warehouse_name'))
                : ($user['warehouse_name'] ?? null);

            if (!$chamber_id || !$client_name) {
                Response::error('Chamber ID and Client Name are required.', 400);
                return;
            }

            if (($user['role'] ?? '') === 'do_operator') {
                Response::error('Super Admin approval is required before removing this client.', 403);
                return;
            }

            $chamberRow = self::resolveChamberForAssignment($chamber_id);
            $resolvedChamberId = $chamberRow ? (int) $chamberRow['id'] : (int) $chamber_id;
            $pdo = Database::pdo();

            if ($warehouse_name) {
                $stmt = $pdo->prepare(
                    'UPDATE chamber_client_assignments SET status = \'inactive\'
                     WHERE chamber_id = ? AND LOWER(TRIM(client_name)) = LOWER(TRIM(?))
                       AND LOWER(TRIM(COALESCE(warehouse_name, \'\'))) = LOWER(TRIM(?))'
                );
                $stmt->execute([$resolvedChamberId, $client_name, $warehouse_name]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE chamber_client_assignments SET status = \'inactive\'
                     WHERE chamber_id = ? AND LOWER(TRIM(client_name)) = LOWER(TRIM(?))'
                );
                $stmt->execute([$resolvedChamberId, $client_name]);
            }

            ActivityLogger::log(
                (string) ($user['email'] ?? 'system'),
                'REMOVE_CLIENT',
                'DO_CHANGE',
                'Removed client "' . $client_name . '" from chamber id ' . $resolvedChamberId
            );

            Response::json(['success' => true, 'message' => 'Assignment removed successfully.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to remove assignment.');
        }
    }

    public static function createChamber(Request $req, array $params = []): void
    {
        try {
            $body = $req->body();
            $user = $req->user;
            $name = trim((string) ($body['name'] ?? ''));
            $chamber_type = trim((string) ($body['chamber_type'] ?? 'Frozen')) ?: 'Frozen';
            $warehouse_name = $body['warehouse_name'] ?? null;

            if ($name === '') {
                Response::error('Chamber name is required.', 400);
                return;
            }

            if (($user['role'] ?? '') === 'do_operator') {
                $name = trim((string) ($body['name'] ?? ''));
                if ($name === '') {
                    Response::error('Chamber name is required.', 400);
                    return;
                }

                $pdo = Database::pdo();
                $limit = 4;
                $warehouseName = trim((string) ($user['warehouse_name'] ?? '')) ?: null;
                try {
                    $uStmt = $pdo->prepare(
                        'SELECT chamber_limit, warehouse_name FROM do_operators WHERE email = ? LIMIT 1'
                    );
                    $uStmt->execute([(string) $user['email']]);
                    $userRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($userRow) {
                        $limit = (int) ($userRow['chamber_limit'] ?? 4);
                        if (!empty($userRow['warehouse_name'])) {
                            $warehouseName = trim((string) $userRow['warehouse_name']) ?: null;
                        }
                    }
                } catch (\Throwable) {
                    $limit = (int) ($user['chamber_limit'] ?? 4);
                }
                if ($limit < 1) {
                    $limit = 4;
                }

                $permId = PermissionService::chamberAddPermissionId($name);
                if (!PermissionService::hasActivePermission((string) $user['email'], 'ChamberMaster', $permId, 'Edit')) {
                    Response::error(
                        'Super Admin approval is required to add this chamber. Request permission from the app first.',
                        403
                    );
                    return;
                }

                $allStmt = $pdo->query(
                    'SELECT id, name, chamber_type, warehouse_name FROM chambers ORDER BY id ASC'
                );
                $existing = $allStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $existingByName = null;
                foreach ($existing as $c) {
                    if (strcasecmp(trim((string) ($c['name'] ?? '')), $name) === 0) {
                        $existingByName = $c;
                        break;
                    }
                }

                $ensureIncluded = static function ($chamberId) use ($pdo, &$existing, &$limit, $user): int {
                    $picked = PermissionService::pickDoChambers($existing, $limit);
                    foreach ($picked as $c) {
                        if ((int) ($c['id'] ?? 0) === (int) $chamberId) {
                            return $limit;
                        }
                    }
                    $newLimit = $limit + 1;
                    while ($newLimit <= 500) {
                        $probe = PermissionService::pickDoChambers($existing, $newLimit);
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
                        ->execute([$newLimit, (string) $user['email']]);
                    $limit = $newLimit;
                    return $limit;
                };

                $bindWarehouse = static function ($chamberId) use ($pdo, $warehouseName): void {
                    if (!$warehouseName || !$chamberId) {
                        return;
                    }
                    $pdo->prepare(
                        "UPDATE chambers
                         SET warehouse_name = COALESCE(NULLIF(TRIM(warehouse_name), ''), ?)
                         WHERE id = ?"
                    )->execute([$warehouseName, $chamberId]);
                };

                $chamber_type = trim((string) ($body['chamber_type'] ?? 'Frozen')) ?: 'Frozen';
                $remark = trim((string) ($body['remark'] ?? ''));

                if ($existingByName) {
                    $bindWarehouse($existingByName['id']);
                    $ensureIncluded($existingByName['id']);
                    try {
                        PermissionService::consumeGrantedPermission(
                            (string) $user['email'],
                            'ChamberMaster',
                            $permId,
                            'Edit'
                        );
                    } catch (\Throwable) {
                    }
                    Response::json([
                        'success' => true,
                        'message' => 'Chamber already assigned.',
                        'data' => [
                            'id' => $existingByName['id'],
                            'name' => $existingByName['name'],
                            'chamber_type' => $existingByName['chamber_type'],
                            'warehouse_name' => $warehouseName ?: ($existingByName['warehouse_name'] ?? null),
                        ],
                        'chamber_limit' => $limit,
                    ]);
                    return;
                }

                // New name — bump limit first so pickDoChambers can include custom after insert
                $current = PermissionService::pickDoChambers($existing, $limit);
                if (count($current) >= $limit) {
                    $newLimit = count($current) + 1;
                    try {
                        $pdo->prepare('UPDATE do_operators SET chamber_limit = ? WHERE email = ?')
                            ->execute([$newLimit, (string) $user['email']]);
                        $limit = $newLimit;
                    } catch (\Throwable) {
                    }
                }

                $ins = $pdo->prepare(
                    'INSERT INTO chambers (name, chamber_type, warehouse_name) VALUES (?, ?, ?)'
                );
                $ins->execute([$name, $chamber_type, $warehouseName]);
                $id = (int) $pdo->lastInsertId();
                $existing[] = [
                    'id' => $id,
                    'name' => $name,
                    'chamber_type' => $chamber_type,
                    'warehouse_name' => $warehouseName,
                ];
                $ensureIncluded($id);

                try {
                    PermissionService::consumeGrantedPermission(
                        (string) $user['email'],
                        'ChamberMaster',
                        $permId,
                        'Edit'
                    );
                } catch (\Throwable) {
                }

                $actorLabel = (string) ($user['full_name'] ?? $user['email'] ?? 'System');
                ActivityLogger::log(
                    (string) $user['email'],
                    'ADD_CHAMBER',
                    'DO_CHANGE',
                    $actorLabel . ' created chamber "' . $name . '" (id: ' . $id . ')'
                    . ($remark !== '' ? '. Remark: ' . $remark : '') . '.',
                    $id
                );

                Response::json([
                    'success' => true,
                    'message' => 'Chamber created successfully.',
                    'data' => [
                        'id' => $id,
                        'name' => $name,
                        'chamber_type' => $chamber_type,
                        'warehouse_name' => $warehouseName,
                    ],
                    'chamber_limit' => $limit,
                ], 201);
                return;
            }

            $name = trim((string) ($body['name'] ?? ''));
            $chamber_type = trim((string) ($body['chamber_type'] ?? 'Frozen')) ?: 'Frozen';
            $warehouse_name = $body['warehouse_name'] ?? null;

            if ($name === '') {
                Response::error('Chamber name is required.', 400);
                return;
            }

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT id, name, chamber_type, warehouse_name FROM chambers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
            $stmt->execute([$name]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                Response::json([
                    'success' => true,
                    'message' => 'Chamber already exists.',
                    'data' => $existing,
                ]);
                return;
            }

            $ins = $pdo->prepare('INSERT INTO chambers (name, chamber_type, warehouse_name) VALUES (?, ?, ?)');
            $ins->execute([$name, $chamber_type, $warehouse_name ? trim((string) $warehouse_name) : null]);
            $id = (int) $pdo->lastInsertId();

            ActivityLogger::log(
                (string) ($user['email'] ?? 'system'),
                'ADD_CHAMBER',
                'DO_CHANGE',
                'Created chamber "' . $name . '" (id: ' . $id . ')'
            );

            Response::json([
                'success' => true,
                'message' => 'Chamber created successfully.',
                'data' => [
                    'id' => $id,
                    'name' => $name,
                    'chamber_type' => $chamber_type,
                    'warehouse_name' => $warehouse_name,
                ],
            ], 201);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate') !== false) {
                Response::error('Chamber name already exists.', 409);
                return;
            }
            self::fail($e, 'Failed to create chamber.');
        }
    }

    public static function updateChamber(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $body = $req->body();
            $user = $req->user;
            if (($user['role'] ?? '') === 'do_operator') {
                Response::error('Super Admin approval is required to edit this chamber.', 403);
                return;
            }
            $sets = [];
            $bind = [];
            if (isset($body['name'])) {
                $sets[] = 'name = ?';
                $bind[] = trim((string) $body['name']);
            }
            if (isset($body['chamber_type'])) {
                $sets[] = 'chamber_type = ?';
                $bind[] = trim((string) $body['chamber_type']);
            }
            if (array_key_exists('warehouse_name', $body)) {
                $sets[] = 'warehouse_name = ?';
                $wh = trim((string) ($body['warehouse_name'] ?? ''));
                $bind[] = $wh !== '' ? $wh : null;
            }
            if (!$sets) {
                Response::error('No fields to update.', 400);
                return;
            }
            $bind[] = $id;
            $stmt = Database::pdo()->prepare('UPDATE chambers SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($bind);
            Response::json(['success' => true, 'message' => 'Chamber updated successfully.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to update chamber.');
        }
    }

    public static function deleteChamber(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $user = $req->user;
            if (($user['role'] ?? '') === 'do_operator') {
                Response::error('Super Admin approval is required to delete this chamber.', 403);
                return;
            }
            $pdo = Database::pdo();
            $pdo->prepare('DELETE FROM chamber_client_assignments WHERE chamber_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM chambers WHERE id = ?')->execute([$id]);
            Response::json(['success' => true, 'message' => 'Chamber deleted successfully.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to delete chamber.');
        }
    }

    public static function addInspection(Request $req, array $params = []): void
    {
        try {
            Multipart::mergeInto($req);
            $body = $req->body();
            $operator_name = $body['operator_name'] ?? null;
            $chamber_id = $body['chamber_id'] ?? null;
            $client_name = $body['client_name'] ?? null;
            $entry_date = $body['entry_date'] ?? null;
            $entry_time = $body['entry_time'] ?? null;
            $box_temp = $body['box_temp'] ?? null;
            $box_count = $body['box_count'] ?? null;
            $chamber_type = $body['chamber_type'] ?? 'Frozen';
            $overdue_time = $body['overdue_time'] ?? 'same day';
            $bodyCaptureTime = $body['photo_capture_time'] ?? null;
            $created_at = $body['created_at'] ?? null;

            $attr = LogAttribution::resolve($req->user, $body);
            $whFields = MasterResolver::resolveWarehouseFields(
                $body['warehouse_code'] ?? $attr['warehouse_code'],
                $attr['warehouse_name']
            );
            $clFields = MasterResolver::resolveClientFields(
                $body['client_code'] ?? null,
                $client_name,
                $whFields['warehouse_name'],
                $whFields['warehouse_code']
            );
            $resolvedClientName = $clFields['client_name'] ?: $client_name;

            if (!$operator_name || !$chamber_id || (!$client_name && empty($body['client_code'])) || !$entry_date || !$entry_time || $box_temp === null) {
                Response::error('Operator Name, Chamber ID, Client Name, Date, Time, and Temperature are required.', 400);
                return;
            }

            $tempVal = (float) $box_temp;
            $boxCountVal = ($box_count !== null && $box_count !== '') ? (int) $box_count : null;
            if ($boxCountVal !== null && $boxCountVal < 0) {
                Response::error('Box quantity cannot be negative. Enter 0 or a positive count.', 400);
                return;
            }

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT name FROM chambers WHERE id = ? LIMIT 1');
            $stmt->execute([$chamber_id]);
            $ch = $stmt->fetch(PDO::FETCH_ASSOC);
            $chamber_name = $ch['name'] ?? ('Chamber ' . $chamber_id);

            $stmt = $pdo->prepare(
                'SELECT id, reference_no FROM daily_chamber_temp_logs
                 WHERE entry_date = ? AND chamber_name = ? AND client_name = ? AND inspection_time = ? LIMIT 1'
            );
            $stmt->execute([$entry_date, $chamber_name, $resolvedClientName, $entry_time]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                Response::json([
                    'success' => false,
                    'message' => 'Duplicate submission: A temperature log for this client in this chamber has already been recorded today.',
                    'logId' => (int) $existing['id'],
                    'reference_no' => $existing['reference_no'] ?? null,
                ], 409);
                return;
            }

            // Offline sync idempotency
            $submissionId = trim((string) ($body['client_submission_id'] ?? $body['local_id'] ?? ''));
            if ($submissionId !== '') {
                try {
                    $stmt = $pdo->prepare(
                        'SELECT id, reference_no FROM daily_chamber_temp_logs WHERE client_submission_id = ? LIMIT 1'
                    );
                    $stmt->execute([$submissionId]);
                    $dup = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($dup) {
                        Response::json([
                            'success' => true,
                            'message' => 'Already synced.',
                            'id' => (int) $dup['id'],
                            'reference_no' => $dup['reference_no'] ?? null,
                            'already_exists' => true,
                        ]);
                        return;
                    }
                } catch (\Throwable) {
                    /* column may not exist yet */
                }
            }

            $photoUrl = null;
            $photo_capture_time = $bodyCaptureTime;
            $file = Multipart::file('sensor_photo');
            if ($file) {
                $photoUrl = FileUpload::save($file, 'daily_temp_monitor_images', 'sensor-temp', 'sensor_photo');
                if (!$photo_capture_time) {
                    $photo_capture_time = DateTimeUtil::formatDateTime();
                }
            }
            $time_variance_minutes = $photo_capture_time
                ? DateTimeUtil::calculateVariance($entry_date, $entry_time ?: '11:00 AM', $photo_capture_time)
                : 0;

            $localTimestamp = DateTimeUtil::formatDateTime();
            $shiftVal = DateTimeUtil::resolveShift($body['shift'] ?? null, $entry_time);

            $cols = 'entry_date, client_name, client_code, chamber_name, inspection_time, box_temp, monitor_supervisor_name, temp_sensor_image, warehouse_name, warehouse_code, operator_email, is_native, box_count, chamber_type, overdue_time, photo_capture_time, time_variance_minutes, photo_capture_latitude, photo_capture_longitude, photo_capture_accuracy, shift, chamber_id, created_at, updated_at';
            $vals = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
            $bind = [
                $entry_date,
                $resolvedClientName,
                $clFields['client_code'],
                $chamber_name,
                $entry_time,
                $tempVal,
                $operator_name,
                $photoUrl,
                $whFields['warehouse_name'],
                $whFields['warehouse_code'],
                $attr['operator_email'],
                $boxCountVal,
                $chamber_type ?: 'Frozen',
                $overdue_time ?: 'same day',
                $photo_capture_time,
                $time_variance_minutes,
                DateTimeUtil::parseOptionalFloat($body['photo_capture_latitude'] ?? null),
                DateTimeUtil::parseOptionalFloat($body['photo_capture_longitude'] ?? null),
                DateTimeUtil::parseOptionalFloat($body['photo_capture_accuracy'] ?? null),
                $shiftVal,
                $chamber_id ? (int) $chamber_id : null,
                $created_at ?: $localTimestamp,
                $localTimestamp,
            ];

            if ($submissionId !== '') {
                try {
                    $cols .= ', client_submission_id';
                    $vals .= ', ?';
                    $bind[] = $submissionId;
                } catch (\Throwable) {
                }
            }

            $ins = $pdo->prepare("INSERT INTO daily_chamber_temp_logs ({$cols}) VALUES ({$vals})");
            try {
                $ins->execute($bind);
            } catch (\Throwable $e) {
                // Retry without client_submission_id if column missing
                if ($submissionId !== '' && stripos($e->getMessage(), 'client_submission_id') !== false) {
                    $ins = $pdo->prepare(
                        'INSERT INTO daily_chamber_temp_logs
                         (entry_date, client_name, client_code, chamber_name, inspection_time, box_temp, monitor_supervisor_name, temp_sensor_image, warehouse_name, warehouse_code, operator_email, is_native, box_count, chamber_type, overdue_time, photo_capture_time, time_variance_minutes, photo_capture_latitude, photo_capture_longitude, photo_capture_accuracy, shift, chamber_id, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $ins->execute(array_slice($bind, 0, 24));
                } else {
                    throw $e;
                }
            }

            $insertId = (int) $pdo->lastInsertId();
            $reference_no = 'RF-CH-26-' . str_pad((string) $insertId, 4, '0', STR_PAD_LEFT);
            try {
                $pdo->prepare('UPDATE daily_chamber_temp_logs SET reference_no = ? WHERE id = ?')->execute([$reference_no, $insertId]);
            } catch (\Throwable) {
            }

            ActivityLogger::log(
                (string) ($req->user['email'] ?? 'unknown'),
                'CREATE',
                'Chamber Temp Log',
                "Created Native Chamber Temp record (Ref: {$reference_no}) — chamber {$chamber_name}, client {$resolvedClientName}, date {$entry_date}"
            );

            Response::json([
                'success' => true,
                'message' => 'Inspection recorded successfully.',
                'id' => $insertId,
                'reference_no' => $reference_no,
                'temp_sensor_image' => $photoUrl,
                'photo_capture_time' => $photo_capture_time,
                'time_variance_minutes' => $time_variance_minutes,
            ], 201);
        } catch (\Throwable $e) {
            self::fail($e, 'Server error while recording temperature log.');
        }
    }

    public static function getInspections(Request $req, array $params = []): void
    {
        try {
            $rows = Database::pdo()->query(
                "SELECT id, entry_date, client_name, client_code, chamber_name, inspection_time,
                        box_temp AS temperature, box_temp AS chamber_temp,
                        monitor_supervisor_name AS operator_name, monitor_supervisor_name,
                        temp_sensor_image AS photo_url, temp_sensor_image,
                        warehouse_name, warehouse_code, operator_email, reference_no, box_count,
                        chamber_type, overdue_time, photo_capture_time, time_variance_minutes,
                        photo_capture_latitude, photo_capture_longitude, photo_capture_accuracy,
                        created_at, updated_at
                 FROM daily_chamber_temp_logs
                 WHERE is_native = 1
                 ORDER BY entry_date DESC, id DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
            Response::json(['success' => true, 'data' => $rows]);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to fetch inspections.');
        }
    }

    public static function deleteInspection(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $body = $req->body();
            $remarks = trim((string) ($body['remarks'] ?? $req->query('remarks') ?? '')) ?: 'Deleted by Super Admin';
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT reference_no, chamber_name, client_name FROM daily_chamber_temp_logs WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $logItem = $stmt->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare('DELETE FROM daily_chamber_temp_logs WHERE id = ?')->execute([$id]);
            if ($logItem) {
                ActivityLogger::log(
                    (string) ($req->user['email'] ?? 'unknown'),
                    'DELETE',
                    'Chamber Temp Log',
                    'Deleted Native Chamber Temp record (Ref: ' . ($logItem['reference_no'] ?? '-') . ') — chamber ' . ($logItem['chamber_name'] ?? '-') . ', client ' . ($logItem['client_name'] ?? '-') . ". Remarks: {$remarks}"
                );
            }
            Response::json(['success' => true, 'message' => 'Inspection log deleted successfully.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to delete inspection.');
        }
    }

    private static function fail(\Throwable $e, string $msg): void
    {
        error_log('[chambers] ' . $e->getMessage());
        Response::json(['success' => false, 'message' => $msg, 'error' => $e->getMessage()], 500);
    }
}
