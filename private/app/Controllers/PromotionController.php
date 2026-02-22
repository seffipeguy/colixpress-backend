<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Promotion;

class PromotionController extends Controller
{
    /**
     * GET /api/promotions — Admin: all promos
     */
    public function index(Request $request): void
    {
        $this->requireRole('admin');
        $model = new Promotion();
        $result = $model->paginate($request->page(), $request->perPage());
        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * POST /api/promotions — Admin: create promo
     */
    public function store(Request $request): void
    {
        $this->requireRole('admin');
        $request->validate(['code', 'discount_type', 'discount_value']);

        $model = new Promotion();
        $code = strtoupper(trim($request->input('code')));

        if ($model->findByCode($code)) {
            Response::error('Promotion code already exists', 409);
        }

        $id = $model->create([
            'code'              => $code,
            'description'       => $request->input('description'),
            'discount_type'     => $request->input('discount_type'),
            'discount_value'    => (int) $request->input('discount_value'),
            'min_order_amount'  => (int) $request->input('min_order_amount', 0),
            'max_discount'      => (int) $request->input('max_discount', 0),
            'max_uses'          => (int) $request->input('max_uses', 0),
            'max_uses_per_user' => (int) $request->input('max_uses_per_user', 1),
            'valid_from'        => $request->input('valid_from'),
            'valid_until'       => $request->input('valid_until'),
            'applicable_cities' => $request->input('applicable_cities'),
            'is_active'         => (int) $request->input('is_active', 1),
        ]);

        Response::success($model->find($id), 'Promotion created', 201);
    }

    /**
     * GET /api/promotions/{id}
     */
    public function show(Request $request): void
    {
        $this->requireRole('admin');

        $model = new Promotion();
        $promo = $model->find((int) $request->param('id'));

        if (!$promo) {
            Response::notFound('Promotion not found');
        }

        $promo['stats'] = $model->getStats((int) $promo['id']);
        Response::success($promo);
    }

    /**
     * PUT /api/promotions/{id}
     */
    public function update(Request $request): void
    {
        $this->requireRole('admin');

        $model = new Promotion();
        $promo = $model->find((int) $request->param('id'));

        if (!$promo) {
            Response::notFound('Promotion not found');
        }

        $allowed = [
            'description', 'discount_type', 'discount_value',
            'min_order_amount', 'max_discount', 'max_uses',
            'max_uses_per_user', 'valid_from', 'valid_until',
            'applicable_cities', 'is_active',
        ];

        $data = [];
        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if ($request->has('code')) {
            $data['code'] = strtoupper(trim($request->input('code')));
        }

        if (!empty($data)) {
            $model->update((int) $promo['id'], $data);
        }

        Response::success($model->find((int) $promo['id']), 'Promotion updated');
    }

    /**
     * DELETE /api/promotions/{id} — Soft delete (deactivate)
     */
    public function destroy(Request $request): void
    {
        $this->requireRole('admin');

        $model = new Promotion();
        $promo = $model->find((int) $request->param('id'));

        if (!$promo) {
            Response::notFound('Promotion not found');
        }

        $model->update((int) $promo['id'], ['is_active' => 0]);
        Response::success(null, 'Promotion deactivated');
    }

    /**
     * POST /api/promotions/validate
     * Body: { "code": "BIENVENUE", "order_amount": 5000, "city": "Douala" }
     * Any authenticated user can validate a code
     */
    public function validateCode(Request $request): void
    {
        $request->validate(['code']);

        $model = new Promotion();
        $orderAmount = (int) $request->input('order_amount', 0);
        $city = $request->input('city');

        $result = $model->validate(
            $request->input('code'),
            $this->userId(),
            $orderAmount,
            $city
        );

        if (!$result['valid']) {
            Response::error($result['error'], 422);
        }

        Response::success([
            'code'          => $result['promotion']['code'],
            'discount_type' => $result['promotion']['discount_type'],
            'discount_value'=> (int) $result['promotion']['discount_value'],
            'discount'      => $result['discount'],
            'final_amount'  => max(0, $orderAmount - $result['discount']),
        ]);
    }
}
