<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\PushSubscription;
use App\Models\Setting;

class PushController extends Controller
{
    /**
     * GET /api/push/vapid-public-key
     * Retourne la clé publique VAPID pour le front (public)
     */
    public function vapidPublicKey(Request $request): void
    {
        $settings = new Setting();
        $publicKey = $settings->get('vapid_public_key');

        if (!$publicKey) {
            Response::error('Clé VAPID non configurée', 500);
        }

        Response::success(['public_key' => $publicKey]);
    }

    /**
     * POST /api/push/subscribe
     * Le front enregistre sa PushSubscription après permission accordée
     * Body: { "endpoint": "...", "p256dh": "...", "auth": "...", "user_agent": "..." }
     */
    public function subscribe(Request $request): void
    {
        $request->validate(['endpoint', 'p256dh', 'auth']);

        $userId   = $this->userId();
        $endpoint = $request->input('endpoint');
        $p256dh   = $request->input('p256dh');
        $auth     = $request->input('auth');

        $subModel = new PushSubscription();

        // Éviter les doublons
        $existing = $subModel->findByEndpoint($userId, $p256dh);
        if ($existing) {
            Response::success(null, 'Abonnement déjà enregistré');
        }

        $subModel->create([
            'user_id'    => $userId,
            'endpoint'   => $endpoint,
            'p256dh'     => $p256dh,
            'auth'       => $auth,
            'user_agent' => $request->input('user_agent'),
        ]);

        Response::success(null, 'Abonnement push enregistré', 201);
    }

    /**
     * DELETE /api/push/unsubscribe
     * Le front se désabonne (ex: déconnexion, révocation permission)
     * Body: { "endpoint": "..." }
     */
    public function unsubscribe(Request $request): void
    {
        $request->validate(['endpoint']);

        $subModel = new PushSubscription();
        $subModel->deleteByEndpoint($this->userId(), $request->input('endpoint'));

        Response::success(null, 'Désabonnement effectué');
    }
}
