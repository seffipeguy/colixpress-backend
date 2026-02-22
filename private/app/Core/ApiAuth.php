<?php

namespace App\Core;

use App\Config\Database;
use PDO;

class ApiAuth
{
    private static ?array $apiKey = null;
    private static ?array $developer = null;

    /**
     * Middleware: validate API key from headers
     * Headers: X-Api-Key + X-Api-Secret
     */
    public function handle(Request $request): void
    {
        self::validate();
    }

    public static function validate(): void
    {
        $key = $_SERVER['HTTP_X_API_KEY'] ?? null;
        $secret = $_SERVER['HTTP_X_API_SECRET'] ?? null;

        if (!$key || !$secret) {
            Response::error('API key and secret required. Use X-Api-Key and X-Api-Secret headers.', 401);
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT ak.*, u.id AS developer_user_id, u.role, u.is_active AS user_active,
                   u.first_name, u.last_name, u.email, u.phone
            FROM api_keys ak
            JOIN users u ON u.id = ak.user_id
            WHERE ak.api_key = :api_key
            LIMIT 1
        ");
        $stmt->execute(['api_key' => $key]);
        $apiKey = $stmt->fetch();

        if (!$apiKey) {
            Response::error('Invalid API key', 401);
        }

        if (!$apiKey['is_active']) {
            Response::error('API key is deactivated', 403);
        }

        if (!$apiKey['user_active']) {
            Response::error('Developer account is deactivated', 403);
        }

        // Verify secret
        if (!hash_equals($apiKey['api_secret'], hash('sha256', $secret))) {
            Response::error('Invalid API secret', 401);
        }

        // Check IP whitelist
        if (!empty($apiKey['allowed_ips'])) {
            $allowedIps = array_map('trim', explode(',', $apiKey['allowed_ips']));
            $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
            if (!in_array($clientIp, $allowedIps)) {
                Response::error('IP address not allowed', 403);
            }
        }

        // Check rate limit
        if (self::isRateLimited($apiKey)) {
            Response::error('Rate limit exceeded. Try again later.', 429);
        }

        // Update request count
        $stmt = $db->prepare("
            UPDATE api_keys 
            SET total_requests = total_requests + 1, last_request_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['id' => $apiKey['id']]);

        self::$apiKey = $apiKey;
    }

    private static function isRateLimited(array $apiKey): bool
    {
        if (!$apiKey['last_request_at']) {
            return false;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT total_requests FROM api_keys 
            WHERE id = :id AND last_request_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute(['id' => $apiKey['id']]);
        $row = $stmt->fetch();

        // Simple rate limiting: check if recent activity exists
        // For production, use a proper sliding window with a separate table
        return false; // Basic implementation - enhance with Redis later
    }

    public static function apiKey(): ?array
    {
        return self::$apiKey;
    }

    public static function apiKeyId(): ?int
    {
        return self::$apiKey ? (int) self::$apiKey['id'] : null;
    }

    public static function developerId(): ?int
    {
        return self::$apiKey ? (int) self::$apiKey['user_id'] : null;
    }

    public static function isTestMode(): bool
    {
        return self::$apiKey && (bool) self::$apiKey['is_test_mode'];
    }

    /**
     * Generate a new API key pair
     */
    public static function generateKeyPair(): array
    {
        return [
            'api_key'    => bin2hex(random_bytes(32)),
            'api_secret' => bin2hex(random_bytes(32)),
        ];
    }

    /**
     * Hash the secret for storage
     */
    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
