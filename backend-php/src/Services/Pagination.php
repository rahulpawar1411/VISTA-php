<?php
declare(strict_types=1);

namespace App\Services;

final class Pagination
{
    /** @param array<string, string|null> $query */
    public static function parse(array $query, int $defaultLimit = 50, int $maxLimit = 200): array
    {
        $isExport = (($query['export'] ?? '') === '1' || ($query['export'] ?? '') === 'true');
        $cap = $isExport ? 2000 : $maxLimit;
        $page = max(1, (int) ($query['page'] ?? 1) ?: 1);
        $limit = (int) ($query['limit'] ?? $defaultLimit) ?: $defaultLimit;
        $limit = min(max(1, $limit), $cap);
        $offset = ($page - 1) * $limit;
        return compact('page', 'limit', 'offset', 'isExport');
    }

    /** @param list<array<string, mixed>> $items */
    public static function payload(array $items, int $total, int $page, int $limit): array
    {
        $hasMore = $page * $limit < $total;
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'hasMore' => $hasMore,
            'has_more' => $hasMore,
        ];
    }

    /** @param list<string> $conditions @param list<mixed> $params @param array<string, mixed>|null $user */
    public static function appendCustomerScope(array &$conditions, array &$params, ?array $user): void
    {
        $role = ($user['role'] ?? '') === 'sub_admin' ? 'customer' : ($user['role'] ?? '');
        if (!$user || $role !== 'customer') {
            return;
        }
        $clients = self::csvLower($user['allowed_clients'] ?? null);
        $warehouses = self::csvLower($user['allowed_warehouses'] ?? null);
        if ($clients) {
            $ph = implode(',', array_fill(0, count($clients), '?'));
            $conditions[] = "(LOWER(TRIM(COALESCE(client_code, ''))) IN ({$ph}) OR LOWER(TRIM(COALESCE(client_name, ''))) IN ({$ph}))";
            array_push($params, ...$clients, ...$clients);
        }
        if ($warehouses) {
            $ph = implode(',', array_fill(0, count($warehouses), '?'));
            $conditions[] = "(warehouse_name IS NULL OR TRIM(COALESCE(warehouse_name, '')) = '' OR LOWER(TRIM(COALESCE(warehouse_code, ''))) IN ({$ph}) OR LOWER(TRIM(warehouse_name)) IN ({$ph}))";
            array_push($params, ...$warehouses, ...$warehouses);
        }
    }

    /** @param list<string> $conditions @param list<mixed> $params */
    public static function appendDoWarehouseScope(array &$conditions, array &$params, ?array $user): void
    {
        if (!$user || ($user['role'] ?? '') !== 'do_operator') {
            return;
        }
        $name = trim((string) ($user['warehouse_name'] ?? ''));
        $code = trim((string) ($user['warehouse_code'] ?? ''));
        if ($name === '' && $code === '') {
            return;
        }
        $parts = ["warehouse_name IS NULL OR TRIM(COALESCE(warehouse_name, '')) = ''"];
        if ($code !== '') {
            $parts[] = 'LOWER(TRIM(COALESCE(warehouse_code, \'\'))) = ?';
            $params[] = strtolower($code);
        }
        if ($name !== '') {
            $parts[] = 'LOWER(TRIM(COALESCE(warehouse_name, \'\'))) = ?';
            $params[] = strtolower($name);
        }
        $conditions[] = '(' . implode(' OR ', $parts) . ')';
    }

    /** @return list<string> */
    private static function csvLower($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn($v) => strtolower(trim((string) $v)), $value)));
        }
        return array_values(array_filter(array_map(
            static fn($s) => strtolower(trim($s)),
            explode(',', (string) $value)
        )));
    }
}
