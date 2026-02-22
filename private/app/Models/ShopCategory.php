<?php

namespace App\Models;

use App\Core\Model;

class ShopCategory extends Model
{
    protected string $table = 'shop_categories';

    public function getActive(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY sort_order ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
