<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Setting;

class SettingsController extends Controller
{
    /**
     * GET /api/settings
     * Query: ?category=finance
     */
    public function index(Request $request): void
    {
        $this->requireRole('admin');

        $model = new Setting();
        $category = $request->query('category');

        if ($category) {
            $data = $model->getByCategory($category);
        } else {
            $data = $model->getAll();
        }

        Response::success($data);
    }

    /**
     * GET /api/settings/categories
     */
    public function categories(Request $request): void
    {
        $this->requireRole('admin');
        $model = new Setting();
        Response::success($model->getCategories());
    }

    /**
     * PUT /api/settings/{key}
     * Body: { "value": "15" }
     */
    public function update(Request $request): void
    {
        $this->requireRole('admin');
        $request->validate(['value']);

        $key = $request->param('key');
        $model = new Setting();
        $existing = $model->findBy('setting_key', $key);

        if (!$existing) {
            Response::notFound('Setting not found');
        }

        $model->set($key, $request->input('value'));

        Response::success($model->findBy('setting_key', $key), 'Setting updated');
    }

    /**
     * POST /api/settings
     * Body: { "key": "new_setting", "value": "123", "description": "...", "category": "general" }
     */
    public function store(Request $request): void
    {
        $this->requireRole('admin');
        $request->validate(['key', 'value']);

        $model = new Setting();

        if ($model->findBy('setting_key', $request->input('key'))) {
            Response::error('Setting already exists, use PUT to update', 409);
        }

        $model->set(
            $request->input('key'),
            $request->input('value'),
            $request->input('description'),
            $request->input('category', 'general')
        );

        Response::success($model->findBy('setting_key', $request->input('key')), 'Setting created', 201);
    }

    /**
     * DELETE /api/settings/{key}
     */
    public function destroy(Request $request): void
    {
        $this->requireRole('admin');

        $key = $request->param('key');
        $model = new Setting();
        $existing = $model->findBy('setting_key', $key);

        if (!$existing) {
            Response::notFound('Setting not found');
        }

        $model->delete((int) $existing['id']);
        Response::success(null, 'Setting deleted');
    }

    /**
     * PUT /api/settings/bulk
     * Body: { "settings": { "commission_percent": "12", "night_start_hour": "21" } }
     */
    public function bulkUpdate(Request $request): void
    {
        $this->requireRole('admin');

        $settings = $request->input('settings');
        if (!is_array($settings) || empty($settings)) {
            Response::error('settings object required', 422);
        }

        $model = new Setting();
        $updated = [];
        $errors = [];

        foreach ($settings as $key => $value) {
            $existing = $model->findBy('setting_key', $key);
            if ($existing) {
                $model->set($key, (string) $value);
                $updated[] = $key;
            } else {
                $errors[] = "Setting '{$key}' not found";
            }
        }

        Response::success([
            'updated' => $updated,
            'errors'  => $errors,
        ], count($updated) . ' settings updated');
    }

    /**
     * GET /api/settings/public
     * Returns non-sensitive settings for the mobile app
     */
    public function publicSettings(Request $request): void
    {
        $model = new Setting();
        $publicKeys = [
            'app_name', 'app_version', 'default_currency',
            'maintenance_mode', 'max_delivery_distance_km',
            'default_search_radius_km',
        ];

        $result = [];
        foreach ($publicKeys as $key) {
            $result[$key] = $model->get($key);
        }

        Response::success($result);
    }

    /**
     * GET /api/settings/maps-pricing
     * Returns Maps API pricing for frontend usage tracking
     */
    public function mapsPricing(Request $request): void
    {
        $model = new Setting();
        
        Response::success([
            'autocomplete'   => $model->getInt('maps_cost_autocomplete', 10),
            'geocode'        => $model->getInt('maps_cost_geocode', 15),
            'directions'     => $model->getInt('maps_cost_directions', 20),
            'place_details'  => $model->getInt('maps_cost_place_details', 15),
            'currency'       => 'XAF',
        ]);
    }
}
