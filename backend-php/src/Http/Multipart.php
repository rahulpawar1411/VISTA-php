<?php
declare(strict_types=1);

namespace App\Http;

/** Multi-file + single-file helpers for PHP $_FILES. */
final class Multipart
{
    public static function mergeInto(Request $req): void
    {
        $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (!str_contains($ct, 'multipart/form-data') && empty($_FILES) && empty($_POST)) {
            return;
        }
        if (!empty($_POST)) {
            $req->mergeBody($_POST);
        }
    }

    /** @return array{tmp_name: string, name: string, type: string, size: int, error: int}|null */
    public static function file(string $field): ?array
    {
        $list = self::files($field);
        return $list[0] ?? null;
    }

    /**
     * @return list<array{tmp_name: string, name: string, type: string, size: int, error: int}>
     */
    public static function files(string $field): array
    {
        if (empty($_FILES[$field])) {
            return [];
        }
        $f = $_FILES[$field];
        $out = [];

        // Multi: name[] structure
        if (is_array($f['name'] ?? null)) {
            $count = count($f['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $out[] = [
                    'tmp_name' => (string) $f['tmp_name'][$i],
                    'name' => (string) $f['name'][$i],
                    'type' => (string) ($f['type'][$i] ?? 'application/octet-stream'),
                    'size' => (int) ($f['size'][$i] ?? 0),
                    'error' => (int) $f['error'][$i],
                ];
            }
            return $out;
        }

        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return [];
        }
        return [[
            'tmp_name' => (string) $f['tmp_name'],
            'name' => (string) ($f['name'] ?? 'upload.bin'),
            'type' => (string) ($f['type'] ?? 'application/octet-stream'),
            'size' => (int) ($f['size'] ?? 0),
            'error' => (int) $f['error'],
        ]];
    }
}
