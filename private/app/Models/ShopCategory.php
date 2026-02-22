<?php

namespace App\Models;

use App\Core\Model;

class ShopCategory extends Model
{
    protected string $table = 'shop_categories';

    public function getActive(): array
    {
        // Get all active categories
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->table} 
            WHERE is_active = 1 
            ORDER BY sort_order ASC, name ASC
        ");
        $stmt->execute();
        $all = $stmt->fetchAll();

        // Build hierarchy tree
        return $this->buildTree($all);
    }

    private function buildTree(array $elements, ?int $parentId = null): array
    {
        $branch = [];

        foreach ($elements as $element) {
            if ($element['parent_id'] == $parentId) {
                $children = $this->buildTree($elements, $element['id']);
                if ($children) {
                    $element['children'] = $children;
                }
                $branch[] = $element;
            }
        }

        return $branch;
    }
}
