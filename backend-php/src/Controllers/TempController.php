<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\LogAttribution;
use App\Services\MasterResolver;
use PDO;

final class TempController
{
    public static function getAllTempLogs(Request $req, array $params = []): void
    {
        try {
            $sql = 'SELECT * FROM daily_temp_logs WHERE 1=1';
            $bind = [];

            $entryType = $req->query('entry_type');
            if ($entryType && $entryType !== 'All') {
                $sql .= ' AND entry_type = ?';
                $bind[] = $entryType;
            }
            $status = $req->query('status');
            if ($status && $status !== 'All') {
                $sql .= ' AND status = ?';
                $bind[] = $status;
            }
            $search = $req->query('search');
            if ($search) {
                $sql .= ' AND (container_number LIKE ? OR client_name LIKE ? OR cargo_type LIKE ? OR driver_name LIKE ? OR seal_number LIKE ?)';
                $like = '%' . $search . '%';
                array_push($bind, $like, $like, $like, $like, $like);
            }

            $user = $req->user;
            if (($user['role'] ?? '') === 'do_operator' && (($user['warehouse_name'] ?? null) || ($user['warehouse_code'] ?? null))) {
                $sql .= ' AND (warehouse_name IS NULL OR TRIM(COALESCE(warehouse_name, \'\')) = \'\' OR LOWER(TRIM(COALESCE(warehouse_code, \'\'))) = ? OR LOWER(TRIM(COALESCE(warehouse_name, \'\'))) = ?)';
                $bind[] = strtolower(trim((string) ($user['warehouse_code'] ?? '')));
                $bind[] = strtolower(trim((string) ($user['warehouse_name'] ?? '')));
            }

            $sql .= ' ORDER BY recorded_at DESC';
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute($bind);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            Response::json(['success' => true, 'count' => count($rows), 'data' => $rows]);
        } catch (\Throwable $e) {
            Response::json([
                'success' => false,
                'message' => 'Failed to fetch temperature logs from database.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public static function createTempLog(Request $req, array $params = []): void
    {
        try {
            $body = $req->body();
            $entry_type = $body['entry_type'] ?? null;
            $container_number = $body['container_number'] ?? null;
            $client_name = $body['client_name'] ?? null;
            $target_temp = $body['target_temp'] ?? null;
            $actual_temp = $body['actual_temp'] ?? null;

            if (!$entry_type || !$container_number || !$client_name || $target_temp === null || $actual_temp === null) {
                Response::json([
                    'success' => false,
                    'message' => 'Entry Type, Container Number, Client Name, Target Temp, and Actual Temp are required.',
                ], 400);
                return;
            }

            $target = (float) $target_temp;
            $actual = (float) $actual_temp;
            $variance = abs($actual - $target);
            $genset = (string) ($body['genset_status'] ?? 'Running');
            $status = 'Normal';
            if ($genset === 'Faulty' || $variance > 4.0) {
                $status = 'Critical';
            } elseif ($variance > 1.5) {
                $status = 'Warning';
            }

            $attr = LogAttribution::resolve($req->user, $body);
            $whFields = MasterResolver::resolveWarehouseFields(
                (string) ($body['warehouse_code'] ?? $attr['warehouse_code'] ?? ''),
                (string) ($attr['warehouse_name'] ?? '')
            );
            $clFields = MasterResolver::resolveClientFields(
                isset($body['client_code']) ? (string) $body['client_code'] : null,
                (string) $client_name,
                $whFields['warehouse_name'],
                $whFields['warehouse_code']
            );
            $resolvedClientName = $clFields['client_name'] ?: $client_name;

            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO daily_temp_logs
                 (entry_type, container_number, client_name, client_code, cargo_type, target_temp, actual_temp, temp_variance, status,
                  location_dock, driver_name, driver_phone, seal_number, genset_status, fuel_level, operator_name, remarks,
                  warehouse_name, warehouse_code, operator_email)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $entry_type,
                $container_number,
                $resolvedClientName,
                $clFields['client_code'],
                $body['cargo_type'] ?? 'Cold Cargo',
                $target,
                $actual,
                $variance,
                $status,
                $body['location_dock'] ?? 'Bay 1',
                $body['driver_name'] ?? '',
                $body['driver_phone'] ?? '',
                $body['seal_number'] ?? '',
                $genset,
                $body['fuel_level'] ?? '100%',
                $body['operator_name'] ?? 'Data Operator DO',
                $body['remarks'] ?? '',
                $whFields['warehouse_name'],
                $whFields['warehouse_code'],
                $attr['operator_email'],
            ]);

            Response::json([
                'success' => true,
                'message' => 'DO daily temperature log recorded successfully!',
                'logId' => (int) $pdo->lastInsertId(),
                'calculatedStatus' => $status,
                'variance' => $variance,
            ], 201);
        } catch (\Throwable $e) {
            Response::json([
                'success' => false,
                'message' => 'Server error while recording DO temperature log.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public static function deleteTempLog(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $stmt = Database::pdo()->prepare('DELETE FROM daily_temp_logs WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                Response::json(['success' => false, 'message' => "Temp log with ID {$id} not found."], 404);
                return;
            }
            Response::json(['success' => true, 'message' => 'Daily temperature log deleted successfully.']);
        } catch (\Throwable $e) {
            Response::json([
                'success' => false,
                'message' => 'Server error while deleting temperature log.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
