<?php

namespace App\Services\Payment;

/**
 * Interface commune pour tous les providers de paiement
 */
interface PaymentProviderInterface
{
    /**
     * Initier un paiement
     * 
     * @param array $data Données du paiement (amount, phone, description, etc.)
     * @return array ['success' => bool, 'transaction_id' => string, 'reference' => string, 'message' => string, 'data' => array]
     */
    public function initiatePayment(array $data): array;

    /**
     * Vérifier le statut d'un paiement
     * 
     * @param string $transactionId ID de la transaction chez le provider
     * @return array ['success' => bool, 'status' => string, 'data' => array]
     */
    public function checkStatus(string $transactionId): array;

    /**
     * Vérifier la signature du webhook
     * 
     * @param array $payload Données reçues
     * @param string $signature Signature reçue
     * @return bool
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool;

    /**
     * Traiter le webhook
     * 
     * @param array $payload Données du webhook
     * @return array ['success' => bool, 'status' => string, 'transaction_id' => string]
     */
    public function processWebhook(array $payload): array;

    /**
     * Obtenir le nom du provider
     */
    public function getName(): string;

    /**
     * Obtenir le code du provider
     */
    public function getCode(): string;
}
