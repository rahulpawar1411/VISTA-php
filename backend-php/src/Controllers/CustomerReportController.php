<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\ActivityLogger;
use PDO;

final class CustomerReportController
{
    private const ALLOWED = ['Open', 'In Progress', 'Resolved', 'Closed'];

    private static function loadCustomerIdentity(string $email): ?array
    {
        $clean = strtolower(trim($email));
        if ($clean === '') {
            return null;
        }
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT id, email, full_name, phone_no, allowed_clients, allowed_warehouses FROM customers WHERE email = ? LIMIT 1'
            );
            $stmt->execute([$clean]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function createCustomerReport(Request $req, array $params = []): void
    {
        try {
            $role = $req->user['role'] ?? '';
            if ($role !== 'customer' && $role !== 'super_admin') {
                Response::json(['error' => 'Only customers can submit reports.'], 403);
                return;
            }
            $reference_no = trim((string) ($req->body()['reference_no'] ?? '')) ?: 'Query';
            $message = trim((string) ($req->body()['message'] ?? ''));
            $email = strtolower(trim((string) ($req->user['email'] ?? 'unknown')));
            if ($message === '') {
                Response::json(['error' => 'Please type your query message.'], 400);
                return;
            }
            if (strlen($message) > 4000) {
                Response::json(['error' => 'Issue message is too long (max 4000 characters).'], 400);
                return;
            }
            $profile = self::loadCustomerIdentity($email);
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO customer_reports
                 (customer_id, customer_email, customer_name, customer_phone, allowed_clients, allowed_warehouses, reference_no, message, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'Open\')'
            );
            $stmt->execute([
                $profile['id'] ?? null,
                $email,
                $profile['full_name'] ?? ($req->user['full_name'] ?? null),
                $profile['phone_no'] ?? null,
                $profile['allowed_clients'] ?? null,
                $profile['allowed_warehouses'] ?? null,
                $reference_no,
                $message,
            ]);
            $id = (int) $pdo->lastInsertId();
            ActivityLogger::log($email, 'CUSTOMER_REPORT', 'SYSTEM', "Customer report #{$id} for Ref {$reference_no}: " . substr($message, 0, 220));
            Response::json(['success' => true, 'id' => $id, 'message' => 'Your query has been submitted. Our team will review it.'], 201);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to submit report.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function getCustomerReports(Request $req, array $params = []): void
    {
        try {
            if (($req->user['role'] ?? '') !== 'super_admin') {
                Response::json(['error' => 'Only Super Admin can view customer reports.'], 403);
                return;
            }
            $conditions = [];
            $bind = [];
            $status = trim((string) ($req->query('status') ?? ''));
            if ($status && $status !== 'All' && in_array($status, self::ALLOWED, true)) {
                $conditions[] = 'r.status = ?';
                $bind[] = $status;
            }
            $search = trim((string) ($req->query('search') ?? ''));
            if ($search !== '') {
                $q = '%' . $search . '%';
                $conditions[] = '(r.reference_no LIKE ? OR r.message LIKE ? OR r.customer_email LIKE ? OR r.customer_name LIKE ? OR r.customer_phone LIKE ?)';
                array_push($bind, $q, $q, $q, $q, $q);
            }
            $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
            $stmt = Database::pdo()->prepare(
                "SELECT r.*, sa.full_name AS live_customer_name, sa.phone_no AS live_customer_phone,
                        sa.allowed_clients AS live_allowed_clients, sa.allowed_warehouses AS live_allowed_warehouses
                 FROM customer_reports r
                 LEFT JOIN customers sa ON sa.email = r.customer_email
                 {$where}
                 ORDER BY CASE r.status WHEN 'Open' THEN 0 WHEN 'In Progress' THEN 1 WHEN 'Resolved' THEN 2 ELSE 3 END, r.created_at DESC"
            );
            $stmt->execute($bind);
            $reports = array_map(static function ($row) {
                return [
                    'id' => $row['id'],
                    'customer_id' => $row['customer_id'] ?? null,
                    'customer_email' => $row['customer_email'],
                    'customer_name' => $row['live_customer_name'] ?? $row['customer_name'] ?? null,
                    'customer_phone' => $row['live_customer_phone'] ?? $row['customer_phone'] ?? null,
                    'allowed_clients' => $row['live_allowed_clients'] ?? $row['allowed_clients'] ?? null,
                    'allowed_warehouses' => $row['live_allowed_warehouses'] ?? $row['allowed_warehouses'] ?? null,
                    'reference_no' => $row['reference_no'],
                    'message' => $row['message'],
                    'status' => $row['status'] ?? 'Open',
                    'reviewed_by_email' => $row['reviewed_by_email'] ?? null,
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'] ?? null,
                    'resolved_at' => $row['resolved_at'] ?? null,
                ];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            Response::json($reports);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to fetch customer reports.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function updateCustomerReportStatus(Request $req, array $params = []): void
    {
        try {
            if (($req->user['role'] ?? '') !== 'super_admin') {
                Response::json(['error' => 'Only Super Admin can update report status.'], 403);
                return;
            }
            $id = (int) ($params['id'] ?? 0);
            $status = trim((string) ($req->body()['status'] ?? ''));
            if (!in_array($status, self::ALLOWED, true)) {
                Response::json(['error' => 'Status must be one of: ' . implode(', ', self::ALLOWED)], 400);
                return;
            }
            $reviewer = (string) ($req->user['email'] ?? 'super_admin');
            $resolvedAt = in_array($status, ['Resolved', 'Closed'], true) ? date('Y-m-d H:i:s') : null;
            $stmt = Database::pdo()->prepare(
                'UPDATE customer_reports SET status = ?, reviewed_by_email = ?, resolved_at = ? WHERE id = ?'
            );
            $stmt->execute([$status, $reviewer, $resolvedAt, $id]);
            if ($stmt->rowCount() === 0) {
                Response::json(['error' => 'Report not found.'], 404);
                return;
            }
            ActivityLogger::log($reviewer, 'CUSTOMER_REPORT_STATUS', 'SYSTEM', "Super Admin set customer report #{$id} to {$status}");
            Response::json(['success' => true, 'message' => "Report marked as {$status}."]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to update report status.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function deleteCustomerReport(Request $req, array $params = []): void
    {
        try {
            if (($req->user['role'] ?? '') !== 'super_admin') {
                Response::json(['error' => 'Only Super Admin can delete customer reports.'], 403);
                return;
            }
            $id = (int) ($params['id'] ?? 0);
            $stmt = Database::pdo()->prepare('DELETE FROM customer_reports WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                Response::json(['error' => 'Report not found.'], 404);
                return;
            }
            ActivityLogger::log((string) ($req->user['email'] ?? 'super_admin'), 'CUSTOMER_REPORT_DELETE', 'SYSTEM', "Super Admin deleted customer report #{$id}");
            Response::json(['success' => true, 'message' => 'Report deleted.']);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to delete report.', 'error' => $e->getMessage()], 500);
        }
    }
}
