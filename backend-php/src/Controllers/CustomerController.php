<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\ActivityLogger;
use App\Services\MailService;
use PDO;

/** Portal customers — mounted at /api/customers (Node: subAdminController). */
final class CustomerController
{
    private static function normalizeScopeCsv($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            $parts = array_values(array_filter(array_map(static fn($v) => trim((string) $v), $value)));
        } else {
            $parts = array_values(array_filter(array_map('trim', explode(',', (string) $value))));
        }
        return $parts ? implode(',', $parts) : null;
    }

    public static function list(Request $req, array $params = []): void
    {
        try {
            $rows = Database::pdo()->query(
                'SELECT id, email, full_name, phone_no, allowed_clients, allowed_warehouses, created_at
                 FROM customers ORDER BY id DESC'
            )->fetchAll(PDO::FETCH_ASSOC);
            Response::json($rows);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to fetch customers.');
        }
    }

    public static function create(Request $req, array $params = []): void
    {
        try {
            $body = $req->body();
            $cleanEmail = strtolower(trim((string) ($body['email'] ?? '')));
            $password = (string) ($body['password'] ?? '');
            $cleanFullName = trim((string) ($body['full_name'] ?? ''));
            $cleanPhone = trim((string) ($body['phone_no'] ?? ''));

            if ($cleanEmail === '' || $password === '' || $cleanFullName === '' || $cleanPhone === '') {
                Response::json(['error' => 'All fields (Email, Password, Full Name, Phone No.) are required.'], 400);
                return;
            }

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
            $stmt->execute([$cleanEmail]);
            if ($stmt->fetch()) {
                Response::json(['error' => 'Customer email already exists.'], 400);
                return;
            }
            $stmt = $pdo->prepare('SELECT id FROM super_admin WHERE email = ? LIMIT 1');
            $stmt->execute([$cleanEmail]);
            if ($stmt->fetch()) {
                Response::json(['error' => 'Email already used by Super Admin.'], 400);
                return;
            }
            try {
                $stmt = $pdo->prepare('SELECT id FROM sub_admins WHERE email = ? LIMIT 1');
                $stmt->execute([$cleanEmail]);
                if ($stmt->fetch()) {
                    Response::json(['error' => 'Email already used by a Sub-Admin.'], 400);
                    return;
                }
            } catch (\Throwable) {
            }
            $stmt = $pdo->prepare('SELECT id FROM do_operators WHERE email = ? LIMIT 1');
            $stmt->execute([$cleanEmail]);
            if ($stmt->fetch()) {
                Response::json(['error' => 'Email already used by a Data Operator.'], 400);
                return;
            }

            $clientsStr = self::normalizeScopeCsv($body['allowed_clients'] ?? null);
            $warehousesStr = self::normalizeScopeCsv($body['allowed_warehouses'] ?? null);
            $hashed = password_hash($password, PASSWORD_BCRYPT);

            $ins = $pdo->prepare(
                'INSERT INTO customers (email, password, full_name, phone_no, allowed_clients, allowed_warehouses)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$cleanEmail, $hashed, $cleanFullName, $cleanPhone, $clientsStr, $warehousesStr]);

            $actor = (string) ($req->user['email'] ?? 'super_admin');
            ActivityLogger::log(
                $actor,
                'CREATE',
                'PERMISSION',
                "Registered customer profile: {$cleanEmail} | Access: Clients=[" . ($clientsStr ?: 'All') . '] Warehouses=[' . ($warehousesStr ?: 'All') . ']'
            );

            $mail = MailService::sendCredentials($cleanEmail, $cleanFullName, 'Customer', $password);
            if ($mail['ok']) {
                Response::json([
                    'message' => 'Customer created successfully. Credentials email sent.',
                    'emailSent' => true,
                ], 201);
            } else {
                Response::json([
                    'message' => 'Customer created successfully, but credentials email could not be sent.',
                    'emailSent' => false,
                    'emailSkipped' => false,
                    'emailError' => $mail['error'],
                ], 201);
            }
        } catch (\Throwable $e) {
            $detail = 'Failed to create customer.';
            if (stripos($e->getMessage(), 'Duplicate') !== false) {
                $detail = 'Customer email already exists.';
                Response::json(['success' => false, 'message' => $detail, 'error' => $detail], 409);
                return;
            }
            self::fail($e, $detail);
        }
    }

    public static function update(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $body = $req->body();
            $cleanEmail = strtolower(trim((string) ($body['email'] ?? '')));
            $password = $body['password'] ?? null;
            $cleanFullName = trim((string) ($body['full_name'] ?? ''));
            $cleanPhone = trim((string) ($body['phone_no'] ?? ''));

            if ($cleanEmail === '' || $cleanFullName === '' || $cleanPhone === '') {
                Response::json(['error' => 'All fields (Email, Full Name, Phone No.) are required.'], 400);
                return;
            }

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT id FROM customers WHERE email = ? AND id != ? LIMIT 1');
            $stmt->execute([$cleanEmail, $id]);
            if ($stmt->fetch()) {
                Response::json(['error' => 'Email is already taken by another customer.'], 400);
                return;
            }

            $clientsStr = self::normalizeScopeCsv($body['allowed_clients'] ?? null);
            $warehousesStr = self::normalizeScopeCsv($body['allowed_warehouses'] ?? null);

            if ($password && trim((string) $password) !== '') {
                $hashed = password_hash((string) $password, PASSWORD_BCRYPT);
                $upd = $pdo->prepare(
                    'UPDATE customers SET email = ?, password = ?, full_name = ?, phone_no = ?, allowed_clients = ?, allowed_warehouses = ? WHERE id = ?'
                );
                $upd->execute([$cleanEmail, $hashed, $cleanFullName, $cleanPhone, $clientsStr, $warehousesStr, $id]);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE customers SET email = ?, full_name = ?, phone_no = ?, allowed_clients = ?, allowed_warehouses = ? WHERE id = ?'
                );
                $upd->execute([$cleanEmail, $cleanFullName, $cleanPhone, $clientsStr, $warehousesStr, $id]);
            }

            $actor = (string) ($req->user['email'] ?? 'super_admin');
            ActivityLogger::log(
                $actor,
                'UPDATE',
                'PERMISSION',
                "Updated customer profile: {$cleanEmail} | Access: Clients=[" . ($clientsStr ?: 'All') . '] Warehouses=[' . ($warehousesStr ?: 'All') . ']'
            );
            Response::json(['message' => 'Customer updated successfully.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to update customer.');
        }
    }

    public static function delete(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT email FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $opEmail = $row['email'] ?? ("ID {$id}");

            $del = $pdo->prepare('DELETE FROM customers WHERE id = ?');
            $del->execute([$id]);

            $actor = (string) ($req->user['email'] ?? 'super_admin');
            ActivityLogger::log(
                $actor,
                'DELETE',
                'PERMISSION',
                "Revoked workspace access for customer: {$opEmail}"
            );
            Response::json(['message' => 'Customer deleted successfully.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to delete customer.');
        }
    }

    private static function fail(\Throwable $e, string $msg): void
    {
        error_log('[customers] ' . $e->getMessage());
        Response::json(['success' => false, 'message' => $msg, 'error' => $e->getMessage()], 500);
    }
}
