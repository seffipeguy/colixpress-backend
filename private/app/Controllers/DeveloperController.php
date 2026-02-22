<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Core\ApiAuth;
use App\Models\ApiKey;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Shop;
use App\Models\Notification;

class DeveloperController extends Controller
{
    // ==========================================
    // API Key Management (Bearer token auth)
    // ==========================================

    /**
     * POST /api/developer/api-keys
     * Body: { "name": "Mon App", "webhook_url": "https://...", "allowed_ips": "1.2.3.4,5.6.7.8" }
     */
    public function createApiKey(Request $request): void
    {
        $this->requireRole('developer', 'admin');
        $request->validate(['name']);

        $model = new ApiKey();
        $result = $model->createKey(
            $this->userId(),
            $request->input('name'),
            $request->input('webhook_url'),
            $request->input('allowed_ips')
        );

        Response::success([
            'id'         => $result['id'],
            'api_key'    => $result['api_key'],
            'api_secret' => $result['api_secret'],
            'message'    => 'Save your api_secret now. It will not be shown again.',
        ], 'API key created', 201);
    }

    /**
     * GET /api/developer/api-keys
     */
    public function listApiKeys(Request $request): void
    {
        $this->requireRole('developer', 'admin');
        $model = new ApiKey();
        Response::success($model->getByUser($this->userId()));
    }

    /**
     * PUT /api/developer/api-keys/{id}
     * Body: { "name": "...", "webhook_url": "...", "allowed_ips": "...", "is_active": 1, "is_test_mode": 0 }
     */
    public function updateApiKey(Request $request): void
    {
        $this->requireRole('developer', 'admin');

        $model = new ApiKey();
        $key = $model->find((int) $request->param('id'));

        if (!$key || (int) $key['user_id'] !== $this->userId()) {
            Response::notFound('API key not found');
        }

        $allowed = ['name', 'webhook_url', 'allowed_ips', 'is_active', 'is_test_mode', 'rate_limit_per_hour'];
        $data = [];
        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        // Only admin can change rate_limit
        if (isset($data['rate_limit_per_hour']) && !Auth::isAdmin()) {
            unset($data['rate_limit_per_hour']);
        }

        if (!empty($data)) {
            $model->update((int) $key['id'], $data);
        }

        Response::success($model->find((int) $key['id']), 'API key updated');
    }

    /**
     * POST /api/developer/api-keys/{id}/regenerate-secret
     */
    public function regenerateSecret(Request $request): void
    {
        $this->requireRole('developer', 'admin');

        $model = new ApiKey();
        $key = $model->find((int) $request->param('id'));

        if (!$key || (int) $key['user_id'] !== $this->userId()) {
            Response::notFound('API key not found');
        }

        $result = $model->regenerateSecret((int) $key['id']);

        Response::success([
            'api_secret' => $result['api_secret'],
            'message'    => 'Save your new api_secret now. It will not be shown again.',
        ], 'Secret regenerated');
    }

    /**
     * DELETE /api/developer/api-keys/{id}
     */
    public function deleteApiKey(Request $request): void
    {
        $this->requireRole('developer', 'admin');

        $model = new ApiKey();
        $key = $model->find((int) $request->param('id'));

        if (!$key || (int) $key['user_id'] !== $this->userId()) {
            Response::notFound('API key not found');
        }

        $model->update((int) $key['id'], ['is_active' => 0]);
        Response::success(null, 'API key deactivated');
    }

    /**
     * GET /api/developer/api-keys/{id}/stats
     */
    public function apiKeyStats(Request $request): void
    {
        $this->requireRole('developer', 'admin');

        $model = new ApiKey();
        $key = $model->find((int) $request->param('id'));

        if (!$key || (int) $key['user_id'] !== $this->userId()) {
            Response::notFound('API key not found');
        }

        $stats = $model->getStats((int) $key['id']);
        Response::success($stats);
    }

    // ==========================================
    // External API endpoints (API key auth)
    // ==========================================

    /**
     * POST /api/v1/orders
     * Create an order via API key
     */
    public function createOrder(Request $request): void
    {
        $request->validate(['dropoff_address']);

        $orderModel = new Order();
        $orderType = $request->input('order_type', 'direct');
        $apiKeyId = ApiAuth::apiKeyId();
        $developerId = ApiAuth::developerId();

        $data = [
            'reference'            => $orderModel->generateReference(),
            'external_reference'   => $request->input('external_reference'),
            'order_type'           => $orderType,
            'client_id'            => $developerId,
            'api_key_id'           => $apiKeyId,
            'dropoff_address'      => $request->input('dropoff_address'),
            'dropoff_lat'          => $request->input('dropoff_lat'),
            'dropoff_lng'          => $request->input('dropoff_lng'),
            'dropoff_contact_name' => $request->input('dropoff_contact_name'),
            'dropoff_contact_phone'=> $request->input('dropoff_contact_phone'),
            'payment_method'       => $request->input('payment_method', 'cash'),
            'notes'                => $request->input('notes'),
            'scheduled_at'         => $request->input('scheduled_at'),
            'status'               => 'pending',
        ];

        if ($orderType === 'shop') {
            $request->validate(['shop_id']);
            $shopModel = new Shop();
            $shop = $shopModel->find((int) $request->input('shop_id'));
            if (!$shop || !$shop['is_approved']) {
                Response::error('Shop not found or not approved', 404);
            }
            $data['shop_id']              = (int) $shop['id'];
            $data['pickup_address']       = $shop['address'];
            $data['pickup_lat']           = $shop['latitude'];
            $data['pickup_lng']           = $shop['longitude'];
            $data['pickup_contact_name']  = $shop['name'];
            $data['pickup_contact_phone'] = $shop['phone'];
        } else {
            $request->validate(['pickup_address']);
            $data['pickup_address']       = $request->input('pickup_address');
            $data['pickup_lat']           = $request->input('pickup_lat');
            $data['pickup_lng']           = $request->input('pickup_lng');
            $data['pickup_contact_name']  = $request->input('pickup_contact_name');
            $data['pickup_contact_phone'] = $request->input('pickup_contact_phone');
            $data['package_description']  = $request->input('package_description');
            $data['package_size']         = $request->input('package_size');
            $data['package_weight_kg']    = $request->input('package_weight_kg');
        }

        $data['package_value'] = (int) $request->input('package_value', 0);

        // Calculate distance & price
        $distanceKm = (float) $request->input('distance_km', 0);
        if ($distanceKm <= 0 && $data['pickup_lat'] && $data['pickup_lng'] && $data['dropoff_lat'] && $data['dropoff_lng']) {
            $distanceKm = $this->haversineDistance(
                (float) $data['pickup_lat'], (float) $data['pickup_lng'],
                (float) $data['dropoff_lat'], (float) $data['dropoff_lng']
            );
        }

        $data['distance_km'] = round($distanceKm, 2);
        $priceResult = $orderModel->calculatePrice(
            $distanceKm, 'Douala',
            $data['package_size'] ?? null,
            isset($data['package_weight_kg']) ? (float) $data['package_weight_kg'] : null,
            $data['package_value']
        );
        $data['price'] = $priceResult['price'];
        $data['currency'] = 'XAF';

        $items = $request->input('items', []);
        $orderId = $orderModel->create($data);

        // Create order items for shop orders
        $itemsTotal = 0;
        if ($orderType === 'shop' && !empty($items)) {
            $orderItemModel = new OrderItem();
            $orderItemModel->createFromCart($orderId, $items);
            $orderItems = $orderItemModel->getByOrder($orderId);
            foreach ($orderItems as $item) {
                $itemsTotal += (int) $item['total_price'];
            }
            $orderModel->update($orderId, ['price' => $data['price'] + $itemsTotal]);
        }

        $historyModel = new OrderStatusHistory();
        $historyModel->create([
            'order_id'   => $orderId,
            'status'     => 'pending',
            'comment'    => 'Order created via API',
            'changed_by' => $developerId,
        ]);

        $order = $orderModel->findWithDetails($orderId);
        if ($orderType === 'shop') {
            $orderItemModel = $orderItemModel ?? new OrderItem();
            $order['items'] = $orderItemModel->getByOrder($orderId);
        }

        Response::success($order, 'Order created', 201);
    }

    /**
     * GET /api/v1/orders
     * List orders for this API key
     */
    public function listOrders(Request $request): void
    {
        $model = new ApiKey();
        $result = $model->getOrdersByApiKey(
            ApiAuth::apiKeyId(),
            $request->page(),
            $request->perPage(),
            $request->query('status')
        );

        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * GET /api/v1/orders/{reference}
     */
    public function showOrder(Request $request): void
    {
        $orderModel = new Order();
        $order = $orderModel->findWithDetailsByReference($request->param('reference'));

        if (!$order || (int) ($order['api_key_id'] ?? 0) !== ApiAuth::apiKeyId()) {
            Response::notFound('Order not found');
        }

        $historyModel = new OrderStatusHistory();
        $order['status_history'] = $historyModel->getByOrder((int) $order['id']);

        if ($order['order_type'] === 'shop') {
            $orderItemModel = new OrderItem();
            $order['items'] = $orderItemModel->getByOrder((int) $order['id']);
        }

        Response::success($order);
    }

    /**
     * GET /api/v1/orders/by-reference/{reference}
     * Find order by external reference
     */
    public function showOrderByReference(Request $request): void
    {
        $ref = $request->param('reference');
        $orderModel = new Order();

        $stmt = $orderModel->getDb()->prepare("
            SELECT o.*, u.first_name AS client_first_name, u.last_name AS client_last_name
            FROM orders o
            LEFT JOIN users u ON u.id = o.client_id
            WHERE o.external_reference = :ref AND o.api_key_id = :api_key_id
            LIMIT 1
        ");
        $stmt->execute(['ref' => $ref, 'api_key_id' => ApiAuth::apiKeyId()]);
        $order = $stmt->fetch();

        if (!$order) {
            Response::notFound('Order not found');
        }

        Response::success($order);
    }

    /**
     * PUT /api/v1/orders/{reference}/cancel
     */
    public function cancelOrder(Request $request): void
    {
        $orderModel = new Order();
        $order = $orderModel->findByReference($request->param('reference'));

        if (!$order || (int) ($order['api_key_id'] ?? 0) !== ApiAuth::apiKeyId()) {
            Response::notFound('Order not found');
        }

        $cancellable = ['pending', 'accepted'];
        if (!in_array($order['status'], $cancellable)) {
            Response::error('Order cannot be cancelled at this stage', 422);
        }

        $reason = $request->input('cancellation_reason', 'Cancelled via API');
        $orderModel->update((int) $order['id'], ['cancellation_reason' => $reason]);
        $orderModel->updateStatus((int) $order['id'], 'cancelled', ApiAuth::developerId(), $reason);

        Response::success(null, 'Order cancelled');
    }

    /**
     * GET /api/v1/orders/{reference}/tracking
     */
    public function trackOrder(Request $request): void
    {
        $orderModel = new Order();
        $order = $orderModel->findByReference($request->param('reference'));

        if (!$order || (int) ($order['api_key_id'] ?? 0) !== ApiAuth::apiKeyId()) {
            Response::notFound('Order not found');
        }

        $currentPos = null;
        if ($order['livreur_id']) {
            $livreurModel = new \App\Models\LivreurProfile();
            $profile = $livreurModel->findByUserId((int) $order['livreur_id']);
            if ($profile && $profile['current_lat']) {
                $currentPos = [
                    'latitude'   => $profile['current_lat'],
                    'longitude'  => $profile['current_lng'],
                    'updated_at' => $profile['last_location_at'],
                ];
            }
        }

        $locationModel = new \App\Models\LivreurLocation();
        $trail = $locationModel->getTrail((int) $order['id']);

        Response::success([
            'order_status'     => $order['status'],
            'current_position' => $currentPos,
            'trail'            => $trail,
        ]);
    }

    /**
     * GET /api/v1/estimate
     * Query: ?pickup_lat=X&pickup_lng=X&dropoff_lat=X&dropoff_lng=X&city=Douala
     */
    public function estimate(Request $request): void
    {
        $pickupLat  = (float) $request->query('pickup_lat');
        $pickupLng  = (float) $request->query('pickup_lng');
        $dropoffLat = (float) $request->query('dropoff_lat');
        $dropoffLng = (float) $request->query('dropoff_lng');
        $city       = $request->query('city', 'Douala');

        if (!$pickupLat || !$pickupLng || !$dropoffLat || !$dropoffLng) {
            Response::error('All coordinates are required', 422);
        }

        $distanceKm = $this->haversineDistance($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
        $orderModel = new Order();
        $packageSize   = $request->query('package_size');
        $packageWeight = $request->query('package_weight_kg') ? (float) $request->query('package_weight_kg') : null;
        $packageValue  = (int) $request->query('package_value', 0);

        $priceResult = $orderModel->calculatePrice($distanceKm, $city, $packageSize, $packageWeight, $packageValue);

        $response = [
            'distance_km' => round($distanceKm, 2),
            'price'       => $priceResult['price'],
            'currency'    => 'XAF',
            'city'        => $city,
        ];
        if ($priceResult['value_surcharge'] > 0) {
            $response['value_surcharge'] = $priceResult['value_surcharge'];
        }

        Response::success($response);
    }

    /**
     * GET /api/v1/shops
     */
    public function listShops(Request $request): void
    {
        $shopModel = new Shop();
        $result = $shopModel->getApproved(
            $request->page(),
            $request->perPage(),
            $request->query('category_id') ? (int) $request->query('category_id') : null,
            $request->query('city')
        );
        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * GET /api/v1/shops/{id}
     */
    public function showShop(Request $request): void
    {
        $shopModel = new Shop();
        $shop = $shopModel->findWithDetails((int) $request->param('id'));
        if (!$shop) {
            Response::notFound('Shop not found');
        }
        $itemModel = new \App\Models\ShopItem();
        $shop['items'] = $itemModel->getByShop((int) $shop['id']);
        Response::success($shop);
    }

    /**
     * GET /api/v1/countries
     */
    public function countries(Request $request): void
    {
        $model = new \App\Models\Country();
        Response::success($model->getActive());
    }

    /**
     * GET /api/v1/pricing
     */
    public function pricing(Request $request): void
    {
        $model = new \App\Models\PricingRule();
        Response::success($model->getAllActive());
    }

    /**
     * Haversine formula
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
           * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
