<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use PDO;

final class LoginSecurity
{
    private const FAIL_WINDOW_MS = 3600 * 1000;
    private const MAX_FAILED = 5;
    private const LOCK_MS = 30 * 60 * 1000;

    public const MAX_FAILED_ATTEMPTS = 5;

    public static function lockoutEnabled(): bool
    {
        return Env::bool('LOGIN_LOCKOUT', false);
    }

    /** @return array{locked: bool, lockedUntil?: string, minutesLeft?: int, role?: ?string} */
    public static function checkLoginLock(string $email): array
    {
        if (!self::lockoutEnabled()) {
            return ['locked' => false];
        }

        $row = self::getRow($email);
        if (!$row || empty($row['locked_until'])) {
            return ['locked' => false];
        }

        $until = strtotime((string) $row['locked_until']);
        if ($until !== false && $until > time()) {
            return [
                'locked' => true,
                'lockedUntil' => gmdate('c', $until),
                'minutesLeft' => max(1, (int) ceil(($until - time()) / 60)),
                'role' => $row['role'] ?? null,
            ];
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'UPDATE login_security
             SET failed_count = 0, window_started_at = NULL, locked_until = NULL
             WHERE email = ?'
        );
        $stmt->execute([$email]);
        return ['locked' => false];
    }

    /** @return array{failedCount: int, locked: bool, remainingAttempts: int, lockedUntil?: string, minutesLeft?: int} */
    public static function recordFailedLogin(string $email, ?string $role = null): array
    {
        if (!self::lockoutEnabled()) {
            return ['failedCount' => 0, 'locked' => false, 'remainingAttempts' => 999];
        }

        $pdo = Database::pdo();
        $now = time();
        $nowSql = date('Y-m-d H:i:s', $now);
        $row = self::getRow($email);

        if (!$row) {
            $stmt = $pdo->prepare(
                'INSERT INTO login_security
                  (email, role, failed_count, window_started_at, last_failed_at, locked_until)
                 VALUES (?, ?, 1, ?, ?, NULL)'
            );
            $stmt->execute([$email, $role, $nowSql, $nowSql]);
            return [
                'failedCount' => 1,
                'locked' => false,
                'remainingAttempts' => self::MAX_FAILED - 1,
            ];
        }

        $failedCount = (int) ($row['failed_count'] ?? 0);
        $windowStarted = !empty($row['window_started_at']) ? strtotime((string) $row['window_started_at']) : false;
        $windowExpired = !$windowStarted || (($now - $windowStarted) * 1000) > self::FAIL_WINDOW_MS;

        if ($windowExpired) {
            $failedCount = 1;
            $windowStarted = $now;
        } else {
            $failedCount += 1;
        }

        if ($failedCount >= self::MAX_FAILED) {
            $lockedUntil = $now + (int) (self::LOCK_MS / 1000);
            $stmt = $pdo->prepare(
                'UPDATE login_security
                 SET role = COALESCE(?, role),
                     failed_count = ?,
                     window_started_at = ?,
                     last_failed_at = ?,
                     locked_until = ?
                 WHERE email = ?'
            );
            $stmt->execute([
                $role,
                $failedCount,
                date('Y-m-d H:i:s', $windowStarted),
                $nowSql,
                date('Y-m-d H:i:s', $lockedUntil),
                $email,
            ]);
            return [
                'failedCount' => $failedCount,
                'locked' => true,
                'remainingAttempts' => 0,
                'lockedUntil' => gmdate('c', $lockedUntil),
                'minutesLeft' => 30,
            ];
        }

        $stmt = $pdo->prepare(
            'UPDATE login_security
             SET role = COALESCE(?, role),
                 failed_count = ?,
                 window_started_at = ?,
                 last_failed_at = ?,
                 locked_until = NULL
             WHERE email = ?'
        );
        $stmt->execute([
            $role,
            $failedCount,
            date('Y-m-d H:i:s', $windowStarted ?: $now),
            $nowSql,
            $email,
        ]);

        return [
            'failedCount' => $failedCount,
            'locked' => false,
            'remainingAttempts' => max(0, self::MAX_FAILED - $failedCount),
        ];
    }

    public static function clearLoginSecurity(string $email): void
    {
        if (!self::lockoutEnabled()) {
            return;
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE login_security
             SET failed_count = 0, window_started_at = NULL, last_failed_at = NULL, locked_until = NULL
             WHERE email = ?'
        );
        $stmt->execute([$email]);
    }

    /** @return array<string, mixed>|null */
    private static function getRow(string $email): ?array
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT * FROM login_security WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
