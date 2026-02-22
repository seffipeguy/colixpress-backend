<?php

namespace App\Models;

use App\Core\Model;

class User extends Model
{
    protected string $table = 'users';

    public function findByPhone(int $countryId, string $phone): ?array
    {
        $stmt = $this->db->prepare("
            SELECT u.*, c.dial_code, c.iso_code AS country_code
            FROM {$this->table} u
            JOIN countries c ON c.id = u.country_id
            WHERE u.country_id = :country_id AND u.phone = :phone
            LIMIT 1
        ");
        $stmt->execute(['country_id' => $countryId, 'phone' => $phone]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findWithCountry(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT u.*, c.dial_code, c.iso_code AS country_code, c.name AS country_name
            FROM {$this->table} u
            JOIN countries c ON c.id = u.country_id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function profile(int $id): ?array
    {
        $user = $this->findWithCountry($id);
        if ($user) {
            unset($user['password'], $user['is_active']);
            return $user;
        }
        return null;
    }
}
