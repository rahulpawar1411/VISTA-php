<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\ClientCodeGenerator;
use PDO;

final class MasterController
{
    private static function normalizeCode(?string $value, string $prefix): string
    {
        $v = strtoupper(trim((string) $value));
        if ($v === '') {
            return '';
        }
        if (!str_starts_with($v, $prefix . '-')) {
            return '';
        }
        if (!preg_match('/^[A-Z0-9-]+$/', $v)) {
            return '';
        }
        return $v;
    }

    public static function listWarehouses(Request $req, array $params = []): void
    {
        try {
            $q = trim((string) ($req->query('q') ?? ''));
            $activeOnly = ($req->query('active_only', '1') ?? '1') !== '0';
            $where = 'WHERE 1=1';
            $bind = [];
            if ($activeOnly) {
                $where .= ' AND is_active = 1';
            }
            if ($q !== '') {
                $where .= ' AND (warehouse_code LIKE ? OR warehouse_name LIKE ? OR city LIKE ?)';
                $like = '%' . $q . '%';
                $bind = [$like, $like, $like];
            }
            $stmt = Database::pdo()->prepare(
                "SELECT id, warehouse_code, warehouse_name, city, is_active, created_at, updated_at
                 FROM warehouse_master
                 {$where}
                 ORDER BY is_active DESC, warehouse_name ASC"
            );
            $stmt->execute($bind);
            Response::json(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to fetch warehouses.');
        }
    }

    public static function createWarehouse(Request $req, array $params = []): void
    {
        try {
            $body = $req->body();
            $warehouse_code = self::normalizeCode($body['warehouse_code'] ?? null, 'WH');
            $warehouse_name = trim((string) ($body['warehouse_name'] ?? ''));
            $city = trim((string) ($body['city'] ?? ''));
            $city = $city !== '' ? $city : null;
            if ($warehouse_code === '' || $warehouse_name === '') {
                Response::error('Warehouse code and name are required.', 400);
                return;
            }

            $pdo = Database::pdo();
            $finalCode = $warehouse_code;
            $stmt = $pdo->prepare('SELECT warehouse_code FROM warehouse_master WHERE warehouse_code = ? LIMIT 1');
            $stmt->execute([$finalCode]);
            if ($stmt->fetch()) {
                for ($i = 2; $i <= 99; $i++) {
                    $candidate = substr($warehouse_code . '-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 0, 48);
                    $stmt->execute([$candidate]);
                    if (!$stmt->fetch()) {
                        $finalCode = $candidate;
                        break;
                    }
                }
            }

            $ins = $pdo->prepare(
                'INSERT INTO warehouse_master (warehouse_code, warehouse_name, city, is_active) VALUES (?, ?, ?, 1)'
            );
            $ins->execute([$finalCode, $warehouse_name, $city]);
            Response::json([
                'success' => true,
                'message' => 'Warehouse created successfully.',
                'data' => [
                    'id' => (int) $pdo->lastInsertId(),
                    'warehouse_code' => $finalCode,
                    'warehouse_name' => $warehouse_name,
                    'city' => $city,
                    'is_active' => 1,
                ],
            ], 201);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate') !== false) {
                Response::error('Warehouse code already exists.', 409);
                return;
            }
            self::fail($e, 'Failed to create warehouse.');
        }
    }

    public static function updateWarehouse(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            if (!$id) {
                Response::error('Invalid warehouse id.', 400);
                return;
            }
            $body = $req->body();
            $sets = [];
            $bind = [];
            if (array_key_exists('warehouse_name', $body)) {
                $name = trim((string) $body['warehouse_name']);
                if ($name === '') {
                    Response::error('Warehouse name cannot be empty.', 400);
                    return;
                }
                $sets[] = 'warehouse_name = ?';
                $bind[] = $name;
            }
            if (array_key_exists('city', $body)) {
                $city = trim((string) $body['city']);
                $sets[] = 'city = ?';
                $bind[] = $city !== '' ? $city : null;
            }
            if (array_key_exists('is_active', $body)) {
                $sets[] = 'is_active = ?';
                $bind[] = $body['is_active'] ? 1 : 0;
            }
            if (!$sets) {
                Response::error('No fields to update.', 400);
                return;
            }
            $sets[] = 'updated_at = NOW()';
            $bind[] = $id;
            $stmt = Database::pdo()->prepare('UPDATE warehouse_master SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($bind);
            if ($stmt->rowCount() === 0) {
                Response::error('Warehouse not found.', 404);
                return;
            }
            Response::json(['success' => true, 'message' => 'Warehouse updated successfully.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to update warehouse.');
        }
    }

    public static function deleteWarehouse(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            if (!$id) {
                Response::error('Invalid warehouse id.', 400);
                return;
            }
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT id FROM warehouse_master WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                Response::error('Warehouse not found.', 404);
                return;
            }
            $upd = $pdo->prepare('UPDATE warehouse_master SET is_active = 0, updated_at = NOW() WHERE id = ?');
            $upd->execute([$id]);
            Response::json(['success' => true, 'message' => 'Warehouse deactivated.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to delete warehouse.');
        }
    }

    public static function listClients(Request $req, array $params = []): void
    {
        try {
            $q = trim((string) ($req->query('q') ?? ''));
            $activeOnly = ($req->query('active_only', '1') ?? '1') !== '0';
            $warehouseCode = trim((string) ($req->query('warehouse_code') ?? ''));
            $where = 'WHERE 1=1';
            $bind = [];
            if ($activeOnly) {
                $where .= ' AND cm.is_active = 1';
            }
            if ($warehouseCode !== '') {
                $where .= ' AND wm.warehouse_code = ?';
                $bind[] = $warehouseCode;
            }
            if ($q !== '') {
                $where .= ' AND (cm.client_code LIKE ? OR cm.client_name LIKE ? OR COALESCE(cm.warehouse_name, \'\') LIKE ?)';
                $like = '%' . $q . '%';
                array_push($bind, $like, $like, $like);
            }
            $stmt = Database::pdo()->prepare(
                "SELECT cm.id, cm.client_code, cm.client_name, cm.warehouse_name, cm.is_active, cm.created_at, cm.updated_at,
                        wm.warehouse_code
                 FROM client_master cm
                 LEFT JOIN warehouse_master wm
                   ON LOWER(TRIM(wm.warehouse_name)) = LOWER(TRIM(COALESCE(cm.warehouse_name, '')))
                 {$where}
                 ORDER BY cm.is_active DESC, cm.client_name ASC"
            );
            $stmt->execute($bind);
            Response::json(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to fetch clients.');
        }
    }

    public static function createClient(Request $req, array $params = []): void
    {
        try {
            $body = $req->body();
            $client_name = trim((string) ($body['client_name'] ?? ''));
            $warehouse_name = trim((string) ($body['warehouse_name'] ?? ''));
            $warehouse_name = $warehouse_name !== '' ? $warehouse_name : null;
            $warehouse_code = trim((string) ($body['warehouse_code'] ?? ''));
            $warehouse_code = $warehouse_code !== '' ? $warehouse_code : null;

            $pdo = Database::pdo();
            if ($warehouse_name && !$warehouse_code) {
                $stmt = $pdo->prepare(
                    'SELECT warehouse_code FROM warehouse_master WHERE LOWER(TRIM(warehouse_name)) = LOWER(TRIM(?)) LIMIT 1'
                );
                $stmt->execute([$warehouse_name]);
                $wh = $stmt->fetch(PDO::FETCH_ASSOC);
                $warehouse_code = $wh['warehouse_code'] ?? null;
            }

            $client_code = self::normalizeCode($body['client_code'] ?? null, 'CL');
            if ($client_code === '' && $client_name !== '') {
                $client_code = self::normalizeCode(
                    ClientCodeGenerator::generate($client_name, $warehouse_name, $warehouse_code),
                    'CL'
                );
            }
            if ($client_code === '' || $client_name === '') {
                Response::error('Client code and name are required.', 400);
                return;
            }

            $finalCode = $client_code;
            $stmt = $pdo->prepare('SELECT client_code FROM client_master WHERE client_code = ? LIMIT 1');
            $stmt->execute([$finalCode]);
            if ($stmt->fetch()) {
                for ($i = 2; $i <= 99; $i++) {
                    $candidate = substr($client_code . '-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 0, 48);
                    $stmt->execute([$candidate]);
                    if (!$stmt->fetch()) {
                        $finalCode = $candidate;
                        break;
                    }
                }
            }

            $ins = $pdo->prepare(
                'INSERT INTO client_master (client_code, client_name, warehouse_name, is_active) VALUES (?, ?, ?, 1)'
            );
            $ins->execute([$finalCode, $client_name, $warehouse_name]);
            Response::json([
                'success' => true,
                'message' => 'Client created successfully.',
                'client_code' => $finalCode,
                'data' => [
                    'id' => (int) $pdo->lastInsertId(),
                    'client_code' => $finalCode,
                    'client_name' => $client_name,
                    'warehouse_name' => $warehouse_name,
                    'is_active' => 1,
                ],
            ], 201);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate') !== false) {
                Response::error('Client code already exists.', 409);
                return;
            }
            self::fail($e, 'Failed to create client.');
        }
    }

    public static function updateClient(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            if (!$id) {
                Response::error('Invalid client id.', 400);
                return;
            }
            $body = $req->body();
            $sets = [];
            $bind = [];
            if (array_key_exists('client_name', $body)) {
                $name = trim((string) $body['client_name']);
                if ($name === '') {
                    Response::error('Client name cannot be empty.', 400);
                    return;
                }
                $sets[] = 'client_name = ?';
                $bind[] = $name;
            }
            if (array_key_exists('warehouse_name', $body)) {
                $wh = trim((string) $body['warehouse_name']);
                $sets[] = 'warehouse_name = ?';
                $bind[] = $wh !== '' ? $wh : null;
            }
            if (array_key_exists('is_active', $body)) {
                $sets[] = 'is_active = ?';
                $bind[] = $body['is_active'] ? 1 : 0;
            }
            if (!$sets) {
                Response::error('No fields to update.', 400);
                return;
            }
            $sets[] = 'updated_at = NOW()';
            $bind[] = $id;
            $stmt = Database::pdo()->prepare('UPDATE client_master SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($bind);
            Response::json(['success' => true, 'message' => 'Client updated successfully.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to update client.');
        }
    }

    public static function deleteClient(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            if (!$id) {
                Response::error('Invalid client id.', 400);
                return;
            }
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT id FROM client_master WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                Response::error('Client not found.', 404);
                return;
            }
            $upd = $pdo->prepare('UPDATE client_master SET is_active = 0, updated_at = NOW() WHERE id = ?');
            $upd->execute([$id]);
            Response::json(['success' => true, 'message' => 'Client deactivated.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to delete client.');
        }
    }

    private static function fail(\Throwable $e, string $msg): void
    {
        error_log('[masters] ' . $e->getMessage());
        Response::json(['success' => false, 'message' => $msg, 'error' => $e->getMessage()], 500);
    }
}
