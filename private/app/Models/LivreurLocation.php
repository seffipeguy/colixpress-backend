<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class LivreurLocation extends Model
{
    protected string $table = 'livreur_locations';

    public function track(int $livreurId, float $lat, float $lng, ?int $orderId = null): int
    {
        return $this->create([
            'livreur_id' => $livreurId,
            'order_id'   => $orderId,
            'latitude'   => $lat,
            'longitude'  => $lng,
        ]);
    }

    public function getTrail(int $orderId, int $limit = 100): array
    {
        $stmt = $this->db->prepare("
            SELECT latitude, longitude, created_at
            FROM {$this->table}
            WHERE order_id = :oid
            ORDER BY created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue('oid', $orderId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getLastPosition(int $livreurId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT latitude, longitude, created_at
            FROM {$this->table}
            WHERE livreur_id = :lid
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute(['lid' => $livreurId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }
}
