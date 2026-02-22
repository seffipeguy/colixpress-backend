<?php

namespace App\Models;

use App\Core\Model;

class OrderStatusHistory extends Model
{
    protected string $table = 'order_status_history';

    public function getByOrder(int $orderId): array
    {
        $stmt = $this->db->prepare("
            SELECT osh.*, u.first_name, u.last_name
            FROM {$this->table} osh
            LEFT JOIN users u ON u.id = osh.changed_by
            WHERE osh.order_id = :oid
            ORDER BY osh.created_at ASC
        ");
        $stmt->execute(['oid' => $orderId]);
        return $stmt->fetchAll();
    }
}
