<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\Shop;
use App\Models\ShopItem;

class ShopItemController extends Controller
{
    /**
     * GET /api/shops/{shop_id}/items
     */
    public function index(Request $request): void
    {
        $model = new ShopItem();
        $items = $model->getByShop((int) $request->param('shop_id'));
        Response::success($items);
    }

    /**
     * POST /api/shops/{shop_id}/items
     */
    public function store(Request $request): void
    {
        $shopId = (int) $request->param('shop_id');
        $this->authorizeShopOwner($shopId);
        $request->validate(['name', 'price']);

        $model = new ShopItem();
        $id = $model->create([
            'shop_id'      => $shopId,
            'name'         => $request->input('name'),
            'description'  => $request->input('description'),
            'price'        => (int) $request->input('price'),
            'photo'        => $request->input('photo'),
            'category'     => $request->input('category'),
            'is_available' => $request->input('is_available', 1),
            'sort_order'   => (int) $request->input('sort_order', 0),
        ]);

        Response::success($model->find($id), 'Item created', 201);
    }

    /**
     * PUT /api/shops/{shop_id}/items/{id}
     */
    public function update(Request $request): void
    {
        $shopId = (int) $request->param('shop_id');
        $this->authorizeShopOwner($shopId);

        $model = new ShopItem();
        $item = $model->find((int) $request->param('id'));

        if (!$item || (int) $item['shop_id'] !== $shopId) {
            Response::notFound('Item not found');
        }

        $allowed = ['name', 'description', 'price', 'photo', 'category', 'is_available', 'sort_order'];
        $data = [];
        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if (!empty($data)) {
            $model->update((int) $item['id'], $data);
        }

        Response::success($model->find((int) $item['id']), 'Item updated');
    }

    /**
     * DELETE /api/shops/{shop_id}/items/{id}
     */
    public function destroy(Request $request): void
    {
        $shopId = (int) $request->param('shop_id');
        $this->authorizeShopOwner($shopId);

        $model = new ShopItem();
        $item = $model->find((int) $request->param('id'));

        if (!$item || (int) $item['shop_id'] !== $shopId) {
            Response::notFound('Item not found');
        }

        $model->delete((int) $item['id']);
        Response::success(null, 'Item deleted');
    }

    private function authorizeShopOwner(int $shopId): void
    {
        $shopModel = new Shop();
        $shop = $shopModel->find($shopId);

        if (!$shop) {
            Response::notFound('Shop not found');
        }

        if ((int) $shop['owner_id'] !== $this->userId() && !Auth::isAdmin()) {
            Response::forbidden('You do not own this shop');
        }
    }
}
