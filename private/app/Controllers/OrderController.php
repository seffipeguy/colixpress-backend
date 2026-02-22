<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\LivreurProfile;
use App\Models\Notification;
use App\Models\Shop;

class OrderController extends Controller
{
    /**
     * GET /api/tracking/{reference}
     * Public order tracking
     */
    public function publicTracking(Request $request): void
    {
        $reference = $request->param('reference');
        $orderModel = new Order();
        $order = $orderModel->findWithDetailsByReference($reference);

        if (!$order) {
            Response::notFound('Order not found');
        }

        // Get items if any
        $orderItemModel = new OrderItem();
        $order['items'] = $orderItemModel->getByOrder($order['id']);

        // Get livreur location
        if (!empty($order['livreur_id'])) {
            $livreurModel = new LivreurProfile();
            $livreurProfile = $livreurModel->findWithDetails($order['livreur_id']);
            
            if ($livreurProfile) {
                $order['livreur_location'] = [
                    'current_lat' => $livreurProfile['current_lat'],
                    'current_lng' => $livreurProfile['current_lng'],
                    'last_location_at' => $livreurProfile['last_location_at']
                ];
            }
        }

        // Get status history
        $historyModel = new OrderStatusHistory();
        $order['status_history'] = $historyModel->getByOrder($order['id']);

        // Remove sensitive fields if necessary, but user requested "all infos"
        // We will remove only strictly internal/sensitive fields like passwords or unrelated IDs if present in joined tables
        // Since findWithDetailsByReference joins with users table, we should be careful not to expose password hashes if they were selected (they are usually not in findWithDetailsBy unless explicitly selected or * is used on users table)
        // Looking at Order::findWithDetailsBy, it selects:
        // o.*, uc.first_name, uc.last_name, uc.phone, ul.first_name, ul.last_name, ul.phone, s.name
        // So no passwords are exposed.

        Response::success($order);
    }

    /**
     * POST /api/orders

     * Direct delivery: { "pickup_address", "pickup_lat", "pickup_lng", "dropoff_address", ... "distance_km", "package_description", "package_size" }
     * Shop order: { "order_type": "shop", "shop_id": 1, "dropoff_address", ... "items": [{"shop_item_id": 1, "quantity": 2}] }
     */
    public function store(Request $request): void
    {
        // $request->validate(['dropoff_address']); // Disabled to allow incomplete orders

        $orderModel = new Order();
        $orderType = $request->input('order_type', 'direct');

        $data = [
            'reference'            => $orderModel->generateReference(),
            'order_type'           => $orderType,
            'client_id'            => $this->userId(),
            'dropoff_address'      => $request->input('dropoff_address'),
            'dropoff_lat'          => $request->input('dropoff_lat'),
            'dropoff_lng'          => $request->input('dropoff_lng'),
            'dropoff_contact_name' => $request->input('dropoff_contact_name'),
            'dropoff_contact_phone'=> $request->input('dropoff_contact_phone'),
            'payment_method'       => $request->input('payment_method', 'cash'),
            'notes'                => $request->input('notes'),
            'pickup_instructions'  => $request->input('pickup_instructions'),
            'delivery_instructions'=> $request->input('delivery_instructions'),
            'pickup_scheduled_at'  => $request->input('pickup_scheduled_at'),
            'scheduled_at'         => $request->input('scheduled_at'),
            'status'               => 'pending',
        ];

        if ($orderType === 'shop') {
            // Shop order — pickup is the shop's address
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
            // Direct delivery
            // $request->validate(['pickup_address']); // Disabled to allow incomplete orders
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

        // Calculate Maps API cost from frontend usage
        $mapsUsage = $request->input('maps_usage', []);
        $data['maps_api_cost'] = $this->calculateMapsApiCost($mapsUsage);

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
        $data['price'] = $priceResult['price'] + $data['maps_api_cost'];
        $data['currency'] = 'XAF';

        // For shop orders, add items total to price
        $itemsTotal = 0;
        $items = $request->input('items', []);

        $orderId = $orderModel->create($data);

        // Create order items for shop orders
        if ($orderType === 'shop' && !empty($items)) {
            $orderItemModel = new OrderItem();
            $orderItemModel->createFromCart($orderId, $items);

            // Recalculate items total
            $orderItems = $orderItemModel->getByOrder($orderId);
            foreach ($orderItems as $item) {
                $itemsTotal += (int) $item['total_price'];
            }
            // Update price = delivery + items
            $orderModel->update($orderId, ['price' => $data['price'] + $itemsTotal]);
        }

        // Log initial status
        $historyModel = new OrderStatusHistory();
        $historyModel->create([
            'order_id'   => $orderId,
            'status'     => 'pending',
            'comment'    => 'Order created',
            'changed_by' => $this->userId(),
        ]);

        $order = $orderModel->findWithDetails($orderId);

        // Include items if shop order
        if ($orderType === 'shop') {
            $orderItemModel = $orderItemModel ?? new OrderItem();
            $order['items'] = $orderItemModel->getByOrder($orderId);
        }

        Response::success($order, 'Order created', 201);
    }

    /**
     * GET /api/orders
     */
    public function index(Request $request): void
    {
        $orderModel = new Order();
        $status = $request->query('status');

        if (Auth::isLivreur()) {
            $result = $orderModel->getByLivreur($this->userId(), $request->page(), $request->perPage(), $status);
        } elseif (Auth::isAdmin()) {
            $result = $orderModel->paginate($request->page(), $request->perPage());
        } else {
            $result = $orderModel->getByClient($this->userId(), $request->page(), $request->perPage(), $status);
        }

        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * GET /api/orders/pending — For livreurs to see available orders
     */
    public function pending(Request $request): void
    {
        $this->requireRole('livreur', 'admin');
        $orderModel = new Order();
        $result = $orderModel->getPending($request->page(), $request->perPage());
        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * GET /api/orders/{reference}
     */
    public function show(Request $request): void
    {
        $orderModel = new Order();
        $order = $orderModel->findWithDetailsByReference($request->param('reference'));

        if (!$order) {
            Response::notFound('Order not found');
        }

        // Authorization: client, assigned livreur, or admin
        if (!Auth::isAdmin()
            && (int) $order['client_id'] !== $this->userId()
            && (int) ($order['livreur_id'] ?? 0) !== $this->userId()) {
            Response::forbidden();
        }

        // Include status history
        $historyModel = new OrderStatusHistory();
        $order['status_history'] = $historyModel->getByOrder((int) $order['id']);

        // Include items if shop order
        if ($order['order_type'] === 'shop') {
            $orderItemModel = new OrderItem();
            $order['items'] = $orderItemModel->getByOrder((int) $order['id']);
        }

        Response::success($order);
    }

    /**
     * PUT /api/orders/{reference}
     * Update order details (only if pending)
     */
    public function update(Request $request): void
    {
        $orderModel = new Order();
        $order = $orderModel->findByReference($request->param('reference'));

        if (!$order) {
            Response::notFound('Order not found');
        }

        // Authorization
        // if (!Auth::isAdmin() && (int) $order['client_id'] !== $this->userId()) {
        //     Response::forbidden();
        // }

        if ($order['status'] !== 'pending') {
            Response::error('Order cannot be updated (current status: ' . $order['status'] . ')', 422);
        }

        $data = [];
        $fields = [
            'pickup_address', 'pickup_lat', 'pickup_lng', 'pickup_contact_name', 'pickup_contact_phone',
            'dropoff_address', 'dropoff_lat', 'dropoff_lng', 'dropoff_contact_name', 'dropoff_contact_phone',
            'package_description', 'package_size', 'package_weight_kg', 'package_value',
            'notes', 'pickup_instructions', 'delivery_instructions', 'pickup_scheduled_at', 'scheduled_at', 'payment_method'
        ];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if (empty($data)) {
            Response::success($order, 'No changes made');
        }

        // Recalculate price if location or package info changes
        $recalcPrice = false;
        $priceFields = ['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng', 'package_size', 'package_weight_kg', 'package_value'];
        foreach ($priceFields as $field) {
            if (isset($data[$field])) {
                $recalcPrice = true;
                break;
            }
        }

        if ($recalcPrice) {
            // Merge existing data with new data for calculation
            $merged = array_merge($order, $data);
            
            $distanceKm = (float) $request->input('distance_km', 0);
            if ($distanceKm <= 0 && 
                !empty($merged['pickup_lat']) && !empty($merged['pickup_lng']) && 
                !empty($merged['dropoff_lat']) && !empty($merged['dropoff_lng'])
            ) {
                $distanceKm = $this->haversineDistance(
                    (float) $merged['pickup_lat'], (float) $merged['pickup_lng'],
                    (float) $merged['dropoff_lat'], (float) $merged['dropoff_lng']
                );
            }
            
            $data['distance_km'] = round($distanceKm, 2);
            
            // Calculate Maps API cost if provided in update
            $mapsUsage = $request->input('maps_usage', []);
            if (!empty($mapsUsage)) {
                $data['maps_api_cost'] = $this->calculateMapsApiCost($mapsUsage);
            }

            $priceResult = $orderModel->calculatePrice(
                $distanceKm, 'Douala',
                $merged['package_size'] ?? null,
                isset($merged['package_weight_kg']) ? (float) $merged['package_weight_kg'] : null,
                (int) ($merged['package_value'] ?? 0)
            );
            
            $data['price'] = $priceResult['price'] + ($data['maps_api_cost'] ?? $order['maps_api_cost']);
        }

        $orderModel->update((int) $order['id'], $data);
        
        Response::success($orderModel->findWithDetails((int) $order['id']), 'Order updated');
    }

    /**
     * PUT /api/orders/{reference}/accept — Livreur accepts the order
     */
    public function accept(Request $request): void
    {
        $this->requireRole('livreur', 'admin');

        $orderModel = new Order();
        $order = $orderModel->findByReference($request->param('reference'));

        if (!$order) {
            Response::notFound('Order not found');
        }
        if ($order['status'] !== 'pending') {
            Response::error('Order cannot be accepted (current status: ' . $order['status'] . ')', 422);
        }

        $orderModel->update((int) $order['id'], ['livreur_id' => $this->userId()]);
        $orderModel->updateStatus((int) $order['id'], 'accepted', $this->userId(), 'Accepted by livreur');

        // Notify client
        Notification::send(
            (int) $order['client_id'],
            'Livreur assigned',
            'A delivery driver has been assigned to your order ' . $order['reference'],
            'order_update',
            ['order_id' => (int) $order['id'], 'order_reference' => $order['reference']]
        );

        Response::success($orderModel->findWithDetails((int) $order['id']), 'Order accepted');
    }

    /**
     * PUT /api/orders/{reference}/status
     * Body: { "status": "picking_up|picked_up|in_transit|delivered", "comment": "..." }
     */
    public function updateStatus(Request $request): void
    {
        $request->validate(['status']);

        $orderModel = new Order();
        $order = $orderModel->findByReference($request->param('reference'));

        if (!$order) {
            Response::notFound('Order not found');
        }

        // Only assigned livreur or admin can update status
        if (!Auth::isAdmin() && (int) ($order['livreur_id'] ?? 0) !== $this->userId()) {
            Response::forbidden('Only the assigned livreur can update status');
        }

        $newStatus = $request->input('status');
        $allowedTransitions = [
            'accepted'   => ['picking_up', 'cancelled'],
            'picking_up' => ['picked_up', 'cancelled'],
            'picked_up'  => ['in_transit', 'cancelled'],
            'in_transit' => ['delivered', 'cancelled'],
        ];

        $currentStatus = $order['status'];
        if (!isset($allowedTransitions[$currentStatus]) || !in_array($newStatus, $allowedTransitions[$currentStatus])) {
            Response::error("Cannot transition from '{$currentStatus}' to '{$newStatus}'", 422);
        }

        $comment = $request->input('comment');
        if ($newStatus === 'cancelled') {
            $orderModel->update((int) $order['id'], [
                'cancellation_reason' => $request->input('cancellation_reason', $comment),
            ]);
        }

        $orderModel->updateStatus((int) $order['id'], $newStatus, $this->userId(), $comment);

        // Update livreur stats on delivery
        if ($newStatus === 'delivered') {
            $livreurModel = new LivreurProfile();
            $profile = $livreurModel->findByUserId($this->userId());
            if ($profile) {
                $livreurModel->update((int) $profile['id'], [
                    'total_deliveries' => (int) $profile['total_deliveries'] + 1,
                ]);
            }
        }

        // Notify client
        Notification::send(
            (int) $order['client_id'],
            'Order update',
            "Your order {$order['reference']} is now: {$newStatus}",
            'order_update',
            ['order_id' => (int) $order['id'], 'order_reference' => $order['reference'], 'status' => $newStatus]
        );

        Response::success($orderModel->findWithDetails((int) $order['id']), 'Status updated');
    }

    /**
     * PUT /api/orders/{reference}/cancel — Client cancels order
     */
    public function cancel(Request $request): void
    {
        $orderModel = new Order();
        $order = $orderModel->findByReference($request->param('reference'));

        if (!$order) {
            Response::notFound('Order not found');
        }

        if ((int) $order['client_id'] !== $this->userId() && !Auth::isAdmin()) {
            Response::forbidden();
        }

        $cancellable = ['pending', 'accepted'];
        if (!in_array($order['status'], $cancellable)) {
            Response::error('Order cannot be cancelled at this stage', 422);
        }

        $reason = $request->input('cancellation_reason', 'Cancelled by client');
        $orderModel->update((int) $order['id'], ['cancellation_reason' => $reason]);
        $orderModel->updateStatus((int) $order['id'], 'cancelled', $this->userId(), $reason);

        // Notify livreur if assigned
        if ($order['livreur_id']) {
            Notification::send(
                (int) $order['livreur_id'],
                'Order cancelled',
                "Order {$order['reference']} has been cancelled by the client",
                'order_update',
                ['order_id' => (int) $order['id'], 'order_reference' => $order['reference']]
            );
        }

        Response::success(null, 'Order cancelled');
    }

    /**
     * GET /api/orders/{reference}/tracking — Get livreur GPS trail for an order
     */
    public function tracking(Request $request): void
    {
        $orderModel = new Order();
        $order = $orderModel->findByReference($request->param('reference'));

        if (!$order) {
            Response::notFound('Order not found');
        }

        if (!Auth::isAdmin()
            && (int) $order['client_id'] !== $this->userId()
            && (int) ($order['livreur_id'] ?? 0) !== $this->userId()) {
            Response::forbidden();
        }

        $locationModel = new \App\Models\LivreurLocation();
        $trail = $locationModel->getTrail((int) $order['id']);

        // Also get current position from profile
        $currentPos = null;
        if ($order['livreur_id']) {
            $livreurModel = new LivreurProfile();
            $profile = $livreurModel->findByUserId((int) $order['livreur_id']);
            if ($profile && $profile['current_lat']) {
                $currentPos = [
                    'latitude'  => $profile['current_lat'],
                    'longitude' => $profile['current_lng'],
                    'updated_at'=> $profile['last_location_at'],
                ];
            }
        }

        Response::success([
            'current_position' => $currentPos,
            'trail'            => $trail,
        ]);
    }

    /**
     * GET /api/orders/frequent-places
     * Query: ?limit=5
     */
    public function frequentPlaces(Request $request): void
    {
        $limit = (int) $request->query('limit', 5);
        $limit = max(1, min($limit, 20));

        $orderModel = new Order();
        $places = $orderModel->getFrequentPlaces($this->userId(), $limit);

        Response::success($places);
    }

    /**
     * GET /api/orders/frequent-shops
     * Query: ?limit=5
     */
    public function frequentShops(Request $request): void
    {
        $limit = (int) $request->query('limit', 5);
        $limit = max(1, min($limit, 20));

        $orderModel = new Order();
        $shops = $orderModel->getFrequentShops($this->userId(), $limit);

        Response::success($shops);
    }

    /**
     * GET /api/orders/estimate
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
        $packageSize  = $request->query('package_size');
        $packageWeight = $request->query('package_weight_kg') ? (float) $request->query('package_weight_kg') : null;
        $packageValue = (int) $request->query('package_value', 0);

        $priceResult = $orderModel->calculatePrice($distanceKm, $city, $packageSize, $packageWeight, $packageValue);

        $response = [
            'distance_km'     => round($distanceKm, 2),
            'price'           => $priceResult['price'],
            'currency'        => 'XAF',
            'city'            => $city,
        ];
        if ($priceResult['value_surcharge'] > 0) {
            $response['value_surcharge'] = $priceResult['value_surcharge'];
        }

        Response::success($response);
    }

    /**
     * Calculate Maps API cost based on frontend usage
     * @param array $mapsUsage ['autocomplete' => 3, 'geocode' => 2, 'directions' => 1, 'place_details' => 1]
     * @return int Total cost in XAF
     */
    private function calculateMapsApiCost(array $mapsUsage): int
    {
        if (empty($mapsUsage)) {
            return 0;
        }

        $settingsModel = new \App\Models\Setting();
        $totalCost = 0;

        $costMap = [
            'autocomplete'   => $settingsModel->getInt('maps_cost_autocomplete', 10),
            'geocode'        => $settingsModel->getInt('maps_cost_geocode', 15),
            'directions'     => $settingsModel->getInt('maps_cost_directions', 20),
            'place_details'  => $settingsModel->getInt('maps_cost_place_details', 15),
        ];

        foreach ($mapsUsage as $type => $count) {
            if (isset($costMap[$type]) && is_numeric($count) && $count > 0) {
                $totalCost += $costMap[$type] * (int) $count;
            }
        }

        return $totalCost;
    }

    /**
     * Haversine formula to calculate distance between two GPS coordinates
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
           * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
