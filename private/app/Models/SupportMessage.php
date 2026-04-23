<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class SupportMessage extends Model
{
    protected string $table = 'support_messages';

    public function getByTicket(int $ticketId): array
    {
        $stmt = $this->db->prepare("
            SELECT m.*,
                   u.first_name AS sender_first_name, u.last_name AS sender_last_name,
                   u.profile_photo AS sender_photo,
                   sm.file_url    AS media_url,
                   sm.file_type   AS media_type,
                   sm.file_name   AS media_file_name,
                   sm.mime_type   AS media_mime_type
            FROM support_messages m
            JOIN users u ON u.id = m.sender_id
            LEFT JOIN media_uploads sm ON sm.id = m.media_id
            WHERE m.ticket_id = :ticket_id
            ORDER BY m.created_at ASC
        ");
        $stmt->execute(['ticket_id' => $ticketId]);
        return $stmt->fetchAll();
    }
}
