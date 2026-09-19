<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use PDO;

final class LeadController
{
    public static function getAllLeads(Request $req, array $params = []): void
    {
        try {
            $sql = 'SELECT * FROM leads WHERE 1=1';
            $bind = [];
            $status = $req->query('status');
            if ($status && $status !== 'All') {
                $sql .= ' AND status = ?';
                $bind[] = $status;
            }
            $search = $req->query('search');
            if ($search) {
                // Support both name and client_name schemas
                $sql .= ' AND (COALESCE(name, client_name) LIKE ? OR company LIKE ? OR phone LIKE ?)';
                $like = '%' . $search . '%';
                array_push($bind, $like, $like, $like);
            }
            $sql .= ' ORDER BY created_at DESC';
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute($bind);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            Response::json(['success' => true, 'count' => count($rows), 'data' => $rows]);
        } catch (\Throwable $e) {
            // Fallback without client_name
            try {
                $sql = 'SELECT * FROM leads WHERE 1=1';
                $bind = [];
                $status = $req->query('status');
                if ($status && $status !== 'All') {
                    $sql .= ' AND status = ?';
                    $bind[] = $status;
                }
                $search = $req->query('search');
                if ($search) {
                    $sql .= ' AND (name LIKE ? OR company LIKE ? OR phone LIKE ?)';
                    $like = '%' . $search . '%';
                    array_push($bind, $like, $like, $like);
                }
                $sql .= ' ORDER BY created_at DESC';
                $stmt = Database::pdo()->prepare($sql);
                $stmt->execute($bind);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                Response::json(['success' => true, 'count' => count($rows), 'data' => $rows]);
            } catch (\Throwable $e2) {
                Response::json(['success' => false, 'message' => 'Server error while fetching leads.', 'error' => $e2->getMessage()], 500);
            }
        }
    }

    public static function getLeadById(Request $req, array $params = []): void
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT * FROM leads WHERE id = ?');
            $stmt->execute([(int) ($params['id'] ?? 0)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                Response::error('Lead not found.', 404);
                return;
            }
            Response::json(['success' => true, 'data' => $row]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Server error while fetching lead details.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function createLead(Request $req, array $params = []): void
    {
        try {
            $body = $req->body();
            $name = trim((string) ($body['name'] ?? $body['client_name'] ?? ''));
            $phone = trim((string) ($body['phone'] ?? ''));
            if ($name === '' || $phone === '') {
                Response::error('Lead name and phone number are required fields.', 400);
                return;
            }
            $pdo = Database::pdo();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO leads (name, company, phone, email, status, source, value, notes) VALUES (?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $name,
                    $body['company'] ?? '',
                    $phone,
                    $body['email'] ?? '',
                    $body['status'] ?? 'New',
                    $body['source'] ?? 'Direct',
                    (float) ($body['value'] ?? 0),
                    $body['notes'] ?? '',
                ]);
            } catch (\Throwable $e) {
                $stmt = $pdo->prepare(
                    'INSERT INTO leads (client_name, company, phone, email, status, value, notes) VALUES (?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $name,
                    $body['company'] ?? '',
                    $phone,
                    $body['email'] ?? '',
                    $body['status'] ?? 'New',
                    (float) ($body['value'] ?? 0),
                    $body['notes'] ?? '',
                ]);
            }
            Response::json(['success' => true, 'message' => 'New lead added successfully!', 'leadId' => (int) $pdo->lastInsertId()], 201);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to create new lead.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function updateLead(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $body = $req->body();
            $pdo = Database::pdo();
            try {
                $stmt = $pdo->prepare(
                    'UPDATE leads SET name = ?, company = ?, phone = ?, email = ?, status = ?, source = ?, value = ?, notes = ? WHERE id = ?'
                );
                $stmt->execute([
                    $body['name'] ?? null,
                    $body['company'] ?? null,
                    $body['phone'] ?? null,
                    $body['email'] ?? null,
                    $body['status'] ?? null,
                    $body['source'] ?? null,
                    (float) ($body['value'] ?? 0),
                    $body['notes'] ?? null,
                    $id,
                ]);
            } catch (\Throwable) {
                $stmt = $pdo->prepare(
                    'UPDATE leads SET client_name = ?, company = ?, phone = ?, email = ?, status = ?, value = ?, notes = ? WHERE id = ?'
                );
                $stmt->execute([
                    $body['name'] ?? $body['client_name'] ?? null,
                    $body['company'] ?? null,
                    $body['phone'] ?? null,
                    $body['email'] ?? null,
                    $body['status'] ?? null,
                    (float) ($body['value'] ?? 0),
                    $body['notes'] ?? null,
                    $id,
                ]);
            }
            if ($stmt->rowCount() === 0) {
                Response::error("Lead with ID {$id} not found.", 404);
                return;
            }
            Response::json(['success' => true, 'message' => 'Lead updated successfully!']);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to update lead details.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function deleteLead(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $stmt = Database::pdo()->prepare('DELETE FROM leads WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                Response::error("Lead with ID {$id} not found.", 404);
                return;
            }
            Response::json(['success' => true, 'message' => 'Lead deleted successfully.']);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to delete lead.', 'error' => $e->getMessage()], 500);
        }
    }
}
