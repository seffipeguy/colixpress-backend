<?php

namespace App\Models;

use App\Core\Model;

class ShopTag extends Model
{
    protected string $table = 'shop_tags';

    public function getAll(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} ORDER BY name ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function search(string $query): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE name LIKE :q ORDER BY name ASC LIMIT 20");
        $stmt->execute(['q' => "%$query%"]);
        return $stmt->fetchAll();
    }
}
