<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\ActivityLogger;
use App\Services\Pagination;
use PDO;

final class ActivityController
{
    public static function getActivityLogs(Request $req, array $params = []): void
    {
        try {
            $q = [];
            foreach (['page', 'limit', 'export', 'search', 'fromDate', 'toDate', 'action', 'category', 'warehouse', 'operatorEmail', 'operator_email'] as $k) {
                $q[$k] = $req->query($k);
            }
            $pageInfo = Pagination::parse($q);
            $conditions = [];
            $bind = [];
            $cat = strtolower((string) ($req->query('category') ?? 'activity'));

            if ($cat === 'security') {
                $conditions[] = "(a.log_type IN ('PERMISSION', 'SECURITY'))";
            } elseif ($cat === 'system') {
                $conditions[] = "(a.log_type IN ('SYSTEM', 'ERROR') OR a.action = 'SYSTEM_ERROR' OR (a.description IS NOT NULL AND a.description LIKE '%[CHECKPOINT]%'))";
            } elseif ($cat === 'do_changes') {
                $conditions[] = "(a.action IN (
                  'ADD_CLIENT','DELETE_CLIENT','UPDATE_CLIENT','ADD_CHAMBER','DELETE_CHAMBER','UPDATE_CHAMBER',
                  'REQUEST_EDIT','REQUEST_DELETE','GRANT_PERMISSION','GRANT_DELETE','DENY_PERMISSION','DENY_DELETE',
                  'USE_EDIT_PERMISSION','USE_DELETE_PERMISSION'
                ) OR a.log_type IN ('DO_CHANGE','ChamberMaster','ClientMaster','MasterSetup','ChamberType'))";
            } else {
                $conditions[] = "(
                  (a.log_type IS NULL OR a.log_type NOT IN ('PERMISSION','SECURITY','ERROR','SYSTEM','DO_CHANGE','ChamberMaster','ClientMaster','MasterSetup','ChamberType'))
                  AND (a.action IS NULL OR a.action NOT IN (
                    'SYSTEM_ERROR','ADD_CLIENT','DELETE_CLIENT','ADD_CHAMBER','DELETE_CHAMBER',
                    'REQUEST_EDIT','REQUEST_DELETE','GRANT_PERMISSION','GRANT_DELETE','DENY_PERMISSION','DENY_DELETE',
                    'USE_EDIT_PERMISSION','USE_DELETE_PERMISSION'
                  ))
                )";
            }

            $action = $req->query('action');
            if ($action && $action !== 'All') {
                $conditions[] = 'a.action = ?';
                $bind[] = $action;
            }
            $opEmail = trim((string) ($req->query('operatorEmail') ?? $req->query('operator_email') ?? ''));
            if ($opEmail !== '') {
                $conditions[] = 'LOWER(TRIM(a.operator_email)) = LOWER(TRIM(?))';
                $bind[] = $opEmail;
            }
            $search = trim((string) ($req->query('search') ?? ''));
            if ($search !== '') {
                $conditions[] = '(a.description LIKE ? OR a.operator_email LIKE ? OR a.action LIKE ?)';
                $like = '%' . $search . '%';
                array_push($bind, $like, $like, $like);
            }
            if ($req->query('fromDate')) {
                $conditions[] = 'DATE(a.created_at) >= ?';
                $bind[] = $req->query('fromDate');
            }
            if ($req->query('toDate')) {
                $conditions[] = 'DATE(a.created_at) <= ?';
                $bind[] = $req->query('toDate');
            }

            $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
            $pdo = Database::pdo();
            $cStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM do_operator_activities a {$where}");
            $cStmt->execute($bind);
            $total = (int) ($cStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $stmt = $pdo->prepare(
                "SELECT a.id, a.operator_email, a.action, a.log_type, a.description, a.permission_req, a.created_at
                 FROM do_operator_activities a {$where}
                 ORDER BY a.id DESC LIMIT ? OFFSET ?"
            );
            $stmt->execute(array_merge($bind, [$pageInfo['limit'], $pageInfo['offset']]));
            Response::json(Pagination::payload($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $pageInfo['page'], $pageInfo['limit']));
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to fetch activity logs.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function createActivityLog(Request $req, array $params = []): void
    {
        try {
            $body = $req->body();
            $email = (string) ($req->user['email'] ?? $body['operator_email'] ?? 'system');
            $action = (string) ($body['action'] ?? 'NOTE');
            $logType = (string) ($body['log_type'] ?? $body['type'] ?? 'ACTIVITY');
            $description = (string) ($body['description'] ?? '');
            $permissionReq = $body['permission_req'] ?? $body['record_id'] ?? null;
            ActivityLogger::log($email, $action, $logType, $description, $permissionReq);
            Response::json(['success' => true, 'message' => 'Activity logged.']);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to create activity log.', 'error' => $e->getMessage()], 500);
        }
    }
}
