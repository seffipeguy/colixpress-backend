<?php

namespace App\Models;

use App\Core\Model;

class PricingRule extends Model
{
    protected string $table = 'pricing_rules';

    public function getForCity(string $city = 'Douala'): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE city = :city AND is_active = 1 LIMIT 1");
        $stmt->execute(['city' => $city]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getAllActive(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY city ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
