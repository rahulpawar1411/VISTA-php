<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\DateTimeUtil;
use App\Services\Pagination;
use PDO;

final class DashboardController
{
    public static function getDashboardStats(Request $req, array $params = []): void
    {
        try {
            $pdo = Database::pdo();
            $totalLeads = (int) $pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn();
            $newLeads = (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE status = 'New'")->fetchColumn();
            $inProgress = 0;
            try {
                $inProgress = (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE status = 'In Progress' OR status = 'In-Progress'")->fetchColumn();
            } catch (\Throwable) {
            }
            $wonLeads = (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE status = 'Won'")->fetchColumn();
            $totalValue = (float) ($pdo->query('SELECT COALESCE(SUM(value),0) FROM leads')->fetchColumn() ?: 0);
            $totalCustomers = 0;
            try {
                $totalCustomers = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
            } catch (\Throwable) {
            }
            $totalOperators = (int) $pdo->query('SELECT COUNT(*) FROM do_operators')->fetchColumn();

            $overdueCount = 0;
            try {
                $assignments = $pdo->query(
                    'SELECT a.chamber_id, a.client_name, c.name as chamber_name
                     FROM chamber_client_assignments a
                     JOIN chambers c ON a.chamber_id = c.id
                     WHERE (a.status IS NULL OR a.status = \'active\')'
                )->fetchAll(PDO::FETCH_ASSOC);
                $pastDates = [];
                for ($i = 1; $i <= 5; $i++) {
                    $pastDates[] = date('Y-m-d', strtotime("-{$i} days"));
                }
                if ($assignments && $pastDates) {
                    $ph = implode(',', array_fill(0, count($pastDates), '?'));
                    $stmt = $pdo->prepare("SELECT entry_date, client_name, chamber_name FROM daily_chamber_temp_logs WHERE entry_date IN ({$ph})");
                    $stmt->execute($pastDates);
                    $logMap = [];
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $log) {
                        $dateStr = substr((string) $log['entry_date'], 0, 10);
                        $key = strtolower($dateStr . '_' . $log['chamber_name'] . '_' . $log['client_name']);
                        $logMap[$key] = true;
                    }
                    foreach ($pastDates as $date) {
                        foreach ($assignments as $item) {
                            $key = strtolower($date . '_' . $item['chamber_name'] . '_' . $item['client_name']);
                            if (!isset($logMap[$key])) {
                                $overdueCount++;
                            }
                        }
                    }
                }
            } catch (\Throwable) {
            }

            Response::json([
                'success' => true,
                'stats' => [
                    'totalLeads' => $totalLeads,
                    'newLeads' => $newLeads,
                    'inProgressLeads' => $inProgress,
                    'wonLeads' => $wonLeads,
                    'totalValue' => $totalValue,
                    'totalSubAdmins' => $totalCustomers,
                    'totalCustomers' => $totalCustomers,
                    'totalOperators' => $totalOperators,
                    'overdueInspections' => $overdueCount,
                ],
            ]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Server error while calculating dashboard statistics.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function getAccessScopeOptions(Request $req, array $params = []): void
    {
        try {
            $pdo = Database::pdo();
            $clients = [];
            $warehouses = [];
            $warehouseClients = [];

            $add = static function (?string $wh, ?string $cl) use (&$warehouseClients, &$warehouses, &$clients): void {
                $w = trim((string) $wh);
                $c = trim((string) $cl);
                if ($w === '' || $c === '') {
                    return;
                }
                if (!isset($warehouseClients[$w])) {
                    $warehouseClients[$w] = [];
                }
                $warehouseClients[$w][$c] = true;
                $warehouses[$w] = true;
                $clients[$c] = true;
            };

            $safeDistinct = static function (string $sql) use ($pdo): array {
                try {
                    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (\Throwable) {
                    return [];
                }
            };

            foreach ($safeDistinct("SELECT DISTINCT client_name AS name FROM chamber_client_assignments WHERE client_name IS NOT NULL AND TRIM(client_name) != ''") as $r) {
                if (!empty($r['name'])) {
                    $clients[trim((string) $r['name'])] = true;
                }
            }
            foreach ($safeDistinct("SELECT DISTINCT client_name AS name FROM daily_chamber_temp_logs WHERE client_name IS NOT NULL AND TRIM(client_name) != ''") as $r) {
                if (!empty($r['name'])) {
                    $clients[trim((string) $r['name'])] = true;
                }
            }
            foreach ($safeDistinct("SELECT DISTINCT inward_client_name AS name FROM inward_temp_logs WHERE inward_client_name IS NOT NULL AND TRIM(inward_client_name) != ''") as $r) {
                if (!empty($r['name'])) {
                    $clients[trim((string) $r['name'])] = true;
                }
            }
            foreach ($safeDistinct("SELECT DISTINCT outward_client_name AS name FROM outward_temp_logs WHERE outward_client_name IS NOT NULL AND TRIM(outward_client_name) != ''") as $r) {
                if (!empty($r['name'])) {
                    $clients[trim((string) $r['name'])] = true;
                }
            }
            foreach ($safeDistinct("SELECT DISTINCT warehouse_name AS name FROM do_operators WHERE warehouse_name IS NOT NULL AND TRIM(warehouse_name) != ''") as $r) {
                if (!empty($r['name'])) {
                    $warehouses[trim((string) $r['name'])] = true;
                }
            }
            foreach ($safeDistinct("SELECT DISTINCT warehouse_name AS name FROM chamber_client_assignments WHERE warehouse_name IS NOT NULL AND TRIM(warehouse_name) != ''") as $r) {
                if (!empty($r['name'])) {
                    $warehouses[trim((string) $r['name'])] = true;
                }
            }
            foreach ($safeDistinct("SELECT DISTINCT warehouse_name, client_name FROM chamber_client_assignments WHERE warehouse_name IS NOT NULL AND client_name IS NOT NULL") as $r) {
                $add($r['warehouse_name'] ?? null, $r['client_name'] ?? null);
            }
            try {
                foreach ($pdo->query('SELECT warehouse_name FROM warehouse_master WHERE is_active = 1') as $r) {
                    if (!empty($r['warehouse_name'])) {
                        $warehouses[trim((string) $r['warehouse_name'])] = true;
                    }
                }
                foreach ($pdo->query('SELECT client_name, warehouse_name FROM client_master WHERE is_active = 1') as $r) {
                    if (!empty($r['client_name'])) {
                        $clients[trim((string) $r['client_name'])] = true;
                    }
                    $add($r['warehouse_name'] ?? null, $r['client_name'] ?? null);
                }
            } catch (\Throwable) {
            }

            $clientList = array_keys($clients);
            $warehouseList = array_keys($warehouses);
            sort($clientList, SORT_NATURAL | SORT_FLAG_CASE);
            sort($warehouseList, SORT_NATURAL | SORT_FLAG_CASE);
            $map = [];
            foreach ($warehouseClients as $wh => $set) {
                $arr = array_keys($set);
                sort($arr, SORT_NATURAL | SORT_FLAG_CASE);
                $map[$wh] = $arr;
            }

            Response::json([
                'success' => true,
                'clients' => $clientList,
                'warehouses' => $warehouseList,
                'warehouseClients' => $map,
            ]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to load access options.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function getInventoryFilterOptions(Request $req, array $params = []): void
    {
        try {
            $pdo = Database::pdo();
            /** @var array<string, array<string, true>> $warehouseClients */
            $warehouseClients = [];
            $addPair = static function (?string $warehouse, ?string $client) use (&$warehouseClients): void {
                $wh = trim((string) $warehouse);
                $cl = trim((string) $client);
                if ($wh === '') {
                    return;
                }
                if (!isset($warehouseClients[$wh])) {
                    $warehouseClients[$wh] = [];
                }
                if ($cl !== '') {
                    $warehouseClients[$wh][$cl] = true;
                }
            };

            $pairQueries = [
                "SELECT DISTINCT warehouse_name, client_name
                 FROM chamber_client_assignments
                 WHERE warehouse_name IS NOT NULL AND TRIM(warehouse_name) != ''
                   AND client_name IS NOT NULL AND TRIM(client_name) != ''
                   AND (status IS NULL OR status = 'active')",
                "SELECT DISTINCT warehouse_name, client_name
                 FROM daily_chamber_temp_logs
                 WHERE warehouse_name IS NOT NULL AND TRIM(warehouse_name) != ''
                   AND client_name IS NOT NULL AND TRIM(client_name) != ''",
                "SELECT DISTINCT warehouse_name, inward_client_name AS client_name
                 FROM inward_temp_logs
                 WHERE warehouse_name IS NOT NULL AND TRIM(warehouse_name) != ''
                   AND inward_client_name IS NOT NULL AND TRIM(inward_client_name) != ''",
                "SELECT DISTINCT warehouse_name, outward_client_name AS client_name
                 FROM outward_temp_logs
                 WHERE warehouse_name IS NOT NULL AND TRIM(warehouse_name) != ''
                   AND outward_client_name IS NOT NULL AND TRIM(outward_client_name) != ''",
            ];
            foreach ($pairQueries as $sql) {
                try {
                    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $addPair($row['warehouse_name'] ?? null, $row['client_name'] ?? null);
                    }
                } catch (\Throwable) {
                }
            }
            try {
                foreach ($pdo->query(
                    "SELECT DISTINCT warehouse_name FROM do_operators
                     WHERE warehouse_name IS NOT NULL AND TRIM(warehouse_name) != ''"
                )->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $addPair($row['warehouse_name'] ?? null, null);
                }
            } catch (\Throwable) {
            }

            $warehouses = [];
            $allClients = [];
            foreach ($warehouseClients as $name => $clientSet) {
                $clients = array_keys($clientSet);
                natcasesort($clients);
                $clients = array_values($clients);
                foreach ($clients as $c) {
                    $allClients[$c] = true;
                }
                $warehouses[] = [
                    'name' => $name,
                    'client_count' => count($clients),
                    'clients' => $clients,
                ];
            }
            usort($warehouses, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            Response::json([
                'success' => true,
                'total_warehouses' => count($warehouses),
                'total_clients' => count($allClients),
                'warehouses' => $warehouses,
            ]);
        } catch (\Throwable $e) {
            Response::json([
                'success' => false,
                'message' => 'Server error while loading live warehouse/client filters.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public static function getInventoryReconciliation(Request $req, array $params = []): void
    {
        try {
            $pageInfo = Pagination::parse([
                'page' => $req->query('page'),
                'limit' => $req->query('limit'),
            ]);
            $pdo = Database::pdo();
            $sql = "
              SELECT
                d.client_name,
                d.warehouse_name,
                d.chamber_id,
                d.chamber_name,
                d.chamber_type,
                COALESCE(i.total_inward, 0) AS total_inward_boxes,
                COALESCE(o.total_outward, 0) AS total_outward_boxes,
                GREATEST(0, COALESCE(i.total_inward, 0) - COALESCE(o.total_outward, 0)) AS calculated_balance,
                COALESCE(d.last_box_count, 0) AS physical_audit_count,
                d.last_audit_date,
                (GREATEST(0, COALESCE(i.total_inward, 0) - COALESCE(o.total_outward, 0)) - COALESCE(d.last_box_count, 0)) AS discrepancy
              FROM (
                SELECT
                  d1.client_name,
                  d1.warehouse_name,
                  COALESCE(d1.chamber_id, ch.id) AS chamber_id,
                  COALESCE(NULLIF(TRIM(d1.chamber_name), ''), ch.name) AS chamber_name,
                  COALESCE(NULLIF(TRIM(d1.chamber_type), ''), NULLIF(TRIM(ch.chamber_type), ''), 'Frozen') AS chamber_type,
                  d1.box_count AS last_box_count,
                  d1.entry_date AS last_audit_date
                FROM daily_chamber_temp_logs d1
                INNER JOIN (
                  SELECT MAX(id) AS max_id FROM daily_chamber_temp_logs
                  WHERE client_name IS NOT NULL AND TRIM(client_name) != ''
                  GROUP BY client_name, COALESCE(warehouse_name, ''), COALESCE(chamber_id, 0), LOWER(TRIM(COALESCE(chamber_name, '')))
                ) pick ON d1.id = pick.max_id
                LEFT JOIN chambers ch ON ch.id = d1.chamber_id
              ) d
              LEFT JOIN (
                SELECT inward_client_name, warehouse_name, SUM(inward_received_boxes_qty) AS total_inward
                FROM inward_temp_logs GROUP BY inward_client_name, warehouse_name
              ) i ON d.client_name = i.inward_client_name AND (d.warehouse_name = i.warehouse_name OR (d.warehouse_name IS NULL AND i.warehouse_name IS NULL))
              LEFT JOIN (
                SELECT outward_client_name, warehouse_name, SUM(COALESCE(outward_received_boxes_qty, 0)) AS total_outward
                FROM outward_temp_logs GROUP BY outward_client_name, warehouse_name
              ) o ON d.client_name = o.outward_client_name AND (d.warehouse_name = o.warehouse_name OR (d.warehouse_name IS NULL AND o.warehouse_name IS NULL))
            ";

            $all = $pdo->query($sql . ' ORDER BY d.client_name ASC')->fetchAll(PDO::FETCH_ASSOC);

            $search = strtolower(trim((string) ($req->query('search') ?? '')));
            $warehouse = trim((string) ($req->query('warehouse') ?? ''));
            $client = trim((string) ($req->query('client') ?? ''));
            $filtered = array_values(array_filter($all ?: [], static function ($row) use ($search, $warehouse, $client) {
                if ($warehouse && $warehouse !== 'All' && strcasecmp((string) ($row['warehouse_name'] ?? ''), $warehouse) !== 0) {
                    return false;
                }
                if ($client && $client !== 'All' && strcasecmp((string) ($row['client_name'] ?? ''), $client) !== 0) {
                    return false;
                }
                if ($search !== '') {
                    $hay = strtolower(($row['client_name'] ?? '') . ' ' . ($row['warehouse_name'] ?? '') . ' ' . ($row['chamber_name'] ?? ''));
                    if (!str_contains($hay, $search)) {
                        return false;
                    }
                }
                return true;
            }));

            // Customer scope
            if (($req->user['role'] ?? '') === 'customer') {
                $allowedC = array_filter(array_map('strtolower', array_map('trim', explode(',', (string) ($req->user['allowed_clients'] ?? '')))));
                $allowedW = array_filter(array_map('strtolower', array_map('trim', explode(',', (string) ($req->user['allowed_warehouses'] ?? '')))));
                $filtered = array_values(array_filter($filtered, static function ($row) use ($allowedC, $allowedW) {
                    if ($allowedC && !in_array(strtolower((string) ($row['client_name'] ?? '')), $allowedC, true)) {
                        return false;
                    }
                    if ($allowedW) {
                        $wh = strtolower((string) ($row['warehouse_name'] ?? ''));
                        if ($wh !== '' && !in_array($wh, $allowedW, true)) {
                            return false;
                        }
                    }
                    return true;
                }));
            }

            $total = count($filtered);
            $slice = array_slice($filtered, $pageInfo['offset'], $pageInfo['limit']);
            Response::json(array_merge(Pagination::payload($slice, $total, $pageInfo['page'], $pageInfo['limit']), [
                'success' => true,
                'data' => $slice,
            ]));
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to load inventory reconciliation.', 'error' => $e->getMessage(), 'items' => [], 'total' => 0], 500);
        }
    }

    public static function getDailyInventoryDeltas(Request $req, array $params = []): void
    {
        try {
            $warehouse = trim((string) ($req->query('warehouse') ?? ''));
            $fromDate = trim((string) ($req->query('fromDate') ?? ''));
            $toDate = trim((string) ($req->query('toDate') ?? ''));

            $pdo = Database::pdo();
            $sql = "
              SELECT
                id,
                DATE_FORMAT(entry_date, '%Y-%m-%d') AS entry_date,
                client_name,
                chamber_name,
                warehouse_name,
                box_count,
                box_temp,
                box_temp AS chamber_temp,
                shift,
                inspection_time,
                created_at
              FROM daily_chamber_temp_logs
              WHERE client_name IS NOT NULL AND TRIM(client_name) != ''
            ";
            $bind = [];
            if ($warehouse !== '' && strcasecmp($warehouse, 'All') !== 0) {
                $sql .= ' AND LOWER(TRIM(warehouse_name)) = LOWER(TRIM(?)) ';
                $bind[] = $warehouse;
            }
            $sql .= ' ORDER BY entry_date DESC, id DESC ';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            /** @var array<string, list<array<string, mixed>>> $groups */
            $groups = [];
            foreach ($rows as $row) {
                $key = ($row['client_name'] ?? '') . '|||' . ($row['chamber_name'] ?? '') . '|||' . ($row['warehouse_name'] ?? '');
                if (!isset($groups[$key])) {
                    $groups[$key] = [];
                }
                $shiftLabel = DateTimeUtil::resolveShift(
                    isset($row['shift']) ? (string) $row['shift'] : null,
                    isset($row['inspection_time']) ? (string) $row['inspection_time'] : null
                );
                $dateShiftKey = ($row['entry_date'] ?? '') . '|||' . $shiftLabel;
                $already = false;
                foreach ($groups[$key] as $item) {
                    if (($item['_dateShiftKey'] ?? '') === $dateShiftKey) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) {
                    $row['shift'] = $shiftLabel;
                    $row['_dateShiftKey'] = $dateShiftKey;
                    $groups[$key][] = $row;
                }
            }

            $deltas = [];
            foreach ($groups as $key => $groupLogs) {
                $parts = explode('|||', $key);
                $client_name = $parts[0] ?? '';
                $chamber_name = $parts[1] ?? '';
                $warehouse_name = $parts[2] ?? '';

                if ($groupLogs === []) {
                    continue;
                }

                $indexOfLatest = -1;
                foreach ($groupLogs as $i => $log) {
                    $entryDate = (string) ($log['entry_date'] ?? '');
                    $inRange = true;
                    if ($fromDate !== '' && $entryDate < $fromDate) {
                        $inRange = false;
                    }
                    if ($toDate !== '' && $entryDate > $toDate) {
                        $inRange = false;
                    }
                    if ($inRange) {
                        $indexOfLatest = $i;
                        break;
                    }
                }

                if (($fromDate !== '' || $toDate !== '') && $indexOfLatest === -1) {
                    continue;
                }
                if ($indexOfLatest === -1) {
                    $indexOfLatest = 0;
                }

                $latest = $groupLogs[$indexOfLatest];
                $prev = ($indexOfLatest + 1 < count($groupLogs)) ? $groupLogs[$indexOfLatest + 1] : null;

                $latest_count = max(0, (int) ($latest['box_count'] ?? 0));
                $prev_count = $prev ? max(0, (int) ($prev['box_count'] ?? 0)) : 0;
                $rawDelta = $latest_count - $prev_count;
                $inward_qty = $rawDelta > 0 ? $rawDelta : 0;
                $outward_qty = $rawDelta < 0 ? abs($rawDelta) : 0;
                $flow_type = $rawDelta > 0 ? 'inward' : ($rawDelta < 0 ? 'outward' : 'no_change');

                if ($fromDate !== '' || $toDate !== '') {
                    $historyLogs = array_values(array_filter($groupLogs, static function ($g) use ($fromDate, $toDate) {
                        $entryDate = (string) ($g['entry_date'] ?? '');
                        if ($fromDate !== '' && $entryDate < $fromDate) {
                            return false;
                        }
                        if ($toDate !== '' && $entryDate > $toDate) {
                            return false;
                        }
                        return true;
                    }));
                } else {
                    $historyLogs = array_slice($groupLogs, 0, 50);
                }

                $history = [];
                foreach ($historyLogs as $g) {
                    $shift = (string) ($g['shift'] ?? DateTimeUtil::resolveShift(
                        isset($g['shift']) ? (string) $g['shift'] : null,
                        isset($g['inspection_time']) ? (string) $g['inspection_time'] : null
                    ));
                    $temp = DateTimeUtil::parseOptionalFloat($g['box_temp'] ?? $g['chamber_temp'] ?? null);
                    $rawCount = $g['box_count'] ?? null;
                    $count = ($rawCount === null || $rawCount === '')
                        ? null
                        : max(0, (int) $rawCount);
                    $history[] = [
                        'id' => $g['id'] ?? null,
                        'date' => $g['entry_date'] ?? null,
                        'entry_date' => $g['entry_date'] ?? null,
                        'shift' => $shift,
                        'slot' => $shift,
                        'inspection_time' => $g['inspection_time'] ?? null,
                        'box_count' => $count,
                        'count' => $count,
                        'box_temp' => $temp,
                        'temp' => $temp,
                        'chamber_temp' => $temp,
                        'chamber_name' => $g['chamber_name'] ?? null,
                        'warehouse_name' => $g['warehouse_name'] ?? null,
                    ];
                }

                usort($history, static function ($a, $b) {
                    $da = (string) ($a['date'] ?? '');
                    $db = (string) ($b['date'] ?? '');
                    if ($db !== $da) {
                        return strcmp($db, $da);
                    }
                    $sa = (($a['shift'] ?? '') === 'Evening') ? 1 : 0;
                    $sb = (($b['shift'] ?? '') === 'Evening') ? 1 : 0;
                    if ($sb !== $sa) {
                        return $sb - $sa;
                    }
                    return ((int) ($b['id'] ?? 0)) - ((int) ($a['id'] ?? 0));
                });

                $latest_temp = DateTimeUtil::parseOptionalFloat($latest['box_temp'] ?? $latest['chamber_temp'] ?? null);
                $prev_temp = $prev ? DateTimeUtil::parseOptionalFloat($prev['box_temp'] ?? $prev['chamber_temp'] ?? null) : null;
                $latest_shift = (string) ($latest['shift'] ?? DateTimeUtil::resolveShift(
                    isset($latest['shift']) ? (string) $latest['shift'] : null,
                    isset($latest['inspection_time']) ? (string) $latest['inspection_time'] : null
                ));
                $prev_shift = $prev
                    ? (string) ($prev['shift'] ?? DateTimeUtil::resolveShift(
                        isset($prev['shift']) ? (string) $prev['shift'] : null,
                        isset($prev['inspection_time']) ? (string) $prev['inspection_time'] : null
                    ))
                    : null;

                $deltas[] = [
                    'client_name' => $client_name,
                    'chamber_name' => $chamber_name !== '' ? $chamber_name : '-',
                    'warehouse_name' => $warehouse_name !== '' ? $warehouse_name : '-',
                    'latest_date' => $latest['entry_date'] ?? null,
                    'latest_count' => $latest_count,
                    'latest_temp' => $latest_temp,
                    'latest_shift' => $latest_shift,
                    'latest_slot' => $latest_shift,
                    'box_temp' => $latest_temp,
                    'prev_date' => $prev['entry_date'] ?? null,
                    'prev_count' => $prev_count,
                    'prev_temp' => $prev_temp,
                    'prev_shift' => $prev_shift,
                    'prev_slot' => $prev_shift,
                    'delta' => $rawDelta,
                    'inward_qty' => $inward_qty,
                    'outward_qty' => $outward_qty,
                    'flow_type' => $flow_type,
                    'history' => $history,
                ];
            }

            usort($deltas, static function ($a, $b) {
                $da = (string) ($a['latest_date'] ?? '');
                $dbDate = (string) ($b['latest_date'] ?? '');
                if ($dbDate !== $da) {
                    return strcmp($dbDate, $da);
                }
                return strcasecmp((string) ($a['client_name'] ?? ''), (string) ($b['client_name'] ?? ''));
            });

            Response::json([
                'success' => true,
                'items' => $deltas,
            ]);
        } catch (\Throwable $e) {
            Response::json([
                'success' => false,
                'message' => 'Server error while calculating daily inventory deltas.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public static function getClientMonthBoxSheet(Request $req, array $params = []): void
    {
        try {
            $clientName = trim((string) ($req->query('client') ?? $req->query('client_name') ?? ''));
            $warehouseName = trim((string) ($req->query('warehouse') ?? $req->query('warehouse_name') ?? ''));
            $chamberName = trim((string) ($req->query('chamber') ?? $req->query('chamber_name') ?? ''));

            if ($clientName === '') {
                Response::json([
                    'success' => false,
                    'message' => 'client (client_name) is required.',
                ], 400);
                return;
            }

            $today = date('Y-m-d');
            $toDate = trim((string) ($req->query('toDate') ?? '')) ?: $today;
            $fromDate = trim((string) ($req->query('fromDate') ?? ''));
            if ($fromDate === '') {
                $fromDate = date('Y-m-d', strtotime($today . ' -29 days'));
            }
            if ($fromDate > $toDate) {
                $tmp = $fromDate;
                $fromDate = $toDate;
                $toDate = $tmp;
            }

            $pdo = Database::pdo();

            $chamberSql = "
              SELECT
                id,
                DATE_FORMAT(entry_date, '%Y-%m-%d') AS entry_date,
                client_name,
                chamber_name,
                warehouse_name,
                box_count,
                box_temp,
                shift,
                inspection_time,
                created_at
              FROM daily_chamber_temp_logs
              WHERE LOWER(TRIM(client_name)) = LOWER(TRIM(?))
                AND entry_date >= ?
                AND entry_date <= ?
            ";
            $chamberParams = [$clientName, $fromDate, $toDate];
            if ($warehouseName !== '') {
                $chamberSql .= " AND LOWER(TRIM(IFNULL(warehouse_name,''))) = LOWER(TRIM(?)) ";
                $chamberParams[] = $warehouseName;
            }
            if ($chamberName !== '' && $chamberName !== '-') {
                $chamberSql .= " AND LOWER(TRIM(IFNULL(chamber_name,''))) = LOWER(TRIM(?)) ";
                $chamberParams[] = $chamberName;
            }
            $chamberSql .= ' ORDER BY entry_date ASC, id ASC ';
            $stmt = $pdo->prepare($chamberSql);
            $stmt->execute($chamberParams);
            $chamberRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $inwardSql = "
              SELECT
                DATE_FORMAT(inward_entry_date, '%Y-%m-%d') AS entry_date,
                SUM(GREATEST(0, IFNULL(inward_received_boxes_qty, 0))) AS boxes
              FROM inward_temp_logs
              WHERE LOWER(TRIM(inward_client_name)) = LOWER(TRIM(?))
                AND inward_entry_date >= ?
                AND inward_entry_date <= ?
            ";
            $inwardParams = [$clientName, $fromDate, $toDate];
            if ($warehouseName !== '') {
                $inwardSql .= " AND LOWER(TRIM(IFNULL(warehouse_name,''))) = LOWER(TRIM(?)) ";
                $inwardParams[] = $warehouseName;
            }
            $inwardSql .= " GROUP BY DATE_FORMAT(inward_entry_date, '%Y-%m-%d') ";
            $stmt = $pdo->prepare($inwardSql);
            $stmt->execute($inwardParams);
            $inwardRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $outwardSql = "
              SELECT
                DATE_FORMAT(outward_entry_date, '%Y-%m-%d') AS entry_date,
                SUM(GREATEST(0, IFNULL(outward_received_boxes_qty, 0))) AS boxes
              FROM outward_temp_logs
              WHERE LOWER(TRIM(outward_client_name)) = LOWER(TRIM(?))
                AND outward_entry_date >= ?
                AND outward_entry_date <= ?
            ";
            $outwardParams = [$clientName, $fromDate, $toDate];
            if ($warehouseName !== '') {
                $outwardSql .= " AND LOWER(TRIM(IFNULL(warehouse_name,''))) = LOWER(TRIM(?)) ";
                $outwardParams[] = $warehouseName;
            }
            $outwardSql .= " GROUP BY DATE_FORMAT(outward_entry_date, '%Y-%m-%d') ";
            $stmt = $pdo->prepare($outwardSql);
            $stmt->execute($outwardParams);
            $outwardRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $supervisor_name = null;
            $supervisor_email = null;
            $whForSupervisor = $warehouseName !== ''
                ? $warehouseName
                : trim((string) ($chamberRows[0]['warehouse_name'] ?? ''));
            if ($whForSupervisor !== '') {
                $opStmt = $pdo->prepare(
                    "SELECT full_name, email FROM do_operators
                     WHERE LOWER(TRIM(warehouse_name)) = LOWER(TRIM(?))
                     ORDER BY id ASC LIMIT 1"
                );
                $opStmt->execute([$whForSupervisor]);
                $op = $opStmt->fetch(PDO::FETCH_ASSOC);
                if ($op) {
                    $supervisor_name = $op['full_name'] ?? null;
                    $supervisor_email = $op['email'] ?? null;
                }
            }

            /** @var array<string, array<string, mixed>> $byDate */
            $byDate = [];
            $ensureDay = static function (string $ymd) use (&$byDate): array {
                if (!isset($byDate[$ymd])) {
                    $byDate[$ymd] = [
                        'date' => $ymd,
                        'morning_qty' => null,
                        'evening_qty' => null,
                        'morning_temp' => null,
                        'evening_temp' => null,
                        'inward_boxes' => 0,
                        'outward_boxes' => 0,
                        'total_boxes' => null,
                    ];
                }
                return $byDate[$ymd];
            };

            $cursor = new \DateTimeImmutable($fromDate);
            $end = new \DateTimeImmutable($toDate);
            while ($cursor <= $end) {
                $ensureDay($cursor->format('Y-m-d'));
                $cursor = $cursor->modify('+1 day');
            }

            foreach ($chamberRows as $row) {
                $ymd = (string) ($row['entry_date'] ?? '');
                if ($ymd === '') {
                    continue;
                }
                $ensureDay($ymd);
                $shift = DateTimeUtil::resolveShift(
                    isset($row['shift']) ? (string) $row['shift'] : null,
                    isset($row['inspection_time']) ? (string) $row['inspection_time'] : null
                );
                $rawCount = $row['box_count'] ?? null;
                $qty = ($rawCount === null || $rawCount === '')
                    ? null
                    : max(0, (int) $rawCount);
                $temp = DateTimeUtil::parseOptionalFloat($row['box_temp'] ?? null);
                if ($shift === 'Evening') {
                    $byDate[$ymd]['evening_qty'] = $qty;
                    $byDate[$ymd]['evening_temp'] = $temp;
                } else {
                    $byDate[$ymd]['morning_qty'] = $qty;
                    $byDate[$ymd]['morning_temp'] = $temp;
                }
            }

            foreach ($inwardRows as $row) {
                if (empty($row['entry_date'])) {
                    continue;
                }
                $ymd = (string) $row['entry_date'];
                $ensureDay($ymd);
                $byDate[$ymd]['inward_boxes'] = max(0, (int) ($row['boxes'] ?? 0));
            }
            foreach ($outwardRows as $row) {
                if (empty($row['entry_date'])) {
                    continue;
                }
                $ymd = (string) $row['entry_date'];
                $ensureDay($ymd);
                $byDate[$ymd]['outward_boxes'] = max(0, (int) ($row['boxes'] ?? 0));
            }

            foreach ($byDate as &$day) {
                if ($day['evening_qty'] !== null) {
                    $day['total_boxes'] = $day['evening_qty'];
                } elseif ($day['morning_qty'] !== null) {
                    $day['total_boxes'] = $day['morning_qty'];
                } else {
                    $day['total_boxes'] = null;
                }
            }
            unset($day);

            $dayKeys = array_keys($byDate);
            sort($dayKeys, SORT_STRING);
            $days = [];
            foreach ($dayKeys as $k) {
                $days[] = $byDate[$k];
            }

            $resolvedChamber = ($chamberName !== '' && $chamberName !== '-')
                ? $chamberName
                : (trim((string) ($chamberRows[0]['chamber_name'] ?? '')) ?: null);
            $resolvedWarehouse = $warehouseName !== ''
                ? $warehouseName
                : (trim((string) ($chamberRows[0]['warehouse_name'] ?? '')) ?: null);

            $totalsInward = 0;
            $totalsOutward = 0;
            foreach ($days as $d) {
                $totalsInward += (int) ($d['inward_boxes'] ?? 0);
                $totalsOutward += (int) ($d['outward_boxes'] ?? 0);
            }

            $closingTotal = null;
            for ($i = count($days) - 1; $i >= 0; $i--) {
                if ($days[$i]['total_boxes'] !== null) {
                    $closingTotal = $days[$i]['total_boxes'];
                    break;
                }
            }

            Response::json([
                'success' => true,
                'meta' => [
                    'client_name' => $clientName,
                    'warehouse_name' => $resolvedWarehouse,
                    'chamber_name' => $resolvedChamber,
                    'supervisor_name' => $supervisor_name,
                    'supervisor_email' => $supervisor_email,
                    'fromDate' => $fromDate,
                    'toDate' => $toDate,
                    'day_count' => count($days),
                    'month_inward_total' => $totalsInward,
                    'month_outward_total' => $totalsOutward,
                    'closing_total' => $closingTotal,
                ],
                'days' => $days,
            ]);
        } catch (\Throwable $e) {
            Response::json([
                'success' => false,
                'message' => 'Failed to load client month box sheet.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public static function getDoTaskOverview(Request $req, array $params = []): void
    {
        try {
            $pdo = Database::pdo();
            $today = date('Y-m-d');
            $from = substr((string) ($req->query('fromDate') ?? $req->query('date') ?? $today), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $from = $today;
            }

            $operators = $pdo->query(
                'SELECT id, email, full_name, phone_no, warehouse_name, warehouse_code, chamber_limit
                 FROM do_operators ORDER BY warehouse_name ASC, full_name ASC'
            )->fetchAll(PDO::FETCH_ASSOC);

            $assignments = [];
            try {
                $assignments = $pdo->query(
                    "SELECT a.chamber_id, a.client_name, NULLIF(TRIM(a.warehouse_name), '') AS assignment_warehouse,
                            c.name AS chamber_name, NULLIF(TRIM(c.warehouse_name), '') AS chamber_warehouse
                     FROM chamber_client_assignments a
                     LEFT JOIN chambers c ON c.id = a.chamber_id
                     WHERE (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
                       AND a.client_name IS NOT NULL AND TRIM(a.client_name) <> ''"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable) {
            }

            $logs = [];
            try {
                $stmt = $pdo->prepare(
                    "SELECT LOWER(TRIM(IFNULL(operator_email,''))) AS op, client_name, chamber_name, warehouse_name, shift, inspection_time
                     FROM daily_chamber_temp_logs WHERE entry_date = ?"
                );
                $stmt->execute([$from]);
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable) {
            }

            $logKeys = [];
            foreach ($logs as $l) {
                $shift = strtolower(trim((string) ($l['shift'] ?? '')));
                if ($shift === '') {
                    $t = strtoupper((string) ($l['inspection_time'] ?? ''));
                    $shift = (str_starts_with($t, '16:') || str_starts_with($t, '18:') || str_contains($t, 'PM')) ? 'evening' : 'morning';
                }
                $key = strtolower(($l['warehouse_name'] ?? '') . '|' . ($l['chamber_name'] ?? '') . '|' . ($l['client_name'] ?? '') . '|' . $shift);
                $logKeys[$key] = true;
            }

            $warehouses = [];
            $flatOperators = [];
            $summary = [
                'warehouses' => 0,
                'operators' => 0,
                'completed' => 0,
                'pending' => 0,
                'overdue' => 0,
                'morning_completed' => 0,
                'morning_pending' => 0,
                'morning_expected' => 0,
                'evening_completed' => 0,
                'evening_pending' => 0,
                'evening_expected' => 0,
            ];
            foreach ($operators as $op) {
                $wh = trim((string) ($op['warehouse_name'] ?? '')) ?: 'Unassigned';
                if (!isset($warehouses[$wh])) {
                    $warehouses[$wh] = [
                        'warehouse_name' => $wh,
                        'name' => $wh,
                        'operators' => [],
                        'morning_completed' => 0,
                        'evening_completed' => 0,
                        'morning_pending' => 0,
                        'evening_pending' => 0,
                        'morning_expected' => 0,
                        'evening_expected' => 0,
                        'completed' => 0,
                        'pending' => 0,
                        'overdue' => 0,
                    ];
                }
                $opAssignments = array_values(array_filter($assignments, static function ($a) use ($wh) {
                    $aw = trim((string) ($a['assignment_warehouse'] ?? $a['chamber_warehouse'] ?? ''));
                    return $aw === '' || strcasecmp($aw, $wh) === 0;
                }));

                $mDone = $eDone = $mPend = $ePend = 0;
                foreach ($opAssignments as $a) {
                    foreach (['morning', 'evening'] as $shift) {
                        $key = strtolower($wh . '|' . ($a['chamber_name'] ?? '') . '|' . ($a['client_name'] ?? '') . '|' . $shift);
                        $done = isset($logKeys[$key]);
                        if ($shift === 'morning') {
                            $done ? $mDone++ : $mPend++;
                        } else {
                            $done ? $eDone++ : $ePend++;
                        }
                    }
                }

                $opRow = [
                    'id' => $op['id'],
                    'email' => $op['email'],
                    'full_name' => $op['full_name'],
                    'phone_no' => $op['phone_no'],
                    'warehouse_name' => $op['warehouse_name'],
                    'chamber_limit' => $op['chamber_limit'],
                    'morning_completed' => $mDone,
                    'evening_completed' => $eDone,
                    'morning_pending' => $mPend,
                    'evening_pending' => $ePend,
                    'morning_expected' => $mDone + $mPend,
                    'evening_expected' => $eDone + $ePend,
                    'completed' => $mDone + $eDone,
                    'pending' => $mPend + $ePend,
                    'tasks_total' => count($opAssignments) * 2,
                ];
                $warehouses[$wh]['operators'][] = $opRow;
                $flatOperators[] = $opRow;
                $warehouses[$wh]['morning_completed'] += $mDone;
                $warehouses[$wh]['evening_completed'] += $eDone;
                $warehouses[$wh]['morning_pending'] += $mPend;
                $warehouses[$wh]['evening_pending'] += $ePend;
                $warehouses[$wh]['morning_expected'] += $mDone + $mPend;
                $warehouses[$wh]['evening_expected'] += $eDone + $ePend;
                $warehouses[$wh]['completed'] += $mDone + $eDone;
                $warehouses[$wh]['pending'] += $mPend + $ePend;
            }

            $whList = array_values($warehouses);
            foreach ($whList as $w) {
                $summary['warehouses']++;
                $summary['operators'] += count($w['operators']);
                $summary['completed'] += $w['completed'];
                $summary['pending'] += $w['pending'];
                $summary['morning_completed'] += $w['morning_completed'];
                $summary['morning_pending'] += $w['morning_pending'];
                $summary['morning_expected'] += $w['morning_expected'];
                $summary['evening_completed'] += $w['evening_completed'];
                $summary['evening_pending'] += $w['evening_pending'];
                $summary['evening_expected'] += $w['evening_expected'];
            }

            Response::json([
                'success' => true,
                'today' => $from,
                'date' => $from,
                'fromDate' => $from,
                'toDate' => $from,
                'summary' => $summary,
                'warehouses' => $whList,
                'operators' => $flatOperators,
            ]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to load DO task overview.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function getPortalCustomers(Request $req, array $params = []): void
    {
        try {
            $rows = Database::pdo()->query(
                'SELECT id, email, full_name, phone_no, allowed_clients, allowed_warehouses, created_at
                 FROM customers ORDER BY full_name ASC, email ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
            Response::json(['success' => true, 'total' => count($rows), 'customers' => $rows]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Server error while loading customers.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function getDoOperatorsList(Request $req, array $params = []): void
    {
        try {
            $pdo = Database::pdo();
            $rows = $pdo->query(
                'SELECT id, email, full_name, phone_no, warehouse_name, warehouse_code, chamber_limit, created_at
                 FROM do_operators ORDER BY warehouse_name ASC, full_name ASC, email ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
            $today = date('Y-m-d');
            $io = [];
            $bump = static function (string $email, string $field, $n) use (&$io): void {
                $key = strtolower(trim($email));
                if ($key === '') {
                    return;
                }
                if (!isset($io[$key])) {
                    $io[$key] = ['total_inward' => 0, 'total_outward' => 0, 'today_inward' => 0, 'today_outward' => 0];
                }
                $io[$key][$field] = (int) $n;
            };
            try {
                foreach ($pdo->query("SELECT LOWER(TRIM(IFNULL(operator_email,''))) AS email, COUNT(*) AS c FROM inward_temp_logs WHERE TRIM(IFNULL(operator_email,'')) <> '' GROUP BY 1") as $r) {
                    $bump((string) $r['email'], 'total_inward', $r['c']);
                }
                foreach ($pdo->query("SELECT LOWER(TRIM(IFNULL(operator_email,''))) AS email, COUNT(*) AS c FROM outward_temp_logs WHERE TRIM(IFNULL(operator_email,'')) <> '' GROUP BY 1") as $r) {
                    $bump((string) $r['email'], 'total_outward', $r['c']);
                }
                $st = $pdo->prepare("SELECT LOWER(TRIM(IFNULL(operator_email,''))) AS email, COUNT(*) AS c FROM inward_temp_logs WHERE inward_entry_date = ? AND TRIM(IFNULL(operator_email,'')) <> '' GROUP BY 1");
                $st->execute([$today]);
                foreach ($st as $r) {
                    $bump((string) $r['email'], 'today_inward', $r['c']);
                }
                $st = $pdo->prepare("SELECT LOWER(TRIM(IFNULL(operator_email,''))) AS email, COUNT(*) AS c FROM outward_temp_logs WHERE outward_entry_date = ? AND TRIM(IFNULL(operator_email,'')) <> '' GROUP BY 1");
                $st->execute([$today]);
                foreach ($st as $r) {
                    $bump((string) $r['email'], 'today_outward', $r['c']);
                }
            } catch (\Throwable) {
            }

            $list = array_map(static function ($row) use ($io) {
                $key = strtolower(trim((string) ($row['email'] ?? '')));
                return array_merge($row, $io[$key] ?? [
                    'total_inward' => 0, 'total_outward' => 0, 'today_inward' => 0, 'today_outward' => 0,
                ]);
            }, $rows);

            Response::json(['success' => true, 'operators' => $list, 'data' => $list]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to load DO operators.', 'error' => $e->getMessage()], 500);
        }
    }

    public static function getDoOperatorIoCounts(Request $req, array $params = []): void
    {
        try {
            $email = strtolower(trim((string) ($req->query('email') ?? '')));
            if ($email === '') {
                Response::json(['error' => 'email is required.'], 400);
                return;
            }
            $pdo = Database::pdo();
            $today = date('Y-m-d');
            $st = $pdo->prepare('SELECT COUNT(*) FROM inward_temp_logs WHERE LOWER(TRIM(IFNULL(operator_email,\'\'))) = ?');
            $st->execute([$email]);
            $inTotal = (int) $st->fetchColumn();
            $st = $pdo->prepare('SELECT COUNT(*) FROM outward_temp_logs WHERE LOWER(TRIM(IFNULL(operator_email,\'\'))) = ?');
            $st->execute([$email]);
            $outTotal = (int) $st->fetchColumn();
            $st = $pdo->prepare('SELECT COUNT(*) FROM inward_temp_logs WHERE LOWER(TRIM(IFNULL(operator_email,\'\'))) = ? AND inward_entry_date = ?');
            $st->execute([$email, $today]);
            $inToday = (int) $st->fetchColumn();
            $st = $pdo->prepare('SELECT COUNT(*) FROM outward_temp_logs WHERE LOWER(TRIM(IFNULL(operator_email,\'\'))) = ? AND outward_entry_date = ?');
            $st->execute([$email, $today]);
            $outToday = (int) $st->fetchColumn();

            Response::json([
                'success' => true,
                'email' => $email,
                'total_inward' => $inTotal,
                'total_outward' => $outTotal,
                'today_inward' => $inToday,
                'today_outward' => $outToday,
            ]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => 'Failed to load IO counts.', 'error' => $e->getMessage()], 500);
        }
    }
}
