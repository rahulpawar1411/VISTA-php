<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;

final class LogDedup
{
    public static function normalizeSubmittedAt($value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }
        return gmdate('c', $ts);
    }

    /** @param array<string, mixed> $fields */
    public static function pickSubmission(array $fields): array
    {
        return [
            'submissionId' => trim((string) (
                $fields['submissionId']
                ?? $fields['client_submission_id']
                ?? $fields['local_id']
                ?? ''
            )),
            'submittedAt' => self::normalizeSubmittedAt(
                $fields['submittedAt']
                ?? $fields['client_submitted_at']
                ?? $fields['created_at']
                ?? ''
            ),
        ];
    }

    /** @param array<string, mixed> $fields @return array{id:int, reference_no:?string}|null */
    public static function findInwardDuplicate(array $fields): ?array
    {
        $pdo = Database::pdo();
        $pick = self::pickSubmission($fields);
        if ($pick['submissionId'] !== '') {
            try {
                $stmt = $pdo->prepare(
                    'SELECT inward_id AS id, reference_no FROM inward_temp_logs WHERE client_submission_id = ? LIMIT 1'
                );
                $stmt->execute([$pick['submissionId']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return ['id' => (int) $row['id'], 'reference_no' => $row['reference_no'] ?? null];
                }
            } catch (\Throwable) {
            }
        }
        if ($pick['submittedAt'] !== '' && !empty($fields['date']) && !empty($fields['vehicle'])) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT inward_id AS id, reference_no FROM inward_temp_logs
                     WHERE inward_entry_date = ?
                       AND TRIM(LOWER(inward_vehicle_no)) = TRIM(LOWER(?))
                       AND TRIM(LOWER(IFNULL(operator_email,\'\'))) = TRIM(LOWER(IFNULL(?,\'\')))
                       AND client_submitted_at = ?
                     LIMIT 1'
                );
                $stmt->execute([
                    $fields['date'],
                    trim((string) $fields['vehicle']),
                    $fields['operator'] ?? '',
                    $pick['submittedAt'],
                ]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return ['id' => (int) $row['id'], 'reference_no' => $row['reference_no'] ?? null];
                }
            } catch (\Throwable) {
            }
        }
        return null;
    }

    /** @param array<string, mixed> $fields @return array{id:int, reference_no:?string}|null */
    public static function findOutwardDuplicate(array $fields): ?array
    {
        $pdo = Database::pdo();
        $pick = self::pickSubmission($fields);
        if ($pick['submissionId'] !== '') {
            try {
                $stmt = $pdo->prepare(
                    'SELECT outward_id AS id, reference_no FROM outward_temp_logs WHERE client_submission_id = ? LIMIT 1'
                );
                $stmt->execute([$pick['submissionId']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return ['id' => (int) $row['id'], 'reference_no' => $row['reference_no'] ?? null];
                }
            } catch (\Throwable) {
            }
        }
        if ($pick['submittedAt'] !== '' && !empty($fields['date']) && !empty($fields['vehicle'])) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT outward_id AS id, reference_no FROM outward_temp_logs
                     WHERE outward_entry_date = ?
                       AND TRIM(LOWER(outward_vehicle_no)) = TRIM(LOWER(?))
                       AND TRIM(LOWER(IFNULL(operator_email,\'\'))) = TRIM(LOWER(IFNULL(?,\'\')))
                       AND client_submitted_at = ?
                     LIMIT 1'
                );
                $stmt->execute([
                    $fields['date'],
                    trim((string) $fields['vehicle']),
                    $fields['operator'] ?? '',
                    $pick['submittedAt'],
                ]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return ['id' => (int) $row['id'], 'reference_no' => $row['reference_no'] ?? null];
                }
            } catch (\Throwable) {
            }
        }
        return null;
    }
}
