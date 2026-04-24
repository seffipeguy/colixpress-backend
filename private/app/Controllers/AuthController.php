<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\User;
use App\Models\OtpCode;
use App\Models\Country;
use App\Services\SmsService;

class AuthController extends Controller
{
    /**
     * POST /api/auth/send-otp
     * Body: { "country_id": 1, "phone": "691234567" }
     */
    public function sendOtp(Request $request): void
    {
        $request->validate(['country_id', 'phone']);

        $countryId = (int) $request->input('country_id');
        $phone = trim($request->input('phone'));

        // Validate country
        $countryModel = new Country();
        $country = $countryModel->find($countryId);
        if (!$country) {
            Response::error('Invalid country', 422);
        }

        // Validate phone length
        if (strlen($phone) !== (int) $country['phone_length']) {
            Response::error("Phone number must be {$country['phone_length']} digits for {$country['name']}", 422);
        }

        // Generate OTP
        $otpModel = new OtpCode();
        $code = $otpModel->generate($countryId, $phone);

        // Envoyer via Twilio Verify si SMS_ENABLED
        $phoneE164 = SmsService::formatE164($country['dial_code'], $phone);
        $sms = new SmsService();
        if (SMS_ENABLED && !$sms->sendOtp($phoneE164)) {
            Response::error('Échec de l\'envoi du SMS, veuillez réessayer', 500);
        }

        $responseData = [
            'message'    => 'OTP envoyé avec succès',
            'expires_in' => OTP_EXPIRY_MINUTES . ' minutes',
        ];

        if (!SMS_ENABLED) {
            $responseData['otp_code'] = $code;
        }

        Response::success($responseData, 'OTP sent');
    }

    /**
     * POST /api/auth/verify-otp
     * Body: { "country_id": 1, "phone": "691234567", "code": "1234" }
     */
    public function verifyOtp(Request $request): void
    {
        $request->validate(['country_id', 'phone', 'code']);

        $countryId = (int) $request->input('country_id');
        $phone = trim($request->input('phone'));
        $code = trim($request->input('code'));

        // Verify OTP
        $otpModel = new OtpCode();
        if (!$otpModel->verify($countryId, $phone, $code)) {
            Response::error('Invalid or expired OTP', 401);
        }

        // Check if user exists
        $userModel = new User();
        $user = $userModel->findByPhone($countryId, $phone);

        $isNewUser = false;
        if (!$user) {
            // Create new user
            $userId = $userModel->create([
                'country_id'  => $countryId,
                'phone'       => $phone,
                'role'        => 'client',
                'is_verified' => 1,
            ]);
            $user = $userModel->findWithCountry($userId);
            $isNewUser = true;
        } else {
            // Mark as verified
            if (!$user['is_verified']) {
                $userModel->update((int) $user['id'], ['is_verified' => 1]);
            }
        }

        // Generate auth token
        $token = Auth::generateToken((int) $user['id']);

        Response::success([
            'token'    => $token,
            'user'     => $userModel->profile((int) $user['id']),
            'is_new'   => $isNewUser,
        ], $isNewUser ? 'Account created' : 'Login successful');
    }

    /**
     * POST /api/auth/register
     * Body: { "country_id": 1, "phone": "691234567", "password": "mypassword", "first_name": "Jean", "last_name": "Kamga" }
     */
    public function register(Request $request): void
    {
        $request->validate(['country_id', 'phone', 'password']);

        $countryId = (int) $request->input('country_id');
        $phone = trim($request->input('phone'));
        $password = $request->input('password');

        // Validate country
        $countryModel = new Country();
        $country = $countryModel->find($countryId);
        if (!$country) {
            Response::error('Invalid country', 422);
        }

        // Validate phone length
        if (strlen($phone) !== (int) $country['phone_length']) {
            Response::error("Phone number must be {$country['phone_length']} digits for {$country['name']}", 422);
        }

        // Validate password strength
        if (strlen($password) < 6) {
            Response::error('Password must be at least 6 characters', 422);
        }

        // Check if user already exists
        $userModel = new User();
        $existing = $userModel->findByPhone($countryId, $phone);
        if ($existing) {
            // User exists — if no password set, allow setting it
            if (!empty($existing['password'])) {
                Response::error('An account with this phone number already exists. Please login.', 409);
            }
            // Set password on existing account (e.g. created via OTP)
            $userModel->update((int) $existing['id'], [
                'password'   => password_hash($password, PASSWORD_BCRYPT),
                'first_name' => $request->input('first_name', $existing['first_name']),
                'last_name'  => $request->input('last_name', $existing['last_name']),
            ]);
            $user = $userModel->findWithCountry((int) $existing['id']);
        } else {
            // Create new user
            $userId = $userModel->create([
                'country_id'  => $countryId,
                'phone'       => $phone,
                'password'    => password_hash($password, PASSWORD_BCRYPT),
                'role'        => 'client',
                'first_name'  => $request->input('first_name'),
                'last_name'   => $request->input('last_name'),
                'is_verified' => 0, // Not verified until OTP confirmed
            ]);
            $user = $userModel->findWithCountry($userId);
        }

        // Generate auth token
        $token = Auth::generateToken((int) $user['id']);

        Response::success([
            'token' => $token,
            'user'  => $userModel->profile((int) $user['id']),
        ], 'Account registered', 201);
    }

    /**
     * POST /api/auth/login
     * Body: { "country_id": 1, "phone": "691234567", "password": "mypassword" }
     */
    public function login(Request $request): void
    {
        $request->validate(['country_id', 'phone', 'password']);

        $countryId = (int) $request->input('country_id');
        $phone = trim($request->input('phone'));
        $password = $request->input('password');

        $userModel = new User();
        $user = $userModel->findByPhone($countryId, $phone);

        if (!$user) {
            Response::error('Invalid phone number or password', 401);
        }

        if (empty($user['password'])) {
            Response::error('No password set on this account. Please use OTP login or register with a password first.', 401);
        }

        if (!password_verify($password, $user['password'])) {
            Response::error('Invalid phone number or password', 401);
        }

        if (!$user['is_active']) {
            Response::forbidden('Account deactivated');
        }

        // Generate auth token
        $token = Auth::generateToken((int) $user['id']);

        Response::success([
            'token' => $token,
            'user'  => $userModel->profile((int) $user['id']),
        ], 'Login successful');
    }

    /**
     * PUT /api/auth/password
     * Header: Authorization: Bearer <token>
     * Body: { "current_password": "old", "new_password": "new" }
     * OR (if no password set): { "new_password": "new" }
     */
    public function changePassword(Request $request): void
    {
        $request->validate(['new_password']);

        $newPassword = $request->input('new_password');
        if (strlen($newPassword) < 6) {
            Response::error('Password must be at least 6 characters', 422);
        }

        $userModel = new User();
        $user = $userModel->find($this->userId());

        // If user already has a password, require current one
        if (!empty($user['password'])) {
            $currentPassword = $request->input('current_password');
            if (!$currentPassword) {
                Response::error('Current password is required', 422);
            }
            if (!password_verify($currentPassword, $user['password'])) {
                Response::error('Current password is incorrect', 401);
            }
        }

        $userModel->update($this->userId(), [
            'password' => password_hash($newPassword, PASSWORD_BCRYPT),
        ]);

        Response::success(null, 'Password updated');
    }

    /**
     * POST /api/auth/logout
     * Header: Authorization: Bearer <token>
     */
    public function logout(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token) {
            Auth::revokeToken($token);
        }
        Response::success(null, 'Logged out successfully');
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): void
    {
        $userModel = new User();
        $profile = $userModel->profile($this->userId());
        Response::success($profile);
    }

    /**
     * GET /api/health
     */
    public function health(Request $request): void
    {
        Response::success([
            'app'     => APP_NAME,
            'version' => APP_VERSION,
            'status'  => 'running',
            'time'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * GET /api/countries
     */
    public function countries(Request $request): void
    {
        $countryModel = new Country();
        Response::success($countryModel->getActive());
    }

    /**
     * POST /api/auth/request-verification
     * Header: Authorization: Bearer <token>
     * Envoie un OTP sur le numéro du compte connecté pour vérifier le compte.
     */
    public function requestVerification(Request $request): void
    {
        $userModel = new User();
        $user      = $userModel->find($this->userId());

        if (!$user) {
            Response::notFound('Utilisateur introuvable');
        }

        if ($user['is_verified']) {
            Response::error('Ce compte est déjà vérifié', 422);
        }

        $countryModel = new Country();
        $country      = $countryModel->find((int) $user['country_id']);

        $otpModel = new OtpCode();
        $code     = $otpModel->generate((int) $user['country_id'], $user['phone']);

        $phoneE164 = SmsService::formatE164($country['dial_code'], $user['phone']);
        $sms = new SmsService();
        if (SMS_ENABLED && !$sms->sendOtp($phoneE164)) {
            Response::error('Échec de l\'envoi du SMS, veuillez réessayer', 500);
        }

        $responseData = [
            'message'    => 'OTP envoyé sur votre numéro',
            'expires_in' => OTP_EXPIRY_MINUTES . ' minutes',
        ];

        if (!SMS_ENABLED) {
            $responseData['otp_code'] = $code;
        }

        Response::success($responseData, 'OTP envoyé');
    }

    /**
     * POST /api/auth/confirm-verification
     * Header: Authorization: Bearer <token>
     * Body: { "code": "1234" }
     * Valide l'OTP et marque le compte comme vérifié.
     */
    public function confirmVerification(Request $request): void
    {
        $request->validate(['code']);

        $userModel = new User();
        $user      = $userModel->find($this->userId());

        if (!$user) {
            Response::notFound('Utilisateur introuvable');
        }

        if ($user['is_verified']) {
            Response::error('Ce compte est déjà vérifié', 422);
        }

        $code     = trim($request->input('code'));
        $otpModel = new OtpCode();

        if (!$otpModel->verify((int) $user['country_id'], $user['phone'], $code)) {
            Response::error('Code OTP invalide ou expiré', 401);
        }

        $userModel->update($this->userId(), ['is_verified' => 1]);

        Response::success([
            'is_verified' => true,
        ], 'Compte vérifié avec succès');
    }
}
