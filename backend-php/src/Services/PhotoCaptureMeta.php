<?php
/**
 * Soft-normalize photo_capture_metadata (inward/outward JSON GPS + time map).
 */
declare(strict_types=1);

namespace App\Services;

final class PhotoCaptureMeta
{
    /** @param mixed $raw */
    public static function normalize($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            $json = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json !== false ? $json : null;
        }
        if (!is_string($raw)) {
            return null;
        }
        $trim = trim($raw);
        if ($trim === '' || strtolower($trim) === 'null') {
            return null;
        }
        $decoded = json_decode($trim, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json !== false ? $json : $trim;
        }
        // Keep raw string if clients already sent JSON-ish text
        return $trim;
    }
}
