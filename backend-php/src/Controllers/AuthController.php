<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\ActivityLogger;
use App\Services\JwtService;
use App\Services\LoginSecurity;
use PDO;

final class AuthController
{
    private const TOKEN_TTL = 86400;

    public static function login(Request $req, array $params = []): void
    {
        try {
            $body = $req->body();
            $email = isset($body['email']) ? trim((string) $body['email']) : '';
            $password = isset($body['password']) ? (string) $body['password'] : '';

            if ($email === '' || $password === '') {
                Response::error('Please provide email and password.', 400);
                return;
            }

            $cleanEmail = strtolower($email);

            try {
                $lockState = LoginSecurity::checkLoginLock($cleanEmail);
            } catch (\Throwable) {
                $lockState = ['locked' => false];
            }

            if (!empty($lockState['locked'])) {
                ActivityLogger::log(
                    $cleanEmail,
                    'LOGIN_BLOCKED',
                    'SECURITY',
                    'Login blocked — account locked for ' . ($lockState['minutesLeft'] ?? 30) . ' more minute(s)'
                );
                self::lockedResponse($lockState);
                return;
            }

            $pdo = Database::pdo();
            $user = null;
            $resolvedRole = null;

            $stmt = $pdo->prepare('SELECT * FROM super_admin WHERE email = ? LIMIT 1');
            $stmt->execute([$cleanEmail]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $user = $row;
                $resolvedRole = 'super_admin';
            } else {
                try {
                    $stmt = $pdo->prepare('SELECT * FROM sub_admins WHERE email = ? LIMIT 1');
                    $stmt->execute([$cleanEmail]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $user = $row;
                        $resolvedRole = 'sub_admin';
                    }
                } catch (\Throwable) {
                    /* table may missing */
                }

                if (!$user) {
                    try {
                        $stmt = $pdo->prepare('SELECT * FROM customers WHERE email = ? LIMIT 1');
                        $stmt->execute([$cleanEmail]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $user = $row;
                            $resolvedRole = 'customer';
                        }
                    } catch (\Throwable) {
                    }
                }

                if (!$user) {
                    $stmt = $pdo->prepare('SELECT * FROM do_operators WHERE email = ? LIMIT 1');
                    $stmt->execute([$cleanEmail]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $user = $row;
                        $resolvedRole = 'do_operator';
                    }
                }
            }

            if (!$user) {
                $failInfo = LoginSecurity::recordFailedLogin($cleanEmail, null);
                ActivityLogger::log($cleanEmail, 'LOGIN_FAILED', 'SECURITY', 'Failed login attempt for unregistered email');
                if (!empty($failInfo['locked'])) {
                    ActivityLogger::log(
                        $cleanEmail,
                        'LOGIN_LOCKED',
                        'SECURITY',
                        'Account locked for 30 minutes after ' . LoginSecurity::MAX_FAILED_ATTEMPTS . ' failed attempts within 1 hour'
                    );
                    self::lockedResponse($failInfo);
                    return;
                }
                Response::json([
                    'success' => false,
                    'remainingAttempts' => $failInfo['remainingAttempts'] ?? 0,
                    'message' => 'Invalid email or password. Access Denied. (' . ($failInfo['remainingAttempts'] ?? 0) . ' attempt(s) left before 30 min lock)',
                ], 401);
                return;
            }

            if (!password_verify($password, (string) $user['password'])) {
                // bcryptjs $2a$ / $2b$ — PHP password_verify handles these
                $failInfo = LoginSecurity::recordFailedLogin($cleanEmail, $resolvedRole);
                ActivityLogger::log(
                    $cleanEmail,
                    'LOGIN_FAILED',
                    'SECURITY',
                    'Incorrect password attempt for role: ' . $resolvedRole
                );
                if (!empty($failInfo['locked'])) {
                    ActivityLogger::log(
                        $cleanEmail,
                        'LOGIN_LOCKED',
                        'SECURITY',
                        "Role {$resolvedRole} account locked for 30 minutes after " . LoginSecurity::MAX_FAILED_ATTEMPTS . ' failed attempts within 1 hour'
                    );
                    self::lockedResponse($failInfo);
                    return;
                }
                Response::json([
                    'success' => false,
                    'remainingAttempts' => $failInfo['remainingAttempts'] ?? 0,
                    'message' => 'Invalid email or password. Access Denied. (' . ($failInfo['remainingAttempts'] ?? 0) . ' attempt(s) left before 30 min lock)',
                ], 401);
                return;
            }

            try {
                LoginSecurity::clearLoginSecurity($cleanEmail);
            } catch (\Throwable) {
            }

            $tokenPayload = self::buildPayload($user, $resolvedRole);
            $token = JwtService::sign($tokenPayload, self::TOKEN_TTL);
            Response::setCookieToken($token, self::TOKEN_TTL * 1000);

            $loginName = trim((string) ($tokenPayload['full_name'] ?? ''));
            $loginWho = ($resolvedRole === 'do_operator' && $loginName !== '')
                ? "DO Operator {$loginName} ({$cleanEmail})"
                : "{$resolvedRole} ({$cleanEmail})";
            ActivityLogger::log($cleanEmail, 'LOGIN', 'SECURITY', "Authenticated successfully as {$loginWho}");

            Response::json([
                'success' => true,
                'message' => 'Logged in successfully.',
                'token' => $token,
                'user' => $tokenPayload,
            ]);
        } catch (\Throwable $e) {
            self::serverError($e, 'A server error occurred during login. Please contact support.');
        }
    }

    public static function logout(Request $req, array $params = []): void
    {
        try {
            Response::clearCookieToken();
            Response::json([
                'success' => true,
                'message' => 'Logged out successfully.',
            ]);
        } catch (\Throwable $e) {
            self::serverError($e, 'Server error while clearing session.');
        }
    }

    public static function me(Request $req, array $params = []): void
    {
        try {
            if ($req->user === null) {
                Response::error('No active session found.', 401);
                return;
            }
            Response::json([
                'success' => true,
                'user' => $req->user,
            ]);
        } catch (\Throwable $e) {
            self::serverError($e, 'Server error retrieving user profile.');
        }
    }

    public static function verifySuperAdminProfileAccess(Request $req, array $params = []): void
    {
        try {
            $sessionEmail = $req->user['email'] ?? null;
            if (!$sessionEmail || ($req->user['role'] ?? '') !== 'super_admin') {
                Response::error('Only Super Admin can access this section.', 403);
                return;
            }

            $body = $req->body();
            $cleanEmail = strtolower(trim((string) ($body['email'] ?? '')));
            $password = (string) ($body['password'] ?? '');
            if ($cleanEmail === '' || $password === '') {
                Response::error('Please enter your ID and password.', 400);
                return;
            }
            if ($cleanEmail !== strtolower((string) $sessionEmail)) {
                Response::error('Please use your currently logged-in Super Admin ID.', 403);
                return;
            }

            $stmt = Database::pdo()->prepare('SELECT * FROM super_admin WHERE email = ? LIMIT 1');
            $stmt->execute([$cleanEmail]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$admin) {
                Response::error('Super Admin account not found.', 404);
                return;
            }
            if (!password_verify($password, (string) $admin['password'])) {
                ActivityLogger::log($cleanEmail, 'PROFILE_ACCESS_FAILED', 'SECURITY', 'Profile access denied due to incorrect password.');
                Response::error('Invalid ID or password.', 401);
                return;
            }

            ActivityLogger::log($cleanEmail, 'PROFILE_ACCESS_VERIFIED', 'SECURITY', 'Profile access verified successfully.');
            Response::json([
                'success' => true,
                'message' => 'Identity verified.',
                'profile' => [
                    'email' => $admin['email'],
                    'full_name' => $admin['full_name'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            self::serverError($e, 'Server error while verifying profile access.');
        }
    }

    public static function changeSuperAdminPassword(Request $req, array $params = []): void
    {
        try {
            $sessionEmail = $req->user['email'] ?? null;
            if (!$sessionEmail || ($req->user['role'] ?? '') !== 'super_admin') {
                Response::error('Only Super Admin can update this profile.', 403);
                return;
            }

            $body = $req->body();
            $currentPassword = (string) ($body['currentPassword'] ?? '');
            $newPassword = $body['newPassword'] ?? null;
            $nextEmailRaw = $body['email'] ?? null;

            if ($currentPassword === '') {
                Response::error('Current password is required to save profile changes.', 400);
                return;
            }

            $nextEmail = is_string($nextEmailRaw) ? strtolower(trim($nextEmailRaw)) : '';
            $wantsEmailChange = $nextEmail !== '' && $nextEmail !== strtolower((string) $sessionEmail);
            $wantsPasswordChange = !empty($newPassword);

            if (!$wantsEmailChange && !$wantsPasswordChange) {
                Response::error('Update email and/or password to save changes.', 400);
                return;
            }
            if ($wantsPasswordChange && strlen((string) $newPassword) < 8) {
                Response::error('New password must be at least 8 characters long.', 400);
                return;
            }
            if ($wantsEmailChange && !filter_var($nextEmail, FILTER_VALIDATE_EMAIL)) {
                Response::error('Please enter a valid email address.', 400);
                return;
            }

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT * FROM super_admin WHERE email = ? LIMIT 1');
            $stmt->execute([$sessionEmail]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$admin) {
                Response::error('Super Admin account not found.', 404);
                return;
            }
            if (!password_verify($currentPassword, (string) $admin['password'])) {
                ActivityLogger::log((string) $sessionEmail, 'PROFILE_UPDATE_FAILED', 'SECURITY', 'Incorrect current password entered.');
                Response::error('Current password is incorrect.', 401);
                return;
            }

            if ($wantsEmailChange) {
                $stmt = $pdo->prepare('SELECT id FROM super_admin WHERE email = ? AND id <> ? LIMIT 1');
                $stmt->execute([$nextEmail, $admin['id']]);
                if ($stmt->fetch()) {
                    Response::error('This email is already used by another Super Admin account.', 409);
                    return;
                }
                $stmt = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
                $stmt->execute([$nextEmail]);
                if ($stmt->fetch()) {
                    Response::error('This email is already used by a Customer account.', 409);
                    return;
                }
                $stmt = $pdo->prepare('SELECT id FROM do_operators WHERE email = ? LIMIT 1');
                $stmt->execute([$nextEmail]);
                if ($stmt->fetch()) {
                    Response::error('This email is already used by a Data Operator account.', 409);
                    return;
                }
            }

            $hashed = null;
            if ($wantsPasswordChange) {
                if (password_verify((string) $newPassword, (string) $admin['password'])) {
                    Response::error('New password must be different from current password.', 400);
                    return;
                }
                $hashed = password_hash((string) $newPassword, PASSWORD_BCRYPT);
            }

            $finalEmail = $wantsEmailChange ? $nextEmail : $admin['email'];
            if ($wantsEmailChange && $wantsPasswordChange) {
                $stmt = $pdo->prepare('UPDATE super_admin SET email = ?, password = ? WHERE id = ?');
                $stmt->execute([$finalEmail, $hashed, $admin['id']]);
            } elseif ($wantsEmailChange) {
                $stmt = $pdo->prepare('UPDATE super_admin SET email = ? WHERE id = ?');
                $stmt->execute([$finalEmail, $admin['id']]);
            } else {
                $stmt = $pdo->prepare('UPDATE super_admin SET password = ? WHERE id = ?');
                $stmt->execute([$hashed, $admin['id']]);
            }

            $tokenPayload = [
                'id' => (int) $admin['id'],
                'email' => $finalEmail,
                'role' => 'super_admin',
                'full_name' => $admin['full_name'] ?? ($req->user['full_name'] ?? null),
                'phone_no' => $admin['phone_no'] ?? ($req->user['phone_no'] ?? null),
                'warehouse_name' => $req->user['warehouse_name'] ?? null,
                'allowed_clients' => $req->user['allowed_clients'] ?? null,
                'allowed_warehouses' => $req->user['allowed_warehouses'] ?? null,
            ];
            $token = JwtService::sign($tokenPayload, self::TOKEN_TTL);
            Response::setCookieToken($token, self::TOKEN_TTL * 1000);

            $changedParts = [];
            if ($wantsEmailChange) {
                $changedParts[] = 'email';
            }
            if ($wantsPasswordChange) {
                $changedParts[] = 'password';
            }
            ActivityLogger::log(
                $finalEmail,
                'PROFILE_UPDATED',
                'SECURITY',
                'Super Admin updated ' . implode(' and ', $changedParts) . ' from profile window' .
                ($wantsEmailChange ? " (from {$sessionEmail})" : '') . '.'
            );

            $message = ($wantsEmailChange && $wantsPasswordChange)
                ? 'Email and password updated successfully.'
                : ($wantsEmailChange ? 'Email updated successfully.' : 'Password updated successfully.');

            Response::json([
                'success' => true,
                'message' => $message,
                'user' => $tokenPayload,
                'token' => $token,
            ]);
        } catch (\Throwable $e) {
            self::serverError($e, 'Server error while updating profile.');
        }
    }

    public static function registerPushToken(Request $req, array $params = []): void
    {
        try {
            $role = $req->user['role'] ?? '';
            if ($role !== 'sub_admin' && $role !== 'do_operator') {
                Response::error('Only Sub-Admin or DO accounts can register a push token.', 403);
                return;
            }

            $body = $req->body();
            $token = trim((string) ($body['expo_push_token'] ?? $body['token'] ?? ''));
            if ($token === '') {
                Response::error('expo_push_token is required.', 400);
                return;
            }
            if (
                !str_starts_with($token, 'ExponentPushToken[') &&
                !str_starts_with($token, 'ExpoPushToken[')
            ) {
                Response::error('Invalid Expo push token format.', 400);
                return;
            }

            $email = (string) $req->user['email'];
            $pdo = Database::pdo();

            try {
                $stmt = $pdo->prepare(
                    'UPDATE sub_admins SET expo_push_token = NULL, updated_at = NOW()
                     WHERE expo_push_token = ? AND email <> ?'
                );
                $stmt->execute([$token, $email]);
            } catch (\Throwable) {
            }
            try {
                $stmt = $pdo->prepare(
                    'UPDATE do_operators SET expo_push_token = NULL
                     WHERE expo_push_token = ? AND email <> ?'
                );
                $stmt->execute([$token, $email]);
            } catch (\Throwable) {
            }

            if ($role === 'sub_admin') {
                $stmt = $pdo->prepare(
                    'UPDATE sub_admins SET expo_push_token = ?, updated_at = NOW() WHERE email = ? LIMIT 1'
                );
                $stmt->execute([$token, $email]);
            } else {
                try {
                    $stmt = $pdo->prepare(
                        'UPDATE do_operators SET expo_push_token = ?, updated_at = NOW() WHERE email = ? LIMIT 1'
                    );
                    $stmt->execute([$token, $email]);
                } catch (\Throwable $err) {
                    if (stripos($err->getMessage(), "Unknown column 'updated_at'") !== false) {
                        $stmt = $pdo->prepare(
                            'UPDATE do_operators SET expo_push_token = ? WHERE email = ? LIMIT 1'
                        );
                        $stmt->execute([$token, $email]);
                    } else {
                        throw $err;
                    }
                }
            }

            Response::json(['success' => true, 'message' => 'Push token saved.', 'role' => $role]);
        } catch (\Throwable $e) {
            self::serverError($e, 'Failed to save push token.');
        }
    }

    public static function clearPushToken(Request $req, array $params = []): void
    {
        try {
            $role = $req->user['role'] ?? '';
            if ($role !== 'sub_admin' && $role !== 'do_operator') {
                Response::json(['success' => true]);
                return;
            }
            $email = (string) $req->user['email'];
            $pdo = Database::pdo();
            if ($role === 'sub_admin') {
                $stmt = $pdo->prepare(
                    'UPDATE sub_admins SET expo_push_token = NULL, updated_at = NOW() WHERE email = ? LIMIT 1'
                );
                $stmt->execute([$email]);
            } else {
                try {
                    $stmt = $pdo->prepare(
                        'UPDATE do_operators SET expo_push_token = NULL, updated_at = NOW() WHERE email = ? LIMIT 1'
                    );
                    $stmt->execute([$email]);
                } catch (\Throwable $err) {
                    if (stripos($err->getMessage(), "Unknown column 'updated_at'") !== false) {
                        $stmt = $pdo->prepare(
                            'UPDATE do_operators SET expo_push_token = NULL WHERE email = ? LIMIT 1'
                        );
                        $stmt->execute([$email]);
                    } else {
                        throw $err;
                    }
                }
            }
            Response::json(['success' => true]);
        } catch (\Throwable $e) {
            self::serverError($e, 'Failed to clear push token.');
        }
    }

    /** @param array<string, mixed> $user */
    private static function buildPayload(array $user, string $role): array
    {
        return [
            'id' => (int) ($user['id'] ?? 0),
            'email' => $user['email'],
            'role' => $role,
            'full_name' => $user['full_name'] ?? $user['fullName'] ?? $user['username'] ?? $user['name'] ?? null,
            'phone_no' => $user['phone_no'] ?? $user['phone'] ?? null,
            'warehouse_name' => $user['warehouse_name'] ?? $user['warehouse'] ?? null,
            'warehouse_code' => $user['warehouse_code'] ?? null,
            'allowed_clients' => $user['allowed_clients'] ?? null,
            'allowed_warehouses' => $user['allowed_warehouses'] ?? null,
            'chamber_limit' => $user['chamber_limit'] ?? 4,
        ];
    }

    /** @param array<string, mixed> $lockInfo */
    private static function lockedResponse(array $lockInfo): void
    {
        $mins = $lockInfo['minutesLeft'] ?? 30;
        Response::json([
            'success' => false,
            'locked' => true,
            'minutesLeft' => $mins,
            'lockedUntil' => $lockInfo['lockedUntil'] ?? null,
            'message' => "Too many failed login attempts. This account is locked for {$mins} minute(s). Please try again later.",
        ], 429);
    }

    private static function serverError(\Throwable $e, string $clientMessage): void
    {
        error_log('[auth] ' . $e->getMessage());
        Response::json([
            'success' => false,
            'message' => $clientMessage,
            'error' => $e->getMessage(),
        ], 500);
    }
}
