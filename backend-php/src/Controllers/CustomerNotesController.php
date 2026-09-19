<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\ActivityLogger;
use PDO;

final class CustomerNotesController
{
    public static function listNotes(Request $req, array $params = []): void
    {
        try {
            $role = $req->user['role'] ?? '';
            if ($role !== 'super_admin' && $role !== 'customer') {
                Response::json(['error' => 'Access denied.'], 403);
                return;
            }
            $conditions = [];
            $bind = [];
            if ($role === 'customer') {
                $conditions[] = 'customer_email = ?';
                $bind[] = strtolower(trim((string) ($req->user['email'] ?? '')));
            } else {
                $customerEmail = strtolower(trim((string) ($req->query('customer_email') ?? '')));
                $search = trim((string) ($req->query('search') ?? ''));
                if ($customerEmail !== '') {
                    $conditions[] = 'customer_email = ?';
                    $bind[] = $customerEmail;
                }
                if ($search !== '') {
                    $q = '%' . $search . '%';
                    $conditions[] = '(customer_email LIKE ? OR customer_name LIKE ? OR message LIKE ? OR author_email LIKE ?)';
                    array_push($bind, $q, $q, $q, $q);
                }
            }
            $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
            $stmt = Database::pdo()->prepare(
                "SELECT id, customer_id, customer_email, customer_name, author_role, author_email, author_name, message, created_at
                 FROM customer_admin_notes {$where}
                 ORDER BY created_at ASC, id ASC LIMIT 500"
            );
            $stmt->execute($bind);
            Response::json(['success' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to load notes.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function listThreads(Request $req, array $params = []): void
    {
        try {
            if (($req->user['role'] ?? '') !== 'super_admin') {
                Response::json(['error' => 'Only Super Admin can list note threads.'], 403);
                return;
            }
            $rows = Database::pdo()->query(
                "SELECT n.customer_email, n.customer_name, n.customer_id,
                        MAX(n.created_at) AS last_at,
                        SUBSTRING_INDEX(GROUP_CONCAT(n.message ORDER BY n.created_at DESC, n.id DESC SEPARATOR '\\n'), '\\n', 1) AS last_message,
                        COUNT(*) AS message_count
                 FROM customer_admin_notes n
                 GROUP BY n.customer_email, n.customer_name, n.customer_id
                 ORDER BY last_at DESC LIMIT 200"
            )->fetchAll(PDO::FETCH_ASSOC);
            Response::json(['success' => true, 'items' => $rows]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to load note threads.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function createNote(Request $req, array $params = []): void
    {
        try {
            if (($req->user['role'] ?? '') !== 'super_admin') {
                Response::json(['error' => 'Only Super Admin can send notes. Customers can only read updates.'], 403);
                return;
            }
            $body = $req->body();
            $message = trim((string) ($body['message'] ?? ''));
            if ($message === '') {
                Response::json(['error' => 'Please type a note message.'], 400);
                return;
            }
            if (strlen($message) > 4000) {
                Response::json(['error' => 'Message is too long (max 4000 characters).'], 400);
                return;
            }
            $author_email = strtolower(trim((string) ($req->user['email'] ?? '')));
            $author_name = $req->user['full_name'] ?? $author_email;
            $broadcast = !empty($body['broadcast']) || strtolower(trim((string) ($body['customer_email'] ?? ''))) === 'all';
            $pdo = Database::pdo();

            if ($broadcast) {
                $customers = $pdo->query(
                    "SELECT id, email, full_name FROM customers WHERE email IS NOT NULL AND TRIM(email) != '' ORDER BY full_name ASC"
                )->fetchAll(PDO::FETCH_ASSOC);
                if (!$customers) {
                    Response::json(['error' => 'No customers found to send this note.'], 404);
                    return;
                }
                $ins = $pdo->prepare(
                    'INSERT INTO customer_admin_notes
                     (customer_id, customer_email, customer_name, author_role, author_email, author_name, message)
                     VALUES (?, ?, ?, \'super_admin\', ?, ?, ?)'
                );
                $count = 0;
                foreach ($customers as $c) {
                    $ins->execute([
                        $c['id'],
                        strtolower(trim((string) $c['email'])),
                        $c['full_name'] ?? null,
                        $author_email,
                        $author_name,
                        $message,
                    ]);
                    $count++;
                }
                ActivityLogger::log($author_email, 'CUSTOMER_NOTE_BROADCAST', 'SYSTEM', "Broadcast note to {$count} customers");
                Response::json(['success' => true, 'message' => "Note sent to {$count} customers.", 'count' => $count], 201);
                return;
            }

            $customerEmail = strtolower(trim((string) ($body['customer_email'] ?? '')));
            if ($customerEmail === '') {
                Response::json(['error' => 'customer_email is required (or set broadcast=true).'], 400);
                return;
            }
            $stmt = $pdo->prepare('SELECT id, email, full_name FROM customers WHERE email = ? LIMIT 1');
            $stmt->execute([$customerEmail]);
            $cust = $stmt->fetch(PDO::FETCH_ASSOC);
            $ins = $pdo->prepare(
                'INSERT INTO customer_admin_notes
                 (customer_id, customer_email, customer_name, author_role, author_email, author_name, message)
                 VALUES (?, ?, ?, \'super_admin\', ?, ?, ?)'
            );
            $ins->execute([
                $cust['id'] ?? null,
                $customerEmail,
                $cust['full_name'] ?? null,
                $author_email,
                $author_name,
                $message,
            ]);
            ActivityLogger::log($author_email, 'CUSTOMER_NOTE', 'SYSTEM', "Note to {$customerEmail}");
            Response::json(['success' => true, 'message' => 'Note sent.', 'id' => (int) $pdo->lastInsertId()], 201);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to send note.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function deleteNote(Request $req, array $params = []): void
    {
        try {
            if (($req->user['role'] ?? '') !== 'super_admin') {
                Response::json(['error' => 'Only Super Admin can delete notes.'], 403);
                return;
            }
            $id = (int) ($params['id'] ?? 0);
            $stmt = Database::pdo()->prepare('DELETE FROM customer_admin_notes WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                Response::json(['error' => 'Note not found.'], 404);
                return;
            }
            Response::json(['success' => true, 'message' => 'Note deleted.']);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to delete note.', 'error' => $e->getMessage()], 500);
        }
    }
}
