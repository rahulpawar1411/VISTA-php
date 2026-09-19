<?php
declare(strict_types=1);

namespace App\Services;

final class LogAttribution
{
    /** @param array<string, mixed>|null $user @param array<string, mixed> $body */
    public static function resolve(?array $user, array $body = []): array
    {
        $warehouse = ($user['warehouse_name'] ?? null) ?: ($body['warehouse_name'] ?? null);
        $warehouseCode = ($user['warehouse_code'] ?? null) ?: ($body['warehouse_code'] ?? null);
        $operatorEmail = ($user['email'] ?? null) ?: ($body['operator_email'] ?? null);

        return [
            'warehouse_name' => $warehouse ? trim((string) $warehouse) : null,
            'warehouse_code' => $warehouseCode ? trim((string) $warehouseCode) : null,
            'operator_email' => $operatorEmail ? strtolower(trim((string) $operatorEmail)) : null,
        ];
    }
}
