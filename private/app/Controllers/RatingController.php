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
     * POST /api/orders/{reference}/rating
     * Body: { "score": 5, "comment": "Excellent livreur!" }
     */
    public function store(Request $request): void
    {
        $request->validate(['score']);

        $reference = $request->param('reference');
        $score = (int) $request->input('score');

        if ($score < 1 || $score > 5) {
            Response::error('Score must be between 1 and 5', 422);
        }

        // Verify order exists and is delivered
        $orderModel = new Order();
        $order = $orderModel->findByReference($reference);

        if (!$order) {
            Response::notFound('Order not found');
        }

        $orderId = (int) $order['id'];

        if ($order['status'] !== 'delivered') {
            Response::error('Can only rate delivered orders', 422);
        }

        if ((int) $order['client_id'] !== $this->userId()) {
            Response::forbidden('Only the client can rate this order');
        }

        // Check if already rated
        $ratingModel = new Rating();
        if ($ratingModel->findByOrderAndUser($orderId, $this->userId())) {
            Response::error('You have already rated this order', 422);
        }

        $id = $ratingModel->create([
            'order_id'   => $orderId,
            'rated_by'   => $this->userId(),
            'rated_user' => (int) ($order['claimed_by'] ?? 0),
            'score'      => $score,
            'comment'    => $request->input('comment'),
        ]);

        Response::success($ratingModel->find($id), 'Rating submitted', 201);
    }
}
