<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class Shop extends Model
{
    protected string $table = 'shops';

    public function getApproved(int $page, int $perPage, ?int $categoryId = null, ?string $city = null, ?string $search = null): array
    {
        $where = 's.is_active = 1 AND s.is_approved = 1 AND s.is_featured = 1';
        $params = [];

        // Join with shop_category_map if category filter is applied
        $join = '';
        if ($categoryId) {
            $join = 'JOIN shop_category_map scm ON scm.shop_id = s.id';
            $where .= ' AND scm.category_id = :cat';
            $params['cat'] = $categoryId;
        }

        if ($city) {
            $where .= ' AND s.city = :city';
            $params['city'] = $city;
        }

        if ($search) {
            $where .= ' AND (s.name LIKE :search_name OR s.description LIKE :search_desc OR s.address LIKE :search_addr)';
            $params['search_name'] = '%' . $search . '%';
            $params['search_desc'] = '%' . $search . '%';
            $params['search_addr'] = '%' . $search . '%';
        }

        // Custom pagination query for joined tables
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT DISTINCT s.* 
                FROM {$this->table} s 
                {$join}
                WHERE {$where} 
                ORDER BY s.name ASC 
                LIMIT :limit OFFSET :offset";
        
        $countSql = "SELECT COUNT(DISTINCT s.id) 
                     FROM {$this->table} s 
                     {$join}
                     WHERE {$where}";

        // Get total
        $stmtCount = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmtCount->bindValue($key, $value);
        }
        $stmtCount->execute();
        $total = $stmtCount->fetchColumn();

        // Get data
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll();

        // Enrich with categories and tags
        foreach ($data as &$shop) {
            $shop['categories'] = $this->getCategoriesForShop($shop['id']);
            $shop['tags'] = $this->getTagsForShop($shop['id']);
            $shop['permissions'] = json_decode($shop['permissions'] ?? '[]', true);
        }

        return [
            'data' => $data,
            'total' => (int) $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => ceil($total / $perPage)
        ];
    }

    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, c.dial_code
            FROM {$this->table} s
            LEFT JOIN countries c ON c.id = s.country_id
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $shop = $stmt->fetch();

        if ($shop) {
            $shop['categories'] = $this->getCategoriesForShop($shop['id']);
            $shop['tags'] = $this->getTagsForShop($shop['id']);
            $shop['permissions'] = json_decode($shop['permissions'] ?? '[]', true);
        }

        return $shop ?: null;
    }

    public function getByOwner(int $ownerId): array
    {
        $shops = $this->where('owner_id', $ownerId);
        foreach ($shops as &$shop) {
            $shop['categories'] = $this->getCategoriesForShop($shop['id']);
            $shop['tags'] = $this->getTagsForShop($shop['id']);
            $shop['permissions'] = json_decode($shop['permissions'] ?? '[]', true);
        }
        return $shops;
    }

    public function getPopular(int $limit = 10, ?int $categoryId = null, ?string $city = null): array
    {
        $where = 's.is_active = 1 AND s.is_approved = 1';
        $params = [];
        $join = '';

        if ($categoryId) {
            $join = 'JOIN shop_category_map scm ON scm.shop_id = s.id';
            $where .= ' AND scm.category_id = :cat';
            $params['cat'] = $categoryId;
        }
        if ($city) {
            $where .= ' AND s.city = :city';
            $params['city'] = $city;
        }

        $sql = "
            SELECT s.id, s.name, s.short_description, s.website_url, s.address, s.city, s.latitude, s.longitude,
                   s.phone, s.logo, s.cover_photo,
                   COUNT(o.id) AS total_orders,
                   COUNT(DISTINCT o.client_id) AS unique_clients,
                   COALESCE(AVG(r.score), 0) AS avg_rating,
                   COUNT(DISTINCT r.id) AS total_ratings
            FROM {$this->table} s
            {$join}
            LEFT JOIN orders o ON o.shop_id = s.id AND o.status != 'cancelled'
            LEFT JOIN ratings r ON r.rated_user = s.owner_id
            WHERE {$where}
            GROUP BY s.id, s.name, s.short_description, s.website_url, s.address, s.city, s.latitude, s.longitude,
                     s.phone, s.logo, s.cover_photo
            ORDER BY total_orders DESC, avg_rating DESC
            LIMIT :lim
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $shops = $stmt->fetchAll();

        foreach ($shops as &$shop) {
            $shop['categories'] = $this->getCategoriesForShop($shop['id']);
            $shop['tags'] = $this->getTagsForShop($shop['id']);
        }

        return $shops;
    }

    public function getNearby(float $lat, float $lng, float $radiusKm, int $limit = 20, int $offset = 0, ?int $categoryId = null): array
    {
        $where = 's.is_active = 1 AND s.is_approved = 1 AND s.latitude IS NOT NULL AND s.longitude IS NOT NULL';
        $join = '';
        $params = [
            'lat1' => $lat,
            'lat2' => $lat,
            'lng' => $lng,
            'radius' => $radiusKm
        ];

        if ($categoryId) {
            $join = 'JOIN shop_category_map scm ON scm.shop_id = s.id';
            $where .= ' AND scm.category_id = :cat';
            $params['cat'] = $categoryId;
        }

        $sql = "SELECT DISTINCT s.*, 
                (
                    6371 * acos(
                        cos(radians(:lat1)) * cos(radians(s.latitude)) * cos(radians(s.longitude) - radians(:lng)) +
                        sin(radians(:lat2)) * sin(radians(s.latitude))
                    )
                ) AS distance_km
                FROM {$this->table} s
                {$join}
                WHERE {$where}
                HAVING distance_km <= :radius
                ORDER BY distance_km ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $shops = $stmt->fetchAll();

        foreach ($shops as &$shop) {
             $shop['categories'] = $this->getCategoriesForShop($shop['id']);
             $shop['tags'] = $this->getTagsForShop($shop['id']);
             $shop['permissions'] = json_decode($shop['permissions'] ?? '[]', true);
             $shop['distance_km'] = round((float) $shop['distance_km'], 2);
        }

        return $shops;
    }

    // --- Helpers for Many-to-Many ---

    public function getCategoriesForShop(int $shopId): array
    {
        $stmt = $this->db->prepare("
            SELECT sc.* 
            FROM shop_categories sc
            JOIN shop_category_map scm ON scm.category_id = sc.id
            WHERE scm.shop_id = :sid
            ORDER BY sc.name ASC
        ");
        $stmt->execute(['sid' => $shopId]);
        return $stmt->fetchAll();
    }

    public function getTagsForShop(int $shopId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.* 
            FROM shop_tags t
            JOIN shop_tag_map tm ON tm.tag_id = t.id
            WHERE tm.shop_id = :sid
            ORDER BY t.name ASC
        ");
        $stmt->execute(['sid' => $shopId]);
        return $stmt->fetchAll();
    }

    public function attachCategories(int $shopId, array $categoryIds): void
    {
        $this->db->prepare("DELETE FROM shop_category_map WHERE shop_id = ?")->execute([$shopId]);
        
        $stmt = $this->db->prepare("INSERT INTO shop_category_map (shop_id, category_id) VALUES (?, ?)");
        foreach ($categoryIds as $catId) {
            try {
                $stmt->execute([$shopId, $catId]);
            } catch (\PDOException $e) {
                // Ignore duplicates or invalid ids
            }
        }
    }

    public function attachTags(int $shopId, array $tagIds): void
    {
        $this->db->prepare("DELETE FROM shop_tag_map WHERE shop_id = ?")->execute([$shopId]);
        
        $stmt = $this->db->prepare("INSERT INTO shop_tag_map (shop_id, tag_id) VALUES (?, ?)");
        foreach ($tagIds as $tagId) {
            try {
                $stmt->execute([$shopId, $tagId]);
            } catch (\PDOException $e) {
                // Ignore
            }
        }
    }

    public function getAllShopUrls(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE is_active = 1 AND is_approved = 1 AND website_url IS NOT NULL AND website_url != ''");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function attachToCompany(int $shopId, int $companyId): bool
    {
        $stmt = $this->db->prepare("UPDATE {$this->table} SET company_id = :cid WHERE id = :sid");
        return $stmt->execute(['cid' => $companyId, 'sid' => $shopId]);
    }

    public function detachFromCompany(int $shopId, int $companyId): bool
    {
        $stmt = $this->db->prepare("UPDATE {$this->table} SET company_id = NULL WHERE id = :sid AND company_id = :cid");
        return $stmt->execute(['cid' => $companyId, 'sid' => $shopId]);
    }
}
