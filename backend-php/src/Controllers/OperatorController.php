<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\ActivityLogger;
use App\Services\MailService;
use App\Services\MasterResolver;
use PDO;

final class OperatorController
{
    private static function normalizeIndiaPhone(?string $phone_no): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone_no) ?? '';
        if (str_starts_with($digits, '91') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }
        if (!preg_match('/^\d{10}$/', $digits)) {
            return null;
        }
        return '+91' . $digits;
    }

    private static function syncOperatorWarehouseOnPastLogs(string $operatorEmail, string $warehouseName, ?string $warehouseCode = null): int
    {
        $email = trim($operatorEmail);
        $warehouse = trim($warehouseName);
        $code = trim((string) $warehouseCode);
        if ($email === '' || $warehouse === '') {
            return 0;
        }
        $pdo = Database::pdo();
        $updated = 0;
        foreach (['daily_chamber_temp_logs', 'inward_temp_logs', 'outward_temp_logs'] as $table) {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE {$table}
                     SET warehouse_name = ?, warehouse_code = COALESCE(?, warehouse_code)
                     WHERE LOWER(TRIM(operator_email)) = LOWER(?)
                       AND (warehouse_name IS NULL OR TRIM(warehouse_name) = '' OR warehouse_name <> ?)"
                );
                $stmt->execute([$warehouse, $code !== '' ? $code : null, $email, $warehouse]);
                $updated += $stmt->rowCount();
            } catch (\Throwable) {
            }
        }
        return $updated;
    }

    /** Lightweight Chamber 1..N ensure (full chamber API in Phase 3). */
    private static function ensureNumberedChambers(int $limit): void
    {
        if ($limit < 1) {
            return;
        }
        $pdo = Database::pdo();
        for ($i = 1; $i <= $limit; $i++) {
            $name = 'Chamber ' . $i;
            try {
                $stmt = $pdo->prepare('SELECT id FROM chambers WHERE name = ? LIMIT 1');
                $stmt->execute([$name]);
                if ($stmt->fetch()) {
                    continue;
                }
                $ins = $pdo->prepare(
                    "INSERT INTO chambers (name, chamber_type) VALUES (?, 'Frozen')"
                );
                $ins->execute([$name]);
            } catch (\Throwable) {
                // Schema may differ — Phase 3 hardens this
            }
        }
    }

    public static function getOperators(Request $req, array $params = []): void
    {
        try {
            $pdo = Database::pdo();
            $rows = $pdo->query(
                'SELECT id, email, full_name, phone_no, warehouse_name, warehouse_code, chamber_limit, created_at
                 FROM do_operators ORDER BY id DESC'
            )->fetchAll(PDO::FETCH_ASSOC);

            $todayStr = date('Y-m-d');
            $ioByEmail = [];
            $bump = static function (string $email, string $field, $n) use (&$ioByEmail): void {
                $key = strtolower(trim($email));
                if ($key === '') {
                    return;
                }
                if (!isset($ioByEmail[$key])) {
                    $ioByEmail[$key] = [
                        'total_inward' => 0,
                        'total_outward' => 0,
                        'today_inward' => 0,
                        'today_outward' => 0,
                    ];
                }
                $ioByEmail[$key][$field] = (int) $n;
            };

            try {
                foreach ($pdo->query(
                    "SELECT LOWER(TRIM(IFNULL(operator_email,''))) AS email, COUNT(*) AS c
                     FROM inward_temp_logs WHERE TRIM(IFNULL(operator_email,'')) <> ''
                     GROUP BY LOWER(TRIM(IFNULL(operator_email,'')))"
                ) as $r) {
                    $bump((string) $r['email'], 'total_inward', $r['c']);
                }
                foreach ($pdo->query(
                    "SELECT LOWER(TRIM(IFNULL(operator_email,''))) AS email, COUNT(*) AS c
                     FROM outward_temp_logs WHERE TRIM(IFNULL(operator_email,'')) <> ''
                     GROUP BY LOWER(TRIM(IFNULL(operator_email,'')))"
                ) as $r) {
                    $bump((string) $r['email'], 'total_outward', $r['c']);
                }
                $st = $pdo->prepare(
                    "SELECT LOWER(TRIM(IFNULL(operator_email,''))) AS email, COUNT(*) AS c
                     FROM inward_temp_logs WHERE inward_entry_date = ? AND TRIM(IFNULL(operator_email,'')) <> ''
                     GROUP BY LOWER(TRIM(IFNULL(operator_email,'')))"
                );
                $st->execute([$todayStr]);
                foreach ($st as $r) {
                    $bump((string) $r['email'], 'today_inward', $r['c']);
                }
                $st = $pdo->prepare(
                    "SELECT LOWER(TRIM(IFNULL(operator_email,''))) AS email, COUNT(*) AS c
                     FROM outward_temp_logs WHERE outward_entry_date = ? AND TRIM(IFNULL(operator_email,'')) <> ''
                     GROUP BY LOWER(TRIM(IFNULL(operator_email,'')))"
                );
                $st->execute([$todayStr]);
                foreach ($st as $r) {
                    $bump((string) $r['email'], 'today_outward', $r['c']);
                }
            } catch (\Throwable) {
            }

            $withCounts = array_map(static function (array $row) use ($ioByEmail) {
                $key = strtolower(trim((string) ($row['email'] ?? '')));
                $io = $ioByEmail[$key] ?? [
                    'total_inward' => 0,
                    'total_outward' => 0,
                    'today_inward' => 0,
                    'today_outward' => 0,
                ];
                return array_merge($row, $io);
            }, $rows);

            // Node returns raw array (not {success,data}) — keep parity
            Response::json($withCounts);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to fetch data operators.');
        }
    }

    public static function createOperator(Request $req, array $params = []): void
    {
        try {
            $body = $req->body();
            $email = trim((string) ($body['email'] ?? ''));
            $password = (string) ($body['password'] ?? '');
            $full_name = trim((string) ($body['full_name'] ?? ''));
            $phone_no = $body['phone_no'] ?? null;
            $warehouse_name = $body['warehouse_name'] ?? null;
            $warehouse_code = $body['warehouse_code'] ?? null;
            $chamber_limit = $body['chamber_limit'] ?? 4;

            if ($email === '' || $password === '' || $full_name === '' || !$phone_no || !$warehouse_name) {
                Response::json([
                    'error' => 'All fields (Email, Password, Full Name, Phone No., Warehouse / Data Access) are required.',
                ], 400);
                return;
            }

            $limitVal = $chamber_limit ? (int) $chamber_limit : 4;
            $emailTrim = strtolower($email);
            $phoneTrim = self::normalizeIndiaPhone((string) $phone_no);
            if (!$phoneTrim) {
                Response::json(['error' => 'Phone No. must be a 10-digit Indian mobile number.'], 400);
                return;
            }

            $whFields = MasterResolver::resolveWarehouseFields(
                $warehouse_code !== null ? (string) $warehouse_code : null,
                (string) $warehouse_name
            );
            $warehouseTrim = $whFields['warehouse_name'] ?: trim((string) $warehouse_name);
            $warehouseCode = $whFields['warehouse_code'];

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT id FROM do_operators WHERE email = ? LIMIT 1');
            $stmt->execute([$emailTrim]);
            if ($stmt->fetch()) {
                Response::json(['error' => 'Operator email already exists.'], 400);
                return;
            }

            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $ins = $pdo->prepare(
                'INSERT INTO do_operators (email, password, full_name, phone_no, warehouse_name, warehouse_code, chamber_limit)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$emailTrim, $hashed, $full_name, $phoneTrim, $warehouseTrim, $warehouseCode, $limitVal]);

            try {
                self::ensureNumberedChambers($limitVal);
            } catch (\Throwable) {
            }

            $actor = (string) ($req->user['email'] ?? 'super_admin');
            ActivityLogger::log(
                $actor,
                'CREATE',
                'PERMISSION',
                "Registered operator profile: {$emailTrim} (Warehouse / Data Access: {$warehouseTrim}, Chambers: 1-{$limitVal})"
            );

            $mail = MailService::sendCredentials($emailTrim, $full_name, 'Data Operator', $password);
            if ($mail['ok']) {
                Response::json([
                    'message' => 'Data operator created successfully. Credentials email sent.',
                    'emailSent' => true,
                ], 201);
            } else {
                Response::json([
                    'message' => 'Data operator created successfully, but credentials email could not be sent.',
                    'emailSent' => false,
                    'emailSkipped' => false,
                    'emailError' => $mail['error'],
                ], 201);
            }
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to create data operator.');
        }
    }

    public static function updateOperator(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $body = $req->body();
            $email = trim((string) ($body['email'] ?? ''));
            $password = $body['password'] ?? null;
            $full_name = trim((string) ($body['full_name'] ?? ''));
            $phone_no = $body['phone_no'] ?? null;
            $warehouse_name = $body['warehouse_name'] ?? null;
            $warehouse_code = $body['warehouse_code'] ?? null;
            $chamber_limit = $body['chamber_limit'] ?? 4;

            if ($email === '' || $full_name === '' || !$phone_no || !$warehouse_name) {
                Response::json([
                    'error' => 'All fields (Email, Full Name, Phone No., Warehouse / Data Access) are required.',
                ], 400);
                return;
            }

            $limitVal = $chamber_limit ? (int) $chamber_limit : 4;
            $emailTrim = strtolower($email);
            $phoneTrim = self::normalizeIndiaPhone((string) $phone_no);
            if (!$phoneTrim) {
                Response::json(['error' => 'Phone No. must be a 10-digit Indian mobile number.'], 400);
                return;
            }

            $whFields = MasterResolver::resolveWarehouseFields(
                $warehouse_code !== null ? (string) $warehouse_code : null,
                (string) $warehouse_name
            );
            $warehouseTrim = $whFields['warehouse_name'] ?: trim((string) $warehouse_name);
            $warehouseCode = $whFields['warehouse_code'];

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT email, warehouse_name FROM do_operators WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                Response::json(['error' => 'Operator not found.'], 404);
                return;
            }
            $prevEmail = (string) $before['email'];
            $prevWarehouse = (string) ($before['warehouse_name'] ?? '');

            $stmt = $pdo->prepare('SELECT id FROM do_operators WHERE email = ? AND id != ? LIMIT 1');
            $stmt->execute([$emailTrim, $id]);
            if ($stmt->fetch()) {
                Response::json(['error' => 'Email is already taken by another operator.'], 400);
                return;
            }

            if ($password && trim((string) $password) !== '') {
                $hashed = password_hash((string) $password, PASSWORD_BCRYPT);
                $upd = $pdo->prepare(
                    'UPDATE do_operators SET email = ?, password = ?, full_name = ?, phone_no = ?, warehouse_name = ?, warehouse_code = ?, chamber_limit = ? WHERE id = ?'
                );
                $upd->execute([$emailTrim, $hashed, $full_name, $phoneTrim, $warehouseTrim, $warehouseCode, $limitVal, $id]);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE do_operators SET email = ?, full_name = ?, phone_no = ?, warehouse_name = ?, warehouse_code = ?, chamber_limit = ? WHERE id = ?'
                );
                $upd->execute([$emailTrim, $full_name, $phoneTrim, $warehouseTrim, $warehouseCode, $limitVal, $id]);
            }

            try {
                self::ensureNumberedChambers($limitVal);
            } catch (\Throwable) {
            }

            $syncEmails = array_unique(array_filter([trim($prevEmail), $emailTrim]));
            $pastLogsUpdated = 0;
            foreach ($syncEmails as $syncEmail) {
                $pastLogsUpdated += self::syncOperatorWarehouseOnPastLogs($syncEmail, $warehouseTrim, $warehouseCode);
            }

            if (strtolower(trim($prevEmail)) !== $emailTrim) {
                foreach (['daily_chamber_temp_logs', 'inward_temp_logs', 'outward_temp_logs'] as $table) {
                    try {
                        $st = $pdo->prepare(
                            "UPDATE {$table} SET operator_email = ? WHERE LOWER(TRIM(operator_email)) = LOWER(?)"
                        );
                        $st->execute([$emailTrim, $prevEmail]);
                    } catch (\Throwable) {
                    }
                }
            }

            $actor = (string) ($req->user['email'] ?? 'super_admin');
            $desc = "Updated operator profile: {$emailTrim} (Warehouse / Data Access: {$warehouseTrim}";
            if ($prevWarehouse !== '' && $prevWarehouse !== $warehouseTrim) {
                $desc .= " ← was \"{$prevWarehouse}\"";
            }
            $desc .= ", Chambers: 1-{$limitVal}";
            if ($pastLogsUpdated) {
                $desc .= ", past logs synced: {$pastLogsUpdated}";
            }
            $desc .= ')';
            ActivityLogger::log($actor, 'UPDATE', 'PERMISSION', $desc);

            Response::json([
                'message' => 'Data operator updated successfully.',
                'warehouse_name' => $warehouseTrim,
                'chamber_limit' => $limitVal,
                'past_logs_synced' => $pastLogsUpdated,
            ]);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to update data operator.');
        }
    }

    public static function deleteOperator(Request $req, array $params = []): void
    {
        try {
            $id = (int) ($params['id'] ?? 0);
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT email, warehouse_name FROM do_operators WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $opEmail = $row['email'] ?? ("ID {$id}");
            $opWarehouse = $row['warehouse_name'] ?? 'Unknown';

            $del = $pdo->prepare('DELETE FROM do_operators WHERE id = ?');
            $del->execute([$id]);

            $actor = (string) ($req->user['email'] ?? 'super_admin');
            ActivityLogger::log(
                $actor,
                'DELETE',
                'PERMISSION',
                "Revoked workspace access for operator: {$opEmail} (Warehouse: {$opWarehouse})"
            );
            Response::json(['message' => 'Data operator deleted successfully.']);
        } catch (\Throwable $e) {
            self::fail($e, 'Failed to delete data operator.');
        }
    }

    private static function fail(\Throwable $e, string $msg): void
    {
        error_log('[operators] ' . $e->getMessage());
        Response::json(['success' => false, 'message' => $msg, 'error' => $e->getMessage()], 500);
    }
}
