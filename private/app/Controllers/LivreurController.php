<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\LivreurProfile;
use App\Models\LivreurLocation;
use App\Models\User;

class LivreurController extends Controller
{
    /**
     * POST /api/livreur/register
     * Body: { "vehicle_type": "moto", "plate_number": "LT-1234-AB", "id_card_number": "..." }
     */
    public function register(Request $request): void
    {
        $request->validate(['vehicle_type']);

        $model = new LivreurProfile();

        // Check if already registered
        if ($model->findByUserId($this->userId())) {
            Response::error('Livreur profile already exists', 422);
        }

        // Update user role
        $userModel = new User();
        $userModel->update($this->userId(), ['role' => 'livreur']);

        $id = $model->create([
            'user_id'        => $this->userId(),
            'vehicle_type'   => $request->input('vehicle_type'),
            'plate_number'   => $request->input('plate_number'),
            'id_card_number' => $request->input('id_card_number'),
            'id_card_photo'  => $request->input('id_card_photo'),
        ]);

        Response::success($model->find($id), 'Livreur profile created, pending approval', 201);
    }

    /**
     * GET /api/livreur/profile
     */
    public function profile(Request $request): void
    {
        $this->requireRole('livreur', 'admin');

        $model = new LivreurProfile();
        $profile = $model->findByUserId($this->userId());

        if (!$profile) {
            Response::notFound('Livreur profile not found');
        }

        Response::success($profile);
    }

    /**
     * PUT /api/livreur/profile
     */
    public function updateProfile(Request $request): void
    {
        $this->requireRole('livreur', 'admin');

        $model = new LivreurProfile();
        $profile = $model->findByUserId($this->userId());

        if (!$profile) {
            Response::notFound('Livreur profile not found');
        }

        $allowed = ['vehicle_type', 'plate_number', 'id_card_number', 'id_card_photo'];
        $data = [];
        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if (!empty($data)) {
            $model->update((int) $profile['id'], $data);
        }

        Response::success($model->findByUserId($this->userId()), 'Profile updated');
    }

    /**
     * PUT /api/livreur/availability
     * Body: { "is_available": true }
     */
    public function toggleAvailability(Request $request): void
    {
        $this->requireRole('livreur');

        $model = new LivreurProfile();
        $profile = $model->findByUserId($this->userId());

        if (!$profile) {
            Response::notFound('Livreur profile not found');
        }

        if (!$profile['is_approved']) {
            Response::error('Your profile has not been approved yet', 403);
        }

        $isAvailable = (int) $request->input('is_available', 0);
        $model->update((int) $profile['id'], ['is_available' => $isAvailable]);

        Response::success(['is_available' => (bool) $isAvailable], 'Availability updated');
    }

    /**
     * POST /api/livreur/location
     * Body: { "latitude": 4.0511, "longitude": 9.7679, "order_id": 12 }
     */
    public function updateLocation(Request $request): void
    {
        $this->requireRole('livreur');
        $request->validate(['latitude', 'longitude']);

        $lat = (float) $request->input('latitude');
        $lng = (float) $request->input('longitude');
        $orderId = $request->input('order_id') ? (int) $request->input('order_id') : null;

        // Update current position in profile
        $profileModel = new LivreurProfile();
        $profileModel->updateLocation($this->userId(), $lat, $lng);

        // Log to location history
        $locationModel = new LivreurLocation();
        $locationModel->track($this->userId(), $lat, $lng, $orderId);

        Response::success(null, 'Location updated');
    }

    /**
     * GET /api/livreur/nearby
     * Query: ?lat=4.05&lng=9.76&radius=5
     */
    public function nearby(Request $request): void
    {
        $lat = (float) $request->query('lat');
        $lng = (float) $request->query('lng');
        $radius = (float) $request->query('radius', 5);

        if (!$lat || !$lng) {
            Response::error('Coordinates required', 422);
        }

        $model = new LivreurProfile();
        $livreurs = $model->getAvailableNear($lat, $lng, $radius);

        Response::success($livreurs);
    }

    /**
     * PUT /api/livreur/{id}/approve — Admin only
     */
    public function approve(Request $request): void
    {
        $this->requireRole('admin');

        $model = new LivreurProfile();
        $profile = $model->find((int) $request->param('id'));

        if (!$profile) {
            Response::notFound('Livreur profile not found');
        }

        $model->update((int) $profile['id'], ['is_approved' => 1]);
        Response::success(null, 'Livreur approved');
    }
}
