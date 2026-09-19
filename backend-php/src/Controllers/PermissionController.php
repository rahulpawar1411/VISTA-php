<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\ActivityLogger;
use App\Services\ExpoPush;
use App\Services\PermissionApply;
use App\Services\PermissionService;
use PDO;

final class PermissionController
{
    public static function getSystemConfig(Request $req, array $params = []): void
    {
        try {
            $config = [
                'Chamber_Edit' => 'Require Approval',
                'Chamber_Delete' => 'Require Approval',
                'ChamberMaster_Edit' => 'Require Approval',
                'ChamberMaster_Delete' => 'Require Approval',
                'ClientMaster_Edit' => 'Require Approval',
                'ClientMaster_Delete' => 'Require Approval',
                'Inward_Edit' => 'Require Approval',
                'Inward_Delete' => 'Require Approval',
                'Outward_Edit' => 'Require Approval',
                'Outward_Delete' => 'Require Approval',
            ];
            $rows = Database::pdo()->query(
                "SELECT action AS config_key, description AS config_value FROM do_operator_activities
                 WHERE operator_email = 'system' AND log_type = 'SYSTEM_CONFIG'
                 AND id IN (
                   SELECT MAX(id) FROM do_operator_activities
                   WHERE operator_email = 'system' AND log_type = 'SYSTEM_CONFIG'
                   GROUP BY action
                 )"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows ?: [] as $row) {
                $config[$row['config_key']] = $row['config_value'];
            }
            Response::json($config);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to fetch configuration.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function updateSystemConfig(Request $req, array $params = []): void
    {
        try {
            $body = $req->body();
            foreach ($body as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                ActivityLogger::log('system', $key, 'SYSTEM_CONFIG', (string) $value);
            }
            Response::json(['success' => true, 'message' => 'Configuration updated.']);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to update configuration.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function checkPermission(Request $req, array $params = []): void
    {
        try {
            $recordType = (string) ($req->query('record_type') ?? $req->query('recordType') ?? '');
            $recordId = $req->query('record_id') ?? $req->query('recordId');
            $action = (string) ($req->query('action') ?? 'Edit');
            $email = (string) ($req->user['email'] ?? '');
            $role = $req->user['role'] ?? '';
            if ($role === 'super_admin') {
                Response::json(['approved' => true, 'status' => 'Approved', 'bypass' => true, 'role' => 'super_admin']);
                return;
            }
            $allowed = PermissionService::hasActivePermission($email, $recordType, $recordId, $action);
            Response::json([
                'approved' => $allowed,
                'status' => $allowed ? 'Approved' : 'None',
                'allowed' => $allowed,
                'hasPermission' => $allowed,
            ]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to check permission.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function getPermissionRequests(Request $req, array $params = []): void
    {
        try {
            $pdo = Database::pdo();
            $role = $req->user['role'] ?? '';
            $email = (string) ($req->user['email'] ?? '');
            $logTypes = "'Chamber','Inward','Outward','ChamberMaster','ClientMaster','ChamberType','MasterSetup'";
            $actions = "'REQUEST_EDIT','REQUEST_DELETE','GRANT_PERMISSION','GRANT_DELETE','DENY_PERMISSION','DENY_DELETE','USE_EDIT_PERMISSION','USE_DELETE_PERMISSION'";

            $completedCol = '';
            try {
                $pdo->query('SELECT do_action_completed_at FROM do_operator_activities LIMIT 0');
                $completedCol = ", DATE_FORMAT(a.do_action_completed_at, '%Y-%m-%d %H:%i:%s') AS do_action_completed_at";
            } catch (\Throwable) {
            }

            // Node parity: only the latest row per (operator, log_type, permission_req)
            if (in_array($role, ['super_admin', 'sub_admin'], true)) {
                $sql = "SELECT a.id, a.operator_email, a.log_type AS record_type, a.permission_req AS record_id,
                               a.action AS raw_action, a.description, a.remark,
                               DATE_FORMAT(a.created_at, '%Y-%m-%d %H:%i:%s') AS created_at{$completedCol},
                               CASE
                                 WHEN a.action IN ('REQUEST_EDIT','REQUEST_DELETE') THEN 'Pending'
                                 WHEN a.action IN ('GRANT_PERMISSION','GRANT_DELETE') THEN 'Approved'
                                 WHEN a.action IN ('USE_EDIT_PERMISSION','USE_DELETE_PERMISSION') THEN 'Used'
                                 ELSE 'Denied'
                               END AS status
                        FROM do_operator_activities a
                        WHERE a.log_type IN ({$logTypes})
                          AND a.id IN (
                            SELECT MAX(id) FROM do_operator_activities
                            WHERE action IN ({$actions}) AND log_type IN ({$logTypes})
                            GROUP BY operator_email, log_type, permission_req
                          )
                        ORDER BY a.id DESC
                        LIMIT 300";
                $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT a.id, a.operator_email, a.log_type AS record_type, a.permission_req AS record_id,
                            a.action AS raw_action, a.description, a.remark,
                            DATE_FORMAT(a.created_at, '%Y-%m-%d %H:%i:%s') AS created_at{$completedCol},
                            CASE
                              WHEN a.action IN ('REQUEST_EDIT','REQUEST_DELETE') THEN 'Pending'
                              WHEN a.action IN ('GRANT_PERMISSION','GRANT_DELETE') THEN 'Approved'
                              WHEN a.action IN ('USE_EDIT_PERMISSION','USE_DELETE_PERMISSION') THEN 'Used'
                              ELSE 'Denied'
                            END AS status
                     FROM do_operator_activities a
                     WHERE LOWER(TRIM(a.operator_email)) = LOWER(TRIM(?))
                       AND a.log_type IN ({$logTypes})
                       AND a.id IN (
                         SELECT MAX(id) FROM do_operator_activities
                         WHERE LOWER(TRIM(operator_email)) = LOWER(TRIM(?))
                           AND action IN ({$actions}) AND log_type IN ({$logTypes})
                         GROUP BY operator_email, log_type, permission_req
                       )
                     ORDER BY a.id DESC
                     LIMIT 200"
                );
                $stmt->execute([$email, $email]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Attach original request description when latest is a decision row
            $mapped = [];
            foreach ($rows ?: [] as $r) {
                $reqDesc = $r['description'] ?? null;
                $reqRemark = $r['remark'] ?? null;
                try {
                    $rq = $pdo->prepare(
                        "SELECT description, remark FROM do_operator_activities
                         WHERE LOWER(TRIM(operator_email)) = LOWER(TRIM(?))
                           AND log_type = ? AND permission_req <=> ?
                           AND action IN ('REQUEST_EDIT','REQUEST_DELETE')
                         ORDER BY id DESC LIMIT 1"
                    );
                    $rq->execute([$r['operator_email'], $r['record_type'], $r['record_id']]);
                    $orig = $rq->fetch(PDO::FETCH_ASSOC);
                    if ($orig) {
                        $reqDesc = $orig['description'] ?? $reqDesc;
                        $reqRemark = $orig['remark'] ?? $reqRemark;
                    }
                } catch (\Throwable) {
                }
                $mapped[] = array_merge($r, [
                    'request_description' => $reqDesc,
                    'request_remark' => $reqRemark,
                    'action' => $r['raw_action'] ?? null,
                    'log_type' => $r['record_type'] ?? null,
                    'permission_req' => $r['record_id'] ?? null,
                ]);
            }

            Response::json($mapped);
        } catch (\Throwable $e) {
            // Fallback without remark column / <=> if schema differs
            try {
                $pdo = Database::pdo();
                $sql = "SELECT id, operator_email, action, log_type, description, permission_req,
                               DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
                        FROM do_operator_activities
                        WHERE action IN ('REQUEST_EDIT','REQUEST_DELETE','GRANT_PERMISSION','GRANT_DELETE','DENY_PERMISSION','DENY_DELETE','USE_EDIT_PERMISSION','USE_DELETE_PERMISSION')
                        ORDER BY id DESC LIMIT 300";
                $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                $latest = [];
                foreach ($rows ?: [] as $r) {
                    $key = strtolower(trim((string) $r['operator_email'])) . '|' . $r['log_type'] . '|' . ($r['permission_req'] ?? '');
                    if (!isset($latest[$key])) {
                        $latest[$key] = $r;
                    }
                }
                $mapped = [];
                foreach ($latest as $r) {
                    $action = $r['action'] ?? '';
                    $status = 'Pending';
                    if (str_starts_with($action, 'GRANT')) {
                        $status = 'Approved';
                    } elseif (str_starts_with($action, 'DENY')) {
                        $status = 'Denied';
                    } elseif (str_starts_with($action, 'USE_')) {
                        $status = 'Used';
                    }
                    $mapped[] = array_merge($r, [
                        'record_type' => $r['log_type'] ?? null,
                        'record_id' => $r['permission_req'] ?? null,
                        'raw_action' => $action,
                        'status' => $status,
                        'request_description' => $r['description'] ?? null,
                    ]);
                }
                usort($mapped, static fn ($a, $b) => (int) $b['id'] <=> (int) $a['id']);
                Response::json($mapped);
            } catch (\Throwable $e2) {
                Response::json(['success' => false, 'message' => 'Failed to fetch permission requests.', 'error' => $e2->getMessage()], 500);
            }
        }
    }

    public static function createPermissionRequest(Request $req, array $params = []): void
    {
        try {
            $body = $req->body();
            $record_type = $body['record_type'] ?? null;
            $record_id = $body['record_id'] ?? null;
            $action = $body['action'] ?? 'Edit';
            $description = $body['description'] ?? '';
            $remark = $body['remark'] ?? '';
            $operator_email = (string) ($req->user['email'] ?? '');

            if (!$record_type || $record_id === null || $record_id === '') {
                Response::json(['error' => 'Record type and Record ID are required.'], 400);
                return;
            }

            $reqActionType = $action === 'Edit' ? 'REQUEST_EDIT' : 'REQUEST_DELETE';
            $grantActionType = $action === 'Edit' ? 'GRANT_PERMISSION' : 'GRANT_DELETE';
            $pdo = Database::pdo();

            $stmt = $pdo->prepare(
                'SELECT action, id FROM do_operator_activities
                 WHERE operator_email = ? AND log_type = ? AND permission_req = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$operator_email, $record_type, $record_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if (($existing['action'] ?? '') === $reqActionType) {
                    Response::json([
                        'error' => 'A permission request for this record is already pending approval.',
                        'request' => ['status' => 'Pending', 'id' => (int) $existing['id']],
                    ], 400);
                    return;
                }
                if (($existing['action'] ?? '') === $grantActionType && $record_type !== 'ChamberType') {
                    if (in_array($record_type, ['ClientMaster', 'ChamberMaster'], true)) {
                        PermissionService::consumeGrantedPermission($operator_email, (string) $record_type, $record_id, $action === 'Edit' ? 'Edit' : 'Delete');
                    } else {
                        Response::json([
                            'error' => 'Permission to perform this action has already been granted.',
                            'request' => ['status' => 'Approved'],
                        ], 400);
                        return;
                    }
                }
            }

            $refText = 'ID: ' . $record_id;
            try {
                if ($record_type === 'Chamber') {
                    $s = $pdo->prepare('SELECT reference_no FROM daily_chamber_temp_logs WHERE id = ? LIMIT 1');
                    $s->execute([$record_id]);
                    $ref = $s->fetchColumn();
                    if ($ref) {
                        $refText = 'Ref: ' . $ref;
                    }
                } elseif ($record_type === 'Inward') {
                    $s = $pdo->prepare('SELECT reference_no FROM inward_temp_logs WHERE inward_id = ? LIMIT 1');
                    $s->execute([$record_id]);
                    $ref = $s->fetchColumn();
                    if ($ref) {
                        $refText = 'Ref: ' . $ref;
                    }
                } elseif ($record_type === 'Outward') {
                    $s = $pdo->prepare('SELECT reference_no FROM outward_temp_logs WHERE outward_id = ? LIMIT 1');
                    $s->execute([$record_id]);
                    $ref = $s->fetchColumn();
                    if ($ref) {
                        $refText = 'Ref: ' . $ref;
                    }
                }
            } catch (\Throwable) {
            }

            $desc = trim((string) $description);
            if ($desc === '') {
                $desc = "{$action} request · {$record_type} · {$refText}";
            }
            if (trim((string) $remark) !== '') {
                $desc .= ' | Remark: ' . trim((string) $remark);
            }

            ActivityLogger::log($operator_email, $reqActionType, (string) $record_type, $desc, $record_id);

            // Fire-and-forget push
            ExpoPush::notifySubAdminsPermissionRequest($operator_email, (string) $record_type, (string) $action);

            Response::json([
                'success' => true,
                'message' => 'Permission request submitted.',
                'request' => ['status' => 'Pending', 'record_type' => $record_type, 'record_id' => $record_id],
            ], 201);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to create permission request.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function updatePermissionRequestStatus(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $body = $req->body();
            $status = strtolower(trim((string) ($body['status'] ?? '')));
            $adminRemark = trim((string) ($body['remark'] ?? $body['admin_remark'] ?? ''));

            if (!in_array($status, ['approved', 'denied'], true)) {
                Response::json(['error' => 'status must be approved or denied.'], 400);
                return;
            }
            if ($status === 'denied' && $adminRemark === '') {
                Response::json(['error' => 'Remark is required when denying a request.'], 400);
                return;
            }

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT * FROM do_operator_activities WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                Response::json(['error' => 'Request not found.'], 404);
                return;
            }

            $operatorEmail = (string) ($row['operator_email'] ?? '');
            $recordType = (string) ($row['log_type'] ?? '');
            $recordId = $row['permission_req'] ?? null;
            $requestDescription = (string) ($row['description'] ?? '');
            $isDelete = str_contains((string) ($row['action'] ?? ''), 'DELETE');
            $isEdit = !$isDelete;
            $grantAction = $isDelete ? 'GRANT_DELETE' : 'GRANT_PERMISSION';
            $denyAction = $isDelete ? 'DENY_DELETE' : 'DENY_PERMISSION';
            $decider = (string) ($req->user['full_name'] ?? $req->user['email'] ?? 'Admin');
            $deciderEmail = (string) ($req->user['email'] ?? '');

            $appliedChamberAdd = null;
            $appliedChamberType = null;
            $appliedClientMaster = null;

            if ($status === 'approved') {
                if ($recordType === 'ChamberMaster') {
                    $isAddRequest = (bool) preg_match('/allow to ADD chamber/i', $requestDescription);
                    if ($isEdit && $isAddRequest) {
                        $appliedChamberAdd = PermissionApply::applyApprovedChamberAdd(
                            $operatorEmail,
                            $requestDescription,
                            $recordId
                        );
                    }
                } elseif ($recordType === 'ChamberType' && $isEdit) {
                    $appliedChamberType = PermissionApply::applyApprovedChamberTypeChange(
                        $requestDescription,
                        $recordId
                    );
                } elseif ($recordType === 'ClientMaster') {
                    $appliedClientMaster = PermissionApply::applyApprovedClientMasterChange(
                        $operatorEmail,
                        $requestDescription,
                        $recordId,
                        $isDelete
                    );
                }

                $desc = "Approved · #{$recordId}" . ($adminRemark !== '' ? " | Admin remark: {$adminRemark}" : '') . " | Decided by: {$decider} ({$deciderEmail})";
                ActivityLogger::log($operatorEmail, $grantAction, $recordType, $desc, $recordId);

                // ClientMaster applied on approve — consume GRANT so DO cannot reuse it
                if ($recordType === 'ClientMaster') {
                    try {
                        PermissionService::consumeGrantedPermission(
                            $operatorEmail,
                            'ClientMaster',
                            $recordId,
                            $isEdit ? 'Edit' : 'Delete'
                        );
                    } catch (\Throwable) {
                    }
                }

                ExpoPush::notifyDoPermissionDecision($operatorEmail, 'Approved', $recordType, $adminRemark);
                Response::json([
                    'success' => true,
                    'message' => 'Permission approved.',
                    'status' => 'Approved',
                    'chamber_add' => $appliedChamberAdd,
                    'chamber_type' => $appliedChamberType,
                    'client_master' => $appliedClientMaster,
                    'remark' => $adminRemark !== '' ? $adminRemark : null,
                ]);
                return;
            }

            $desc = "Denied · #{$recordId} | Admin remark: {$adminRemark} | Decided by: {$decider} ({$deciderEmail})";
            ActivityLogger::log($operatorEmail, $denyAction, $recordType, $desc, $recordId);
            ExpoPush::notifyDoPermissionDecision($operatorEmail, 'Denied', $recordType, $adminRemark);
            Response::json([
                'success' => true,
                'message' => 'Permission denied.',
                'status' => 'Denied',
                'chamber_add' => null,
                'chamber_type' => null,
                'client_master' => null,
                'remark' => $adminRemark !== '' ? $adminRemark : null,
            ]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to update permission request.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function markPermissionActionComplete(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT id, operator_email, action, log_type, permission_req FROM do_operator_activities WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                Response::json(['error' => 'Request not found.'], 404);
                return;
            }

            $allowedActions = [
                'REQUEST_EDIT',
                'REQUEST_DELETE',
                'GRANT_PERMISSION',
                'GRANT_DELETE',
                'DENY_PERMISSION',
                'DENY_DELETE',
                'USE_EDIT_PERMISSION',
                'USE_DELETE_PERMISSION',
            ];
            if (!in_array($row['action'] ?? '', $allowedActions, true)) {
                Response::json(['error' => 'This activity cannot be marked complete.'], 400);
                return;
            }

            $email = (string) ($req->user['email'] ?? '');
            $role = (string) ($req->user['role'] ?? '');
            if ($role !== 'super_admin' && strtolower($email) !== strtolower((string) ($row['operator_email'] ?? ''))) {
                Response::error('Access Denied.', 403);
                return;
            }

            try {
                $pdo->prepare(
                    'UPDATE do_operator_activities SET do_action_completed_at = CURRENT_TIMESTAMP WHERE id = ?'
                )->execute([$id]);
            } catch (\Throwable) {
                // column may be missing on older schemas
            }

            $logType = (string) ($row['log_type'] ?? '');
            $action = (string) ($row['action'] ?? '');
            if (
                in_array($logType, ['MasterSetup', 'ChamberMaster', 'ClientMaster'], true) &&
                in_array($action, ['GRANT_PERMISSION', 'GRANT_DELETE'], true)
            ) {
                try {
                    PermissionService::consumeGrantedPermission(
                        (string) $row['operator_email'],
                        $logType,
                        $row['permission_req'] ?? null,
                        $action === 'GRANT_DELETE' ? 'Delete' : 'Edit'
                    );
                } catch (\Throwable) {
                }
            }

            $completedAt = null;
            try {
                $u = $pdo->prepare('SELECT do_action_completed_at FROM do_operator_activities WHERE id = ? LIMIT 1');
                $u->execute([$id]);
                $completedAt = $u->fetchColumn() ?: null;
            } catch (\Throwable) {
            }

            Response::json([
                'success' => true,
                'message' => 'Notification moved to completed.',
                'do_action_completed_at' => $completedAt,
            ]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to mark complete.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function getRecordPermissionHistory(Request $req, array $params = []): void
    {
        try {
            $recordType = (string) ($req->query('record_type') ?? $req->query('recordType') ?? '');
            $recordId = $req->query('record_id') ?? $req->query('recordId');
            if ($recordType === '' || $recordId === null) {
                Response::json(['error' => 'record_type and record_id required.'], 400);
                return;
            }
            $stmt = Database::pdo()->prepare(
                'SELECT id, operator_email, action, log_type, description, permission_req, created_at
                 FROM do_operator_activities
                 WHERE log_type = ? AND permission_req = ?
                 ORDER BY id DESC LIMIT 100'
            );
            $stmt->execute([$recordType, $recordId]);
            Response::json(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to fetch history.', 'error' => $e->getMessage()], 500);
        }
    }
}
