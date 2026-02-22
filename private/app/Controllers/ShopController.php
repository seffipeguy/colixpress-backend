<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\Models\ShopItem;

class ShopController extends Controller
{
    /**
     * GET /api/shops
     * Query: ?category_id=1&city=Douala&page=1&per_page=20
     */
    public function index(Request $request): void
    {
        $model = new Shop();
        $result = $model->getApproved(
            $request->page(),
            $request->perPage(),
            $request->query('category_id') ? (int) $request->query('category_id') : null,
            $request->query('city')
        );
        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * GET /api/shops/popular
     * Query: ?limit=10&category_id=1&city=Douala
     */
    public function popular(Request $request): void
    {
        $model = new Shop();
        $limit = (int) $request->query('limit', 10);
        $limit = max(1, min($limit, 50));

        $shops = $model->getPopular(
            $limit,
            $request->query('category_id') ? (int) $request->query('category_id') : null,
            $request->query('city')
        );

        Response::success($shops);
    }

    /**
     * GET /api/shops/{id}
     */
    public function show(Request $request): void
    {
        $model = new Shop();
        $shop = $model->findWithDetails((int) $request->param('id'));

        if (!$shop) {
            Response::notFound('Shop not found');
        }

        // Include items
        $itemModel = new ShopItem();
        $shop['items'] = $itemModel->getByShop((int) $shop['id']);

        Response::success($shop);
    }

    /**
     * POST /api/shops — Shop owner creates a shop
     */
    public function store(Request $request): void
    {
        $this->requireRole('shop_owner', 'admin');
        $request->validate(['name', 'address', 'country_id', 'phone']);

        $model = new Shop();
        $id = $model->create([
            'owner_id'     => $this->userId(),
            'name'         => $request->input('name'),
            'description'  => $request->input('description'),
            'category_id'  => $request->input('category_id'),
            'address'      => $request->input('address'),
            'latitude'     => $request->input('latitude'),
            'longitude'    => $request->input('longitude'),
            'city'         => $request->input('city', 'Douala'),
            'quarter'      => $request->input('quarter'),
            'country_id'   => (int) $request->input('country_id'),
            'phone'        => $request->input('phone'),
            'opening_time' => $request->input('opening_time'),
            'closing_time' => $request->input('closing_time'),
        ]);

        Response::success($model->findWithDetails($id), 'Shop created, pending approval', 201);
    }

    /**
     * PUT /api/shops/{id}
     */
    public function update(Request $request): void
    {
        $model = new Shop();
        $shop = $model->find((int) $request->param('id'));

        if (!$shop) {
            Response::notFound('Shop not found');
        }

        if ((int) $shop['owner_id'] !== $this->userId() && !Auth::isAdmin()) {
            Response::forbidden();
        }

        $allowed = ['name', 'description', 'category_id', 'address', 'latitude', 'longitude', 'city', 'quarter', 'phone', 'opening_time', 'closing_time'];
        $data = [];
        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if (!empty($data)) {
            $model->update((int) $shop['id'], $data);
        }

        Response::success($model->findWithDetails((int) $shop['id']), 'Shop updated');
    }

    /**
     * GET /api/shops/my — Shop owner's shops
     */
    public function myShops(Request $request): void
    {
        $this->requireRole('shop_owner', 'admin');
        $model = new Shop();
        Response::success($model->getByOwner($this->userId()));
    }

    /**
     * GET /api/shop-categories
     */
    public function categories(Request $request): void
    {
        $model = new ShopCategory();
        Response::success($model->getActive());
    }

    /**
     * PUT /api/shops/{id}/approve — Admin approves a shop
     */
    public function approve(Request $request): void
    {
        $this->requireRole('admin');

        $model = new Shop();
        $shop = $model->find((int) $request->param('id'));

        if (!$shop) {
            Response::notFound('Shop not found');
        }

        $model->update((int) $shop['id'], ['is_approved' => 1]);
        Response::success(null, 'Shop approved');
    }
}
