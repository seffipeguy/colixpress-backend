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
     * Body: { "message": "..." }
     */
    public function addMessage(Request $request): void
    {
        $request->validate(['message']);

        $ticketModel = new SupportTicket();
        $ticket = $ticketModel->findByReference($request->param('reference'));

        if (!$ticket) {
            Response::notFound('Ticket introuvable');
        }

        // Seul le créateur ou un admin peut répondre
        if ((int) $ticket['created_by'] !== $this->userId() && !Auth::isAdmin()) {
            Response::forbidden();
        }

        if ($ticket['status'] === 'closed') {
            Response::error('Ce ticket est fermé', 422);
        }

        $isAgent = Auth::isAdmin() ? 1 : 0;

        $msgModel = new SupportMessage();
        $msgId = $msgModel->create([
            'ticket_id'    => (int) $ticket['id'],
            'sender_id'    => $this->userId(),
            'message'      => $request->input('message'),
            'is_from_agent'=> $isAgent,
        ]);

        // Passer le ticket en "in_progress" si admin répond
        if ($isAgent && $ticket['status'] === 'open') {
            $ticketModel->update((int) $ticket['id'], [
                'status'      => 'in_progress',
                'assigned_to' => $this->userId(),
            ]);
        }

        // Notifier l'autre partie
        $notifyUserId = $isAgent ? (int) $ticket['created_by'] : null;

        // Notifier le client si c'est un agent qui répond
        if ($isAgent && $notifyUserId) {
            Notification::send(
                $notifyUserId,
                'Réponse à votre ticket',
                'Le service client a répondu à votre ticket : ' . $ticket['subject'],
                'system',
                ['ticket_reference' => $ticket['reference']]
            );
        }

        $message = $msgModel->find($msgId);
        Response::success($message, 'Message envoyé', 201);
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
