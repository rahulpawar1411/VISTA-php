<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;

final class MasterResolver
{
    public static function resolveWarehouseByCodeOrName(?string $warehouse_code = null, ?string $warehouse_name = null): ?array
    {
        $pdo = Database::pdo();
        $code = trim((string) $warehouse_code);
        $name = trim((string) $warehouse_name);

        if ($code !== '') {
            $stmt = $pdo->prepare(
                'SELECT warehouse_code, warehouse_name FROM warehouse_master
                 WHERE warehouse_code = ? OR UPPER(TRIM(warehouse_code)) = UPPER(TRIM(?)) LIMIT 1'
            );
            $stmt->execute([$code, $code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        if ($name !== '') {
            $stmt = $pdo->prepare(
                'SELECT warehouse_code, warehouse_name FROM warehouse_master
                 WHERE LOWER(TRIM(warehouse_name)) = LOWER(TRIM(?)) LIMIT 1'
            );
            $stmt->execute([$name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    public static function resolveWarehouseFields(?string $warehouse_code = null, ?string $warehouse_name = null): array
    {
        $resolved = self::resolveWarehouseByCodeOrName($warehouse_code, $warehouse_name);
        if ($resolved) {
            return [
                'warehouse_code' => $resolved['warehouse_code'],
                'warehouse_name' => $resolved['warehouse_name'],
            ];
        }
        return [
            'warehouse_code' => $warehouse_code !== null && trim($warehouse_code) !== '' ? trim($warehouse_code) : null,
            'warehouse_name' => $warehouse_name !== null && trim($warehouse_name) !== '' ? trim($warehouse_name) : null,
        ];
    }

    public static function resolveClientByCodeOrName(?string $client_code = null, ?string $client_name = null, ?string $warehouse_name = null): ?array
    {
        $pdo = Database::pdo();
        $code = trim((string) $client_code);
        $name = trim((string) $client_name);
        $wh = trim((string) $warehouse_name);

        if ($code !== '') {
            $stmt = $pdo->prepare(
                'SELECT client_code, client_name, warehouse_name FROM client_master WHERE client_code = ? LIMIT 1'
            );
            $stmt->execute([$code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        if ($name !== '') {
            $stmt = $pdo->prepare(
                'SELECT client_code, client_name, warehouse_name FROM client_master
                 WHERE LOWER(TRIM(client_name)) = LOWER(TRIM(?))
                   AND LOWER(TRIM(COALESCE(warehouse_name, \'\'))) = LOWER(TRIM(COALESCE(?, \'\')))
                 LIMIT 1'
            );
            $stmt->execute([$name, $wh]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    public static function resolveClientFields(
        ?string $client_code = null,
        ?string $client_name = null,
        ?string $warehouse_name = null,
        ?string $warehouse_code = null
    ): array {
        $whName = $warehouse_name ? trim($warehouse_name) : null;
        if ($warehouse_code && !$whName) {
            $wh = self::resolveWarehouseByCodeOrName($warehouse_code, null);
            $whName = $wh['warehouse_name'] ?? null;
        }
        $resolved = self::resolveClientByCodeOrName($client_code, $client_name, $whName);
        if ($resolved) {
            return [
                'client_code' => $resolved['client_code'],
                'client_name' => $resolved['client_name'],
            ];
        }
        return [
            'client_code' => $client_code ? trim($client_code) : null,
            'client_name' => $client_name ? trim($client_name) : null,
        ];
    }
}
