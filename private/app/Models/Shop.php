<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class Shop extends Model
{
    protected string $table = 'shops';

    public function getApproved(int $page, int $perPage, ?int $categoryId = null, ?string $city = null): array
    {
        $where = 'is_active = 1 AND is_approved = 1';
        $params = [];

        if ($categoryId) {
            $where .= ' AND category_id = :cat';
            $params['cat'] = $categoryId;
        }
        if ($city) {
            $where .= ' AND city = :city';
            $params['city'] = $city;
        }

        return $this->paginate($page, $perPage, $where, $params, 'name ASC');
    }

    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, sc.name AS category_name, c.dial_code
            FROM {$this->table} s
            LEFT JOIN shop_categories sc ON sc.id = s.category_id
            LEFT JOIN countries c ON c.id = s.country_id
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getByOwner(int $ownerId): array
    {
        return $this->where('owner_id', $ownerId);
    }

    public function getPopular(int $limit = 10, ?int $categoryId = null, ?string $city = null): array
    {
        $where = 's.is_active = 1 AND s.is_approved = 1';
        $params = [];

        if ($categoryId) {
            $where .= ' AND s.category_id = :cat';
            $params['cat'] = $categoryId;
        }
        if ($city) {
            $where .= ' AND s.city = :city';
            $params['city'] = $city;
        }

        $sql = "
            SELECT s.id, s.name, s.address, s.city, s.latitude, s.longitude,
                   s.phone, s.logo, s.cover_photo,
                   sc.name AS category_name,
                   COUNT(o.id) AS total_orders,
                   COUNT(DISTINCT o.client_id) AS unique_clients,
                   COALESCE(AVG(r.score), 0) AS avg_rating,
                   COUNT(DISTINCT r.id) AS total_ratings
            FROM {$this->table} s
            LEFT JOIN orders o ON o.shop_id = s.id AND o.status != 'cancelled'
            LEFT JOIN shop_categories sc ON sc.id = s.category_id
            LEFT JOIN ratings r ON r.rated_user = s.owner_id
            WHERE {$where}
            GROUP BY s.id, s.name, s.address, s.city, s.latitude, s.longitude,
                     s.phone, s.logo, s.cover_photo, sc.name
            HAVING total_orders > 0
            ORDER BY total_orders DESC, avg_rating DESC
            LIMIT :lim
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
