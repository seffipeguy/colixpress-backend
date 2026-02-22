<?php

namespace App\Models;

use App\Core\Model;
use App\Core\ApiAuth;

class ApiKey extends Model
{
    protected string $table = 'api_keys';

    public function getByUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, user_id, name, api_key, webhook_url, allowed_ips,
                   rate_limit_per_hour, is_active, is_test_mode,
                   total_requests, last_request_at, created_at, updated_at
            FROM {$this->table}
            WHERE user_id = :user_id
            ORDER BY created_at DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function findByKey(string $apiKey): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->table}
            WHERE api_key = :api_key
            LIMIT 1
        ");
        $stmt->execute(['api_key' => $apiKey]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function createKey(int $userId, string $name, ?string $webhookUrl = null, ?string $allowedIps = null): array
    {
        $keyPair = ApiAuth::generateKeyPair();

        $id = $this->create([
            'user_id'      => $userId,
            'name'         => $name,
            'api_key'      => $keyPair['api_key'],
            'api_secret'   => ApiAuth::hashSecret($keyPair['api_secret']),
            'webhook_url'  => $webhookUrl,
            'allowed_ips'  => $allowedIps,
        ]);

        return [
            'id'         => $id,
            'api_key'    => $keyPair['api_key'],
            'api_secret' => $keyPair['api_secret'], // Only returned once at creation
        ];
    }

    public function regenerateSecret(int $id): array
    {
        $secret = bin2hex(random_bytes(32));

        $this->update($id, [
            'api_secret' => ApiAuth::hashSecret($secret),
        ]);

        return ['api_secret' => $secret];
    }

    public function getStats(int $apiKeyId): array
    {
        // Orders count by status
        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) as count
            FROM orders
            WHERE api_key_id = :api_key_id
            GROUP BY status
        ");
        $stmt->execute(['api_key_id' => $apiKeyId]);
        $ordersByStatus = $stmt->fetchAll();

        // Total revenue
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total_orders,
                   SUM(price) as total_revenue,
                   AVG(price) as avg_price,
                   SUM(distance_km) as total_distance
            FROM orders
            WHERE api_key_id = :api_key_id AND status != 'cancelled'
        ");
        $stmt->execute(['api_key_id' => $apiKeyId]);
        $summary = $stmt->fetch();

        return [
            'total_orders'    => (int) ($summary['total_orders'] ?? 0),
            'total_revenue'   => (int) ($summary['total_revenue'] ?? 0),
            'avg_price'       => (int) ($summary['avg_price'] ?? 0),
            'total_distance'  => round((float) ($summary['total_distance'] ?? 0), 2),
            'orders_by_status'=> $ordersByStatus,
        ];
    }

    public function getOrdersByApiKey(int $apiKeyId, int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        $where = "o.api_key_id = :api_key_id";
        $params = ['api_key_id' => $apiKeyId];

        if ($status) {
            $where .= " AND o.status = :status";
            $params['status'] = $status;
        }

        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM orders o WHERE {$where}
        ");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT o.*, u.first_name AS client_first_name, u.last_name AS client_last_name
            FROM orders o
            LEFT JOIN users u ON u.id = o.client_id
            WHERE {$where}
            ORDER BY o.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        return ['data' => $data, 'total' => $total];
    }
}
