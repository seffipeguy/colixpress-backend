<?php

namespace App\Models;

use App\Core\Model;
use PDO;

class PaymentTransaction extends Model
{
    protected string $table = 'payment_transactions';

    /**
     * Générer une référence unique
     */
    public function generateReference(): string
    {
        return 'TX-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -8));
    }

    /**
     * Créer une transaction
     */
    public function createTransaction(array $data): int
    {
        if (!isset($data['reference'])) {
            $data['reference'] = $this->generateReference();
        }

        return $this->create($data);
    }

    /**
     * Trouver par référence
     */
    public function findByReference(string $reference): ?array
    {
        $stmt = $this->db->prepare("
            SELECT pt.*, pp.name as provider_name, pp.code as provider_code
            FROM {$this->table} pt
            JOIN payment_providers pp ON pp.id = pt.provider_id
            WHERE pt.reference = :reference
            LIMIT 1
        ");
        $stmt->execute(['reference' => $reference]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Trouver par ID de transaction du provider
     */
    public function findByProviderTransactionId(string $providerTxId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->table}
            WHERE provider_transaction_id = :tx_id
            LIMIT 1
        ");
        $stmt->execute(['tx_id' => $providerTxId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Récupérer les transactions d'une commande
     */
    public function getByOrder(int $orderId): array
    {
        $stmt = $this->db->prepare("
            SELECT pt.*, pp.name as provider_name, pp.code as provider_code
            FROM {$this->table} pt
            JOIN payment_providers pp ON pp.id = pt.provider_id
            WHERE pt.order_id = :order_id
            ORDER BY pt.created_at DESC
        ");
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    /**
     * Récupérer les transactions d'un client (par téléphone)
     */
    public function getByCustomerPhone(string $phone, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT pt.*, pp.name as provider_name, pp.code as provider_code
            FROM {$this->table} pt
            JOIN payment_providers pp ON pp.id = pt.provider_id
            WHERE pt.customer_phone = :phone
            ORDER BY pt.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue('phone', $phone);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Mettre à jour le statut
     */
    public function updateStatus(int $id, string $status, ?string $errorCode = null, ?string $errorMessage = null): bool
    {
        $data = ['status' => $status];

        if ($status === 'completed') {
            $data['completed_at'] = date('Y-m-d H:i:s');
        } elseif ($status === 'failed') {
            $data['failed_at'] = date('Y-m-d H:i:s');
            if ($errorCode) {
                $data['error_code'] = $errorCode;
            }
            if ($errorMessage) {
                $data['error_message'] = $errorMessage;
            }
        } elseif ($status === 'refunded') {
            $data['refunded_at'] = date('Y-m-d H:i:s');
        }

        return $this->update($id, $data);
    }

    /**
     * Marquer comme complété
     */
    public function markAsCompleted(int $id, ?string $providerTxId = null): bool
    {
        $data = [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ];

        if ($providerTxId) {
            $data['provider_transaction_id'] = $providerTxId;
        }

        return $this->update($id, $data);
    }

    /**
     * Marquer comme échoué
     */
    public function markAsFailed(int $id, ?string $errorCode = null, ?string $errorMessage = null): bool
    {
        $data = [
            'status' => 'failed',
            'failed_at' => date('Y-m-d H:i:s'),
        ];

        if ($errorCode) {
            $data['error_code'] = $errorCode;
        }
        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }

        return $this->update($id, $data);
    }

    /**
     * Enregistrer la réception du webhook
     */
    public function recordWebhook(int $id, array $webhookData): bool
    {
        return $this->update($id, [
            'webhook_received_at' => date('Y-m-d H:i:s'),
            'webhook_data' => json_encode($webhookData, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Incrémenter le compteur de retry
     */
    public function incrementRetry(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE {$this->table}
            SET retry_count = retry_count + 1
            WHERE id = :id
        ");
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Statistiques des transactions
     */
    public function getStats(?int $providerId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $where = [];
        $params = [];

        if ($providerId) {
            $where[] = 'provider_id = :provider_id';
            $params['provider_id'] = $providerId;
        }

        if ($startDate) {
            $where[] = 'created_at >= :start_date';
            $params['start_date'] = $startDate;
        }

        if ($endDate) {
            $where[] = 'created_at <= :end_date';
            $params['end_date'] = $endDate;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_amount,
                SUM(CASE WHEN status = 'completed' THEN fee_amount ELSE 0 END) as total_fees,
                AVG(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as avg_amount
            FROM {$this->table}
            {$whereClause}
        ");
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
    }

    /**
     * Transactions récentes
     */
    public function getRecent(int $limit = 20, ?int $providerId = null): array
    {
        $where = $providerId ? 'WHERE pt.provider_id = :provider_id' : '';
        
        $stmt = $this->db->prepare("
            SELECT pt.*, pp.name as provider_name, pp.code as provider_code,
                   o.reference as order_reference
            FROM {$this->table} pt
            JOIN payment_providers pp ON pp.id = pt.provider_id
            LEFT JOIN orders o ON o.id = pt.order_id
            {$where}
            ORDER BY pt.created_at DESC
            LIMIT :limit
        ");
        
        if ($providerId) {
            $stmt->bindValue('provider_id', $providerId, PDO::PARAM_INT);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}
