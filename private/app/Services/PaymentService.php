<?php

namespace App\Services;

use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Services\Payment\PaymentProviderInterface;
use App\Services\Payment\CamPayProvider;
use App\Services\Payment\CashProvider;

/**
 * Service orchestrateur pour gérer tous les providers de paiement
 */
class PaymentService
{
    private PaymentProvider $providerModel;
    private PaymentTransaction $transactionModel;

    public function __construct()
    {
        $this->providerModel = new PaymentProvider();
        $this->transactionModel = new PaymentTransaction();
    }

    /**
     * Obtenir une instance du provider
     */
    public function getProviderInstance(string $providerCode): PaymentProviderInterface
    {
        $provider = $this->providerModel->findByCode($providerCode);
        
        if (!$provider) {
            throw new \Exception("Provider '{$providerCode}' not found");
        }

        if (!$provider['is_active']) {
            throw new \Exception("Provider '{$providerCode}' is not active");
        }

        return match ($providerCode) {
            'campay' => new CamPayProvider($provider),
            'cash' => new CashProvider(),
            default => throw new \Exception("Provider '{$providerCode}' not implemented"),
        };
    }

    /**
     * Initier un paiement
     * 
     * @param array $data [
     *   'provider_code' => string,
     *   'order_id' => int (optional),
     *   'amount' => int,
     *   'phone' => string,
     *   'customer_name' => string (optional),
     *   'description' => string (optional)
     * ]
     */
    public function initiatePayment(array $data): array
    {
        try {
            // Validation
            if (!isset($data['provider_code'], $data['amount'], $data['phone'])) {
                return [
                    'success' => false,
                    'message' => 'provider_code, amount, and phone are required',
                ];
            }

            // Récupérer le provider
            $provider = $this->providerModel->findByCode($data['provider_code']);
            if (!$provider) {
                return [
                    'success' => false,
                    'message' => 'Payment provider not found',
                ];
            }

            // Vérifier les limites
            if ($data['amount'] < $provider['min_amount']) {
                return [
                    'success' => false,
                    'message' => "Minimum amount is {$provider['min_amount']} XAF",
                ];
            }

            if ($data['amount'] > $provider['max_amount']) {
                return [
                    'success' => false,
                    'message' => "Maximum amount is {$provider['max_amount']} XAF",
                ];
            }

            // Calculer les frais
            $feeAmount = $this->calculateFees($data['amount'], $provider);

            // Créer la transaction en base
            $transactionId = $this->transactionModel->createTransaction([
                'provider_id' => $provider['id'],
                'order_id' => $data['order_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'amount' => $data['amount'],
                'fee_amount' => $feeAmount,
                'customer_phone' => $data['phone'],
                'customer_name' => $data['customer_name'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'payment_method' => $data['provider_code'],
                'status' => 'pending',
            ]);

            $transaction = $this->transactionModel->find($transactionId);

            // Initier le paiement via le provider
            $providerInstance = $this->getProviderInstance($data['provider_code']);
            
            $paymentData = [
                'amount' => $data['amount'],
                'phone' => $data['phone'],
                'description' => $data['description'] ?? "ColiXpress Order #{$data['order_id']}",
                'external_reference' => $transaction['reference'],
            ];

            $result = $providerInstance->initiatePayment($paymentData);

            // Mettre à jour la transaction
            if ($result['success']) {
                $this->transactionModel->update($transactionId, [
                    'provider_transaction_id' => $result['transaction_id'] ?? null,
                    'provider_reference' => $result['reference'] ?? null,
                    'status' => 'processing',
                    'payment_details' => json_encode($result['data'] ?? []),
                ]);
            } else {
                $this->transactionModel->markAsFailed(
                    $transactionId,
                    'INIT_FAILED',
                    $result['message'] ?? 'Payment initiation failed'
                );
            }

            return [
                'success' => $result['success'],
                'message' => $result['message'] ?? 'Payment initiated',
                'transaction' => array_merge($transaction, [
                    'provider_transaction_id' => $result['transaction_id'] ?? null,
                    'status' => $result['success'] ? 'processing' : 'failed',
                ]),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Vérifier le statut d'un paiement
     */
    public function checkPaymentStatus(string $reference): array
    {
        $transaction = $this->transactionModel->findByReference($reference);
        
        if (!$transaction) {
            return [
                'success' => false,
                'message' => 'Transaction not found',
            ];
        }

        // Si déjà complété ou échoué, retourner le statut actuel
        if (in_array($transaction['status'], ['completed', 'failed', 'refunded'])) {
            return [
                'success' => true,
                'transaction' => $transaction,
            ];
        }

        // Vérifier auprès du provider
        try {
            // Récupérer le provider depuis provider_id
            $provider = $this->providerModel->find((int) $transaction['provider_id']);
            if (!$provider) {
                throw new \Exception('Provider not found');
            }
            
            $providerInstance = $this->getProviderInstance($provider['code']);
            
            if ($transaction['provider_transaction_id']) {
                $result = $providerInstance->checkStatus($transaction['provider_transaction_id']);
                
                if (isset($result['status'])) {
                    // Mettre à jour le statut
                    $updateData = ['status' => $result['status']];
                    if ($result['status'] === 'completed') {
                        $updateData['completed_at'] = date('Y-m-d H:i:s');
                    } elseif ($result['status'] === 'failed') {
                        $updateData['failed_at'] = date('Y-m-d H:i:s');
                    }
                    
                    $this->transactionModel->update((int) $transaction['id'], $updateData);
                    $transaction['status'] = $result['status'];
                }
            }

            return [
                'success' => true,
                'transaction' => $transaction,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'transaction' => $transaction,
            ];
        }
    }

    /**
     * Traiter un webhook
     */
    public function processWebhook(string $providerCode, array $payload, ?string $signature = null): array
    {
        try {
            $providerInstance = $this->getProviderInstance($providerCode);

            // Vérifier la signature si fournie
            if ($signature && !$providerInstance->verifyWebhookSignature($payload, $signature)) {
                return [
                    'success' => false,
                    'message' => 'Invalid webhook signature',
                ];
            }

            // Traiter le webhook
            $result = $providerInstance->processWebhook($payload);

            if (!$result['success']) {
                return $result;
            }

            // Trouver la transaction
            $transaction = $this->transactionModel->findByProviderTransactionId($result['transaction_id']);
            
            if (!$transaction) {
                return [
                    'success' => false,
                    'message' => 'Transaction not found',
                ];
            }

            // Enregistrer le webhook
            $this->transactionModel->recordWebhook((int) $transaction['id'], $payload);

            // Mettre à jour le statut
            $this->transactionModel->updateStatus((int) $transaction['id'], $result['status']);

            return [
                'success' => true,
                'message' => 'Webhook processed successfully',
                'transaction' => $transaction,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Calculer les frais de transaction
     */
    private function calculateFees(int $amount, array $provider): int
    {
        $percentFee = ($amount * ($provider['transaction_fee_percent'] ?? 0)) / 100;
        $fixedFee = $provider['transaction_fee_fixed'] ?? 0;
        
        return (int) ($percentFee + $fixedFee);
    }
}
