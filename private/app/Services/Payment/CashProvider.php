<?php

namespace App\Services\Payment;

/**
 * Cash Payment Provider (paiement en espèces)
 * Pas d'API, juste un provider fictif pour gérer le cash
 */
class CashProvider implements PaymentProviderInterface
{
    public function getName(): string
    {
        return 'Cash';
    }

    public function getCode(): string
    {
        return 'cash';
    }

    public function initiatePayment(array $data): array
    {
        // Le paiement cash est toujours "en attente" jusqu'à confirmation manuelle
        return [
            'success' => true,
            'transaction_id' => 'CASH-' . time(),
            'reference' => 'CASH-' . time(),
            'message' => 'Cash payment will be collected upon delivery',
            'data' => [
                'payment_method' => 'cash',
                'status' => 'pending',
            ],
        ];
    }

    public function checkStatus(string $transactionId): array
    {
        // Le statut cash doit être mis à jour manuellement
        return [
            'success' => true,
            'status' => 'pending',
            'data' => [
                'message' => 'Cash payment status must be updated manually',
            ],
        ];
    }

    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        // Pas de webhook pour le cash
        return true;
    }

    public function processWebhook(array $payload): array
    {
        // Pas de webhook pour le cash
        return [
            'success' => false,
            'message' => 'Cash provider does not support webhooks',
        ];
    }
}
