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

final class OutwardController
{
    private const PHOTO_FIELDS_MULTI = [
        'outward_invoice_photos',
        'outward_count_sheet_photo',
        'outward_damage_boxes_photo',
    ];

    private const PHOTO_FIELDS_SINGLE = [
        'outward_pod_photo',
        'outward_vehicle_seal_photo',
        'outward_vehicle_temp_photo',
        'outward_pre_vehicle_temp_photo',
        'outward_material_temp_photo',
        'outward_vehicle_back_side_photo',
        'outward_vehicle_back_side_photo_with_material',
    ];

    /** @return array<string, ?string> */
    private static function collectPhotos(): array
    {
        $out = [];
        foreach (self::PHOTO_FIELDS_MULTI as $field) {
            $paths = [];
            foreach (Multipart::files($field) as $f) {
                $paths[] = FileUpload::save($f, 'outward_images', 'outward', $field);
            }
            $out[$field] = $paths ? implode(',', $paths) : null;
        }
        foreach (self::PHOTO_FIELDS_SINGLE as $field) {
            $f = Multipart::file($field);
            $out[$field] = $f ? FileUpload::save($f, 'outward_images', 'outward', $field) : null;
        }
        return $out;
    }

    public static function getOutwardLogs(Request $req, array $params = []): void
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

            $role = ($req->user['role'] ?? '') === 'sub_admin' ? 'customer' : ($req->user['role'] ?? '');
            if ($role === 'customer' && $req->user) {
                $clients = array_filter(array_map('trim', explode(',', (string) ($req->user['allowed_clients'] ?? ''))));
                $warehouses = array_filter(array_map('trim', explode(',', (string) ($req->user['allowed_warehouses'] ?? ''))));
                if ($clients) {
                    $clients = array_map('strtolower', $clients);
                    $ph = implode(',', array_fill(0, count($clients), '?'));
                    $conditions[] = "(LOWER(TRIM(COALESCE(outward_client_code,''))) IN ({$ph}) OR LOWER(TRIM(COALESCE(outward_client_name,''))) IN ({$ph}))";
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
                $conditions[] = '(reference_no LIKE ? OR outward_vehicle_no LIKE ? OR outward_client_name LIKE ? OR outward_transporter_name LIKE ? OR outward_driver_name LIKE ? OR operator_email LIKE ?)';
                $like = '%' . $search . '%';
                array_push($bind, $like, $like, $like, $like, $like, $like);
            }
            if ($req->query('fromDate')) {
                $conditions[] = 'outward_entry_date >= ?';
                $bind[] = $req->query('fromDate');
            }
            if ($req->query('toDate')) {
                $conditions[] = 'outward_entry_date <= ?';
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
                $conditions[] = "(outward_pod_photo IS NULL OR TRIM(outward_pod_photo) = '')";
            }

            $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
            $pdo = Database::pdo();
            $cStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM outward_temp_logs {$where}");
            $cStmt->execute($bind);
            $total = (int) ($cStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $sql = "SELECT outward_id, reference_no, DATE_FORMAT(outward_entry_date, '%Y-%m-%d') as outward_entry_date, outward_vehicle_no, outward_seal_no,
                    outward_vehicle_temp, outward_pre_vehicle_temp, outward_material_temp, outward_transporter_name, outward_driver_name, outward_driver_no,
                    outward_client_name, outward_dock_no, outward_vehicle_reporting_time, outward_loading_start_time,
                    outward_loading_duration_hours, outward_loading_duration_mins, outward_loading_end_time,
                    outward_pallets_in_qty, outward_invoice_qty, outward_received_qty, outward_received_boxes_qty,
                    outward_short_received_boxes_qty, outward_excess_received_boxes_qty, outward_damage_received_boxes_qty,
                    outward_material_type, outward_loading_supervisor_name, outward_remarks, outward_invoice_photos, outward_pod_photo,
                    outward_vehicle_seal_photo, outward_vehicle_temp_photo, outward_pre_vehicle_temp_photo, outward_material_temp_photo,
                    outward_vehicle_back_side_photo, outward_vehicle_back_side_photo_with_material, outward_count_sheet_photo, outward_damage_boxes_photo,
                    photo_capture_metadata, update_details, update_count, outward_created_at, outward_updated_at, warehouse_name, warehouse_code, outward_client_code, operator_email
                    FROM outward_temp_logs {$where}
                    ORDER BY outward_entry_date DESC, outward_id DESC LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($bind, [$pageInfo['limit'], $pageInfo['offset']]));
            Response::json(Pagination::payload($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $pageInfo['page'], $pageInfo['limit']));
        } catch (\Throwable $e) {
            // Fallback if some columns missing (pre_vehicle etc.)
            error_log('[outward get] ' . $e->getMessage());
            Response::json(['success' => false, 'message' => 'Failed to fetch outward logs.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function addOutwardLog(Request $req, array $params = []): void
    {
        try {
            Multipart::mergeInto($req);
            $data = $req->body();
            $photos = self::collectPhotos();

            $required = [
                'outward_entry_date', 'outward_client_name', 'outward_dock_no', 'outward_material_type',
                'outward_vehicle_no', 'outward_transporter_name', 'outward_driver_name', 'outward_driver_no',
                'outward_vehicle_reporting_time', 'outward_loading_start_time', 'outward_loading_end_time',
                'outward_vehicle_temp', 'outward_material_temp', 'outward_loading_supervisor_name',
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
                $data['outward_client_code'] ?? $data['client_code'] ?? null,
                $data['outward_client_name'] ?? null,
                $whFields['warehouse_name'],
                $whFields['warehouse_code']
            );
            $resolvedClientName = $clFields['client_name'] ?: ($data['outward_client_name'] ?? null);

            $pick = LogDedup::pickSubmission($data);
            $existing = LogDedup::findOutwardDuplicate([
                'date' => $data['outward_entry_date'] ?? null,
                'vehicle' => $data['outward_vehicle_no'] ?? null,
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
                    'message' => 'Outward log already saved.',
                ]);
                return;
            }

            $localTimestamp = DateTimeUtil::formatDateTime();
            $dispatched = isset($data['outward_received_boxes_qty']) && $data['outward_received_boxes_qty'] !== ''
                ? (int) $data['outward_received_boxes_qty']
                : (isset($data['outward_received_qty']) && $data['outward_received_qty'] !== '' ? (int) $data['outward_received_qty'] : 0);
            $meta = PhotoCaptureMeta::normalize($data['photo_capture_metadata'] ?? null);

            $pdo = Database::pdo();
            $sql = 'INSERT INTO outward_temp_logs (
                outward_entry_date, outward_vehicle_no, outward_seal_no, outward_vehicle_temp, outward_pre_vehicle_temp, outward_material_temp,
                outward_transporter_name, outward_driver_name, outward_driver_no, outward_client_name, outward_dock_no, outward_vehicle_reporting_time,
                outward_loading_start_time, outward_loading_duration_hours, outward_loading_duration_mins, outward_loading_end_time,
                outward_pallets_in_qty, outward_invoice_qty, outward_received_qty, outward_received_boxes_qty,
                outward_short_received_boxes_qty, outward_excess_received_boxes_qty, outward_damage_received_boxes_qty,
                outward_material_type, outward_loading_supervisor_name, outward_remarks,
                outward_invoice_photos, outward_pod_photo, outward_vehicle_seal_photo, outward_vehicle_temp_photo, outward_pre_vehicle_temp_photo,
                outward_material_temp_photo, outward_vehicle_back_side_photo, outward_vehicle_back_side_photo_with_material,
                outward_count_sheet_photo, outward_damage_boxes_photo,
                outward_created_at, outward_updated_at, warehouse_name, warehouse_code, outward_client_code, operator_email, photo_capture_metadata,
                client_submission_id, client_submitted_at
              ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';

            $values = [
                $data['outward_entry_date'],
                $data['outward_vehicle_no'],
                $data['outward_seal_no'] ?? null,
                isset($data['outward_vehicle_temp']) && $data['outward_vehicle_temp'] !== '' ? (float) $data['outward_vehicle_temp'] : null,
                isset($data['outward_pre_vehicle_temp']) && $data['outward_pre_vehicle_temp'] !== '' ? (float) $data['outward_pre_vehicle_temp'] : null,
                isset($data['outward_material_temp']) && $data['outward_material_temp'] !== '' ? (float) $data['outward_material_temp'] : null,
                $data['outward_transporter_name'] ?? null,
                $data['outward_driver_name'] ?? null,
                $data['outward_driver_no'] ?? null,
                $resolvedClientName,
                $data['outward_dock_no'] ?? null,
                $data['outward_vehicle_reporting_time'] ?? null,
                $data['outward_loading_start_time'] ?? null,
                $data['outward_loading_duration_hours'] ?? null,
                $data['outward_loading_duration_mins'] ?? null,
                $data['outward_loading_end_time'] ?? null,
                isset($data['outward_pallets_in_qty']) && $data['outward_pallets_in_qty'] !== '' ? (int) $data['outward_pallets_in_qty'] : 0,
                isset($data['outward_invoice_qty']) && $data['outward_invoice_qty'] !== '' ? (int) $data['outward_invoice_qty'] : 0,
                $dispatched,
                $dispatched,
                isset($data['outward_short_received_boxes_qty']) && $data['outward_short_received_boxes_qty'] !== '' ? (int) $data['outward_short_received_boxes_qty'] : 0,
                isset($data['outward_excess_received_boxes_qty']) && $data['outward_excess_received_boxes_qty'] !== '' ? (int) $data['outward_excess_received_boxes_qty'] : 0,
                isset($data['outward_damage_received_boxes_qty']) && $data['outward_damage_received_boxes_qty'] !== '' ? (int) $data['outward_damage_received_boxes_qty'] : 0,
                $data['outward_material_type'] ?? null,
                $data['outward_loading_supervisor_name'] ?? null,
                $data['outward_remarks'] ?? null,
                $photos['outward_invoice_photos'],
                $photos['outward_pod_photo'],
                $photos['outward_vehicle_seal_photo'],
                $photos['outward_vehicle_temp_photo'],
                $photos['outward_pre_vehicle_temp_photo'],
                $photos['outward_material_temp_photo'],
                $photos['outward_vehicle_back_side_photo'],
                $photos['outward_vehicle_back_side_photo_with_material'],
                $photos['outward_count_sheet_photo'],
                $photos['outward_damage_boxes_photo'],
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
                if (stripos($e->getMessage(), 'client_submission') !== false || stripos($e->getMessage(), 'pre_vehicle') !== false) {
                    // Simplified insert without optional columns
                    $sql2 = 'INSERT INTO outward_temp_logs (
                        outward_entry_date, outward_vehicle_no, outward_seal_no, outward_vehicle_temp, outward_material_temp,
                        outward_transporter_name, outward_driver_name, outward_driver_no, outward_client_name, outward_dock_no,
                        outward_vehicle_reporting_time, outward_loading_start_time, outward_loading_end_time,
                        outward_material_type, outward_loading_supervisor_name, outward_remarks,
                        outward_invoice_photos, outward_pod_photo, outward_vehicle_temp_photo, outward_material_temp_photo,
                        outward_created_at, outward_updated_at, warehouse_name, warehouse_code, outward_client_code, operator_email,
                        photo_capture_metadata
                      ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                    $pdo->prepare($sql2)->execute([
                        $data['outward_entry_date'], $data['outward_vehicle_no'], $data['outward_seal_no'] ?? null,
                        $values[3], $values[5], $values[6], $values[7], $values[8], $resolvedClientName, $data['outward_dock_no'] ?? null,
                        $data['outward_vehicle_reporting_time'] ?? null, $data['outward_loading_start_time'] ?? null, $data['outward_loading_end_time'] ?? null,
                        $data['outward_material_type'] ?? null, $data['outward_loading_supervisor_name'] ?? null, $data['outward_remarks'] ?? null,
                        $photos['outward_invoice_photos'], $photos['outward_pod_photo'], $photos['outward_vehicle_temp_photo'], $photos['outward_material_temp_photo'],
                        $localTimestamp, $localTimestamp, $whFields['warehouse_name'], $whFields['warehouse_code'], $clFields['client_code'], $attr['operator_email'],
                        $meta,
                    ]);
                } elseif (stripos($e->getMessage(), 'Duplicate') !== false && $pick['submissionId'] !== '') {
                    $dup = LogDedup::findOutwardDuplicate(['submissionId' => $pick['submissionId']]);
                    if ($dup) {
                        Response::json([
                            'success' => true,
                            'duplicate' => true,
                            'id' => $dup['id'],
                            'reference_no' => $dup['reference_no'],
                            'message' => 'Outward log already saved.',
                        ]);
                        return;
                    }
                    throw $e;
                } else {
                    throw $e;
                }
            }

            $insertId = (int) $pdo->lastInsertId();
            $reference_no = 'RF-OUT-26-' . str_pad((string) $insertId, 4, '0', STR_PAD_LEFT);
            try {
                $pdo->prepare('UPDATE outward_temp_logs SET reference_no = ? WHERE outward_id = ?')->execute([$reference_no, $insertId]);
            } catch (\Throwable) {
            }

            ActivityLogger::log(
                (string) ($req->user['email'] ?? 'unknown'),
                'CREATE',
                'Outward Log',
                "Created Outward record (Ref: {$reference_no}) — vehicle " . ($data['outward_vehicle_no'] ?? '-')
            );

            Response::json([
                'id' => $insertId,
                'reference_no' => $reference_no,
                'message' => 'Outward temperature record saved successfully.',
            ], 201);
        } catch (\Throwable $e) {
            error_log('[outward add] ' . $e->getMessage());
            Response::json(['success' => false, 'message' => 'Failed to save outward log.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function updateOutwardPodPhoto(Request $req, array $params = []): void
    {
        try {
            Multipart::mergeInto($req);
            $id = (int) ($params['id'] ?? 0);
            $file = Multipart::file('outward_pod_photo');
            if (!$file) {
                Response::error('outward_pod_photo is required.', 400);
                return;
            }
            $path = FileUpload::save($file, 'outward_images', 'outward', 'outward_pod_photo');
            Database::pdo()->prepare('UPDATE outward_temp_logs SET outward_pod_photo = ?, outward_updated_at = NOW() WHERE outward_id = ?')
                ->execute([$path, $id]);
            Response::json(['success' => true, 'message' => 'POD photo updated.', 'outward_pod_photo' => $path]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to update POD photo.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function updateOutwardLog(Request $req, array $params = []): void
    {
        try {
            Multipart::mergeInto($req);
            $id = (int) ($params['id'] ?? 0);
            $data = $req->body();
            $remarks = trim((string) ($data['remarks'] ?? $data['outward_remarks'] ?? ''));
            if ($remarks === '') {
                Response::error('Remarks are required to update this log.', 400);
                return;
            }
            if (($req->user['role'] ?? '') === 'do_operator') {
                if (!PermissionService::hasActivePermission((string) $req->user['email'], 'Outward', $id, 'Edit')) {
                    Response::error('Edit permission expired or not approved. Request Super Admin permission again.', 403);
                    return;
                }
            }
            $photos = self::collectPhotos();
            $sets = ['outward_updated_at = NOW()', 'update_count = COALESCE(update_count,0)+1'];
            $bind = [];
            foreach (['outward_vehicle_no', 'outward_client_name', 'outward_dock_no', 'outward_material_type', 'outward_remarks', 'outward_loading_supervisor_name'] as $f) {
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
            Database::pdo()->prepare('UPDATE outward_temp_logs SET ' . implode(', ', $sets) . ' WHERE outward_id = ?')->execute($bind);
            if (($req->user['role'] ?? '') === 'do_operator') {
                PermissionService::consumeGrantedPermission((string) $req->user['email'], 'Outward', $id, 'Edit');
            }
            Response::json(['success' => true, 'message' => 'Outward log updated successfully.']);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to update outward log.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function deleteOutwardLog(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            if (($req->user['role'] ?? '') === 'do_operator') {
                if (!PermissionService::hasActivePermission((string) $req->user['email'], 'Outward', $id, 'Delete')) {
                    Response::error('Delete permission expired or not approved. Request Super Admin permission again.', 403);
                    return;
                }
            }
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT reference_no FROM outward_temp_logs WHERE outward_id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                Response::json(['error' => 'Record not found.'], 404);
                return;
            }
            $pdo->prepare('DELETE FROM outward_temp_logs WHERE outward_id = ?')->execute([$id]);
            if (($req->user['role'] ?? '') === 'do_operator') {
                PermissionService::consumeGrantedPermission((string) $req->user['email'], 'Outward', $id, 'Delete');
            }
            Response::json(['message' => 'Outward log deleted successfully.']);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to delete outward log.', 'error' => $e->getMessage()], 500);
        }
    }
}
