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
use App\Services\Pagination;
use PDO;

final class ChamberTempController
{
    public static function getChamberLogs(Request $req, array $params = []): void
    {
        try {
            $queryBag = [];
            foreach (['search', 'page', 'limit', 'export', 'fromDate', 'toDate', 'warehouse', 'client', 'chamber', 'shift'] as $k) {
                $queryBag[$k] = $req->query($k);
            }
            $pageInfo = Pagination::parse($queryBag);
            $conditions = [];
            $paramsSql = [];

            Pagination::appendDoWarehouseScope($conditions, $paramsSql, $req->user);
            Pagination::appendCustomerScope($conditions, $paramsSql, $req->user);

            $search = trim((string) ($req->query('search') ?? ''));
            if ($search !== '') {
                $conditions[] = '(reference_no LIKE ? OR client_name LIKE ? OR chamber_name LIKE ? OR monitor_supervisor_name LIKE ? OR inspection_time LIKE ? OR operator_email LIKE ?)';
                $like = '%' . $search . '%';
                array_push($paramsSql, $like, $like, $like, $like, $like, $like);
            }
            $fromDate = $req->query('fromDate');
            if ($fromDate) {
                $conditions[] = 'entry_date >= ?';
                $paramsSql[] = $fromDate;
            }
            $toDate = $req->query('toDate');
            if ($toDate) {
                $conditions[] = 'entry_date <= ?';
                $paramsSql[] = $toDate;
            }

            $warehouse = $req->query('warehouse');
            $role = $req->user['role'] ?? '';
            if ($warehouse && $warehouse !== 'All' && in_array($role, ['super_admin', 'customer', 'sub_admin'], true)) {
                if ($warehouse === 'Generic') {
                    $conditions[] = "(warehouse_name IS NULL OR TRIM(COALESCE(warehouse_name, '')) = '' OR LOWER(TRIM(warehouse_name)) = ?)";
                    $paramsSql[] = 'generic';
                } else {
                    $val = strtolower(trim($warehouse));
                    $conditions[] = '(LOWER(TRIM(COALESCE(warehouse_code, \'\'))) = ? OR LOWER(TRIM(COALESCE(warehouse_name, \'\'))) = ?)';
                    array_push($paramsSql, $val, $val);
                }
            }

            $client = $req->query('client');
            if ($client && $client !== 'All') {
                $val = strtolower(trim($client));
                $conditions[] = '(LOWER(TRIM(COALESCE(client_code, \'\'))) = ? OR LOWER(TRIM(COALESCE(client_name, \'\'))) = ?)';
                array_push($paramsSql, $val, $val);
            }

            $chamber = $req->query('chamber');
            if ($chamber && $chamber !== 'All') {
                $conditions[] = 'LOWER(TRIM(chamber_name)) = ?';
                $paramsSql[] = strtolower(trim($chamber));
            }

            $shiftFilter = strtolower(trim((string) ($req->query('shift') ?? '')));
            if ($shiftFilter === 'morning') {
                $conditions[] = "(LOWER(TRIM(IFNULL(shift, ''))) = 'morning' OR ((shift IS NULL OR TRIM(shift) = '') AND NOT (inspection_time LIKE '16:%' OR inspection_time LIKE '18:%')))";
            } elseif ($shiftFilter === 'evening') {
                $conditions[] = "(LOWER(TRIM(IFNULL(shift, ''))) = 'evening' OR inspection_time LIKE '16:%' OR inspection_time LIKE '18:%')";
            }

            $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
            $pdo = Database::pdo();
            $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM daily_chamber_temp_logs {$where}");
            $countStmt->execute($paramsSql);
            $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $sql = "SELECT id, reference_no, entry_date, client_name, client_code, chamber_name, inspection_time, box_temp, box_temp AS chamber_temp, box_count, overdue_time, monitor_supervisor_name, temp_sensor_image, photo_capture_time, photo_capture_latitude, photo_capture_longitude, photo_capture_accuracy, time_variance_minutes, update_details, update_count, DATE_FORMAT(entry_date, '%Y-%m-%d') as formatted_date, created_at, updated_at, warehouse_name, warehouse_code, operator_email, chamber_type, shift, chamber_id, is_native, remarks
                    FROM daily_chamber_temp_logs {$where}
                    ORDER BY entry_date DESC, id DESC LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($sql);
            $bind = array_merge($paramsSql, [$pageInfo['limit'], $pageInfo['offset']]);
            $stmt->execute($bind);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::json(Pagination::payload($rows, $total, $pageInfo['page'], $pageInfo['limit']));
        } catch (\Throwable $e) {
            error_log('[chamber-temp get] ' . $e->getMessage());
            Response::json([
                'success' => false,
                'message' => 'Failed to fetch chamber logs.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public static function addChamberLog(Request $req, array $params = []): void
    {
        try {
            Multipart::mergeInto($req);
            $body = $req->body();
            $entry_date = $body['entry_date'] ?? null;
            $client_name = $body['client_name'] ?? null;
            $chamber_name = $body['chamber_name'] ?? null;
            $inspection_time = $body['inspection_time'] ?? '11:00';
            $box_temp = $body['box_temp'] ?? null;
            $monitor_supervisor_name = $body['monitor_supervisor_name'] ?? null;

            $temp_sensor_image = null;
            $photo_capture_time = $body['photo_capture_time'] ?? null;
            $time_variance_minutes = isset($body['time_variance_minutes']) ? (int) $body['time_variance_minutes'] : 0;

            $file = Multipart::file('temp_sensor_image');
            if ($file) {
                $temp_sensor_image = FileUpload::save($file, 'daily_temp_monitor_images', 'sensor-temp', 'temp_sensor_image');
                if (!$photo_capture_time) {
                    $photo_capture_time = DateTimeUtil::formatDateTime();
                }
            } elseif (!empty($body['temp_sensor_image_base64'])) {
                $temp_sensor_image = (string) $body['temp_sensor_image_base64'];
                if (!$photo_capture_time) {
                    $photo_capture_time = DateTimeUtil::formatDateTime();
                }
            }
            // Always compute variance when capture time is known (mobile sends both)
            if ($photo_capture_time && $entry_date) {
                $time_variance_minutes = DateTimeUtil::calculateVariance(
                    $entry_date,
                    $inspection_time ?: '11:00',
                    $photo_capture_time
                );
            }

            if (!$entry_date || (!$client_name && empty($body['client_code'])) || !$chamber_name || $box_temp === null || !$monitor_supervisor_name) {
                Response::json(['error' => 'Entry Date, Client Name, Chamber Name, Box Temp, and Monitor Supervisor Name are required.'], 400);
                return;
            }

            $pdo = Database::pdo();
            $submissionId = trim((string) ($body['client_submission_id'] ?? $body['local_id'] ?? ''));
            if ($submissionId !== '') {
                try {
                    $stmt = $pdo->prepare('SELECT id, reference_no, temp_sensor_image FROM daily_chamber_temp_logs WHERE client_submission_id = ? LIMIT 1');
                    $stmt->execute([$submissionId]);
                    $dup = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($dup) {
                        Response::json([
                            'id' => (int) $dup['id'],
                            'reference_no' => $dup['reference_no'] ?? null,
                            'temp_sensor_image' => $dup['temp_sensor_image'] ?? null,
                            'message' => 'Already synced.',
                            'already_exists' => true,
                        ]);
                        return;
                    }
                } catch (\Throwable) {
                }
            }

            $localTimestamp = DateTimeUtil::formatDateTime();
            $shiftVal = DateTimeUtil::resolveShift($body['shift'] ?? null, $inspection_time);
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

            $bind = [
                $entry_date,
                $resolvedClientName,
                $clFields['client_code'],
                $chamber_name,
                $inspection_time ?: '11:00',
                $box_temp,
                $monitor_supervisor_name,
                $temp_sensor_image,
                $photo_capture_time,
                $time_variance_minutes,
                DateTimeUtil::parseOptionalFloat($body['photo_capture_latitude'] ?? null),
                DateTimeUtil::parseOptionalFloat($body['photo_capture_longitude'] ?? null),
                DateTimeUtil::parseOptionalFloat($body['photo_capture_accuracy'] ?? null),
                $localTimestamp,
                $localTimestamp,
                $whFields['warehouse_name'],
                $whFields['warehouse_code'],
                $attr['operator_email'],
                $shiftVal,
                $body['chamber_type'] ?? 'Frozen',
            ];

            $sql = 'INSERT INTO daily_chamber_temp_logs
              (entry_date, client_name, client_code, chamber_name, inspection_time, box_temp, monitor_supervisor_name, temp_sensor_image, photo_capture_time, time_variance_minutes, photo_capture_latitude, photo_capture_longitude, photo_capture_accuracy, created_at, updated_at, warehouse_name, warehouse_code, operator_email, shift, chamber_type)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

            if ($submissionId !== '') {
                try {
                    $sql = 'INSERT INTO daily_chamber_temp_logs
                      (entry_date, client_name, client_code, chamber_name, inspection_time, box_temp, monitor_supervisor_name, temp_sensor_image, photo_capture_time, time_variance_minutes, photo_capture_latitude, photo_capture_longitude, photo_capture_accuracy, created_at, updated_at, warehouse_name, warehouse_code, operator_email, shift, chamber_type, client_submission_id)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                    $bind[] = $submissionId;
                    $pdo->prepare($sql)->execute($bind);
                } catch (\Throwable $e) {
                    if (stripos($e->getMessage(), 'client_submission_id') !== false) {
                        array_pop($bind);
                        $sql = 'INSERT INTO daily_chamber_temp_logs
                          (entry_date, client_name, client_code, chamber_name, inspection_time, box_temp, monitor_supervisor_name, temp_sensor_image, photo_capture_time, time_variance_minutes, photo_capture_latitude, photo_capture_longitude, photo_capture_accuracy, created_at, updated_at, warehouse_name, warehouse_code, operator_email, shift, chamber_type)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                        $pdo->prepare($sql)->execute($bind);
                    } else {
                        throw $e;
                    }
                }
            } else {
                $pdo->prepare($sql)->execute($bind);
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
                "Created Chamber Temp record (Ref: {$reference_no}) — chamber {$chamber_name}, client {$resolvedClientName}, date {$entry_date}"
            );

            Response::json([
                'id' => $insertId,
                'reference_no' => $reference_no,
                'temp_sensor_image' => $temp_sensor_image,
                'photo_capture_time' => $photo_capture_time,
                'time_variance_minutes' => $time_variance_minutes,
                'message' => 'Chamber temperature record saved.',
            ], 201);
        } catch (\Throwable $e) {
            error_log('[chamber-temp add] ' . $e->getMessage());
            Response::json(['success' => false, 'message' => 'Failed to save chamber temperature record.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function updateChamberLog(Request $req, array $params = []): void
    {
        try {
            Multipart::mergeInto($req);
            $id = (int) ($params['id'] ?? 0);
            $body = $req->body();
            $remarks = trim((string) ($body['remarks'] ?? ''));
            if ($remarks === '') {
                Response::error('Remarks are required to update this log.', 400);
                return;
            }

            if (($req->user['role'] ?? '') === 'do_operator') {
                if (!PermissionService::hasActivePermission((string) $req->user['email'], 'Chamber', $id, 'Edit')) {
                    Response::error('Edit permission expired or not approved. Request Super Admin permission again.', 403);
                    return;
                }
            }

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT * FROM daily_chamber_temp_logs WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$current) {
                Response::error('Log not found.', 404);
                return;
            }

            $temp_sensor_image = $body['temp_sensor_image'] ?? $current['temp_sensor_image'];
            $photo_capture_time = $body['photo_capture_time'] ?? $current['photo_capture_time'];
            $photo_capture_latitude = array_key_exists('photo_capture_latitude', $body)
                ? DateTimeUtil::parseOptionalFloat($body['photo_capture_latitude'])
                : DateTimeUtil::parseOptionalFloat($current['photo_capture_latitude'] ?? null);
            $photo_capture_longitude = array_key_exists('photo_capture_longitude', $body)
                ? DateTimeUtil::parseOptionalFloat($body['photo_capture_longitude'])
                : DateTimeUtil::parseOptionalFloat($current['photo_capture_longitude'] ?? null);
            $photo_capture_accuracy = array_key_exists('photo_capture_accuracy', $body)
                ? DateTimeUtil::parseOptionalFloat($body['photo_capture_accuracy'])
                : DateTimeUtil::parseOptionalFloat($current['photo_capture_accuracy'] ?? null);
            $time_variance_minutes = (int) ($current['time_variance_minutes'] ?? 0);
            $file = Multipart::file('temp_sensor_image');
            if ($file) {
                $temp_sensor_image = FileUpload::save($file, 'daily_temp_monitor_images', 'sensor-temp', 'temp_sensor_image');
                if (empty($body['photo_capture_time'])) {
                    $photo_capture_time = DateTimeUtil::formatDateTime();
                }
            }

            $entry_date = $body['entry_date'] ?? $current['entry_date'];
            $inspection_time = $body['inspection_time'] ?? $current['inspection_time'];
            if ($photo_capture_time) {
                $time_variance_minutes = DateTimeUtil::calculateVariance($entry_date, $inspection_time, $photo_capture_time);
            }

            $clFields = MasterResolver::resolveClientFields(
                $body['client_code'] ?? $current['client_code'],
                $body['client_name'] ?? $current['client_name'],
                $current['warehouse_name'] ?? null,
                $current['warehouse_code'] ?? null
            );

            $localTimestamp = DateTimeUtil::formatDateTime();
            $upd = $pdo->prepare(
                'UPDATE daily_chamber_temp_logs SET
                   entry_date = COALESCE(?, entry_date),
                   client_name = COALESCE(?, client_name),
                   client_code = COALESCE(?, client_code),
                   chamber_name = COALESCE(?, chamber_name),
                   inspection_time = COALESCE(?, inspection_time),
                   box_temp = COALESCE(?, box_temp),
                   box_count = COALESCE(?, box_count),
                   chamber_type = COALESCE(?, chamber_type),
                   monitor_supervisor_name = COALESCE(?, monitor_supervisor_name),
                   temp_sensor_image = COALESCE(?, temp_sensor_image),
                   photo_capture_time = ?,
                   time_variance_minutes = ?,
                   photo_capture_latitude = COALESCE(?, photo_capture_latitude),
                   photo_capture_longitude = COALESCE(?, photo_capture_longitude),
                   photo_capture_accuracy = COALESCE(?, photo_capture_accuracy),
                   remarks = ?,
                   update_count = COALESCE(update_count, 0) + 1,
                   updated_at = ?
                 WHERE id = ?'
            );
            $upd->execute([
                $body['entry_date'] ?? null,
                $clFields['client_name'] ?? ($body['client_name'] ?? null),
                $clFields['client_code'] ?? null,
                $body['chamber_name'] ?? null,
                $body['inspection_time'] ?? null,
                array_key_exists('box_temp', $body) && $body['box_temp'] !== '' ? $body['box_temp'] : null,
                array_key_exists('box_count', $body) && $body['box_count'] !== '' ? (int) $body['box_count'] : null,
                $body['chamber_type'] ?? null,
                $body['monitor_supervisor_name'] ?? null,
                $temp_sensor_image,
                $photo_capture_time,
                $time_variance_minutes,
                $photo_capture_latitude,
                $photo_capture_longitude,
                $photo_capture_accuracy,
                $remarks,
                $localTimestamp,
                $id,
            ]);

            ActivityLogger::log(
                (string) ($req->user['email'] ?? 'unknown'),
                'UPDATE',
                'Chamber Temp Log',
                'Updated Chamber Temp record id ' . $id . '. Remarks: ' . $remarks
            );

            if (($req->user['role'] ?? '') === 'do_operator') {
                PermissionService::consumeGrantedPermission((string) $req->user['email'], 'Chamber', $id, 'Edit');
            }

            Response::json(['success' => true, 'message' => 'Chamber temperature record updated.']);
        } catch (\Throwable $e) {
            error_log('[chamber-temp update] ' . $e->getMessage());
            Response::json(['success' => false, 'message' => 'Failed to update chamber temperature record.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function deleteChamberLog(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $body = $req->body();
            $remarks = trim((string) ($body['remarks'] ?? $req->query('remarks') ?? ''));

            if (($req->user['role'] ?? '') === 'do_operator') {
                if (!PermissionService::hasActivePermission((string) $req->user['email'], 'Chamber', $id, 'Delete')) {
                    Response::error('Delete permission expired or not approved. Request Super Admin permission again.', 403);
                    return;
                }
            }
            if ($remarks === '') {
                $remarks = 'Deleted by admin';
            }

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT reference_no, chamber_name, client_name FROM daily_chamber_temp_logs WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare('DELETE FROM daily_chamber_temp_logs WHERE id = ?')->execute([$id]);

            if ($row) {
                ActivityLogger::log(
                    (string) ($req->user['email'] ?? 'unknown'),
                    'DELETE',
                    'Chamber Temp Log',
                    'Deleted Chamber Temp (Ref: ' . ($row['reference_no'] ?? '-') . '). Remarks: ' . $remarks
                );
            }
            if (($req->user['role'] ?? '') === 'do_operator') {
                PermissionService::consumeGrantedPermission((string) $req->user['email'], 'Chamber', $id, 'Delete');
            }
            Response::json(['success' => true, 'message' => 'Chamber temperature record deleted.']);
        } catch (\Throwable $e) {
            error_log('[chamber-temp delete] ' . $e->getMessage());
            Response::json(['success' => false, 'message' => 'Failed to delete chamber temperature record.', 'error' => $e->getMessage()], 500);
        }
    }
}
