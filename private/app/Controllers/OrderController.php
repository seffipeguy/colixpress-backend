<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\MediaUpload;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderMedia;
use App\Models\OrderMessage;
use App\Models\OrderStatusHistory;
use App\Models\Notification;
use App\Models\Shop;
use App\Services\WalletService;

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


        // Get status history
        $historyModel = new OrderStatusHistory();
        $order['status_history'] = $historyModel->getByOrder($order['id']);

        // Include media
        $mediaModel = new OrderMedia();
        $order['media'] = $mediaModel->getByOrder((int) $order['id']);

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
            'payment_timing'       => $request->input('payment_timing', 'pickup'),
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
            $data['package_description']  = $request->input('package_description', null);
            $data['package_size']         = $request->input('package_size');
            $data['package_weight_kg']    = $request->input('package_weight_kg');
        }

        $data['package_value'] = (int) $request->input('package_value', 0); // Défaut: 0

        // Rattacher à un panier si cart_reference fourni
        $cartReference = $request->input('cart_reference');
        if ($cartReference) {
            $cartModel = new \App\Models\OrderCart();
            $cart      = $cartModel->findByReference($cartReference);
            if (!$cart) {
                Response::error('Panier introuvable', 404);
            }
            if ((int) $cart['client_id'] !== $this->userId()) {
                Response::forbidden();
            }
            if ($cart['status'] === 'closed') {
                Response::error('Ce panier est fermé, impossible d\'y ajouter une commande', 422);
            }
            $data['cart_id'] = (int) $cart['id'];
        }

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
        $isRoundTrip = (bool) $request->input('is_round_trip', false);
        $data['is_round_trip'] = $isRoundTrip ? 1 : 0;
        $basePrice = $priceResult['price'];
        $roundTripSurcharge = $isRoundTrip ? (int) ceil($basePrice * 0.5) : 0;
        $data['price'] = $basePrice + $roundTripSurcharge + $data['maps_api_cost'];
        $data['currency'] = 'XAF';

        // For shop orders, add items total to price
        $itemsTotal = 0;
        $items = $request->input('items', []);

        // NOTE: Le débit wallet ne se fait plus ici
        // Le paiement sera effectué lors de la validation de la commande
        // Cela permet de créer une commande même avec un solde insuffisant

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

        // Attacher les médias référencés
        $mediaRefs = $request->input('media_references', []);
        if (!empty($mediaRefs) && is_array($mediaRefs)) {
            $mediaUploadModel = new MediaUpload();
            $resolved = $mediaUploadModel->resolveReferences($mediaRefs, $this->userId());
            if (isset($resolved['error'])) {
                Response::error($resolved['error'], 422);
            }
            $mediaModel = new OrderMedia();
            foreach ($resolved as $m) {
                $mediaModel->create([
                    'order_id'    => $orderId,
                    'file_name'   => $m['file_name'],
                    'file_path'   => $m['file_path'],
                    'file_url'    => $m['file_url'],
                    'file_type'   => $m['file_type'],
                    'mime_type'   => $m['mime_type'],
                    'file_size'   => $m['file_size'],
                    'uploaded_by' => $m['uploaded_by'],
                ]);
                $mediaUploadModel->markLinked((int) $m['id'], 'order', $orderId);
            }
        }

        $order = $orderModel->findWithDetails($orderId);

        // Include items if shop order
        if ($orderType === 'shop') {
            $orderItemModel = $orderItemModel ?? new OrderItem();
            $order['items'] = $orderItemModel->getByOrder($orderId);
        }

        // Include media
        $mediaModel = $mediaModel ?? new OrderMedia();
        $order['media'] = $mediaModel->getByOrder($orderId);

        Response::success($order, 'Order created', 201);
    }

    /**
     * POST /api/orders/guest
     * Commande invité - pas d'authentification requise
     * Champs supplémentaires requis: country_id, phone, first_name, last_name
     */
    public function guestStore(Request $request): void
    {
        $request->validate(['country_id', 'phone', 'first_name', 'last_name']);

        $countryId = (int) $request->input('country_id');
        $phone = trim($request->input('phone'));
        $firstName = trim($request->input('first_name'));
        $lastName = trim($request->input('last_name'));

        // Valider le pays
        $countryModel = new \App\Models\Country();
        $country = $countryModel->find($countryId);
        if (!$country) {
            Response::error('Pays invalide', 422);
        }

        // Valider la longueur du téléphone
        if (strlen($phone) !== (int) $country['phone_length']) {
            Response::error("Le numéro doit contenir {$country['phone_length']} chiffres pour {$country['name']}", 422);
        }

        // Valider les champs de base
        $request->validate(['order_type', 'dropoff_address', 'dropoff_lat', 'dropoff_lng', 'dropoff_contact_name', 'dropoff_contact_phone']);

        $orderModel = new Order();
        $orderType = $request->input('order_type', 'direct');

        // Créer ou récupérer le compte invité
        $userModel = new \App\Models\User();
        $user = $userModel->findByPhone($countryId, $phone);

        if (!$user) {
            // Créer un compte invité non vérifié
            $userId = $userModel->create([
                'country_id'  => $countryId,
                'phone'       => $phone,
                'first_name'  => $firstName,
                'last_name'   => $lastName,
                'role'        => 'client',
                'is_verified' => 0,
                'is_active'   => 1,
            ]);
            $user = $userModel->find($userId);
        } else {
            // Mettre à jour le nom si différent
            if ($user['first_name'] !== $firstName || $user['last_name'] !== $lastName) {
                $userModel->update((int) $user['id'], [
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                ]);
            }
        }

        $data = [
            'reference'            => $orderModel->generateReference(),
            'order_type'           => $orderType,
            'client_id'            => (int) $user['id'],
            'dropoff_address'      => $request->input('dropoff_address'),
            'dropoff_lat'          => $request->input('dropoff_lat'),
            'dropoff_lng'          => $request->input('dropoff_lng'),
            'dropoff_contact_name' => $request->input('dropoff_contact_name'),
            'dropoff_contact_phone'=> $request->input('dropoff_contact_phone'),
            'payment_method'       => $request->input('payment_method', 'cash'),
            'payment_timing'       => $request->input('payment_timing', 'pickup'),
            'notes'                => $request->input('notes'),
            'pickup_instructions'  => $request->input('pickup_instructions'),
            'delivery_instructions'=> $request->input('delivery_instructions'),
            'pickup_scheduled_at'  => $request->input('pickup_scheduled_at'),
            'scheduled_at'         => $request->input('scheduled_at'),
            'status'               => 'pending',
            'is_guest_order'       => 1,
        ];

        // Logique identique à store() pour le reste
        if ($orderType === 'shop') {
            $request->validate(['shop_id']);
            $shopModel = new Shop();
            $shop = $shopModel->find((int) $request->input('shop_id'));
            if (!$shop || !$shop['is_approved']) {
                Response::error('Boutique introuvable ou non approuvée', 404);
            }

            $data['shop_id']              = (int) $shop['id'];
            $data['pickup_address']       = $shop['address'];
            $data['pickup_lat']           = $shop['latitude'];
            $data['pickup_lng']           = $shop['longitude'];
            $data['pickup_contact_name']  = $shop['name'];
            $data['pickup_contact_phone'] = $shop['phone'];
        } else {
            $request->validate(['pickup_address', 'pickup_lat', 'pickup_lng', 'pickup_contact_name', 'pickup_contact_phone', 'package_description', 'package_size']);
            $data['pickup_address']       = $request->input('pickup_address');
            $data['pickup_lat']           = $request->input('pickup_lat');
            $data['pickup_lng']           = $request->input('pickup_lng');
            $data['pickup_contact_name']  = $request->input('pickup_contact_name');
            $data['pickup_contact_phone'] = $request->input('pickup_contact_phone');
            $data['package_description']  = $request->input('package_description', null);
            $data['package_size']         = $request->input('package_size');
            $data['package_weight_kg']    = $request->input('package_weight_kg');
        }

        $data['package_value'] = (int) $request->input('package_value', 0);

        // Calcul du prix (identique à store())
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

        $isRoundTrip = (bool) $request->input('is_round_trip', false);
        $data['is_round_trip'] = $isRoundTrip ? 1 : 0;
        $basePrice = $priceResult['price'];
        $roundTripSurcharge = $isRoundTrip ? (int) ceil($basePrice * 0.5) : 0;
        $data['maps_api_cost'] = $this->calculateMapsApiCost($request->input('maps_usage', []));
        $data['price'] = $basePrice + $roundTripSurcharge + $data['maps_api_cost'];
        $data['currency'] = 'XAF';

        // Créer la commande
        $orderId = $orderModel->create($data);

        // Gérer les articles pour commande shop
        $itemsTotal = 0;
        if ($orderType === 'shop' && !empty($request->input('items', []))) {
            $orderItemModel = new OrderItem();
            $orderItemModel->createFromCart($orderId, $request->input('items', []));

            $orderItems = $orderItemModel->getByOrder($orderId);
            foreach ($orderItems as $item) {
                $itemsTotal += (int) $item['total_price'];
            }
            $orderModel->update($orderId, ['price' => $data['price'] + $itemsTotal]);
        }

        // Historique de statut
        $historyModel = new OrderStatusHistory();
        $historyModel->create([
            'order_id'   => $orderId,
            'status'     => 'pending',
            'comment'    => 'Commande invité créée',
            'changed_by' => null, // Pas d'utilisateur connecté
        ]);

        // Médias (non supportés pour les invités pour l'instant)
        // TODO: Implémenter si nécessaire

        $order = $orderModel->findWithDetails($orderId);

        // Inclure les articles si commande shop
        if ($orderType === 'shop') {
            $orderItemModel = new OrderItem();
            $order['items'] = $orderItemModel->getByOrder($orderId);
        }

        // Inclure les médias
        $mediaModel = new OrderMedia();
        $order['media'] = $mediaModel->getByOrder($orderId);

        // Inclure les infos utilisateur
        $order['user'] = [
            'id'           => $user['id'],
            'first_name'   => $user['first_name'],
            'last_name'    => $user['last_name'],
            'phone'        => $user['phone'],
            'is_verified'  => $user['is_verified'],
            'is_guest'     => true,
        ];

        Response::success($order, 'Commande invité créée', 201);
    }

    /**
     * GET /api/orders
     */
    public function index(Request $request): void
    {
        $orderModel = new Order();
        $status = $request->query('status');

        if (Auth::isAdmin()) {
            $result = $orderModel->paginate($request->page(), $request->perPage());
        } elseif (Auth::role() === 'dispatcher') {
            $result = $orderModel->getByDispatcher($this->userId(), $request->page(), $request->perPage(), $status);
        } else {
            $result = $orderModel->getByClient($this->userId(), $request->page(), $request->perPage(), $status);
        }

        $isClient = !Auth::isAdmin() && Auth::role() !== 'dispatcher';
        $mediaModel = new OrderMedia();
        $msgModel   = new OrderMessage();

        $result['data'] = array_map(function ($order) use ($mediaModel, $msgModel, $isClient) {
            $orderId = (int) $order['id'];
            $order['media']           = $mediaModel->getByOrder($orderId);
            $order['unread_messages'] = $isClient
                ? $msgModel->countUnreadForClient($orderId)
                : $msgModel->countUnreadForStaff($orderId);
            return $order;
        }, $result['data']);

        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * GET /api/orders/pending — Commandes en attente d'assignation (dispatcher/admin)
     */
    public function pending(Request $request): void
    {
        $this->requireRole('dispatcher', 'admin');
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

        // Authorization: client, dispatcher, or admin
        if (!Auth::isAdmin()
            && (int) $order['client_id'] !== $this->userId()
            && (int) ($order['claimed_by'] ?? 0) !== $this->userId()) {
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

        // Include media
        $mediaModel = new OrderMedia();
        $order['media'] = $mediaModel->getByOrder((int) $order['id']);

        // Include messaging info
        $msgModel = new OrderMessage();
        $isClient = (int) $order['client_id'] === $this->userId();
        $order['unread_messages'] = $isClient
            ? $msgModel->countUnreadForClient((int) $order['id'])
            : $msgModel->countUnreadForStaff((int) $order['id']);
        $messages = $msgModel->getByOrder((int) $order['id']);
        $order['last_message'] = !empty($messages) ? end($messages) : null;

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
            'notes', 'pickup_instructions', 'delivery_instructions', 'pickup_scheduled_at', 'scheduled_at', 'payment_method',
            'payment_timing',
            'is_round_trip'
        ];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);
                // Convertir certains champs en entier
                if ($field === 'is_round_trip') {
                    $value = $value ? 1 : 0;
                }
                if ($field === 'package_value') {
                    $value = (int) $value;
                }
                $data[$field] = $value;
            }
        }

        if (empty($data)) {
            Response::success($order, 'No changes made');
        }

        // Recalculate price if location, package info, or round trip changes
        $recalcPrice = false;
        $priceFields = ['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng', 'package_size', 'package_weight_kg', 'package_value', 'is_round_trip'];
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
            
            $basePrice = $priceResult['price'];
            
            // Calculer la surcharge aller-retour si applicable
            $isRoundTrip = !empty($merged['is_round_trip']);
            $roundTripSurcharge = $isRoundTrip ? (int) ceil($basePrice * 0.5) : 0;
            
            $data['price'] = $basePrice + $roundTripSurcharge + ($data['maps_api_cost'] ?? $order['maps_api_cost']);
        }

        $orderModel->update((int) $order['id'], $data);
        
        Response::success($orderModel->findWithDetails((int) $order['id']), 'Order updated');
    }

    /**
     * PUT /api/orders/{reference}/accept — Dispatcher accepts the order
     */
    public function accept(Request $request): void
    {
        $this->requireRole('dispatcher', 'admin');

        $orderModel = new Order();
        $order = $orderModel->findByReference($request->param('reference'));

        if (!$order) {
            Response::notFound('Order not found');
        }
        if ($order['status'] !== 'pending') {
            Response::error('Order cannot be accepted (current status: ' . $order['status'] . ')', 422);
        }

        $orderModel->update((int) $order['id'], ['claimed_by' => $this->userId()]);
        $orderModel->updateStatus((int) $order['id'], 'accepted', $this->userId(), 'Accepted by dispatcher');

        // Débit wallet si paiement par portefeuille (au moment de l'acceptation)
        if ($order['payment_method'] === 'wallet' && (int) $order['price'] > 0) {
            $walletService = new WalletService();
            try {
                $walletService->debit(
                    (int) $order['client_id'],
                    (int) $order['price'],
                    'order_payment',
                    'Paiement commande ' . $order['reference'],
                    $order['reference']
                );
                // Mettre à jour le statut de paiement
                $orderModel->update((int) $order['id'], ['payment_status' => 'paid']);
            } catch (\Exception $e) {
                // Solde insuffisant - la commande reste acceptée mais le paiement est en attente
                $orderModel->update((int) $order['id'], ['payment_status' => 'pending']);
                // Notifier le client qu'il doit recharger son wallet
                Notification::send(
                    (int) $order['client_id'],
                    'Paiement requis',
                    'Votre commande ' . $order['reference'] . ' est acceptée mais le paiement a échoué par manque de solde. Veuillez recharger votre wallet.',
                    'payment_failed',
                    ['order_id' => (int) $order['id'], 'order_reference' => $order['reference'], 'amount' => (int) $order['price']]
                );
            }
        }

        // Notify client
        Notification::send(
            (int) $order['client_id'],
            'Commande acceptée',
            'Votre commande ' . $order['reference'] . ' a été prise en charge',
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

        // Only dispatcher who claimed or admin can update status
        if (!Auth::isAdmin() && (int) ($order['claimed_by'] ?? 0) !== $this->userId()) {
            Response::forbidden('Only the assigned dispatcher can update status');
        }

        $newStatus = $request->input('status');
        $isRoundTrip = !empty($order['is_round_trip']);
        $allowedTransitions = [
            'accepted'   => ['picking_up', 'cancelled'],
            'picking_up' => ['picked_up', 'cancelled'],
            'picked_up'  => ['in_transit', 'cancelled'],
            'in_transit' => $isRoundTrip ? ['returning', 'cancelled'] : ['delivered', 'cancelled'],
            'returning'  => ['delivered', 'cancelled'],
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

        // REMBOURSEMENT DESACTIVE - sera fait manuellement au besoin
        // Le remboursement automatique est désactivé. Contactez l'admin pour un remboursement manuel.
        // if ($order['payment_method'] === 'wallet' && (int) $order['price'] > 0) {
        //     $walletService = new WalletService();
        //     $walletService->credit(
        //         (int) $order['client_id'],
        //         (int) $order['price'],
        //         'refund',
        //         'Remboursement annulation commande ' . $order['reference'],
        //         $order['reference']
        //     );
        // }

        // Notify dispatcher if claimed
        if (!empty($order['claimed_by'])) {
            Notification::send(
                (int) $order['claimed_by'],
                'Commande annulée',
                "La commande {$order['reference']} a été annulée par le client",
                'order_update',
                ['order_id' => (int) $order['id'], 'order_reference' => $order['reference']]
            );
        }

        Response::success(null, 'Order cancelled');
    }

    /**
     * GET /api/orders/{reference}/tracking — Order status history
     */
    public function tracking(Request $request): void
    {
        $orderModel = new Order();
        $order = $orderModel->findByReference($request->param('reference'));

        if (!$order) {
            Response::notFound('Order not found');
        }

        if (!Auth::isAdmin() && (int) $order['client_id'] !== $this->userId()) {
            Response::forbidden();
        }

        $historyModel = new OrderStatusHistory();
        $history = $historyModel->getByOrder((int) $order['id']);

        Response::success([
            'status'  => $order['status'],
            'history' => $history,
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

        $distanceKm = (float) $request->query('distance_km', 0);
        if ($distanceKm <= 0) {
            $distanceKm = $this->haversineDistance($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
        }
        $orderModel = new Order();
        $packageSize  = $request->query('package_size');
        $packageWeight = $request->query('package_weight_kg') ? (float) $request->query('package_weight_kg') : null;
        $packageValue = (int) $request->query('package_value', 0);
        $isRoundTrip  = (bool) $request->query('is_round_trip', false);

        $priceResult = $orderModel->calculatePrice($distanceKm, $city, $packageSize, $packageWeight, $packageValue);
        $basePrice = $priceResult['price'];
        $roundTripSurcharge = $isRoundTrip ? (int) ceil($basePrice * 0.5) : 0;

        $response = [
            'distance_km'        => round($distanceKm, 2),
            'price'              => $basePrice + $roundTripSurcharge,
            'base_price'         => $basePrice,
            'is_round_trip'      => $isRoundTrip,
            'round_trip_surcharge' => $roundTripSurcharge,
            'currency'           => 'XAF',
            'city'               => $city,
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

    /**
     * GET /api/orders/active-summary
     * Résumé des commandes actives de l'utilisateur connecté :
     * - 2 dernières commandes en cours (priorise celles déjà commencées)
     * - Nombre de commandes restantes actives (hors les 2)
     * - Nombre total de messages non lus liés à ses commandes
     */
    public function activeSummary(Request $request): void
    {
        $userId = $this->userId();
        $db     = \App\Config\Database::getInstance();

        // Ordre de priorité : statuts avancés en premier, puis pending
        $statusPriority = "FIELD(o.status, 'in_transit', 'picked_up', 'picking_up', 'accepted', 'pending')";

        // Toutes les commandes actives (ni delivered ni cancelled)
        $stmt = $db->prepare("
            SELECT o.id, o.reference, o.status, o.created_at, o.claimed_by,
                   o.pickup_address, o.dropoff_address, o.price,
                   COALESCE(unread.cnt, 0) AS unread_messages
            FROM orders o
            LEFT JOIN (
                SELECT order_id, COUNT(*) AS cnt
                FROM order_messages
                WHERE is_read_by_client = 0
                  AND sender_role != 'client'
                GROUP BY order_id
            ) unread ON unread.order_id = o.id
            WHERE o.client_id = :uid
              AND o.status NOT IN ('delivered', 'cancelled')
            ORDER BY {$statusPriority} ASC, o.updated_at DESC
        ");
        $stmt->execute(['uid' => $userId]);
        $all = $stmt->fetchAll();

        $top2      = array_slice($all, 0, 2);
        $remaining = max(0, count($all) - 2);

        // Total messages non lus sur toutes ses commandes
        $stmtUnread = $db->prepare("
            SELECT COALESCE(SUM(sub.cnt), 0) AS total
            FROM (
                SELECT om.order_id, COUNT(*) AS cnt
                FROM order_messages om
                JOIN orders o ON o.id = om.order_id
                WHERE o.client_id = :uid
                  AND om.is_read_by_client = 0
                  AND om.sender_role != 'client'
                GROUP BY om.order_id
            ) sub
        ");
        $stmtUnread->execute(['uid' => $userId]);
        $totalUnread = (int) $stmtUnread->fetchColumn();

        Response::success([
            'active_orders'          => $top2,
            'remaining_active_count' => $remaining,
            'total_unread_messages'  => $totalUnread,
        ]);
    }
}
