<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;

final class ExpoPush
{
    private const PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    public static function isExpoPushToken(?string $token): bool
    {
        $t = trim((string) $token);
        return str_starts_with($t, 'ExponentPushToken[') || str_starts_with($t, 'ExpoPushToken[');
    }

    /**
     * @param string|list<string> $tokens
     * @param array{title: string, body: string, data?: array, channelId?: string} $payload
     */
    public static function send($tokens, array $payload): array
    {
        $list = array_values(array_filter(
            array_map(static fn($t) => trim((string) $t), is_array($tokens) ? $tokens : [$tokens]),
            [self::class, 'isExpoPushToken']
        ));
        if (!$list) {
            return ['ok' => true, 'sent' => 0, 'deadTokens' => []];
        }

        $messages = [];
        foreach ($list as $to) {
            $messages[] = [
                'to' => $to,
                'sound' => 'default',
                'title' => $payload['title'] ?? 'ReeferON',
                'body' => $payload['body'] ?? '',
                'data' => $payload['data'] ?? new \stdClass(),
                'channelId' => $payload['channelId'] ?? 'default',
            ];
        }

        $ch = curl_init(self::PUSH_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Encoding: gzip, deflate',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($messages),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'sent' => 0, 'error' => $err, 'deadTokens' => []];
        }

        $decoded = json_decode($raw, true);
        $dead = [];
        $tickets = $decoded['data'] ?? [];
        if (is_array($tickets)) {
            foreach ($tickets as $i => $ticket) {
                $details = json_encode($ticket);
                if (stripos((string) $details, 'DeviceNotRegistered') !== false || stripos((string) $details, 'InvalidCredentials') !== false) {
                    if (isset($list[$i])) {
                        $dead[] = $list[$i];
                    }
                }
            }
        }
        if ($dead) {
            self::clearDeadTokens($dead);
        }
        return ['ok' => true, 'sent' => count($list), 'deadTokens' => $dead];
    }

    /** @param list<string> $tokens */
    public static function clearDeadTokens(array $tokens): void
    {
        $dead = array_values(array_filter($tokens, [self::class, 'isExpoPushToken']));
        if (!$dead) {
            return;
        }
        $pdo = Database::pdo();
        $ph = implode(',', array_fill(0, count($dead), '?'));
        foreach (['sub_admins', 'do_operators'] as $table) {
            try {
                $stmt = $pdo->prepare("UPDATE {$table} SET expo_push_token = NULL WHERE expo_push_token IN ({$ph})");
                $stmt->execute($dead);
            } catch (\Throwable) {
            }
        }
    }

    public static function notifySubAdminsPermissionRequest(string $operatorEmail, string $recordType, string $action = 'Edit'): void
    {
        try {
            $rows = Database::pdo()->query(
                "SELECT expo_push_token FROM sub_admins
                 WHERE expo_push_token IS NOT NULL AND TRIM(expo_push_token) <> ''"
            )->fetchAll(PDO::FETCH_ASSOC);
            $tokens = array_column($rows ?: [], 'expo_push_token');
            $who = trim($operatorEmail) ?: 'A DO';
            self::send($tokens, [
                'title' => 'New permission request',
                'body' => "{$who} requested {$action} on {$recordType} — open Admin to review",
                'data' => ['screen' => 'Admin', 'section' => 'permissions', 'type' => 'permission_request'],
                'channelId' => 'permission-alerts',
            ]);
        } catch (\Throwable $e) {
            error_log('[push] notifySubAdmins: ' . $e->getMessage());
        }
    }

    public static function notifyDoPermissionDecision(string $operatorEmail, string $status, string $recordType, string $adminRemark = ''): void
    {
        try {
            $email = trim($operatorEmail);
            if ($email === '') {
                return;
            }
            $stmt = Database::pdo()->prepare(
                "SELECT expo_push_token FROM do_operators
                 WHERE email = ? AND expo_push_token IS NOT NULL AND TRIM(expo_push_token) <> ''
                 LIMIT 1"
            );
            $stmt->execute([$email]);
            $token = $stmt->fetchColumn();
            if (!self::isExpoPushToken((string) $token)) {
                return;
            }
            $decided = strtolower($status) === 'denied' ? 'Denied' : 'Approved';
            $remark = trim($adminRemark);
            $body = $decided === 'Denied'
                ? ($remark !== ''
                    ? "Your {$recordType} request was denied. Admin remark: {$remark}"
                    : "Your {$recordType} request was denied. Open the app for details.")
                : "Your {$recordType} request was approved. Open the app to continue.";
            self::send((string) $token, [
                'title' => "Permission {$decided}",
                'body' => $body,
                'data' => ['screen' => 'Notifications', 'type' => 'permission_decision', 'status' => strtolower($decided)],
                'channelId' => 'permission-alerts',
            ]);
        } catch (\Throwable $e) {
            error_log('[push] notifyDo: ' . $e->getMessage());
        }
    }
}
