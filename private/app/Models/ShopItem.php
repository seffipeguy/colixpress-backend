<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class ShopItem extends Model
{
    protected string $table = 'shop_items';

    public function getByShop(int $shopId, bool $onlyAvailable = true): array
    {
        $where = 'shop_id = :sid';
        $params = ['sid' => $shopId];

        if ($onlyAvailable) {
            $where .= ' AND is_available = 1';
        }

        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$where} ORDER BY sort_order ASC, name ASC");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
