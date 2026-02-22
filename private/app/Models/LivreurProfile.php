<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class LivreurProfile extends Model
{
    protected string $table = 'livreur_profiles';

    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT lp.*, c.name AS company_name, c.logo AS company_logo
            FROM {$this->table} lp
            LEFT JOIN companies c ON c.id = lp.company_id
            WHERE lp.user_id = :uid
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getAvailableNear(float $lat, float $lng, float $radiusKm = 5.0, int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT lp.*, u.first_name, u.last_name, u.phone,
                (6371 * ACOS(
                    COS(RADIANS(:lat)) * COS(RADIANS(current_lat))
                    * COS(RADIANS(current_lng) - RADIANS(:lng))
                    + SIN(RADIANS(:lat2)) * SIN(RADIANS(current_lat))
                )) AS distance_km
            FROM {$this->table} lp
            JOIN users u ON u.id = lp.user_id
            WHERE lp.is_available = 1 AND lp.is_approved = 1
              AND lp.current_lat IS NOT NULL AND lp.current_lng IS NOT NULL
            HAVING distance_km <= :radius
            ORDER BY distance_km ASC
            LIMIT :lim
        ");
        $stmt->bindValue('lat', $lat);
        $stmt->bindValue('lng', $lng);
        $stmt->bindValue('lat2', $lat);
        $stmt->bindValue('radius', $radiusKm);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function updateLocation(int $userId, float $lat, float $lng): void
    {
        $this->db->prepare("
            UPDATE {$this->table} SET current_lat = :lat, current_lng = :lng, last_location_at = NOW()
            WHERE user_id = :uid
        ")->execute(['lat' => $lat, 'lng' => $lng, 'uid' => $userId]);
    }
}
