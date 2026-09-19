<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\ActivityLogger;
use PDO;

/** Mobile Sub-Admins — /api/sub-admins (full app access). */
final class AppSubAdminController
{
    private static bool $tableReady = false;

    private static function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        Database::pdo()->exec(
            "CREATE TABLE IF NOT EXISTS sub_admins (
              id INT AUTO_INCREMENT PRIMARY KEY,
              email VARCHAR(150) NOT NULL UNIQUE,
              password VARCHAR(255) NOT NULL,
              full_name VARCHAR(150) DEFAULT NULL,
              phone_no VARCHAR(20) DEFAULT NULL,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL
            )"
        );
        self::$tableReady = true;
    }

    private static function emailTakenElsewhere(string $cleanEmail, $excludeSubAdminId = null): ?string
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM super_admin WHERE email = ? LIMIT 1');
        $stmt->execute([$cleanEmail]);
        if ($stmt->fetch()) {
            return 'super_admin';
        }
        try {
            $stmt = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
            $stmt->execute([$cleanEmail]);
            if ($stmt->fetch()) {
                return 'customer';
            }
        } catch (\Throwable) {
        }
        $stmt = $pdo->prepare('SELECT id FROM do_operators WHERE email = ? LIMIT 1');
        $stmt->execute([$cleanEmail]);
        if ($stmt->fetch()) {
            return 'do_operator';
        }
        self::ensureTable();
        $stmt = $pdo->prepare('SELECT id FROM sub_admins WHERE email = ? LIMIT 1');
        $stmt->execute([$cleanEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (string) $row['id'] !== (string) ($excludeSubAdminId ?? '')) {
            return 'sub_admin';
        }
        return null;
    }

    public static function list(Request $req, array $params = []): void
    {
        try {
            self::ensureTable();
            $rows = Database::pdo()->query(
                'SELECT id, email, full_name, phone_no, created_at FROM sub_admins ORDER BY id DESC'
            )->fetchAll(PDO::FETCH_ASSOC);
            Response::json($rows);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to fetch Sub-Admins.');
        }
    }

    public static function create(Request $req, array $params = []): void
    {
        try {
            self::ensureTable();
            $body = $req->body();
            $cleanEmail = strtolower(trim((string) ($body['email'] ?? '')));
            $password = (string) ($body['password'] ?? '');
            $cleanFullName = trim((string) ($body['full_name'] ?? ''));
            $cleanPhone = trim((string) ($body['phone_no'] ?? ''));

            if ($cleanEmail === '' || $password === '' || $cleanFullName === '' || $cleanPhone === '') {
                Response::json(['error' => 'All fields (Name, Phone, Email, Password) are required.'], 400);
                return;
            }

            $taken = self::emailTakenElsewhere($cleanEmail);
            if ($taken) {
                Response::json([
                    'error' => 'Email already used by another ' . str_replace('_', ' ', $taken) . ' account.',
                ], 400);
                return;
            }

            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $pdo = Database::pdo();
            $ins = $pdo->prepare(
                'INSERT INTO sub_admins (email, password, full_name, phone_no) VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$cleanEmail, $hashed, $cleanFullName, $cleanPhone]);

            $actor = (string) ($req->user['email'] ?? 'super_admin');
            ActivityLogger::log(
                $actor,
                'SUB_ADMIN_CREATE',
                'USER_MGMT',
                "Registered mobile Sub-Admin: {$cleanFullName} <{$cleanEmail}> (full app access)"
            );

            Response::json([
                'message' => 'Sub-Admin created successfully.',
                'id' => (int) $pdo->lastInsertId(),
                'email' => $cleanEmail,
                'full_name' => $cleanFullName,
                'phone_no' => $cleanPhone,
            ], 201);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to create Sub-Admin.');
        }
    }

    public static function update(Request $req, array $params = []): void
    {
        try {
            self::ensureTable();
            $id = (int) ($params['id'] ?? 0);
            $body = $req->body();
            $cleanEmail = strtolower(trim((string) ($body['email'] ?? '')));
            $password = $body['password'] ?? null;
            $cleanFullName = trim((string) ($body['full_name'] ?? ''));
            $cleanPhone = trim((string) ($body['phone_no'] ?? ''));

            if ($cleanEmail === '' || $cleanFullName === '' || $cleanPhone === '') {
                Response::json(['error' => 'Name, Phone and Email are required.'], 400);
                return;
            }

            $taken = self::emailTakenElsewhere($cleanEmail, $id);
            if ($taken) {
                Response::json([
                    'error' => 'Email already used by another ' . str_replace('_', ' ', $taken) . ' account.',
                ], 400);
                return;
            }

            $pdo = Database::pdo();
            if ($password && trim((string) $password) !== '') {
                $hashed = password_hash((string) $password, PASSWORD_BCRYPT);
                $upd = $pdo->prepare(
                    'UPDATE sub_admins SET email = ?, password = ?, full_name = ?, phone_no = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
                );
                $upd->execute([$cleanEmail, $hashed, $cleanFullName, $cleanPhone, $id]);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE sub_admins SET email = ?, full_name = ?, phone_no = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
                );
                $upd->execute([$cleanEmail, $cleanFullName, $cleanPhone, $id]);
            }

            $actor = (string) ($req->user['email'] ?? 'super_admin');
            ActivityLogger::log(
                $actor,
                'SUB_ADMIN_UPDATE',
                'USER_MGMT',
                "Updated mobile Sub-Admin: {$cleanFullName} <{$cleanEmail}>"
            );
            Response::json(['message' => 'Sub-Admin updated successfully.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to update Sub-Admin.');
        }
    }

    public static function delete(Request $req, array $params = []): void
    {
        try {
            self::ensureTable();
            $id = (int) ($params['id'] ?? 0);
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT email, full_name FROM sub_admins WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $label = $row ? trim(($row['full_name'] ?? '') . ' <' . $row['email'] . '>') : "ID {$id}";

            $del = $pdo->prepare('DELETE FROM sub_admins WHERE id = ?');
            $del->execute([$id]);

            $actor = (string) ($req->user['email'] ?? 'super_admin');
            ActivityLogger::log($actor, 'SUB_ADMIN_DELETE', 'USER_MGMT', "Deleted mobile Sub-Admin: {$label}");
            Response::json(['message' => 'Sub-Admin deleted successfully.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to delete Sub-Admin.');
        }
    }

    private static function fail(\Throwable $e, string $msg): void
    {
        error_log('[sub-admins] ' . $e->getMessage());
        Response::json(['success' => false, 'message' => $msg, 'error' => $e->getMessage()], 500);
    }
}
