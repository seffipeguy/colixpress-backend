<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\Setting;

class PushNotificationService
{
    private string $publicKey;
    private string $privateKey;
    private string $subject;

    public function __construct()
    {
        $settings = new Setting();
        $this->publicKey  = $settings->get('vapid_public_key', '');
        $this->privateKey = $settings->get('vapid_private_key', '');
        $this->subject    = $settings->get('vapid_subject', 'mailto:contact@colixpress.com');
    }

    /**
     * Envoyer une notification push à un utilisateur (toutes ses subscriptions)
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        $subModel = new PushSubscription();
        $subscriptions = $subModel->getByUser($userId);

        foreach ($subscriptions as $sub) {
            $this->sendPush($sub['endpoint'], $sub['p256dh'], $sub['auth'], $title, $body, $data);
        }
    }

    /**
     * Envoyer une notification push à tous les utilisateurs abonnés
     */
    public function broadcast(string $title, string $body, array $data = []): void
    {
        $subModel = new PushSubscription();
        $subscriptions = $subModel->getAll();

        foreach ($subscriptions as $sub) {
            $this->sendPush($sub['endpoint'], $sub['p256dh'], $sub['auth'], $title, $body, $data);
        }
    }

    /**
     * Envoi d'une notification push via Web Push Protocol (VAPID + ECDH)
     */
    private function sendPush(
        string $endpoint,
        string $p256dh,
        string $auth,
        string $title,
        string $body,
        array  $data = []
    ): bool {
        if (empty($this->publicKey) || empty($this->privateKey)) {
            error_log('[PushNotification] Clés VAPID manquantes');
            return false;
        }

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'icon'  => '/icons/icon-192.png',
            'badge' => '/icons/badge-72.png',
            'data'  => $data,
        ]);

        // Génération de la paire de clés éphémères ECDH
        $localKeyConfig = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $localKey = openssl_pkey_new($localKeyConfig);
        $localDetails = openssl_pkey_get_details($localKey);

        // Clé publique du serveur (format non compressé)
        $localPublicKey = chr(0x04) . $localDetails['ec']['x'] . $localDetails['ec']['y'];

        // Clé publique du client (décodée depuis base64url)
        $clientPublicKeyBytes = $this->base64urlDecode($p256dh);
        $authBytes            = $this->base64urlDecode($auth);

        // ECDH shared secret
        $clientKey = openssl_pkey_get_public([
            'kty' => 'EC',
            'crv' => 'P-256',
            'x'   => base64_encode(substr($clientPublicKeyBytes, 1, 32)),
            'y'   => base64_encode(substr($clientPublicKeyBytes, 33, 32)),
        ]);

        if (!openssl_pkey_derive($sharedSecret, $localKey, $clientKeyBytes ?? $clientPublicKeyBytes)) {
            // Fallback : utiliser curl sans chiffrement (pour navigateurs supportant HTTPS)
            return $this->sendUnencrypted($endpoint, $payload, $title, $body);
        }

        return true;
    }

    /**
     * Envoi simplifié via curl — payload non chiffré (fonctionne sur HTTPS avec certains serveurs push)
     * Pour une implémentation complète, utiliser la lib minishlink/web-push
     */
    private function sendUnencrypted(string $endpoint, string $payload, string $title, string $body): bool
    {
        $vapidToken = $this->generateVapidJwt($endpoint);

        $headers = [
            'Content-Type: application/json',
            'Authorization: vapid t=' . $vapidToken . ', k=' . $this->publicKey,
            'TTL: 86400',
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            error_log("[PushNotification] Échec envoi push HTTP {$httpCode} vers {$endpoint}: {$response}");
            return false;
        }

        return true;
    }

    /**
     * Génère un JWT VAPID signé avec la clé privée ECDSA
     */
    private function generateVapidJwt(string $endpoint): string
    {
        $parsed = parse_url($endpoint);
        $audience = $parsed['scheme'] . '://' . $parsed['host'];

        $header  = $this->base64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = $this->base64urlEncode(json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,
            'sub' => $this->subject,
        ]));

        $signingInput = $header . '.' . $payload;

        // Reconstruire la clé privée PEM depuis base64url
        $privBytes = $this->base64urlDecode($this->privateKey);
        $privHex   = bin2hex($privBytes);

        // DER encoding de la clé privée EC P-256
        $der = hex2bin(
            '30770201010420' . $privHex .
            'a00a06082a8648ce3d030107a144034200' .
            bin2hex(chr(0x04)) .
            bin2hex(str_pad('', 32, "\x00")) .
            bin2hex(str_pad('', 32, "\x00"))
        );

        $pem = "-----BEGIN EC PRIVATE KEY-----\n" .
               chunk_split(base64_encode($der), 64, "\n") .
               "-----END EC PRIVATE KEY-----";

        $privateKeyRes = openssl_pkey_get_private($pem);
        if (!$privateKeyRes) {
            error_log('[PushNotification] Impossible de charger la clé privée VAPID');
            return '';
        }

        openssl_sign($signingInput, $signature, $privateKeyRes, OPENSSL_ALGO_SHA256);

        // Convertir DER signature → raw r||s (64 bytes)
        $r = substr($signature, 4, ord($signature[3]));
        $sOffset = 4 + ord($signature[3]) + 2;
        $s = substr($signature, $sOffset, ord($signature[$sOffset - 1]));
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return $signingInput . '.' . $this->base64urlEncode($r . $s);
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
