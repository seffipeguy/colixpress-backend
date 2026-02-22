<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class Banner extends Model
{
    protected string $table = 'banners';

    public function getActive(?string $role = null, ?string $city = null): array
    {
        $now = date('Y-m-d H:i:s');

        $sql = "
            SELECT * FROM {$this->table}
            WHERE is_active = 1
              AND (valid_from IS NULL OR valid_from <= :now1)
              AND (valid_until IS NULL OR valid_until >= :now2)
            ORDER BY position ASC, created_at DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['now1' => $now, 'now2' => $now]);
        $banners = $stmt->fetchAll();

        // Filter by role and city in PHP for flexibility
        return array_values(array_filter($banners, function ($banner) use ($role, $city) {
            // Check role targeting
            if (!empty($banner['target_roles'])) {
                $roles = array_map('trim', explode(',', strtolower($banner['target_roles'])));
                if ($role && !in_array(strtolower($role), $roles)) {
                    return false;
                }
            }

            // Check city targeting
            if (!empty($banner['target_cities'])) {
                $cities = array_map('trim', explode(',', strtolower($banner['target_cities'])));
                if ($city && !in_array(strtolower($city), $cities)) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function reorder(array $orderedIds): void
    {
        $position = 0;
        foreach ($orderedIds as $id) {
            $this->update((int) $id, ['position' => $position]);
            $position++;
        }
    }
}
