<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\OrderCart;

class CartController extends Controller
{
    /**
     * GET /api/carts
     */
    public function index(Request $request): void
    {
        $cartModel = new OrderCart();
        $result    = $cartModel->getByClient($this->userId(), $request->page(), $request->perPage());

        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * POST /api/carts
     */
    public function store(Request $request): void
    {
        $cartModel = new OrderCart();

        $id   = $cartModel->create([
            'reference' => $cartModel->generateReference(),
            'client_id' => $this->userId(),
            'status'    => 'open',
            'notes'     => $request->input('notes'),
        ]);

        $cart = $cartModel->find($id);

        Response::success($cart, 'Panier créé', 201);
    }

    /**
     * GET /api/carts/{reference}
     */
    public function show(Request $request): void
    {
        $cartModel = new OrderCart();
        $cart      = $cartModel->findByReference($request->param('reference'));

        if (!$cart) {
            Response::notFound('Panier introuvable');
        }

        if ((int) $cart['client_id'] !== $this->userId()) {
            Response::forbidden();
        }

        $stats        = $cartModel->getOrdersWithStats((int) $cart['id']);
        $cart['orders']       = $stats['orders'];
        $cart['total_orders'] = $stats['total_orders'];
        $cart['total_price']  = $stats['total_price'];
        $cart['status_count'] = $stats['status_count'];

        Response::success($cart);
    }

    /**
     * POST /api/carts/{reference}/close
     */
    public function close(Request $request): void
    {
        $cartModel = new OrderCart();
        $cart      = $cartModel->findByReference($request->param('reference'));

        if (!$cart) {
            Response::notFound('Panier introuvable');
        }

        if ((int) $cart['client_id'] !== $this->userId()) {
            Response::forbidden();
        }

        if ($cart['status'] === 'closed') {
            Response::error('Ce panier est déjà fermé', 422);
        }

        $cartModel->update((int) $cart['id'], ['status' => 'closed']);

        Response::success(null, 'Panier fermé');
    }
}
