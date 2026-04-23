<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Services\PushNotificationService;

class OrderMessageController
{
    /**
     * Résoudre la commande et vérifier l'accès
     * - Client : uniquement ses commandes
     * - Livreur : uniquement les commandes qui lui sont assignées
     * - Dispatcher / Admin : toutes les commandes
     */
    private function resolveOrder(string $reference): array
    {
        if (Auth::id() === null) {
            Response::unauthorized();
        }

        $orderModel = new Order();
        $order      = $orderModel->findBy('reference', $reference);

        if (!$order) {
            Response::notFound('Commande introuvable');
        }

        $userId = Auth::id();
        $role   = Auth::role();

        if ($role === 'client' && (int) $order['client_id'] !== $userId) {
            Response::forbidden();
        }

        if ($role === 'livreur' && (int) ($order['livreur_id'] ?? 0) !== $userId) {
            Response::forbidden();
        }

        return $order;
    }

    /**
     * Déduire le sender_role depuis le rôle Auth
     */
    private function senderRole(): string
    {
        $role = Auth::role();
        return match ($role) {
            'admin'      => 'admin',
            'livreur'    => 'livreur',
            'dispatcher' => 'dispatcher',
            default      => 'client',
        };
    }

    /**
     * GET /api/orders/{reference}/messages
     * Lire le fil de messages d'une commande
     */
    public function index(Request $request): void
    {
        $order   = $this->resolveOrder($request->param('reference'));
        $msgModel = new OrderMessage();

        $messages = $msgModel->getByOrder((int) $order['id']);

        // Marquer les messages comme lus selon le rôle
        $senderRole = $this->senderRole();
        if ($senderRole === 'client') {
            $msgModel->markReadByClient((int) $order['id']);
        } else {
            $msgModel->markReadByStaff((int) $order['id']);
        }

        Response::success([
            'order_reference' => $order['reference'],
            'messages'        => $messages,
            'unread_client'   => $msgModel->countUnreadForClient((int) $order['id']),
            'unread_staff'    => $msgModel->countUnreadForStaff((int) $order['id']),
        ]);
    }

    /**
     * POST /api/orders/{reference}/messages
     * Envoyer un message
     * Body: { "message": "...", "media_id": 5 }  (media_id optionnel)
     */
    public function store(Request $request): void
    {
        $order   = $this->resolveOrder($request->param('reference'));
        $message = trim($request->input('message') ?? '');
        $mediaId = $request->input('media_id') ? (int) $request->input('media_id') : null;

        if ($message === '' && $mediaId === null) {
            Response::error('Le message ou une pièce jointe est requis', 422);
        }

        $senderId   = Auth::id();
        $senderRole = $this->senderRole();
        $orderId    = (int) $order['id'];

        $msgModel = new OrderMessage();
        $msgId    = $msgModel->send($orderId, $senderId, $senderRole, $message, $mediaId);

        // Notifications push aux destinataires
        $push = new PushNotificationService();
        $sender = Auth::user();
        $senderName = trim(($sender['first_name'] ?? '') . ' ' . ($sender['last_name'] ?? ''));
        $pushData = ['screen' => 'order_messages', 'reference' => $order['reference']];

        if ($senderRole === 'client') {
            // Notifier le livreur assigné
            if (!empty($order['livreur_id'])) {
                $push->sendToUser(
                    (int) $order['livreur_id'],
                    'Message client — ' . $order['reference'],
                    $senderName . ' : ' . mb_substr($message, 0, 80),
                    $pushData
                );
            }
            // Notifier les dispatchers/admins via claimed_by
            if (!empty($order['claimed_by'])) {
                $push->sendToUser(
                    (int) $order['claimed_by'],
                    'Message client — ' . $order['reference'],
                    $senderName . ' : ' . mb_substr($message, 0, 80),
                    $pushData
                );
            }
        } else {
            // Notifier le client
            $push->sendToUser(
                (int) $order['client_id'],
                'Nouveau message — ' . $order['reference'],
                $senderName . ' : ' . mb_substr($message, 0, 80),
                $pushData
            );
        }

        // Retourner le message créé avec infos sender + media
        $db   = \App\Config\Database::getInstance();
        $stmt = $db->prepare("
            SELECT om.*,
                   CONCAT(u.first_name,' ',u.last_name) AS sender_name,
                   om_media.file_url  AS media_url,
                   om_media.file_type AS media_type,
                   om_media.file_name AS media_file_name,
                   om_media.mime_type AS media_mime_type
            FROM order_messages om
            JOIN users u ON u.id = om.sender_id
            LEFT JOIN media_uploads om_media ON om_media.id = om.media_id
            WHERE om.id = :id
        ");
        $stmt->execute(['id' => $msgId]);
        $created = $stmt->fetch();

        Response::success($created, 'Message envoyé', 201);
    }

    /**
     * PATCH /api/orders/{reference}/messages/read
     * Marquer les messages comme lus (selon le rôle du caller)
     */
    public function markRead(Request $request): void
    {
        $order      = $this->resolveOrder($request->param('reference'));
        $msgModel   = new OrderMessage();
        $senderRole = $this->senderRole();

        if ($senderRole === 'client') {
            $msgModel->markReadByClient((int) $order['id']);
        } else {
            $msgModel->markReadByStaff((int) $order['id']);
        }

        Response::success(null, 'Messages marqués comme lus');
    }
}
