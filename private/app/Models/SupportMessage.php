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
                   u.profile_photo AS sender_photo
            FROM support_messages m
            JOIN users u ON u.id = m.sender_id
            WHERE m.ticket_id = :ticket_id
            ORDER BY m.created_at ASC
        ");
        $stmt->execute(['ticket_id' => $ticketId]);
        return $stmt->fetchAll();
    }
}
