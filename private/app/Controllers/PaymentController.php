<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\Notification;
use App\Services\PaymentService;
use App\Services\WalletService;

class PaymentController extends Controller
{
    /**
     * GET /api/payment/providers
     * Query: ?country_id=1
     * Liste les providers de paiement disponibles pour un pays
     */
    public function providers(Request $request): void
    {
        $countryId = (int) $request->query('country_id');
        
        if (!$countryId) {
            Response::error('country_id is required', 422);
        }

        $model = new PaymentProvider();
        $providers = $model->getByCountry($countryId, true);

        // Retirer les informations sensibles
        $providers = array_map(function ($provider) {
            return [
                'id' => $provider['id'],
                'code' => $provider['code'],
                'name' => $provider['name'],
                'provider_type' => $provider['provider_type'],
                'logo_url' => $provider['logo_url'],
                'description' => $provider['description'],
                'instructions' => $provider['instructions'],
                'min_amount' => (int) $provider['min_amount'],
                'max_amount' => (int) $provider['max_amount'],
                'is_default' => (bool) $provider['is_default'],
            ];
        }, $providers);

        Response::success($providers);
    }

    /**
     * POST /api/payment/initiate
     * Body: { "provider_code": "campay", "order_id": 123, "amount": 5000, "phone": "690123456" }
     */
    public function initiate(Request $request): void
    {
        $request->validate(['provider_code', 'amount', 'phone']);

        $service = new PaymentService();
        
        // Récupérer les infos du client
        $userModel = new User();
        $user = $userModel->find($this->userId());

        $data = [
            'provider_code' => $request->input('provider_code'),
            'order_id' => $request->input('order_id'),
            'user_id' => $this->userId(), // ID de l'utilisateur qui initie
            'amount' => (int) $request->input('amount'),
            'phone' => $request->input('phone'),
            'customer_name' => $user['first_name'] . ' ' . $user['last_name'],
            'customer_email' => $user['email'] ?? null,
            'description' => $request->input('description'),
        ];

        $result = $service->initiatePayment($data);

        if (!$result['success']) {
            Response::error($result['message'], 422);
        }

        Response::success($result['transaction'], $result['message'], 201);
    }

    /**
     * GET /api/payment/status?reference={reference}
     * Vérifier le statut d'un paiement
     */
    public function status(Request $request): void
    {
        try {
            $reference = $request->query('reference');
            
            if (!$reference) {
                Response::error('reference parameter is required', 400);
            }
            
            $service = new PaymentService();
            $result = $service->checkPaymentStatus($reference);

            if (!$result['success']) {
                Response::error($result['message'], 404);
            }

            Response::success($result['transaction']);
        } catch (\Exception $e) {
            Response::error('Error checking payment status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/payment/transactions
     * Historique des transactions du client connecté
     */
    public function transactions(Request $request): void
    {
        $userModel = new User();
        $user = $userModel->find($this->userId());

        $model = new PaymentTransaction();
        $transactions = $model->getByCustomerPhone($user['phone'], 50);

        Response::success($transactions);
    }

    /**
     * POST|GET /api/payment/webhook/{provider_code}
     * Webhook pour recevoir les notifications des providers
     * Public (pas d'auth requise)
     * Supporte POST (body JSON) et GET (query params)
     */
    public function webhook(Request $request, string $providerCode = 'campay'): void
    {
        try {
            // Récupérer les données du webhook (GET params pour CamPay)
            $payload = $_GET;
            
            // Logger pour debug
            $logFile = '/tmp/webhook_campay.log';
            file_put_contents(
                $logFile,
                "\n" . str_repeat('=', 80) . "\n" .
                date('Y-m-d H:i:s') . " - Webhook reçu\n" .
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );
            
            // Vérifier les paramètres requis
            if (!isset($payload['external_reference']) || !isset($payload['status'])) {
                Response::error('Missing required parameters', 400);
            }
            
            $externalRef = $payload['external_reference']; // Notre référence TX-xxx
            $status = $payload['status']; // SUCCESSFUL, FAILED, etc.
            $campayRef = $payload['reference'] ?? null; // Référence CamPay
            
            // Trouver la transaction dans notre base
            $txModel = new PaymentTransaction();
            $transaction = $txModel->findByReference($externalRef);
            
            if (!$transaction) {
                file_put_contents($logFile, "❌ Transaction non trouvée: {$externalRef}\n", FILE_APPEND);
                Response::error('Transaction not found', 404);
            }
            
            // PROTECTION: Si déjà completed, ignorer les webhooks tardifs avec statut différent
            if ($transaction['status'] === 'completed' && $status !== 'SUCCESSFUL') {
                file_put_contents($logFile, "⚠️ Webhook tardif ignoré: Transaction déjà completed, webhook dit {$status}\n", FILE_APPEND);
                Response::success([
                    'message' => 'Webhook ignored - transaction already completed',
                    'transaction_reference' => $externalRef,
                    'current_status' => 'completed',
                    'webhook_status' => $status
                ], 'OK', 200);
            }
            
            // Mettre à jour la transaction selon le statut
            $updateData = [
                'provider_transaction_id' => $campayRef,
                'webhook_received_at' => date('Y-m-d H:i:s'),
                'webhook_data' => json_encode($payload),
            ];
            
            if ($status === 'SUCCESSFUL') {
                $updateData['status'] = 'completed';
                $updateData['completed_at'] = date('Y-m-d H:i:s');
                
                file_put_contents($logFile, "✅ Transaction {$externalRef} marquée comme COMPLETED\n", FILE_APPEND);
                
                // Créditer le wallet automatiquement
                try {
                    // SÉCURITÉ: Utiliser l'utilisateur qui a INITIÉ la transaction, pas le numéro de paiement
                    if (!$transaction['user_id']) {
                        file_put_contents($logFile, "⚠️ Transaction sans user_id, impossible de créditer le wallet\n", FILE_APPEND);
                        // Mettre à jour quand même la transaction
                        $txModel->update((int) $transaction['id'], $updateData);
                    } else {
                        $userModel = new User();
                        $user = $userModel->find((int) $transaction['user_id']);
                    
                    if ($user) {
                        $amount = (int) $transaction['amount'];
                        $operator = $payload['operator'] ?? 'Mobile Money';
                        
                        // Tout faire dans une seule transaction
                        $db = \App\Config\Database::getInstance();
                        $db->beginTransaction();
                        
                        try {
                            // 1. Mettre à jour la transaction de paiement
                            $txModel->update((int) $transaction['id'], $updateData);
                            
                            // 2. Récupérer ou créer le wallet
                            $walletModel = new \App\Models\Wallet();
                            $wallet = $walletModel->getOrCreate((int) $user['id']);
                            
                            $balanceBefore = (int) $wallet['balance'];
                            $balanceAfter = $balanceBefore + $amount;
                            
                            // 3. Mettre à jour le solde
                            $db->prepare("UPDATE wallets SET balance = :balance WHERE id = :id")
                               ->execute(['balance' => $balanceAfter, 'id' => $wallet['id']]);
                            
                            // 4. Créer la transaction wallet
                            $wtxModel = new \App\Models\WalletTransaction();
                            $wtxModel->create([
                                'wallet_id' => $wallet['id'],
                                'type' => 'credit',
                                'amount' => $amount,
                                'balance_before' => $balanceBefore,
                                'balance_after' => $balanceAfter,
                                'source' => 'payment_gateway',
                                'order_reference' => $externalRef,
                                'description' => "Recharge via {$operator} - {$externalRef}",
                                'performed_by' => (int) $user['id'],
                            ]);
                            
                            $db->commit();
                            
                            file_put_contents($logFile, "💰 Wallet crédité: {$amount} XAF (solde: {$balanceBefore} → {$balanceAfter}) pour user #{$user['id']}\n", FILE_APPEND);
                            
                            // Envoyer notification push
                            Notification::send(
                                (int) $user['id'],
                                '✅ Recharge réussie',
                                "Votre portefeuille a été crédité de {$amount} XAF",
                                'payment',
                                [
                                    'transaction_reference' => $externalRef,
                                    'amount' => $amount,
                                    'operator' => $operator
                                ]
                            );
                            
                            file_put_contents($logFile, "🔔 Notification envoyée à user #{$user['id']}\n", FILE_APPEND);
                            
                        } catch (\Exception $e) {
                            $db->rollBack();
                            throw $e;
                        }
                    } else {
                        // Utilisateur non trouvé, juste mettre à jour la transaction
                        $txModel->update((int) $transaction['id'], $updateData);
                        file_put_contents($logFile, "⚠️ Utilisateur #{$transaction['user_id']} non trouvé\n", FILE_APPEND);
                    }
                    }
                } catch (\Exception $e) {
                    file_put_contents($logFile, "❌ Erreur crédit wallet: {$e->getMessage()}\n", FILE_APPEND);
                    // En cas d'erreur, au moins mettre à jour la transaction
                    $txModel->update((int) $transaction['id'], $updateData);
                }
                
            } elseif ($status === 'FAILED') {
                $updateData['status'] = 'failed';
                $updateData['failed_at'] = date('Y-m-d H:i:s');
                $updateData['error_code'] = $payload['code'] ?? 'PAYMENT_FAILED';
                $updateData['error_message'] = 'Payment failed';
                
                file_put_contents($logFile, "❌ Transaction {$externalRef} marquée comme FAILED\n", FILE_APPEND);
                
                // Mettre à jour en base
                $txModel->update((int) $transaction['id'], $updateData);
            }
            
            Response::success([
                'message' => 'Webhook processed successfully',
                'transaction_reference' => $externalRef,
                'status' => $status,
                'updated' => true
            ], 'OK', 200);
            
        } catch (\Exception $e) {
            file_put_contents('/tmp/webhook_campay.log', "💥 ERREUR: " . $e->getMessage() . "\n", FILE_APPEND);
            Response::error('Webhook error: ' . $e->getMessage(), 500);
        }
    }
}
