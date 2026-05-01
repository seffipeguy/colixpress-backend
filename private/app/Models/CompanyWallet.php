<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use PDO;

/**
 * CompanyWallet Model
 * Gère les wallets entreprise avec système de crédit confiance
 */
class CompanyWallet extends Model
{
    protected string $table = 'company_wallets';

    /**
     * Récupère le wallet d'une entreprise
     */
    public function findByCompanyId(int $companyId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE company_id = :cid LIMIT 1");
        $stmt->execute(['cid' => $companyId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Crée un wallet pour une entreprise (ou retourne l'existant)
     */
    public function getOrCreate(int $companyId, array $defaults = []): array
    {
        $wallet = $this->findByCompanyId($companyId);
        if ($wallet) {
            return $wallet;
        }

        // Récupérer les valeurs par défaut des settings
        $creditLimit = $defaults['credit_limit'] ?? $this->getSetting('company_default_credit_limit', 100000);
        $lowThreshold = $defaults['low_threshold'] ?? $this->getSetting('company_default_low_threshold', 20000);

        $id = $this->create([
            'company_id' => $companyId,
            'balance' => 0,
            'currency' => 'XAF',
            'credit_limit' => (int) $creditLimit,
            'low_balance_threshold' => (int) $lowThreshold,
            'status' => 'active',
        ]);

        return $this->find($id);
    }

    /**
     * Vérifie si l'entreprise peut débiter un montant (crédit confiance)
     */
    public function canDebit(int $companyId, int $amount): bool
    {
        $wallet = $this->findByCompanyId($companyId);
        if (!$wallet || $wallet['status'] !== 'active') {
            return false;
        }

        // Solde après débit = balance - amount
        $balanceAfter = (int) $wallet['balance'] - $amount;
        $creditLimit = (int) $wallet['credit_limit'];

        // Autorisé si balance_after >= -credit_limit
        return $balanceAfter >= -$creditLimit;
    }

    /**
     * Récupère le solde disponible (incluant le crédit)
     */
    public function getAvailableBalance(int $companyId): ?int
    {
        $wallet = $this->findByCompanyId($companyId);
        if (!$wallet) {
            return null;
        }

        return (int) $wallet['balance'] + (int) $wallet['credit_limit'];
    }

    /**
     * Débite le wallet (commission sur commande cash)
     * Retourne le nouveau solde ou null si échec
     */
    public function debit(
        int $companyId,
        int $amount,
        ?int $orderId = null,
        ?int $dispatcherId = null,
        array $extra = []
    ): ?int {
        // Vérification blocage
        $blockIfOver = $this->getSetting('company_wallet_block_if_over_limit', '1');
        if ($blockIfOver === '1' && !$this->canDebit($companyId, $amount)) {
            return null; // Crédit dépassé
        }

        $this->db->beginTransaction();
        try {
            // Lock du wallet
            $stmt = $this->db->prepare("
                SELECT id, balance FROM {$this->table} 
                WHERE company_id = :cid AND status = 'active' 
                FOR UPDATE
            ");
            $stmt->execute(['cid' => $companyId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                $this->db->rollBack();
                return null;
            }

            $newBalance = (int) $wallet['balance'] - $amount;

            // Mise à jour solde
            $update = $this->db->prepare("
                UPDATE {$this->table} 
                SET balance = :bal, updated_at = NOW() 
                WHERE id = :id
            ");
            $update->execute(['bal' => $newBalance, 'id' => $wallet['id']]);

            // Création transaction
            $transactionModel = new CompanyWalletTransaction();
            $transactionModel->create([
                'company_wallet_id' => $wallet['id'],
                'order_id' => $orderId,
                'dispatcher_id' => $dispatcherId,
                'type' => $extra['type'] ?? 'cash_order_commission',
                'amount' => -$amount, // Négatif = débit
                'gross_amount' => $extra['gross_amount'] ?? null,
                'commission_amount' => $extra['commission_amount'] ?? $amount,
                'net_amount' => $extra['net_amount'] ?? null,
                'balance_after' => $newBalance,
                'description' => $extra['description'] ?? "Commission commande #{$orderId}",
                'metadata' => isset($extra['metadata']) ? json_encode($extra['metadata']) : null,
            ]);

            $this->db->commit();
            return $newBalance;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log("[CompanyWallet] Debit failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Crédite le wallet (reverse après paiement client ou rechargement)
     */
    public function credit(
        int $companyId,
        int $amount,
        string $type,
        ?int $orderId = null,
        ?int $dispatcherId = null,
        array $extra = []
    ): ?int {
        if ($amount <= 0) {
            return null;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                SELECT id, balance FROM {$this->table} 
                WHERE company_id = :cid AND status = 'active' 
                FOR UPDATE
            ");
            $stmt->execute(['cid' => $companyId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                $this->db->rollBack();
                return null;
            }

            $newBalance = (int) $wallet['balance'] + $amount;

            $update = $this->db->prepare("
                UPDATE {$this->table} 
                SET balance = :bal, updated_at = NOW() 
                WHERE id = :id
            ");
            $update->execute(['bal' => $newBalance, 'id' => $wallet['id']]);

            $transactionModel = new CompanyWalletTransaction();
            $transactionModel->create([
                'company_wallet_id' => $wallet['id'],
                'order_id' => $orderId,
                'dispatcher_id' => $dispatcherId,
                'type' => $type,
                'amount' => $amount, // Positif = crédit
                'gross_amount' => $extra['gross_amount'] ?? null,
                'commission_amount' => $extra['commission_amount'] ?? null,
                'net_amount' => $extra['net_amount'] ?? null,
                'balance_after' => $newBalance,
                'description' => $extra['description'] ?? "Crédit {$type}",
                'reference' => $extra['reference'] ?? null,
                'payment_method' => $extra['payment_method'] ?? null,
                'payment_provider_id' => $extra['payment_provider_id'] ?? null,
                'payment_status' => $extra['payment_status'] ?? 'completed',
                'metadata' => isset($extra['metadata']) ? json_encode($extra['metadata']) : null,
            ]);

            $this->db->commit();
            return $newBalance;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log("[CompanyWallet] Credit failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Calcule la commission pour une commande
     */
    public function calculateCommission(int $orderAmount): int
    {
        $rate = (float) $this->getSetting('commission_rate_percent', '10.00');
        $commission = (int) ceil($orderAmount * $rate / 100);
        
        // Minimum 100 XAF
        return max($commission, 100);
    }

    /**
     * Traite le paiement d'une commande (cash ou wallet client)
     * Retourne true si succès
     */
    public function processOrderPayment(
        int $companyId,
        int $orderId,
        int $orderAmount,
        string $paymentMethod, // 'cash' ou 'wallet'
        ?int $dispatcherId = null
    ): bool {
        $commission = $this->calculateCommission($orderAmount);

        if ($paymentMethod === 'cash') {
            // Client a payé cash au dispatcher
            // Débit commission uniquement
            $result = $this->debit(
                companyId: $companyId,
                amount: $commission,
                orderId: $orderId,
                dispatcherId: $dispatcherId,
                extra: [
                    'type' => 'cash_order_commission',
                    'gross_amount' => $orderAmount,
                    'commission_amount' => $commission,
                    'net_amount' => $orderAmount - $commission,
                    'description' => "Commission ColiXpress (10%) - Commande #{$orderId} (Cash)"
                ]
            );
            return $result !== null;

        } elseif ($paymentMethod === 'wallet') {
            // Client a payé via wallet ColiXpress
            // Crédit le net après commission
            $netAmount = $orderAmount - $commission;
            
            $result = $this->credit(
                companyId: $companyId,
                amount: $netAmount,
                type: 'wallet_payment_credit',
                orderId: $orderId,
                dispatcherId: $dispatcherId,
                extra: [
                    'gross_amount' => $orderAmount,
                    'commission_amount' => $commission,
                    'net_amount' => $netAmount,
                    'description' => "Reverse après paiement client - Commande #{$orderId}"
                ]
            );
            return $result !== null;
        }

        return false;
    }

    /**
     * Liste les wallets avec filtre optionnel
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = [];
        $params = [];

        if (isset($filters['status'])) {
            $where[] = "status = :status";
            $params['status'] = $filters['status'];
        }
        if (isset($filters['company_id'])) {
            $where[] = "company_id = :cid";
            $params['cid'] = $filters['company_id'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count
        $countStmt = $this->db->prepare("SELECT COUNT(*) as total FROM {$this->table} {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        // Data
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare("
            SELECT cw.*, c.name as company_name, c.email as company_email
            FROM {$this->table} cw
            JOIN companies c ON c.id = cw.company_id
            {$whereSql}
            ORDER BY cw.updated_at DESC
            LIMIT :limit OFFSET :offset
        ");
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
     * Récupère un setting depuis la DB
     */
    private function getSetting(string $key, string $default): string
    {
        $stmt = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1");
        $stmt->execute(['key' => $key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : $default;
    }
}
