<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\SupportTicket;
use App\Models\SupportMessage;
use App\Models\Notification;

class SupportController extends Controller
{
    /**
     * POST /api/support/tickets
     * Client crée un ticket
     * Body: { "subject": "...", "message": "...", "category": "livraison|paiement|compte|autre", "order_reference": "..." }
     */
    public function store(Request $request): void
    {
        $request->validate(['subject', 'message']);

        $ticketModel = new SupportTicket();

        $ticketId = $ticketModel->create([
            'reference'       => $ticketModel->generateReference(),
            'created_by'      => $this->userId(),
            'subject'         => $request->input('subject'),
            'category'        => $request->input('category', 'autre'),
            'order_reference' => $request->input('order_reference'),
            'status'          => 'open',
            'priority'        => 'normal',
        ]);

        // Premier message du ticket
        $msgModel = new SupportMessage();
        $msgModel->create([
            'ticket_id'    => $ticketId,
            'sender_id'    => $this->userId(),
            'message'      => $request->input('message'),
            'is_from_agent'=> 0,
        ]);

        $ticket = $ticketModel->findWithDetails($ticketId);
        $ticket['messages'] = $msgModel->getByTicket($ticketId);

        Response::success($ticket, 'Ticket créé avec succès', 201);
    }

    /**
     * GET /api/support/tickets
     * Client liste ses tickets
     * Query: ?status=open|in_progress|closed
     */
    public function index(Request $request): void
    {
        $ticketModel = new SupportTicket();
        $result = $ticketModel->getByUser(
            $this->userId(),
            $request->page(),
            $request->perPage(),
            $request->query('status')
        );

        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * GET /api/support/tickets/{reference}
     * Client consulte un ticket avec ses messages
     */
    public function show(Request $request): void
    {
        $ticketModel = new SupportTicket();
        $ticket = $ticketModel->findByReference($request->param('reference'));

        if (!$ticket) {
            Response::notFound('Ticket introuvable');
        }

        // Seul le créateur ou un admin peut voir le ticket
        if ((int) $ticket['created_by'] !== $this->userId() && !Auth::isAdmin()) {
            Response::forbidden();
        }

        $msgModel = new SupportMessage();
        $ticket['messages'] = $msgModel->getByTicket((int) $ticket['id']);

        Response::success($ticket);
    }

    /**
     * POST /api/support/tickets/{reference}/messages
     * Client ou admin envoie un message dans un ticket
     * Body: { "message": "...", "media_id": 5 }  (media_id optionnel)
     */
    public function addMessage(Request $request): void
    {
        $ticketModel = new SupportTicket();
        $ticket = $ticketModel->findByReference($request->param('reference'));

        if (!$ticket) {
            Response::notFound('Ticket introuvable');
        }

        if ((int) $ticket['created_by'] !== $this->userId() && !Auth::isAdmin()) {
            Response::forbidden();
        }

        if ($ticket['status'] === 'closed') {
            Response::error('Ce ticket est fermé', 422);
        }

        $message = trim($request->input('message') ?? '');
        $mediaId = $request->input('media_id') ? (int) $request->input('media_id') : null;

        if ($message === '' && $mediaId === null) {
            Response::error('Le message ou une pièce jointe est requis', 422);
        }

        $isAgent = Auth::isAdmin() ? 1 : 0;

        $msgModel = new SupportMessage();
        $msgId = $msgModel->create([
            'ticket_id'    => (int) $ticket['id'],
            'sender_id'    => $this->userId(),
            'message'      => $message,
            'media_id'     => $mediaId,
            'is_from_agent'=> $isAgent,
        ]);

        if ($isAgent && $ticket['status'] === 'open') {
            $ticketModel->update((int) $ticket['id'], [
                'status'      => 'in_progress',
                'assigned_to' => $this->userId(),
            ]);
        }

        $notifyUserId = $isAgent ? (int) $ticket['created_by'] : null;
        if ($isAgent && $notifyUserId) {
            Notification::send(
                $notifyUserId,
                'Réponse à votre ticket',
                'Le service client a répondu à votre ticket : ' . $ticket['subject'],
                'system',
                ['ticket_reference' => $ticket['reference']]
            );
        }

        $db   = \App\Config\Database::getInstance();
        $stmt = $db->prepare("
            SELECT m.*,
                   u.first_name AS sender_first_name, u.last_name AS sender_last_name,
                   sm.file_url AS media_url, sm.file_type AS media_type,
                   sm.file_name AS media_file_name, sm.mime_type AS media_mime_type
            FROM support_messages m
            JOIN users u ON u.id = m.sender_id
            LEFT JOIN media_uploads sm ON sm.id = m.media_id
            WHERE m.id = :id
        ");
        $stmt->execute(['id' => $msgId]);
        Response::success($stmt->fetch(), 'Message envoyé', 201);
    }

    /**
     * POST /api/support/tickets/{reference}/media
     * @deprecated Utiliser POST /api/media/upload à la place (générique)
     */
    public function uploadMedia(Request $request): void
    {
        $ticketModel = new SupportTicket();
        $ticket = $ticketModel->findByReference($request->param('reference'));

        if (!$ticket) {
            Response::notFound('Ticket introuvable');
        }

        if ((int) $ticket['created_by'] !== $this->userId() && !Auth::isAdmin()) {
            Response::forbidden();
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('Fichier manquant ou invalide', 422);
        }

        $file     = $_FILES['file'];
        $mimeType = mime_content_type($file['tmp_name']);
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'video/mp4', 'video/quicktime',
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        if (!in_array($mimeType, $allowedMimes)) {
            Response::error('Type de fichier non autorisé', 422);
        }

        if ($file['size'] > 20 * 1024 * 1024) {
            Response::error('Fichier trop volumineux (max 20MB)', 422);
        }

        $fileType = str_starts_with($mimeType, 'image/') ? 'image'
                  : (str_starts_with($mimeType, 'video/') ? 'video' : 'document');

        $uploadDir = dirname(__DIR__, 3) . '/public_html/uploads/support/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileName = uniqid('sup_', true) . '.' . $ext;
        $filePath = $uploadDir . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            Response::error('Erreur lors de l\'upload', 500);
        }

        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $fileUrl = $baseUrl . '/uploads/support/' . $fileName;

        $db   = \App\Config\Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO support_media (ticket_id, file_name, file_path, file_url, file_type, mime_type, file_size, uploaded_by)
            VALUES (:tid, :fn, :fp, :fu, :ft, :mt, :fs, :uid)
        ");
        $stmt->execute([
            'tid' => (int) $ticket['id'],
            'fn'  => $file['name'],
            'fp'  => $filePath,
            'fu'  => $fileUrl,
            'ft'  => $fileType,
            'mt'  => $mimeType,
            'fs'  => $file['size'],
            'uid' => $this->userId(),
        ]);
        $mediaId = (int) $db->lastInsertId();

        Response::success([
            'id'        => $mediaId,
            'file_url'  => $fileUrl,
            'file_type' => $fileType,
            'file_name' => $file['name'],
            'mime_type' => $mimeType,
            'file_size' => $file['size'],
        ], 'Fichier uploadé', 201);
    }

    // =============================================
    // ADMIN
    // =============================================

    /**
     * GET /api/admin/support/tickets
     * Admin liste tous les tickets
     * Query: ?status=open|in_progress|closed
     */
    public function adminIndex(Request $request): void
    {
        $this->requireRole('admin');

        $ticketModel = new SupportTicket();
        $result = $ticketModel->getAll(
            $request->page(),
            $request->perPage(),
            $request->query('status')
        );

        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * PUT /api/admin/support/tickets/{reference}/status
     * Admin change le statut d'un ticket
     * Body: { "status": "in_progress|closed" }
     */
    public function updateStatus(Request $request): void
    {
        $this->requireRole('admin');
        $request->validate(['status']);

        $ticketModel = new SupportTicket();
        $ticket = $ticketModel->findByReference($request->param('reference'));

        if (!$ticket) {
            Response::notFound('Ticket introuvable');
        }

        $allowed = ['open', 'in_progress', 'closed'];
        $newStatus = $request->input('status');
        if (!in_array($newStatus, $allowed)) {
            Response::error('Statut invalide. Valeurs acceptées : ' . implode(', ', $allowed), 422);
        }

        $data = [
            'status'      => $newStatus,
            'assigned_to' => $this->userId(),
        ];

        if ($newStatus === 'closed') {
            $data['closed_at'] = date('Y-m-d H:i:s');
        }

        $ticketModel->update((int) $ticket['id'], $data);

        // Notifier le client
        Notification::send(
            (int) $ticket['created_by'],
            'Mise à jour de votre ticket',
            'Votre ticket "' . $ticket['subject'] . '" est maintenant : ' . $newStatus,
            'system',
            ['ticket_reference' => $ticket['reference']]
        );

        Response::success($ticketModel->findByReference($ticket['reference']), 'Statut mis à jour');
    }

    /**
     * PUT /api/admin/support/tickets/{reference}/assign
     * Admin assigne un ticket à un agent
     * Body: { "agent_id": 3 }
     */
    public function assign(Request $request): void
    {
        $this->requireRole('admin');
        $request->validate(['agent_id']);

        $ticketModel = new SupportTicket();
        $ticket = $ticketModel->findByReference($request->param('reference'));

        if (!$ticket) {
            Response::notFound('Ticket introuvable');
        }

        $ticketModel->update((int) $ticket['id'], [
            'assigned_to' => (int) $request->input('agent_id'),
            'status'      => 'in_progress',
        ]);

        Response::success($ticketModel->findByReference($ticket['reference']), 'Ticket assigné');
    }
}

