<?php

namespace App\Core;

use App\Config\Database;
use PDO;

class Auth
{
    private static ?array $currentUser = null;

    public function handle(Request $request): void
    {
        $token = $request->bearerToken();

        if (!$token) {
            Response::unauthorized('Token required');
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT u.*, t.expires_at AS token_expires_at
            FROM auth_tokens t
            JOIN users u ON u.id = t.user_id
            WHERE t.token = :token
            LIMIT 1
        ");
        $stmt->execute(['token' => $token]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::unauthorized('Invalid token');
        }

        if (strtotime($user['token_expires_at']) < time()) {
            // Clean up expired token
            $db->prepare("DELETE FROM auth_tokens WHERE token = :token")
               ->execute(['token' => $token]);
            Response::unauthorized('Token expired');
        }

        if (!$user['is_active']) {
            Response::forbidden('Account deactivated');
        }

        unset($user['token_expires_at']);
        self::$currentUser = $user;
    }

    public static function tryAuthenticate(): void
    {
        if (self::$currentUser) {
            return;
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(.+)$/i', $header, $matches)) {
            return;
        }

        $token = $matches[1];
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT u.* FROM auth_tokens t
            JOIN users u ON u.id = t.user_id
            WHERE t.token = :token AND t.expires_at > NOW() AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['token' => $token]);
        $user = $stmt->fetch();

        if ($user) {
            self::$currentUser = $user;
        }
    }

    public static function user(): ?array
    {
        return self::$currentUser;
    }

    public static function id(): ?int
    {
        return self::$currentUser ? (int) self::$currentUser['id'] : null;
    }

    public static function role(): ?string
    {
        if (self::$currentUser === null) {
            return null;
        }
        return self::$currentUser['role'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function isShopOwner(): bool
    {
        return self::role() === 'shop_owner';
    }

    public static function requireRole(string ...$roles): void
    {
        if (!in_array(self::role(), $roles)) {
            Response::forbidden('Access denied for your role');
        }
    }

    public static function generateToken(int $userId): string
    {
        $token = bin2hex(random_bytes(64));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . TOKEN_EXPIRY_HOURS . ' hours'));

        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO auth_tokens (user_id, token, expires_at)
            VALUES (:user_id, :token, :expires_at)
        ");
        $stmt->execute([
            'user_id'    => $userId,
            'token'      => $token,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    public static function revokeToken(string $token): void
    {
        $db = Database::getInstance();
        $db->prepare("DELETE FROM auth_tokens WHERE token = :token")
           ->execute(['token' => $token]);
    }

    public static function revokeAllTokens(int $userId): void
    {
        $db = Database::getInstance();
        $db->prepare("DELETE FROM auth_tokens WHERE user_id = :user_id")
           ->execute(['user_id' => $userId]);
    }
}
