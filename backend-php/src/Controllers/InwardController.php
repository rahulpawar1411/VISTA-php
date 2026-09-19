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
use App\Services\LogDedup;
use App\Services\MasterResolver;
use App\Services\Pagination;
use App\Services\PermissionService;
use App\Services\PhotoCaptureMeta;
use PDO;

final class InwardController
{
    private const PHOTO_FIELDS_MULTI = [
        'inward_invoice_photos',
        'inward_count_sheet_photo',
        'inward_damage_boxes_photo',
    ];

    private const PHOTO_FIELDS_SINGLE = [
        'inward_pod_photo',
        'inward_vehicle_seal_photo',
        'inward_vehicle_temp_photo',
        'inward_material_temp_photo',
        'inward_vehicle_back_side_photo',
        'inward_vehicle_back_side_photo_with_material',
    ];

    /** @return array<string, ?string> */
    private static function collectPhotos(): array
    {
        $out = [];
        foreach (self::PHOTO_FIELDS_MULTI as $field) {
            $paths = [];
            foreach (Multipart::files($field) as $f) {
                $paths[] = FileUpload::save($f, 'inward_images', 'inward', $field);
            }
            $out[$field] = $paths ? implode(',', $paths) : null;
        }
        foreach (self::PHOTO_FIELDS_SINGLE as $field) {
            $f = Multipart::file($field);
            $out[$field] = $f ? FileUpload::save($f, 'inward_images', 'inward', $field) : null;
        }
        return $out;
    }

    public static function getInwardLogs(Request $req, array $params = []): void
    {
        try {
            $q = [];
            foreach (['search', 'page', 'limit', 'export', 'fromDate', 'toDate', 'warehouse', 'missingPod', 'podMissing'] as $k) {
                $q[$k] = $req->query($k);
            }
            $pageInfo = Pagination::parse($q);
            $conditions = [];
            $bind = [];
            Pagination::appendDoWarehouseScope($conditions, $bind, $req->user);
            // customer scope on inward columns
            $role = ($req->user['role'] ?? '') === 'sub_admin' ? 'customer' : ($req->user['role'] ?? '');
            if ($role === 'customer' && $req->user) {
                $clients = array_filter(array_map('trim', explode(',', (string) ($req->user['allowed_clients'] ?? ''))));
                $warehouses = array_filter(array_map('trim', explode(',', (string) ($req->user['allowed_warehouses'] ?? ''))));
                if ($clients) {
                    $clients = array_map('strtolower', $clients);
                    $ph = implode(',', array_fill(0, count($clients), '?'));
                    $conditions[] = "(LOWER(TRIM(COALESCE(inward_client_code,''))) IN ({$ph}) OR LOWER(TRIM(COALESCE(inward_client_name,''))) IN ({$ph}))";
                    array_push($bind, ...$clients, ...$clients);
                }
                if ($warehouses) {
                    $warehouses = array_map('strtolower', $warehouses);
                    $ph = implode(',', array_fill(0, count($warehouses), '?'));
                    $conditions[] = "(warehouse_name IS NULL OR TRIM(COALESCE(warehouse_name,'')) = '' OR LOWER(TRIM(COALESCE(warehouse_code,''))) IN ({$ph}) OR LOWER(TRIM(warehouse_name)) IN ({$ph}))";
                    array_push($bind, ...$warehouses, ...$warehouses);
                }
            }

            $search = trim((string) ($req->query('search') ?? ''));
            if ($search !== '') {
                $conditions[] = '(reference_no LIKE ? OR inward_vehicle_no LIKE ? OR inward_client_name LIKE ? OR inward_transporter_name LIKE ? OR inward_driver_name LIKE ? OR operator_email LIKE ?)';
                $like = '%' . $search . '%';
                array_push($bind, $like, $like, $like, $like, $like, $like);
            }
            if ($req->query('fromDate')) {
                $conditions[] = 'inward_entry_date >= ?';
                $bind[] = $req->query('fromDate');
            }
            if ($req->query('toDate')) {
                $conditions[] = 'inward_entry_date <= ?';
                $bind[] = $req->query('toDate');
            }
            $warehouseFilter = trim((string) ($req->query('warehouse') ?? ''));
            $roleForWh = $req->user['role'] ?? '';
            if ($warehouseFilter !== '' && $warehouseFilter !== 'All' && in_array($roleForWh, ['super_admin', 'customer', 'sub_admin'], true)) {
                $conditions[] = 'LOWER(TRIM(COALESCE(warehouse_name, \'\'))) = ?';
                $bind[] = strtolower($warehouseFilter);
            }
            $missingPod = strtolower(trim((string) ($req->query('missingPod') ?? $req->query('podMissing') ?? '')));
            if (in_array($missingPod, ['1', 'true', 'yes'], true)) {
                $conditions[] = "(inward_pod_photo IS NULL OR TRIM(inward_pod_photo) = '' OR LOWER(TRIM(inward_pod_photo)) = 'null')";
            }

            $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
            $pdo = Database::pdo();
            $cStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM inward_temp_logs {$where}");
            $cStmt->execute($bind);
            $total = (int) ($cStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $sql = "SELECT inward_id, reference_no, DATE_FORMAT(inward_entry_date, '%Y-%m-%d') as inward_entry_date, inward_vehicle_no, inward_seal_no,
                    inward_vehicle_temp, inward_material_temp, inward_transporter_name, inward_driver_name, inward_driver_no,
                    inward_client_name, inward_dock_no, inward_vehicle_reporting_time, inward_unloading_start_time,
                    inward_unloading_duration_hours, inward_unloading_duration_mins, inward_unloading_end_time,
                    inward_pallets_in_qty, inward_invoice_qty, inward_received_qty, inward_received_boxes_qty,
                    inward_short_received_boxes_qty, inward_excess_received_boxes_qty, inward_damage_received_boxes_qty,
                    inward_material_type, inward_unloading_supervisor_name, inward_remarks, inward_invoice_photos, inward_pod_photo,
                    inward_vehicle_seal_photo, inward_vehicle_temp_photo, inward_material_temp_photo, inward_vehicle_back_side_photo,
                    inward_vehicle_back_side_photo_with_material, inward_count_sheet_photo, inward_damage_boxes_photo, photo_capture_metadata,
                    update_details, update_count, inward_created_at, inward_updated_at, warehouse_name, warehouse_code, inward_client_code, operator_email
                    FROM inward_temp_logs {$where}
                    ORDER BY inward_entry_date DESC, inward_id DESC LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($bind, [$pageInfo['limit'], $pageInfo['offset']]));
            Response::json(Pagination::payload($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $pageInfo['page'], $pageInfo['limit']));
        } catch (\Throwable $e) {
            error_log('[inward get] ' . $e->getMessage());
            Response::json(['success' => false, 'message' => 'Failed to fetch inward logs.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function addInwardLog(Request $req, array $params = []): void
    {
        try {
            Multipart::mergeInto($req);
            $data = $req->body();
            $photos = self::collectPhotos();

            $required = [
                'inward_entry_date', 'inward_client_name', 'inward_dock_no', 'inward_material_type',
                'inward_vehicle_no', 'inward_transporter_name', 'inward_driver_name', 'inward_driver_no',
                'inward_vehicle_reporting_time', 'inward_unloading_start_time', 'inward_unloading_end_time',
                'inward_vehicle_temp', 'inward_material_temp', 'inward_unloading_supervisor_name',
            ];
            foreach ($required as $field) {
                if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                    Response::json(['error' => "Missing required field: {$field}", 'success' => false, 'message' => "Missing required field: {$field}"], 400);
                    return;
                }
            }

            $attr = LogAttribution::resolve($req->user, $data);
            $whFields = MasterResolver::resolveWarehouseFields(
                $data['warehouse_code'] ?? $attr['warehouse_code'],
                $attr['warehouse_name']
            );
            $clFields = MasterResolver::resolveClientFields(
                $data['inward_client_code'] ?? $data['client_code'] ?? null,
                $data['inward_client_name'] ?? null,
                $whFields['warehouse_name'],
                $whFields['warehouse_code']
            );
            $resolvedClientName = $clFields['client_name'] ?: ($data['inward_client_name'] ?? null);

            $pick = LogDedup::pickSubmission($data);
            $existing = LogDedup::findInwardDuplicate([
                'date' => $data['inward_entry_date'] ?? null,
                'vehicle' => $data['inward_vehicle_no'] ?? null,
                'operator' => $attr['operator_email'],
                'submissionId' => $pick['submissionId'],
                'submittedAt' => $pick['submittedAt'],
            ]);
            if ($existing) {
                Response::json([
                    'success' => true,
                    'duplicate' => true,
                    'id' => $existing['id'],
                    'reference_no' => $existing['reference_no'],
                    'message' => 'Inward log already saved.',
                ]);
                return;
            }

            $localTimestamp = DateTimeUtil::formatDateTime();
            $startWithDate = $data['inward_unloading_start_time'] ?? null;
            $endWithDate = $data['inward_unloading_end_time'] ?? null;
            $meta = PhotoCaptureMeta::normalize($data['photo_capture_metadata'] ?? null);

            $receivedBoxes = isset($data['inward_received_boxes_qty']) && $data['inward_received_boxes_qty'] !== ''
                ? (int) $data['inward_received_boxes_qty']
                : (isset($data['inward_received_qty']) && $data['inward_received_qty'] !== '' ? (int) $data['inward_received_qty'] : 0);

            $pdo = Database::pdo();
            $sql = 'INSERT INTO inward_temp_logs (
                inward_entry_date, inward_vehicle_no, inward_seal_no, inward_vehicle_temp, inward_material_temp, inward_transporter_name,
                inward_driver_name, inward_driver_no, inward_client_name, inward_dock_no, inward_vehicle_reporting_time,
                inward_unloading_start_time, inward_unloading_duration_hours, inward_unloading_duration_mins, inward_unloading_end_time,
                inward_pallets_in_qty, inward_invoice_qty, inward_received_qty, inward_received_boxes_qty,
                inward_short_received_boxes_qty, inward_excess_received_boxes_qty, inward_damage_received_boxes_qty,
                inward_material_type, inward_unloading_supervisor_name, inward_remarks,
                inward_invoice_photos, inward_pod_photo, inward_vehicle_seal_photo, inward_vehicle_temp_photo,
                inward_material_temp_photo, inward_vehicle_back_side_photo, inward_vehicle_back_side_photo_with_material,
                inward_count_sheet_photo, inward_damage_boxes_photo,
                inward_created_at, inward_updated_at, warehouse_name, warehouse_code, inward_client_code, operator_email, photo_capture_metadata,
                client_submission_id, client_submitted_at
              ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';

            $values = [
                $data['inward_entry_date'],
                $data['inward_vehicle_no'],
                $data['inward_seal_no'] ?? null,
                isset($data['inward_vehicle_temp']) && $data['inward_vehicle_temp'] !== '' ? (float) $data['inward_vehicle_temp'] : null,
                isset($data['inward_material_temp']) && $data['inward_material_temp'] !== '' ? (float) $data['inward_material_temp'] : null,
                $data['inward_transporter_name'] ?? null,
                $data['inward_driver_name'] ?? null,
                $data['inward_driver_no'] ?? null,
                $resolvedClientName,
                $data['inward_dock_no'] ?? null,
                $data['inward_vehicle_reporting_time'] ?? null,
                $startWithDate,
                $data['inward_unloading_duration_hours'] ?? null,
                $data['inward_unloading_duration_mins'] ?? null,
                $endWithDate,
                isset($data['inward_pallets_in_qty']) && $data['inward_pallets_in_qty'] !== '' ? (int) $data['inward_pallets_in_qty'] : 0,
                isset($data['inward_invoice_qty']) && $data['inward_invoice_qty'] !== '' ? (int) $data['inward_invoice_qty'] : 0,
                $receivedBoxes,
                $receivedBoxes,
                isset($data['inward_short_received_boxes_qty']) && $data['inward_short_received_boxes_qty'] !== '' ? (int) $data['inward_short_received_boxes_qty'] : 0,
                isset($data['inward_excess_received_boxes_qty']) && $data['inward_excess_received_boxes_qty'] !== '' ? (int) $data['inward_excess_received_boxes_qty'] : 0,
                isset($data['inward_damage_received_boxes_qty']) && $data['inward_damage_received_boxes_qty'] !== '' ? (int) $data['inward_damage_received_boxes_qty'] : 0,
                $data['inward_material_type'] ?? null,
                $data['inward_unloading_supervisor_name'] ?? null,
                $data['inward_remarks'] ?? null,
                $photos['inward_invoice_photos'],
                $photos['inward_pod_photo'],
                $photos['inward_vehicle_seal_photo'],
                $photos['inward_vehicle_temp_photo'],
                $photos['inward_material_temp_photo'],
                $photos['inward_vehicle_back_side_photo'],
                $photos['inward_vehicle_back_side_photo_with_material'],
                $photos['inward_count_sheet_photo'],
                $photos['inward_damage_boxes_photo'],
                $localTimestamp,
                $localTimestamp,
                $whFields['warehouse_name'],
                $whFields['warehouse_code'],
                $clFields['client_code'],
                $attr['operator_email'],
                $meta,
                $pick['submissionId'] !== '' ? $pick['submissionId'] : null,
                $pick['submittedAt'] !== '' ? $pick['submittedAt'] : null,
            ];

            try {
                $pdo->prepare($sql)->execute($values);
            } catch (\Throwable $e) {
                if (stripos($e->getMessage(), 'client_submission') !== false) {
                    // retry without submission cols
                    $sql2 = str_replace(', client_submission_id, client_submitted_at', '', $sql);
                    $sql2 = str_replace(', ?, ?)', ')', $sql2);
                    $pdo->prepare($sql2)->execute(array_slice($values, 0, -2));
                } elseif (stripos($e->getMessage(), 'Duplicate') !== false && $pick['submissionId'] !== '') {
                    $dup = LogDedup::findInwardDuplicate(['submissionId' => $pick['submissionId']]);
                    if ($dup) {
                        Response::json([
                            'success' => true,
                            'duplicate' => true,
                            'id' => $dup['id'],
                            'reference_no' => $dup['reference_no'],
                            'message' => 'Inward log already saved.',
                        ]);
                        return;
                    }
                    throw $e;
                } else {
                    throw $e;
                }
            }

            $insertId = (int) $pdo->lastInsertId();
            $reference_no = 'RF-IN-26-' . str_pad((string) $insertId, 4, '0', STR_PAD_LEFT);
            try {
                $pdo->prepare('UPDATE inward_temp_logs SET reference_no = ? WHERE inward_id = ?')->execute([$reference_no, $insertId]);
            } catch (\Throwable) {
            }

            ActivityLogger::log(
                (string) ($req->user['email'] ?? 'unknown'),
                'CREATE',
                'Inward Log',
                "Created Inward record (Ref: {$reference_no}) — vehicle " . ($data['inward_vehicle_no'] ?? '-') . ', client ' . ($resolvedClientName ?? '-')
            );

            Response::json([
                'id' => $insertId,
                'reference_no' => $reference_no,
                'message' => 'Inward temperature record saved successfully.',
            ], 201);
        } catch (\Throwable $e) {
            error_log('[inward add] ' . $e->getMessage());
            Response::json(['success' => false, 'message' => 'Failed to save inward log.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function updateInwardPodPhoto(Request $req, array $params = []): void
    {
        try {
            Multipart::mergeInto($req);
            $id = (int) ($params['id'] ?? 0);
            $file = Multipart::file('inward_pod_photo');
            if (!$file) {
                Response::error('inward_pod_photo is required.', 400);
                return;
            }
            $path = FileUpload::save($file, 'inward_images', 'inward', 'inward_pod_photo');
            $pdo = Database::pdo();
            $pdo->prepare('UPDATE inward_temp_logs SET inward_pod_photo = ?, inward_updated_at = NOW() WHERE inward_id = ?')
                ->execute([$path, $id]);
            Response::json(['success' => true, 'message' => 'POD photo updated.', 'inward_pod_photo' => $path]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to update POD photo.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function updateInwardLog(Request $req, array $params = []): void
    {
        try {
            Multipart::mergeInto($req);
            $id = (int) ($params['id'] ?? 0);
            $data = $req->body();
            $remarks = trim((string) ($data['remarks'] ?? $data['inward_remarks'] ?? ''));
            if ($remarks === '') {
                Response::error('Remarks are required to update this log.', 400);
                return;
            }
            if (($req->user['role'] ?? '') === 'do_operator') {
                if (!PermissionService::hasActivePermission((string) $req->user['email'], 'Inward', $id, 'Edit')) {
                    Response::error('Edit permission expired or not approved. Request Super Admin permission again.', 403);
                    return;
                }
            }

            $photos = self::collectPhotos();
            $sets = ['inward_updated_at = NOW()', 'update_count = COALESCE(update_count,0)+1'];
            $bind = [];
            $fields = [
                'inward_vehicle_no', 'inward_seal_no', 'inward_transporter_name', 'inward_driver_name', 'inward_driver_no',
                'inward_client_name', 'inward_dock_no', 'inward_material_type', 'inward_unloading_supervisor_name', 'inward_remarks',
                'inward_vehicle_reporting_time', 'inward_unloading_start_time', 'inward_unloading_end_time',
                'inward_unloading_duration_hours', 'inward_unloading_duration_mins',
            ];
            foreach ($fields as $f) {
                if (array_key_exists($f, $data)) {
                    $sets[] = "{$f} = ?";
                    $bind[] = $data[$f];
                }
            }
            foreach ($photos as $col => $path) {
                if ($path !== null) {
                    $sets[] = "{$col} = ?";
                    $bind[] = $path;
                }
            }
            if (!empty($data['photo_capture_metadata'])) {
                $meta = PhotoCaptureMeta::normalize($data['photo_capture_metadata']);
                if ($meta !== null) {
                    $sets[] = 'photo_capture_metadata = ?';
                    $bind[] = $meta;
                }
            }
            $bind[] = $id;
            Database::pdo()->prepare('UPDATE inward_temp_logs SET ' . implode(', ', $sets) . ' WHERE inward_id = ?')->execute($bind);

            if (($req->user['role'] ?? '') === 'do_operator') {
                PermissionService::consumeGrantedPermission((string) $req->user['email'], 'Inward', $id, 'Edit');
            }
            ActivityLogger::log((string) ($req->user['email'] ?? 'unknown'), 'UPDATE', 'Inward Log', "Updated Inward #{$id}. Remarks: {$remarks}");
            Response::json(['success' => true, 'message' => 'Inward log updated successfully.']);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to update inward log.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function deleteInwardLog(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            if (($req->user['role'] ?? '') === 'do_operator') {
                if (!PermissionService::hasActivePermission((string) $req->user['email'], 'Inward', $id, 'Delete')) {
                    Response::error('Delete permission expired or not approved. Request Super Admin permission again.', 403);
                    return;
                }
            }
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT reference_no, inward_vehicle_no, inward_client_name FROM inward_temp_logs WHERE inward_id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                Response::json(['error' => 'Record not found.'], 404);
                return;
            }
            $pdo->prepare('DELETE FROM inward_temp_logs WHERE inward_id = ?')->execute([$id]);
            if (($req->user['role'] ?? '') === 'do_operator') {
                PermissionService::consumeGrantedPermission((string) $req->user['email'], 'Inward', $id, 'Delete');
            }
            ActivityLogger::log(
                (string) ($req->user['email'] ?? 'unknown'),
                'DELETE',
                'Inward Log',
                'Deleted Inward (Ref: ' . ($row['reference_no'] ?? '-') . ')'
            );
            Response::json(['message' => 'Inward log deleted successfully.']);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to delete inward log.', 'error' => $e->getMessage()], 500);
        }
    }
}
