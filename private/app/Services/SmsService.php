<?php

namespace App\Services;

/**
 * Service SMS via Twilio Verify API
 *
 * Utilise l'API Twilio Verify pour envoyer et vérifier des OTP par SMS.
 * Configurer SMS_ENABLED=true + TWILIO_AUTH_TOKEN en production.
 */
class SmsService
{
    private string $accountSid;
    private string $authToken;
    private string $verifySid;
    private string $baseUrl;

    public function __construct()
    {
        $this->accountSid = TWILIO_ACCOUNT_SID;
        $this->authToken  = TWILIO_AUTH_TOKEN;
        $this->verifySid  = TWILIO_VERIFY_SID;
        $this->baseUrl    = "https://verify.twilio.com/v2/Services/{$this->verifySid}";
    }

    /**
     * Envoie un OTP via Twilio Verify au numéro E.164 donné.
     * Retourne true si envoyé avec succès, false sinon.
     */
    public function sendOtp(string $phoneE164): bool
    {
        if (!SMS_ENABLED) {
            return true;
        }

        $response = $this->request('POST', '/Verifications', [
            'To'      => $phoneE164,
            'Channel' => 'sms',
        ]);

        return isset($response['status']) && in_array($response['status'], ['pending', 'approved']);
    }

    /**
     * Vérifie le code OTP saisi par l'utilisateur via Twilio Verify.
     * Retourne true si le code est correct, false sinon.
     */
    public function verifyOtp(string $phoneE164, string $code): bool
    {
        if (!SMS_ENABLED) {
            return true;
        }

        $response = $this->request('POST', '/VerificationCheck', [
            'To'   => $phoneE164,
            'Code' => $code,
        ]);

        return isset($response['status']) && $response['status'] === 'approved';
    }

    /**
     * Formate un numéro local en E.164 (+237XXXXXXXXX).
     */
    public static function formatE164(string $dialCode, string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        $dialCode = preg_replace('/\D/', '', $dialCode);
        return '+' . $dialCode . $phone;
    }

    /**
     * Effectue une requête HTTP vers l'API Twilio Verify.
     */
    private function request(string $method, string $path, array $params): array
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "{$this->accountSid}:{$this->authToken}",
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 10,
        ]);

        $body = curl_exec($ch);
        curl_close($ch);

        return $body ? (json_decode($body, true) ?? []) : [];
    }
}
