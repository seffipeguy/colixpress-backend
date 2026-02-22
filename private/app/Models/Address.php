<?php

namespace App\Models;

use App\Core\Model;

class Address extends Model
{
    protected string $table = 'addresses';

    public function getByUser(int $userId): array
    {
        return $this->where('user_id', $userId);
    }

    public function setDefault(int $userId, int $addressId): void
    {
        // Unset all defaults
        $this->db->prepare("UPDATE {$this->table} SET is_default = 0 WHERE user_id = :uid")
            ->execute(['uid' => $userId]);
        // Set new default
        $this->db->prepare("UPDATE {$this->table} SET is_default = 1 WHERE id = :id AND user_id = :uid")
            ->execute(['id' => $addressId, 'uid' => $userId]);
    }
}
