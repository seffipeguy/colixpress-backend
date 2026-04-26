<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Tip;

class TipController extends Controller
{
    /**
     * GET /api/tips?page=/orders/create
     * Récupérer les tips actifs pour la page courante
     */
    public function index(Request $request): void
    {
        $userId = $this->userId();
        if (!$userId) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $page = $request->query('page');
        if (!$page) {
            Response::error('Paramètre page requis', 422);
            return;
        }

        $tipModel = new Tip();
        $tips = $tipModel->getActiveForPage($page, $userId, $this->userRole());

        // Formater la réponse (ne garder que l'essentiel)
        $formatted = array_map(fn($t) => [
            'id' => (int) $t['id'],
            'tip_key' => $t['tip_key'],
            'title' => $t['title'],
            'html_content' => $t['html_content'],
            'frequency' => $t['frequency'],
        ], $tips);

        Response::success($formatted);
    }

    /**
     * POST /api/tips/{id}/seen
     * Marquer un tip comme vu par l'utilisateur
     * Body: { "dismissed": true/false, "completed": true/false }
     */
    public function markSeen(Request $request): void
    {
        $userId = $this->userId();
        if (!$userId) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $tipId = (int) $request->param('id');
        $dismissed = $request->input('dismissed', false);
        $completed = $request->input('completed', false);

        $tipModel = new Tip();
        $success = $tipModel->markAsSeen($tipId, $userId, (bool) $dismissed, (bool) $completed);

        if ($success) {
            Response::success(null, 'Tip marqué comme vu');
        } else {
            Response::error('Erreur lors de l\'enregistrement', 500);
        }
    }

    /**
     * GET /api/tips/history
     * Historique des tips vus par l'utilisateur courant
     */
    public function history(Request $request): void
    {
        $userId = $this->userId();
        if (!$userId) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $tipModel = new Tip();
        $history = $tipModel->getSeenByUser($userId);

        Response::success($history);
    }

    /**
     * DELETE /api/tips/reset
     * Réinitialiser tous les tips vus (refaire le tour complet)
     */
    public function reset(Request $request): void
    {
        $userId = $this->userId();
        if (!$userId) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $tipModel = new Tip();
        $tipModel->resetForUser($userId);

        Response::success(null, 'Tips réinitialisés');
    }

    // ============================================
    // ADMIN ENDPOINTS (pour gérer les tips)
    // ============================================

    /**
     * GET /api/admin/tips
     * Lister tous les tips (admin only)
     */
    public function adminList(Request $request): void
    {
        $this->requireRole('admin');

        $tipModel = new Tip();
        $tips = $tipModel->all();

        Response::success($tips);
    }

    /**
     * POST /api/admin/tips
     * Créer un nouveau tip (admin only)
     */
    public function adminCreate(Request $request): void
    {
        $this->requireRole('admin');

        $request->validate(['page_route', 'tip_key', 'html_content']);

        $tipModel = new Tip();

        // Vérifier si le tip_key existe déjà
        $existing = $tipModel->findBy('tip_key', $request->input('tip_key'));
        if ($existing) {
            Response::error('Ce tip_key existe déjà', 409);
            return;
        }

        $data = [
            'page_route' => $request->input('page_route'),
            'tip_key' => $request->input('tip_key'),
            'title' => $request->input('title'),
            'html_content' => $request->input('html_content'),
            'frequency' => $request->input('frequency', 'once'),
            'priority' => (int) $request->input('priority', 0),
            'target_roles' => $request->input('target_roles'), // JSON array
            'is_active' => $request->input('is_active', 1),
        ];

        $id = $tipModel->create($data);
        $tip = $tipModel->find($id);

        Response::success($tip, 'Tip créé', 201);
    }

    /**
     * PUT /api/admin/tips/{id}
     * Modifier un tip (admin only)
     */
    public function adminUpdate(Request $request): void
    {
        $this->requireRole('admin');

        $tipModel = new Tip();
        $tip = $tipModel->find((int) $request->param('id'));

        if (!$tip) {
            Response::notFound('Tip non trouvé');
        }

        $updatable = [];
        $fields = ['page_route', 'tip_key', 'title', 'html_content', 'frequency', 'priority', 'target_roles', 'is_active'];

        foreach ($fields as $field) {
            if ($request->input($field) !== null) {
                $updatable[$field] = $request->input($field);
            }
        }

        if (isset($updatable['priority'])) {
            $updatable['priority'] = (int) $updatable['priority'];
        }
        if (isset($updatable['is_active'])) {
            $updatable['is_active'] = (int) $updatable['is_active'];
        }

        if (empty($updatable)) {
            Response::error('Aucun champ à mettre à jour', 422);
        }

        $tipModel->update((int) $tip['id'], $updatable);
        $updated = $tipModel->find((int) $tip['id']);

        Response::success($updated, 'Tip mis à jour');
    }

    /**
     * DELETE /api/admin/tips/{id}
     * Supprimer un tip (admin only)
     */
    public function adminDelete(Request $request): void
    {
        $this->requireRole('admin');

        $tipModel = new Tip();
        $tip = $tipModel->find((int) $request->param('id'));

        if (!$tip) {
            Response::notFound('Tip non trouvé');
        }

        $tipModel->delete((int) $tip['id']);
        Response::success(null, 'Tip supprimé');
    }
}
