<?php

namespace App\Models;

use App\Core\Model;

class Company extends Model
{
    protected string $table = 'companies';

    public function findByOwner(int $ownerId): ?array
    {
        return $this->findBy('owner_id', $ownerId);
    }

    public function getLivreurs(int $companyId): array
    {
        $stmt = $this->db->prepare("
            SELECT lp.*, u.first_name, u.last_name, u.phone, u.email
            FROM livreur_profiles lp
            JOIN users u ON u.id = lp.user_id
            WHERE lp.company_id = :cid
        ");
        $stmt->execute(['cid' => $companyId]);
        return $stmt->fetchAll();
    }

    public function getShops(int $companyId): array
    {
        $stmt = $this->db->prepare("
            SELECT s.*
            FROM shops s
            WHERE s.company_id = :cid
        ");
        $stmt->execute(['cid' => $companyId]);
        return $stmt->fetchAll();
    }

    public function addLivreur(int $companyId, int $livreurId): bool
    {
        $stmt = $this->db->prepare("UPDATE livreur_profiles SET company_id = :cid WHERE user_id = :lid");
        return $stmt->execute(['cid' => $companyId, 'lid' => $livreurId]);
    }

    public function removeLivreur(int $companyId, int $livreurId): bool
    {
        $stmt = $this->db->prepare("UPDATE livreur_profiles SET company_id = NULL WHERE user_id = :lid AND company_id = :cid");
        return $stmt->execute(['cid' => $companyId, 'lid' => $livreurId]);
    }

    public function getMembers(int $companyId, string $role = null): array
    {
        $sql = "SELECT cu.*, u.first_name, u.last_name, u.email, u.phone
                FROM company_users cu
                JOIN users u ON u.id = cu.user_id
                WHERE cu.company_id = :cid AND cu.is_active = 1";
        $params = ['cid' => $companyId];
        if ($role) {
            $sql .= " AND cu.role = :role";
            $params['role'] = $role;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function addMember(int $companyId, int $userId, string $role = 'dispatcher'): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO company_users (company_id, user_id, role)
            VALUES (:cid, :uid, :role)
            ON DUPLICATE KEY UPDATE role = :role, is_active = 1
        ");
        return $stmt->execute(['cid' => $companyId, 'uid' => $userId, 'role' => $role]);
    }

    public function removeMember(int $companyId, int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE company_users SET is_active = 0 WHERE company_id = :cid AND user_id = :uid");
        return $stmt->execute(['cid' => $companyId, 'uid' => $userId]);
    }

    public function getUserCompany(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, cu.role
            FROM companies c
            JOIN company_users cu ON cu.company_id = c.id
            WHERE cu.user_id = :uid AND cu.is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetch() ?: null;
    }

    public function getAllLivreurs(): array
    {
        $stmt = $this->db->prepare("
            SELECT lp.*, u.first_name, u.last_name, u.phone, u.email,
                   c.name as company_name, c.id as company_id
            FROM livreur_profiles lp
            JOIN users u ON u.id = lp.user_id
            LEFT JOIN companies c ON c.id = lp.company_id
            WHERE lp.is_available = 1
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
