<?php
declare(strict_types=1);

namespace App\Services;

final class DateTimeUtil
{
    public static function formatDateTime(?\DateTimeInterface $date = null): string
    {
        $d = $date ?? new \DateTimeImmutable('now');
        return $d->format('Y-m-d H:i:s');
    }

    public static function parseOptionalFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    public static function calculateVariance($entryDateStr, ?string $inspectionTimeStr, $captureDate): int
    {
        try {
            if (!$entryDateStr || !$inspectionTimeStr || !$captureDate) {
                return 0;
            }
            $timePart = $inspectionTimeStr;
            if (stripos($inspectionTimeStr, 'AM') !== false || stripos($inspectionTimeStr, 'PM') !== false) {
                if (preg_match('/(\d+):(\d+)\s*(AM|PM)/i', $inspectionTimeStr, $m)) {
                    $h = (int) $m[1];
                    $min = $m[2];
                    $ampm = strtoupper($m[3]);
                    if ($ampm === 'PM' && $h < 12) {
                        $h += 12;
                    }
                    if ($ampm === 'AM' && $h === 12) {
                        $h = 0;
                    }
                    $timePart = sprintf('%02d:%s', $h, $min);
                }
            }
            [$hours, $minutes] = array_map('intval', explode(':', $timePart) + [0, 0]);

            if ($entryDateStr instanceof \DateTimeInterface) {
                $year = (int) $entryDateStr->format('Y');
                $month = (int) $entryDateStr->format('m');
                $day = (int) $entryDateStr->format('d');
            } else {
                $parts = explode('-', explode('T', (string) $entryDateStr)[0]);
                $year = (int) ($parts[0] ?? 0);
                $month = (int) ($parts[1] ?? 0);
                $day = (int) ($parts[2] ?? 0);
            }

            $inspection = new \DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hours, $minutes));
            if (is_string($captureDate)) {
                $capture = new \DateTimeImmutable(str_replace(' ', 'T', $captureDate));
            } elseif ($captureDate instanceof \DateTimeInterface) {
                $capture = \DateTimeImmutable::createFromInterface($captureDate);
            } else {
                $capture = new \DateTimeImmutable('@' . (int) $captureDate);
            }
            $diff = abs($capture->getTimestamp() - $inspection->getTimestamp());
            return (int) round($diff / 60);
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function resolveShift(?string $shift, ?string $inspectionTime): string
    {
        $s = trim((string) $shift);
        if (preg_match('/^morning$/i', $s)) {
            return 'Morning';
        }
        if (preg_match('/^evening$/i', $s)) {
            return 'Evening';
        }
        $t = strtoupper(trim((string) $inspectionTime));
        if (str_starts_with($t, '10:00') || $t === '10:00 AM') {
            return 'Morning';
        }
        if (str_starts_with($t, '16:00') || str_starts_with($t, '18:00') || str_contains($t, '04:00 PM') || str_contains($t, '06:00 PM')) {
            return 'Evening';
        }
        if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $hm)) {
            $h = (int) $hm[1];
            if (str_contains($t, 'PM') && $h < 12) {
                $h += 12;
            }
            if (str_contains($t, 'AM') && $h === 12) {
                $h = 0;
            }
            return $h < 14 ? 'Morning' : 'Evening';
        }
        return 'Morning';
    }
}
