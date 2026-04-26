<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Company;
use App\Models\Order;

class DispatcherController
{
    private function requireDispatcher(): void
    {
        if (!Auth::check()) {
            Response::unauthorized();
        }
        $role = Auth::user()['role'] ?? '';
        if (!in_array($role, ['dispatcher', 'admin'])) {
            Response::forbidden('Accès réservé aux dispatchers');
        }
    }

    /**
     * GET /api/dispatch/pool
     * Toutes les commandes non encore claimed (visibles par tous les dispatchers)
     */
    public function pool(Request $request): void
    {
        $this->requireDispatcher();

        $db   = \App\Core\Database::getInstance();
        $page = max(1, (int) ($request->query('page') ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $stmt = $db->prepare("
            SELECT o.id, o.reference, o.status, o.created_at,
                   o.pickup_address, o.delivery_address,
                   o.total_price, o.payment_method,
                   CONCAT(u.first_name, ' ', u.last_name) AS client_name,
                   u.phone AS client_phone
            FROM orders o
            JOIN users u ON u.id = o.client_id
            WHERE o.claimed_by IS NULL
              AND o.status NOT IN ('cancelled', 'delivered')
            ORDER BY o.created_at ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit',  $limit,  \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $orders = $stmt->fetchAll();

        $total = $db->query("SELECT COUNT(*) FROM orders WHERE claimed_by IS NULL AND status NOT IN ('cancelled','delivered')")->fetchColumn();

        Response::success([
            'orders'     => $orders,
            'total'      => (int) $total,
            'page'       => $page,
            'total_pages' => (int) ceil($total / $limit),
        ]);
    }

    /**
     * POST /api/dispatch/orders/{id}/claim
     * Le dispatcher prend une commande du pool (atomique)
     */
    public function claim(Request $request): void
    {
        $this->requireDispatcher();

        $orderId     = (int) $request->param('id');
        $dispatcherId = Auth::userId();
        $db          = \App\Core\Database::getInstance();

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT id, claimed_by FROM orders WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch();

            if (!$order) {
                $db->rollBack();
                Response::notFound('Commande introuvable');
            }

            if ($order['claimed_by'] !== null) {
                $db->rollBack();
                $who = $db->prepare("SELECT CONCAT(first_name,' ',last_name) as name FROM users WHERE id = ?");
                $who->execute([$order['claimed_by']]);
                $name = $who->fetchColumn();
                Response::error("Commande déjà prise par $name", 409);
            }

            // Récupérer l'entreprise du dispatcher
            $companyStmt = $db->prepare("SELECT company_id FROM company_users WHERE user_id = :uid AND is_active = 1 LIMIT 1");
            $companyStmt->execute(['uid' => $dispatcherId]);
            $companyId = $companyStmt->fetchColumn() ?: null;

            $db->prepare("UPDATE orders SET claimed_by = :did, claimed_at = NOW(), dispatching_company_id = :cid WHERE id = :id")
               ->execute(['did' => $dispatcherId, 'cid' => $companyId, 'id' => $orderId]);

            $db->prepare("INSERT INTO order_claims (order_id, claimed_by) VALUES (:oid, :did)")
               ->execute(['oid' => $orderId, 'did' => $dispatcherId]);

            $db->commit();
            Response::success(['order_id' => $orderId, 'company_id' => $companyId], 'Commande prise avec succès', 200);
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::error('Erreur lors du claim : ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/dispatch/orders/{id}/release
     * Le dispatcher relâche la commande → retour au pool
     */
    public function release(Request $request): void
    {
        $this->requireDispatcher();

        $orderId      = (int) $request->param('id');
        $dispatcherId = Auth::userId();
        $reason       = $request->input('reason');
        $db           = \App\Core\Database::getInstance();

        $stmt = $db->prepare("SELECT id, claimed_by FROM orders WHERE id = :id");
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            Response::notFound('Commande introuvable');
        }
        if ((int) $order['claimed_by'] !== $dispatcherId && !Auth::isAdmin()) {
            Response::forbidden('Vous ne pouvez pas relâcher une commande que vous ne gérez pas');
        }

        $db->prepare("UPDATE orders SET claimed_by = NULL, claimed_at = NULL, dispatching_company_id = NULL WHERE id = :id")
           ->execute(['id' => $orderId]);

        $db->prepare("
            UPDATE order_claims
            SET released_by = :rid, released_at = NOW(), release_reason = :reason
            WHERE order_id = :oid AND released_at IS NULL
        ")->execute(['rid' => $dispatcherId, 'oid' => $orderId, 'reason' => $reason]);

        Response::success(null, 'Commande remise dans le pool');
    }

    /**
     * POST /api/dispatch/orders/{id}/assign
     * Enregistrer une note d'assignation sur une commande claimed
     * Body: { "notes": "..." }
     */
    public function assign(Request $request): void
    {
        $this->requireDispatcher();

        $orderId      = (int) $request->param('id');
        $notes        = $request->input('notes');
        $dispatcherId = Auth::userId();
        $db           = \App\Core\Database::getInstance();

        $stmt = $db->prepare("SELECT id, claimed_by, status FROM orders WHERE id = :id");
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            Response::notFound('Commande introuvable');
        }
        if ((int) $order['claimed_by'] !== $dispatcherId && !Auth::isAdmin()) {
            Response::forbidden('Vous devez d\'abord claim cette commande');
        }

        $db->prepare("UPDATE orders SET dispatcher_notes = :notes, status = 'accepted' WHERE id = :oid")
           ->execute(['notes' => $notes, 'oid' => $orderId]);

        Response::success(['order_id' => $orderId], 'Commande assignée avec succès');
    }

    /**
     * GET /api/dispatch/orders/mine
     * Commandes claimed par le dispatcher connecté
     */
    public function myClaimed(Request $request): void
    {
        $this->requireDispatcher();

        $dispatcherId = Auth::userId();
        $db           = \App\Core\Database::getInstance();

        $stmt = $db->prepare("
            SELECT o.id, o.reference, o.status, o.claimed_at,
                   o.pickup_address, o.delivery_address, o.total_price,
                   CONCAT(u.first_name,' ',u.last_name) AS client_name,
                   u.phone AS client_phone,
                   o.dispatcher_notes
            FROM orders o
            JOIN users u ON u.id = o.client_id
            WHERE o.claimed_by = :did
              AND o.status NOT IN ('cancelled','delivered')
            ORDER BY o.claimed_at DESC
        ");
        $stmt->execute(['did' => $dispatcherId]);
        Response::success($stmt->fetchAll());
    }

    /**
     * PATCH /api/dispatch/orders/{id}/notes
     * Ajouter une note interne dispatcher sur une commande
     * Body: { "notes": "..." }
     */
    public function updateNotes(Request $request): void
    {
        $this->requireDispatcher();

        $orderId      = (int) $request->param('id');
        $notes        = $request->input('notes');
        $dispatcherId = Auth::userId();
        $db           = \App\Core\Database::getInstance();

        $stmt = $db->prepare("SELECT claimed_by FROM orders WHERE id = :id");
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            Response::notFound('Commande introuvable');
        }
        if ((int) $order['claimed_by'] !== $dispatcherId && !Auth::isAdmin()) {
            Response::forbidden();
        }

        $db->prepare("UPDATE orders SET dispatcher_notes = :notes WHERE id = :id")
           ->execute(['notes' => $notes, 'id' => $orderId]);

        Response::success(null, 'Note mise à jour');
    }

    /**
     * GET /api/dispatch/companies
     * Liste toutes les entreprises enregistrées
     */
    public function companies(Request $request): void
    {
        $this->requireDispatcher();

        $company = new Company();
        Response::success($company->all());
    }
}
