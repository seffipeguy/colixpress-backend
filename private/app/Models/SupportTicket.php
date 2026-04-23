<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class SupportTicket extends Model
{
    protected string $table = 'support_tickets';

    public function generateReference(): string
    {
        do {
            $ref = 'TKT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $stmt = $this->db->prepare("SELECT id FROM support_tickets WHERE reference = :ref LIMIT 1");
            $stmt->execute(['ref' => $ref]);
        } while ($stmt->fetch());

        return $ref;
    }

    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT t.*,
                   u.first_name AS client_first_name, u.last_name AS client_last_name, u.phone AS client_phone,
                   a.first_name AS agent_first_name, a.last_name AS agent_last_name
            FROM support_tickets t
            JOIN users u ON u.id = t.created_by
            LEFT JOIN users a ON a.id = t.assigned_to
            WHERE t.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findByReference(string $reference): ?array
    {
        $stmt = $this->db->prepare("
            SELECT t.*,
                   u.first_name AS client_first_name, u.last_name AS client_last_name, u.phone AS client_phone,
                   a.first_name AS agent_first_name, a.last_name AS agent_last_name
            FROM support_tickets t
            JOIN users u ON u.id = t.created_by
            LEFT JOIN users a ON a.id = t.assigned_to
            WHERE t.reference = :ref
            LIMIT 1
        ");
        $stmt->execute(['ref' => $reference]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getByUser(int $userId, int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        $where = 'created_by = :user_id';
        $params = ['user_id' => $userId];
        if ($status) {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }
        return $this->paginate($page, $perPage, $where, $params, 'created_at DESC');
    }

    public function getAll(int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        $offset = ($page - 1) * $perPage;
        $where = $status ? "WHERE t.status = :status" : "";
        $params = $status ? ['status' => $status] : [];

        $countStmt = $this->db->prepare("SELECT COUNT(*) as total FROM support_tickets t $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        $stmt = $this->db->prepare("
            SELECT t.*,
                   u.first_name AS client_first_name, u.last_name AS client_last_name, u.phone AS client_phone,
                   a.first_name AS agent_first_name, a.last_name AS agent_last_name
            FROM support_tickets t
            JOIN users u ON u.id = t.created_by
            LEFT JOIN users a ON a.id = t.assigned_to
            $where
            ORDER BY t.updated_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['data' => $stmt->fetchAll(), 'total' => $total];
    }
}
