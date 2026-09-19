<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\JwtService;
use PDO;

final class AuthMiddleware
{
    /** Verify JWT and attach $req->user. Returns false if response already sent. */
    public static function verify(Request $req): bool
    {
        $token = $req->bearerToken();
        if (!$token) {
            Response::error('Access Denied: No authentication token provided. Please log in.', 401);
            return false;
        }

        try {
            $decoded = JwtService::verify($token);
        } catch (\Throwable $e) {
            $type = $e->type ?? null;
            $name = $e->name ?? $e->getMessage();
            if (($type === 'ConfigError') || (($e->statusCode ?? null) === 500)) {
                Response::error('Authentication is temporarily unavailable (server config).', 503);
                return false;
            }
            if (($e->name ?? '') === 'TokenExpiredError' || stripos($e->getMessage(), 'expired') !== false) {
                Response::error('Your session has expired. Please log in again.', 401);
                return false;
            }
            Response::error('Invalid signature or corrupt token. Authentication failed.', 403);
            return false;
        }

        $email = (string) ($decoded['email'] ?? '');
        $role = (string) ($decoded['role'] ?? '');
        if ($email === '' || $role === '') {
            Response::error('Invalid signature or corrupt token. Authentication failed.', 403);
            return false;
        }

        try {
            $userExists = false;
            $pdo = Database::pdo();

            if ($role === 'super_admin') {
                $stmt = $pdo->prepare('SELECT id FROM super_admin WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $userExists = true;
                }
            } elseif ($role === 'sub_admin') {
                $stmt = $pdo->prepare('SELECT id, full_name, phone_no FROM sub_admins WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $userExists = true;
                    $decoded['id'] = (int) $row['id'];
                    if (!empty($row['full_name'])) {
                        $decoded['full_name'] = $row['full_name'];
                    }
                    if (!empty($row['phone_no'])) {
                        $decoded['phone_no'] = $row['phone_no'];
                    }
                }
            } elseif ($role === 'customer') {
                $stmt = $pdo->prepare(
                    'SELECT id, allowed_clients, allowed_warehouses, full_name, phone_no FROM customers WHERE email = ? LIMIT 1'
                );
                $stmt->execute([$email]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $userExists = true;
                    $decoded['id'] = (int) $row['id'];
                    $decoded['allowed_clients'] = $row['allowed_clients'];
                    $decoded['allowed_warehouses'] = $row['allowed_warehouses'];
                    if (!empty($row['full_name'])) {
                        $decoded['full_name'] = $row['full_name'];
                    }
                    if (!empty($row['phone_no'])) {
                        $decoded['phone_no'] = $row['phone_no'];
                    }
                }
            } elseif ($role === 'do_operator') {
                $stmt = $pdo->prepare(
                    'SELECT id, warehouse_name, warehouse_code, chamber_limit, full_name, phone_no FROM do_operators WHERE email = ? LIMIT 1'
                );
                $stmt->execute([$email]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $userExists = true;
                    $decoded['id'] = (int) $row['id'];
                    if ($row['warehouse_name'] !== null) {
                        $decoded['warehouse_name'] = $row['warehouse_name'];
                    }
                    if ($row['warehouse_code'] !== null) {
                        $decoded['warehouse_code'] = $row['warehouse_code'];
                    }
                    if ($row['chamber_limit'] !== null) {
                        $decoded['chamber_limit'] = $row['chamber_limit'];
                    }
                    if (!empty($row['full_name'])) {
                        $decoded['full_name'] = $row['full_name'];
                    }
                    if (!empty($row['phone_no'])) {
                        $decoded['phone_no'] = $row['phone_no'];
                    }
                }
            }
        } catch (\Throwable $dbErr) {
            Response::error('Unable to verify your session right now. Please try again shortly.', 503);
            return false;
        }

        if (!$userExists) {
            Response::error('Your account has been deleted or disabled. Please log in again.', 401);
            return false;
        }

        $req->user = $decoded;
        return true;
    }

    /** @param list<string> $allowedRoles */
    public static function requireRole(Request $req, array $allowedRoles): bool
    {
        if ($req->user === null || empty($req->user['role'])) {
            Response::error('Unauthorized: User authentication context missing.', 401);
            return false;
        }
        $role = (string) $req->user['role'];
        if (!in_array($role, $allowedRoles, true)) {
            Response::error(
                "Access Denied: Role '{$role}' does not have permission to access this resource.",
                403
            );
            return false;
        }
        return true;
    }
}
