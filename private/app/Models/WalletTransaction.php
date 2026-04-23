<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class WalletTransaction extends Model
{
    protected string $table = 'wallet_transactions';

    public function getByWallet(int $walletId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare("SELECT COUNT(*) as total FROM wallet_transactions WHERE wallet_id = :wallet_id");
        $countStmt->execute(['wallet_id' => $walletId]);
        $total = (int) $countStmt->fetch()['total'];

        $stmt = $this->db->prepare("
            SELECT wt.*, u.first_name AS performed_by_first_name, u.last_name AS performed_by_last_name
            FROM wallet_transactions wt
            LEFT JOIN users u ON u.id = wt.performed_by
            WHERE wt.wallet_id = :wallet_id
            ORDER BY wt.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue('wallet_id', $walletId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data'  => $stmt->fetchAll(),
            'total' => $total,
        ];
    }
}
