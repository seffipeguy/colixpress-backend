<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Address;

class AddressController extends Controller
{
    /**
     * GET /api/addresses
     */
    public function index(Request $request): void
    {
        $model = new Address();
        $addresses = $model->getByUser($this->userId());
        Response::success($addresses);
    }

    /**
     * POST /api/addresses
     * Body: { "label": "Maison", "full_address": "...", "latitude": 4.05, "longitude": 9.7, "city": "Douala", "quarter": "Akwa" }
     */
    public function store(Request $request): void
    {
        $request->validate(['label', 'full_address']);

        $model = new Address();
        $id = $model->create([
            'user_id'      => $this->userId(),
            'label'        => $request->input('label'),
            'full_address' => $request->input('full_address'),
            'latitude'     => $request->input('latitude'),
            'longitude'    => $request->input('longitude'),
            'city'         => $request->input('city', 'Douala'),
            'quarter'      => $request->input('quarter'),
            'is_default'   => $request->input('is_default', 0),
        ]);

        if ($request->input('is_default')) {
            $model->setDefault($this->userId(), $id);
        }

        $address = $model->find($id);
        Response::success($address, 'Address created', 201);
    }

    /**
     * GET /api/addresses/{id}
     */
    public function show(Request $request): void
    {
        $model = new Address();
        $address = $model->find((int) $request->param('id'));

        if (!$address || (int) $address['user_id'] !== $this->userId()) {
            Response::notFound('Address not found');
        }

        Response::success($address);
    }

    /**
     * PUT /api/addresses/{id}
     */
    public function update(Request $request): void
    {
        $model = new Address();
        $id = (int) $request->param('id');
        $address = $model->find($id);

        if (!$address || (int) $address['user_id'] !== $this->userId()) {
            Response::notFound('Address not found');
        }

        $allowed = ['label', 'full_address', 'latitude', 'longitude', 'city', 'quarter', 'is_default'];
        $data = [];
        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if (!empty($data)) {
            $model->update($id, $data);
        }

        if ($request->input('is_default')) {
            $model->setDefault($this->userId(), $id);
        }

        Response::success($model->find($id), 'Address updated');
    }

    /**
     * DELETE /api/addresses/{id}
     */
    public function destroy(Request $request): void
    {
        $model = new Address();
        $id = (int) $request->param('id');
        $address = $model->find($id);

        if (!$address || (int) $address['user_id'] !== $this->userId()) {
            Response::notFound('Address not found');
        }

        $model->delete($id);
        Response::success(null, 'Address deleted');
    }
}
