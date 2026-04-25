<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Models\Country;

class AdminPaymentController extends Controller
{
    /**
     * GET /api/admin/payment/providers
     * Liste tous les providers (admin)
     */
    public function index(Request $request): void
    {
        $this->requireRole('admin');

        $model = new PaymentProvider();
        $providers = $model->all();

        Response::success($providers);
    }

    /**
     * POST /api/admin/payment/providers
     * Créer un nouveau provider
     */
    public function store(Request $request): void
    {
        $this->requireRole('admin');
        $request->validate(['code', 'name', 'provider_type']);

        $model = new PaymentProvider();
        
        // Vérifier si le code existe déjà
        if ($model->findByCode($request->input('code'))) {
            Response::error('Provider code already exists', 409);
        }

        $data = [
            'code' => $request->input('code'),
            'name' => $request->input('name'),
            'provider_type' => $request->input('provider_type'),
            'logo_url' => $request->input('logo_url'),
            'api_base_url' => $request->input('api_base_url'),
            'api_version' => $request->input('api_version'),
            'api_username' => $request->input('api_username'),
            'api_password' => $request->input('api_password'),
            'api_token' => $request->input('api_token'),
            'webhook_secret' => $request->input('webhook_secret'),
            'extra_config' => $request->input('extra_config') ? json_encode($request->input('extra_config')) : null,
            'is_active' => (int) $request->input('is_active', 1),
            'is_sandbox' => (int) $request->input('is_sandbox', 0),
            'min_amount' => (int) $request->input('min_amount', 100),
            'max_amount' => (int) $request->input('max_amount', 5000000),
            'transaction_fee_percent' => (float) $request->input('transaction_fee_percent', 0),
            'transaction_fee_fixed' => (int) $request->input('transaction_fee_fixed', 0),
            'description' => $request->input('description'),
            'instructions' => $request->input('instructions'),
        ];

        $id = $model->create($data);
        $provider = $model->find($id);

        Response::success($provider, 'Provider created', 201);
    }

    /**
     * GET /api/admin/payment/providers/{id}
     * Détails d'un provider
     */
    public function show(Request $request, int $id): void
    {
        $this->requireRole('admin');

        $model = new PaymentProvider();
        $provider = $model->find($id);

        if (!$provider) {
            Response::notFound('Provider not found');
        }

        // Récupérer les pays liés
        $provider['countries'] = $model->getLinkedCountries($id);

        Response::success($provider);
    }

    /**
     * PUT /api/admin/payment/providers/{id}
     * Modifier un provider
     */
    public function update(Request $request, int $id): void
    {
        $this->requireRole('admin');

        $model = new PaymentProvider();
        $provider = $model->find($id);

        if (!$provider) {
            Response::notFound('Provider not found');
        }

        $data = [];
        
        $fields = [
            'name', 'provider_type', 'logo_url', 'api_base_url', 'api_version',
            'api_username', 'api_password', 'api_token', 'webhook_secret',
            'is_active', 'is_sandbox', 'min_amount', 'max_amount',
            'transaction_fee_percent', 'transaction_fee_fixed',
            'description', 'instructions'
        ];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if ($request->has('extra_config')) {
            $data['extra_config'] = json_encode($request->input('extra_config'));
        }

        $model->update($id, $data);
        $updated = $model->find($id);

        Response::success($updated, 'Provider updated');
    }

    /**
     * DELETE /api/admin/payment/providers/{id}
     * Supprimer un provider
     */
    public function destroy(Request $request, int $id): void
    {
        $this->requireRole('admin');

        $model = new PaymentProvider();
        $provider = $model->find($id);

        if (!$provider) {
            Response::notFound('Provider not found');
        }

        // Vérifier s'il y a des transactions
        $txModel = new PaymentTransaction();
        $stmt = $txModel->db->prepare("SELECT COUNT(*) as count FROM payment_transactions WHERE provider_id = :id");
        $stmt->execute(['id' => $id]);
        $count = $stmt->fetch()['count'];

        if ($count > 0) {
            Response::error('Cannot delete provider with existing transactions', 409);
        }

        $model->delete($id);
        Response::success(null, 'Provider deleted');
    }

    /**
     * GET /api/admin/payment/providers/{id}/countries
     * Liste des pays liés à un provider
     */
    public function countries(Request $request, int $id): void
    {
        $this->requireRole('admin');

        $model = new PaymentProvider();
        $countries = $model->getLinkedCountries($id);

        Response::success($countries);
    }

    /**
     * POST /api/admin/payment/providers/{id}/countries
     * Lier un provider à un pays
     * Body: { "country_id": 1, "is_default": true, "display_order": 1 }
     */
    public function linkCountry(Request $request, int $id): void
    {
        $this->requireRole('admin');
        $request->validate(['country_id']);

        $model = new PaymentProvider();
        $provider = $model->find($id);

        if (!$provider) {
            Response::notFound('Provider not found');
        }

        $countryId = (int) $request->input('country_id');
        $isDefault = (bool) $request->input('is_default', false);
        $displayOrder = (int) $request->input('display_order', 0);

        $model->linkToCountry($id, $countryId, $isDefault, $displayOrder);

        Response::success(null, 'Provider linked to country');
    }

    /**
     * DELETE /api/admin/payment/providers/{id}/countries/{country_id}
     * Délier un provider d'un pays
     */
    public function unlinkCountry(Request $request, int $id, int $countryId): void
    {
        $this->requireRole('admin');

        $model = new PaymentProvider();
        $model->unlinkFromCountry($id, $countryId);

        Response::success(null, 'Provider unlinked from country');
    }

    /**
     * PUT /api/admin/payment/providers/{id}/countries/{country_id}/default
     * Définir comme provider par défaut pour un pays
     */
    public function setDefaultCountry(Request $request, int $id, int $countryId): void
    {
        $this->requireRole('admin');

        $model = new PaymentProvider();
        $model->setAsDefault($id, $countryId);

        Response::success(null, 'Provider set as default for country');
    }

    /**
     * GET /api/admin/payment/transactions
     * Liste toutes les transactions (admin)
     */
    public function transactions(Request $request): void
    {
        $this->requireRole('admin');

        $model = new PaymentTransaction();
        $limit = (int) $request->query('limit', 50);
        $providerId = $request->query('provider_id') ? (int) $request->query('provider_id') : null;

        $transactions = $model->getRecent($limit, $providerId);

        Response::success($transactions);
    }

    /**
     * POST /api/admin/payment/transactions/{reference}/check-status
     * Forcer la vérification du statut auprès du provider
     */
    public function checkStatus(Request $request, string $reference): void
    {
        $service = new PaymentService();
        $result = $service->checkPaymentStatus($reference);

        if (!$result['success']) {
            Response::error($result['message'], 404);
        }

        Response::success([
            'message' => 'Status checked successfully',
            'transaction' => $result['transaction'],
        ]);
    }

    /**
     * POST /api/admin/payment/reconcile
     * Réconcilier toutes les transactions en attente
     */
    public function reconcile(Request $request): void
    {
        $hours = $request->input('hours', 24);
        
        $db = \App\Config\Database::getInstance();
        $stmt = $db->prepare("
            SELECT * FROM payment_transactions 
            WHERE status IN ('pending', 'processing')
            AND initiated_at > DATE_SUB(NOW(), INTERVAL :hours HOUR)
            ORDER BY initiated_at DESC
        ");
        $stmt->execute(['hours' => $hours]);
        $transactions = $stmt->fetchAll();

        $service = new PaymentService();
        $results = [
            'checked' => 0,
            'completed' => 0,
            'failed' => 0,
            'still_pending' => 0,
            'errors' => 0,
            'details' => [],
        ];

        foreach ($transactions as $transaction) {
            $results['checked']++;
            
            try {
                $result = $service->checkPaymentStatus($transaction['reference']);
                
                if ($result['success']) {
                    $newStatus = $result['transaction']['status'];
                    
                    if ($newStatus === 'completed') {
                        $results['completed']++;
                    } elseif ($newStatus === 'failed') {
                        $results['failed']++;
                    } else {
                        $results['still_pending']++;
                    }
                    
                    $results['details'][] = [
                        'reference' => $transaction['reference'],
                        'old_status' => $transaction['status'],
                        'new_status' => $newStatus,
                    ];
                } else {
                    $results['errors']++;
                    $results['details'][] = [
                        'reference' => $transaction['reference'],
                        'error' => $result['message'],
                    ];
                }
            } catch (\Exception $e) {
                $results['errors']++;
                $results['details'][] = [
                    'reference' => $transaction['reference'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        Response::success($results, 'Reconciliation completed');
    }

    /**
     * GET /api/admin/payment/stats
     * Statistiques des paiements
     */
    public function stats(Request $request): void
    {
        $this->requireRole('admin');

        $model = new PaymentTransaction();
        
        $providerId = $request->query('provider_id') ? (int) $request->query('provider_id') : null;
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $stats = $model->getStats($providerId, $startDate, $endDate);

        Response::success($stats);
    }
}
