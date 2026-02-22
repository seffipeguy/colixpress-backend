<?php

namespace App\Models;

use App\Core\Model;

class Rating extends Model
{
    protected string $table = 'ratings';

    public function findByOrderAndUser(int $orderId, int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE order_id = :oid AND rated_by = :uid LIMIT 1");
        $stmt->execute(['oid' => $orderId, 'uid' => $userId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getForUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, u.first_name, u.last_name
            FROM {$this->table} r
            JOIN users u ON u.id = r.rated_by
            WHERE r.rated_user = :uid
            ORDER BY r.created_at DESC
        ");
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    public function recalculateAverage(int $userId): void
    {
        $stmt = $this->db->prepare("SELECT AVG(score) AS avg_score FROM {$this->table} WHERE rated_user = :uid");
        $stmt->execute(['uid' => $userId]);
        $result = $stmt->fetch();
        $avg = round((float) ($result['avg_score'] ?? 0), 2);

        // Update livreur_profiles if exists
        $this->db->prepare("UPDATE livreur_profiles SET rating_avg = :avg WHERE user_id = :uid")
            ->execute(['avg' => $avg, 'uid' => $userId]);

        // Update shops if exists
        $this->db->prepare("UPDATE shops SET rating_avg = :avg WHERE owner_id = :uid")
            ->execute(['avg' => $avg, 'uid' => $userId]);
    }
}
