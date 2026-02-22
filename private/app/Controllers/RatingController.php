<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Rating;
use App\Models\Order;

class RatingController extends Controller
{
    /**
     * POST /api/orders/{order_id}/rating
     * Body: { "score": 5, "comment": "Excellent livreur!" }
     */
    public function store(Request $request): void
    {
        $request->validate(['score']);

        $orderId = (int) $request->param('order_id');
        $score = (int) $request->input('score');

        if ($score < 1 || $score > 5) {
            Response::error('Score must be between 1 and 5', 422);
        }

        // Verify order exists and is delivered
        $orderModel = new Order();
        $order = $orderModel->find($orderId);

        if (!$order) {
            Response::notFound('Order not found');
        }

        if ($order['status'] !== 'delivered') {
            Response::error('Can only rate delivered orders', 422);
        }

        if ((int) $order['client_id'] !== $this->userId()) {
            Response::forbidden('Only the client can rate this order');
        }

        if (!$order['livreur_id']) {
            Response::error('No livreur assigned to this order', 422);
        }

        // Check if already rated
        $ratingModel = new Rating();
        if ($ratingModel->findByOrderAndUser($orderId, $this->userId())) {
            Response::error('You have already rated this order', 422);
        }

        $id = $ratingModel->create([
            'order_id'   => $orderId,
            'rated_by'   => $this->userId(),
            'rated_user' => (int) $order['livreur_id'],
            'score'      => $score,
            'comment'    => $request->input('comment'),
        ]);

        // Recalculate average rating
        $ratingModel->recalculateAverage((int) $order['livreur_id']);

        Response::success($ratingModel->find($id), 'Rating submitted', 201);
    }

    /**
     * GET /api/livreur/{livreur_id}/ratings
     */
    public function livreurRatings(Request $request): void
    {
        $ratingModel = new Rating();
        $ratings = $ratingModel->getForUser((int) $request->param('livreur_id'));
        Response::success($ratings);
    }
}
