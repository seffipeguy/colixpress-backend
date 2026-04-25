<?php

namespace App\Services;

use App\Config\Database;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Core\Response;

class WalletService
{
    private Wallet $walletModel;
    private WalletTransaction $txModel;

    public function __construct()
    {
        $this->walletModel = new Wallet();
        $this->txModel     = new WalletTransaction();
    }

    /**
     * Créditer un portefeuille (rechargement admin, remboursement, bonus)
     */
    public function credit(
        int    $userId,
        int    $amount,
        string $source,
        string $description = null,
        string $orderReference = null,
        int    $performedBy = null
    ): array {
        if ($amount <= 0) {
            Response::error('Le montant doit être supérieur à 0', 422);
        }

        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $wallet = $this->walletModel->getOrCreate($userId);
            $balanceBefore = (int) $wallet['balance'];
            $balanceAfter  = $balanceBefore + $amount;

            $db->prepare("UPDATE wallets SET balance = :balance WHERE id = :id")
               ->execute(['balance' => $balanceAfter, 'id' => $wallet['id']]);

            $txId = $this->txModel->create([
                'wallet_id'       => $wallet['id'],
                'type'            => 'credit',
                'amount'          => $amount,
                'balance_before'  => $balanceBefore,
                'balance_after'   => $balanceAfter,
                'source'          => $source,
                'order_reference' => $orderReference,
                'description'     => $description,
                'performed_by'    => $performedBy,
            ]);

            $db->commit();

            return [
                'wallet_id'      => $wallet['id'],
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'transaction_id' => $txId,
            ];
        } catch (\Exception $e) {
            $db->rollBack();
            Response::error('Erreur lors du crédit du portefeuille', 500);
        }
    }

    /**
     * Débiter un portefeuille (paiement commande)
     * @throws \Exception Si solde insuffisant ou erreur
     */
    public function debit(
        int    $userId,
        int    $amount,
        string $source,
        string $description = null,
        string $orderReference = null,
        int    $performedBy = null
    ): array {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Le montant doit être supérieur à 0');
        }

        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $wallet = $this->walletModel->getOrCreate($userId);
            $balanceBefore = (int) $wallet['balance'];

            if ($balanceBefore < $amount) {
                $db->rollBack();
                throw new \Exception('Solde insuffisant', 422);
            }

            $balanceAfter = $balanceBefore - $amount;

            $db->prepare("UPDATE wallets SET balance = :balance WHERE id = :id")
               ->execute(['balance' => $balanceAfter, 'id' => $wallet['id']]);

            $txId = $this->txModel->create([
                'wallet_id'       => $wallet['id'],
                'type'            => 'debit',
                'amount'          => $amount,
                'balance_before'  => $balanceBefore,
                'balance_after'   => $balanceAfter,
                'source'          => $source,
                'order_reference' => $orderReference,
                'description'     => $description,
                'performed_by'    => $performedBy,
            ]);

            $db->commit();

            return [
                'wallet_id'      => $wallet['id'],
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'transaction_id' => $txId,
            ];
        } catch (\Exception $e) {
            $db->rollBack();
            if ($e->getMessage() === 'Solde insuffisant') {
                throw $e; // Re-lancer pour gestion spécifique
            }
            throw new \Exception('Erreur lors du débit du portefeuille', 500);
        }
    }

    /**
     * Retourner le solde d'un utilisateur
     */
    public function getBalance(int $userId): int
    {
        $wallet = $this->walletModel->findByUserId($userId);
        return $wallet ? (int) $wallet['balance'] : 0;
    }
}
