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
}
