<?php

namespace App\Services\Payment;

use App\Models\PaymentProvider as PaymentProviderModel;

/**
 * CamPay Payment Provider
 * Documentation: https://campay.net/api
 */
class CamPayProvider implements PaymentProviderInterface
{
    private array $config;
    private string $baseUrl;
    private string $token;
    private string $webhookSecret;

    public function __construct(?array $config = null)
    {
        if ($config) {
            $this->config = $config;
        } else {
            // Charger depuis la base de données
            $model = new PaymentProviderModel();
            $provider = $model->findByCode('campay');
            if (!$provider) {
                throw new \Exception('CamPay provider not configured');
            }
            $this->config = $provider;
        }

        $this->baseUrl = rtrim($this->config['api_base_url'] ?? 'https://demo.campay.net/api', '/');
        $this->token = $this->config['api_token'] ?? '';
        $this->webhookSecret = $this->config['webhook_secret'] ?? '';
    }

    public function getName(): string
    {
        return 'CamPay';
    }

    public function getCode(): string
    {
        return 'campay';
    }

    /**
     * Initier un paiement via CamPay
     * 
     * @param array $data [
     *   'amount' => int (required),
     *   'phone' => string (required, format: 237XXXXXXXXX),
     *   'description' => string (optional),
     *   'external_reference' => string (optional),
     *   'external_user' => string (optional)
     * ]
     */
    public function initiatePayment(array $data): array
    {
        try {
            // Validation
            if (!isset($data['amount']) || !isset($data['phone'])) {
                return [
                    'success' => false,
                    'message' => 'Amount and phone are required',
                ];
            }

            // Formater le numéro (CamPay attend 237XXXXXXXXX)
            $phone = $this->formatPhone($data['phone']);

            // Préparer la requête
            $payload = [
                'amount' => (int) $data['amount'],
                'from' => $phone,
                'description' => $data['description'] ?? 'ColiXpress Order Payment',
                'external_reference' => $data['external_reference'] ?? '',
                'external_user' => $data['external_user'] ?? '',
            ];

            // Appel API CamPay
            $response = $this->makeRequest('POST', '/collect/', $payload);

            if (!$response || !isset($response['reference'])) {
                return [
                    'success' => false,
                    'message' => $response['message'] ?? 'Payment initiation failed',
                    'data' => $response,
                ];
            }

            return [
                'success' => true,
                'transaction_id' => $response['reference'],
                'reference' => $response['reference'],
                'message' => 'Paiement initié. Vous recevrez une notification sur votre téléphone pour valider le paiement. Si la notification ne vient pas : Orange Money #150*50# ou MTN Mobile Money *126# et suivez les instructions',
                'data' => $response,
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
    public function checkStatus(string $transactionId): array
    {
        try {
            $response = $this->makeRequest('GET', "/transaction/{$transactionId}/");

            if (!$response) {
                return [
                    'success' => false,
                    'status' => 'unknown',
                    'message' => 'Failed to check status',
                ];
            }

            // Mapper le statut CamPay vers notre système
            $status = $this->mapStatus($response['status'] ?? 'PENDING');

            return [
                'success' => true,
                'status' => $status,
                'data' => $response,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Vérifier la signature du webhook
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        // CamPay utilise HMAC SHA256
        $computedSignature = hash_hmac('sha256', json_encode($payload), $this->webhookSecret);
        return hash_equals($computedSignature, $signature);
    }

    /**
     * Traiter le webhook CamPay
     */
    public function processWebhook(array $payload): array
    {
        try {
            $reference = $payload['reference'] ?? null;
            $status = $payload['status'] ?? 'PENDING';

            if (!$reference) {
                return [
                    'success' => false,
                    'message' => 'Missing reference in webhook',
                ];
            }

            return [
                'success' => true,
                'status' => $this->mapStatus($status),
                'transaction_id' => $reference,
                'data' => $payload,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Faire une requête HTTP vers l'API CamPay
     */
    private function makeRequest(string $method, string $endpoint, ?array $data = null): ?array
    {
        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Authorization: Token ' . $this->token,
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("cURL Error: {$error}");
        }

        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            throw new \Exception($errorData['message'] ?? "HTTP Error {$httpCode}");
        }

        return json_decode($response, true);
    }

    /**
     * Formater le numéro de téléphone pour CamPay
     * CamPay attend: 237XXXXXXXXX (avec indicatif pays)
     */
    private function formatPhone(string $phone): string
    {
        // Retirer espaces et caractères spéciaux
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Si commence par +237, retirer le +
        if (str_starts_with($phone, '+237')) {
            $phone = substr($phone, 1);
        }

        // Si commence par 00237, retirer 00
        if (str_starts_with($phone, '00237')) {
            $phone = substr($phone, 2);
        }

        // Si ne commence pas par 237, l'ajouter
        if (!str_starts_with($phone, '237')) {
            $phone = '237' . $phone;
        }

        return $phone;
    }

    /**
     * Mapper le statut CamPay vers notre système
     */
    private function mapStatus(string $campayStatus): string
    {
        return match (strtoupper($campayStatus)) {
            'SUCCESSFUL' => 'completed',
            'FAILED' => 'failed',
            'PENDING' => 'pending',
            default => 'processing',
        };
    }
}
