<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class PushSubscription extends Model
{
    protected string $table = 'push_subscriptions';

    public function getByUser(int $userId): array
    {
        return $this->where('user_id', $userId);
    }

    public function findByEndpoint(int $userId, string $p256dh): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM push_subscriptions WHERE user_id = :uid AND p256dh = :p256dh LIMIT 1"
        );
        $stmt->execute(['uid' => $userId, 'p256dh' => $p256dh]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function deleteByEndpoint(int $userId, string $endpoint): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM push_subscriptions WHERE user_id = :uid AND endpoint = :endpoint"
        );
        return $stmt->execute(['uid' => $userId, 'endpoint' => $endpoint]);
    }

    public function getAll(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM push_subscriptions");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
