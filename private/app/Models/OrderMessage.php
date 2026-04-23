<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class OrderMessage extends Model
{
    protected string $table = 'order_messages';

    public function getByOrder(int $orderId): array
    {
        $stmt = $this->db->prepare("
            SELECT om.*,
                   CONCAT(u.first_name, ' ', u.last_name) AS sender_name,
                   om_media.file_url  AS media_url,
                   om_media.file_type AS media_type,
                   om_media.file_name AS media_file_name,
                   om_media.mime_type AS media_mime_type
            FROM {$this->table} om
            JOIN users u ON u.id = om.sender_id
            LEFT JOIN media_uploads om_media ON om_media.id = om.media_id
            WHERE om.order_id = :oid
            ORDER BY om.created_at ASC
        ");
        $stmt->execute(['oid' => $orderId]);
        return $stmt->fetchAll();
    }

    public function send(int $orderId, int $senderId, string $role, string $message, ?int $mediaId = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (order_id, sender_id, sender_role, message, media_id)
            VALUES (:oid, :sid, :role, :msg, :mid)
        ");
        $stmt->execute([
            'oid'  => $orderId,
            'sid'  => $senderId,
            'role' => $role,
            'msg'  => $message,
            'mid'  => $mediaId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function markReadByClient(int $orderId): void
    {
        $this->db->prepare("
            UPDATE {$this->table} SET is_read_by_client = 1
            WHERE order_id = :oid AND is_read_by_client = 0
        ")->execute(['oid' => $orderId]);
    }

    public function markReadByStaff(int $orderId): void
    {
        $this->db->prepare("
            UPDATE {$this->table} SET is_read_by_staff = 1
            WHERE order_id = :oid AND is_read_by_staff = 0
        ")->execute(['oid' => $orderId]);
    }

    public function countUnreadForClient(int $orderId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM {$this->table}
            WHERE order_id = :oid AND is_read_by_client = 0 AND sender_role != 'client'
        ");
        $stmt->execute(['oid' => $orderId]);
        return (int) $stmt->fetchColumn();
    }

    public function countUnreadForStaff(int $orderId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM {$this->table}
            WHERE order_id = :oid AND is_read_by_staff = 0 AND sender_role = 'client'
        ");
        $stmt->execute(['oid' => $orderId]);
        return (int) $stmt->fetchColumn();
    }
}
