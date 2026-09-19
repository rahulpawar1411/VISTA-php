<?php
declare(strict_types=1);

namespace App\Services;

final class ClientCodeGenerator
{
    public static function slugPart(string $value, int $maxLen = 14): string
    {
        $v = strtoupper(trim($value));
        $v = preg_replace('/[^A-Z0-9]+/', '-', $v) ?? '';
        $v = trim($v, '-');
        $v = preg_replace('/-+/', '-', $v) ?? '';
        return substr($v, 0, $maxLen);
    }

    public static function warehouseToken(?string $warehouseName, ?string $warehouseCode): string
    {
        $code = strtoupper(trim((string) $warehouseCode));
        if ($code !== '') {
            $stripped = preg_replace('/^WH-/i', '', $code) ?? '';
            if ($stripped !== '') {
                return self::slugPart($stripped, 10);
            }
        }
        $name = trim((string) $warehouseName);
        if ($name === '') {
            return '';
        }
        $words = preg_split('/\s+/', $name) ?: [];
        $words = array_values(array_filter($words));
        if (count($words) >= 2) {
            $initials = '';
            foreach ($words as $w) {
                $initials .= strtoupper($w[0] ?? '');
            }
            return substr($initials, 0, 8);
        }
        return self::slugPart($words[0] ?? '', 10);
    }

    public static function clientToken(?string $clientName): string
    {
        $name = trim((string) $clientName);
        if ($name === '') {
            return '';
        }
        $words = preg_split('/\s+/', $name) ?: [];
        $words = array_values(array_filter($words));
        if (count($words) >= 2 && strlen($words[0]) <= 6) {
            return self::slugPart($words[0], 12);
        }
        return self::slugPart(preg_replace('/\s+/', '-', $name) ?? $name, 16);
    }

    public static function generate(?string $clientName, ?string $warehouseName = null, ?string $warehouseCode = null): string
    {
        $clientPart = self::clientToken($clientName);
        if ($clientPart === '') {
            return '';
        }
        $whPart = self::warehouseToken($warehouseName, $warehouseCode);
        $raw = $whPart !== '' ? "CL-{$whPart}-{$clientPart}" : "CL-{$clientPart}";
        return substr(preg_replace('/-+/', '-', $raw) ?? $raw, 0, 48);
    }
}
