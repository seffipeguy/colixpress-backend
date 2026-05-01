<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use PDO;

/**
 * CompanyWalletTransaction Model
 * Historique des mouvements sur les wallets entreprise
 */
class CompanyWalletTransaction extends Model
{
    protected string $table = 'company_wallet_transactions';

    /**
     * Récupère les transactions d'un wallet entreprise
     */
    public function getByCompanyWalletId(int $walletId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare("SELECT COUNT(*) as total FROM {$this->table} WHERE company_wallet_id = :wid");
        $countStmt->execute(['wid' => $walletId]);
        $total = (int) $countStmt->fetch()['total'];

        $stmt = $this->db->prepare("
            SELECT 
                cwt.*,
                o.reference as order_reference,
                u.first_name as dispatcher_first_name,
                u.last_name as dispatcher_last_name,
                u.phone as dispatcher_phone
            FROM {$this->table} cwt
            LEFT JOIN orders o ON o.id = cwt.order_id
            LEFT JOIN users u ON u.id = cwt.dispatcher_id
            WHERE cwt.company_wallet_id = :wid
            ORDER BY cwt.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue('wid', $walletId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }

    /**
     * Récupère les transactions par entreprise (via company_id)
     */
    public function getByCompanyId(int $companyId, int $page = 1, int $perPage = 20, ?string $type = null): array
    {
        $where = "WHERE cw.company_id = :cid";
        $params = ['cid' => $companyId];

        if ($type) {
            $where .= " AND cwt.type = :type";
            $params['type'] = $type;
        }

        $offset = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) as total FROM {$this->table} cwt 
                     JOIN company_wallets cw ON cw.id = cwt.company_wallet_id 
                     {$where}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        $sql = "
            SELECT 
                cwt.*,
                o.reference as order_reference,
                o.status as order_status,
                u.first_name as dispatcher_first_name,
                u.last_name as dispatcher_last_name
            FROM {$this->table} cwt
            JOIN company_wallets cw ON cw.id = cwt.company_wallet_id
            LEFT JOIN orders o ON o.id = cwt.order_id
            LEFT JOIN users u ON u.id = cwt.dispatcher_id
            {$where}
            ORDER BY cwt.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }

    /**
     * Récupère les transactions par commande
     */
    public function getByOrderId(int $orderId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                cwt.*,
                cw.company_id,
                c.name as company_name
            FROM {$this->table} cwt
            JOIN company_wallets cw ON cw.id = cwt.company_wallet_id
            JOIN companies c ON c.id = cw.company_id
            WHERE cwt.order_id = :oid
            ORDER BY cwt.created_at ASC
        ");
        $stmt->execute(['oid' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupère les transactions par dispatcher
     */
    public function getByDispatcherId(int $dispatcherId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare("SELECT COUNT(*) as total FROM {$this->table} WHERE dispatcher_id = :did");
        $countStmt->execute(['did' => $dispatcherId]);
        $total = (int) $countStmt->fetch()['total'];

        $stmt = $this->db->prepare("
            SELECT 
                cwt.*,
                o.reference as order_reference,
                cw.company_id,
                c.name as company_name
            FROM {$this->table} cwt
            LEFT JOIN orders o ON o.id = cwt.order_id
            JOIN company_wallets cw ON cw.id = cwt.company_wallet_id
            JOIN companies c ON c.id = cw.company_id
            WHERE cwt.dispatcher_id = :did
            ORDER BY cwt.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue('did', $dispatcherId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }

    /**
     * Récupère une transaction par son ID avec détails
     */
    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                cwt.*,
                cw.company_id,
                c.name as company_name,
                c.email as company_email,
                o.reference as order_reference,
                o.status as order_status,
                u.first_name as dispatcher_first_name,
                u.last_name as dispatcher_last_name,
                u.phone as dispatcher_phone
            FROM {$this->table} cwt
            JOIN company_wallets cw ON cw.id = cwt.company_wallet_id
            JOIN companies c ON c.id = cw.company_id
            LEFT JOIN orders o ON o.id = cwt.order_id
            LEFT JOIN users u ON u.id = cwt.dispatcher_id
            WHERE cwt.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Calcule les stats pour une entreprise (sommes par type)
     */
    public function getStatsByCompany(int $companyId, ?string $startDate = null, ?string $endDate = null): array
    {
        $where = "WHERE cw.company_id = :cid";
        $params = ['cid' => $companyId];

        if ($startDate) {
            $where .= " AND cwt.created_at >= :start";
            $params['start'] = $startDate;
        }
        if ($endDate) {
            $where .= " AND cwt.created_at <= :end";
            $params['end'] = $endDate;
        }

        $stmt = $this->db->prepare("
            SELECT 
                cwt.type,
                COUNT(*) as count,
                SUM(cwt.amount) as total_amount,
                SUM(ABS(cwt.amount)) as absolute_amount
            FROM {$this->table} cwt
            JOIN company_wallets cw ON cw.id = cwt.company_wallet_id
            {$where}
            GROUP BY cwt.type
        ");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupère les transactions en attente de paiement (pour rechargements/withdrawals)
     */
    public function getPendingPayments(int $walletId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->table}
            WHERE company_wallet_id = :wid 
            AND payment_status = 'pending'
            ORDER BY created_at DESC
        ");
        $stmt->execute(['wid' => $walletId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Met à jour le statut de paiement d'une transaction
     */
    public function updatePaymentStatus(int $transactionId, string $status, ?string $reference = null): bool
    {
        $sql = "UPDATE {$this->table} SET payment_status = :status";
        $params = ['status' => $status, 'id' => $transactionId];

        if ($reference !== null) {
            $sql .= ", reference = :ref";
            $params['ref'] = $reference;
        }

        $sql .= ", updated_at = NOW() WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Liste globale des transactions (pour admin)
     */
    public function listAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = [];
        $params = [];

        if (isset($filters['type'])) {
            $where[] = "cwt.type = :type";
            $params['type'] = $filters['type'];
        }
        if (isset($filters['company_id'])) {
            $where[] = "cw.company_id = :cid";
            $params['cid'] = $filters['company_id'];
        }
        if (isset($filters['start_date'])) {
            $where[] = "cwt.created_at >= :start";
            $params['start'] = $filters['start_date'];
        }
        if (isset($filters['end_date'])) {
            $where[] = "cwt.created_at <= :end";
            $params['end'] = $filters['end_date'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) as total FROM {$this->table} cwt 
                     JOIN company_wallets cw ON cw.id = cwt.company_wallet_id 
                     {$whereSql}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        $sql = "
            SELECT 
                cwt.*,
                cw.company_id,
                c.name as company_name,
                o.reference as order_reference
            FROM {$this->table} cwt
            JOIN company_wallets cw ON cw.id = cwt.company_wallet_id
            JOIN companies c ON c.id = cw.company_id
            LEFT JOIN orders o ON o.id = cwt.order_id
            {$whereSql}
            ORDER BY cwt.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }
}
