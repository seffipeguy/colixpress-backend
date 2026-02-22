<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\PricingRule;

class PricingController extends Controller
{
    /**
     * GET /api/pricing
     */
    public function index(Request $request): void
    {
        $model = new PricingRule();
        Response::success($model->getAllActive());
    }

    /**
     * GET /api/pricing/{city}
     */
    public function show(Request $request): void
    {
        $model = new PricingRule();
        $rule = $model->getForCity($request->param('city'));

        if (!$rule) {
            Response::notFound('No pricing rule for this city');
        }

        Response::success($rule);
    }

    /**
     * POST /api/pricing — Admin only
     */
    public function store(Request $request): void
    {
        $this->requireRole('admin');
        $request->validate(['city', 'base_price', 'price_per_km', 'min_price']);

        $model = new PricingRule();
        $id = $model->create([
            'city'             => $request->input('city'),
            'base_price'       => (int) $request->input('base_price'),
            'price_per_km'     => (int) $request->input('price_per_km'),
            'min_price'        => (int) $request->input('min_price'),
            'surge_multiplier' => (float) $request->input('surge_multiplier', 1.00),
        ]);

        Response::success($model->find($id), 'Pricing rule created', 201);
    }

    /**
     * PUT /api/pricing/{id} — Admin only
     */
    public function update(Request $request): void
    {
        $this->requireRole('admin');

        $model = new PricingRule();
        $rule = $model->find((int) $request->param('id'));

        if (!$rule) {
            Response::notFound('Pricing rule not found');
        }

        $allowed = ['city', 'base_price', 'price_per_km', 'min_price', 'surge_multiplier', 'is_active'];
        $data = [];
        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if (!empty($data)) {
            $model->update((int) $rule['id'], $data);
        }

        Response::success($model->find((int) $rule['id']), 'Pricing rule updated');
    }

    /**
     * POST /api/pricing/calculate — Calculate delivery price
     */
    public function calculatePrice(Request $request): void
    {
        $request->validate([
            'pickup_lat', 'pickup_lng',
            'delivery_lat', 'delivery_lng'
        ]);

        $pickupLat = (float) $request->input('pickup_lat');
        $pickupLng = (float) $request->input('pickup_lng');
        $deliveryLat = (float) $request->input('delivery_lat');
        $deliveryLng = (float) $request->input('delivery_lng');

        // Calculate distance using Haversine formula
        $earthRadius = 6371; // km
        $dLat = deg2rad($deliveryLat - $pickupLat);
        $dLng = deg2rad($deliveryLng - $pickupLng);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($pickupLat)) * cos(deg2rad($deliveryLat)) *
             sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distanceKm = round($earthRadius * $c, 2);

        // Optional parameters
        $city = $request->input('city', 'Douala');
        $packageSize = $request->input('package_size');
        $packageWeight = $request->input('package_weight_kg') ? (float) $request->input('package_weight_kg') : null;
        $packageValue = (int) $request->input('package_value', 0);
        $scheduledTime = $request->input('scheduled_time');
        $mapsUsage = $request->input('maps_usage', []);

        // Get pricing rule
        $pricingModel = new PricingRule();
        $rule = $pricingModel->getForCity($city);
        
        if (!$rule) {
            Response::error('No pricing rule found for this city', 404);
        }

        $settingsModel = new \App\Models\Setting();

        // Calculate base + distance
        $basePrice = (int) $rule['base_price'];
        $distanceFee = (int) round($distanceKm * (int) $rule['price_per_km']);
        $price = $basePrice + $distanceFee;

        // Package size surcharge
        if ($packageSize === 'moyen' && isset($rule['surcharge_moyen'])) {
            $price += (int) $rule['surcharge_moyen'];
        } elseif ($packageSize === 'grand' && isset($rule['surcharge_grand'])) {
            $price += (int) $rule['surcharge_grand'];
        }

        // Weight surcharge
        if ($packageWeight && isset($rule['weight_threshold_kg']) && isset($rule['price_per_extra_kg'])) {
            $threshold = (float) $rule['weight_threshold_kg'];
            if ($packageWeight > $threshold) {
                $extraKg = $packageWeight - $threshold;
                $price += (int) round($extraKg * (int) $rule['price_per_extra_kg']);
            }
        }

        // Surge multiplier
        $price = (int) round($price * (float) $rule['surge_multiplier']);

        // Time-based multipliers
        $currentHour = $scheduledTime ? (int) date('H', strtotime($scheduledTime)) : (int) date('H');
        
        $nightStart = $settingsModel->getInt('night_start_hour', 22);
        $nightEnd = $settingsModel->getInt('night_end_hour', 6);
        $isNight = ($nightStart > $nightEnd)
            ? ($currentHour >= $nightStart || $currentHour < $nightEnd)
            : ($currentHour >= $nightStart && $currentHour < $nightEnd);

        $nightFee = 0;
        $peakFee = 0;
        $priceBeforeMultiplier = $price;

        if ($isNight && isset($rule['night_multiplier']) && (float) $rule['night_multiplier'] > 1) {
            $price = (int) round($price * (float) $rule['night_multiplier']);
            $nightFee = $price - $priceBeforeMultiplier;
        } else {
            $peakStart1 = $settingsModel->getInt('peak_start_hour', 7);
            $peakEnd1 = $settingsModel->getInt('peak_end_hour', 9);
            $peakStart2 = $settingsModel->getInt('peak_start_hour_2', 17);
            $peakEnd2 = $settingsModel->getInt('peak_end_hour_2', 19);

            $isPeak = ($currentHour >= $peakStart1 && $currentHour < $peakEnd1)
                   || ($currentHour >= $peakStart2 && $currentHour < $peakEnd2);

            if ($isPeak && isset($rule['peak_multiplier']) && (float) $rule['peak_multiplier'] > 1) {
                $price = (int) round($price * (float) $rule['peak_multiplier']);
                $peakFee = $price - $priceBeforeMultiplier;
            }
        }

        // Package value surcharge
        $valueSurcharge = 0;
        if ($packageValue > 0) {
            $valueThreshold = $settingsModel->getInt('package_value_threshold', 10000);
            if ($packageValue > $valueThreshold) {
                $surchargePercent = $settingsModel->getFloat('package_value_surcharge_percent', 3);
                $valueSurcharge = (int) round($packageValue * $surchargePercent / 100);
                $maxSurcharge = $settingsModel->getInt('package_value_max_surcharge', 5000);
                if ($maxSurcharge > 0 && $valueSurcharge > $maxSurcharge) {
                    $valueSurcharge = $maxSurcharge;
                }
                $price += $valueSurcharge;
            }
        }

        // Calculate Maps API cost
        $mapsApiCost = 0;
        if (!empty($mapsUsage)) {
            $costMap = [
                'autocomplete'   => $settingsModel->getInt('maps_cost_autocomplete', 10),
                'geocode'        => $settingsModel->getInt('maps_cost_geocode', 15),
                'directions'     => $settingsModel->getInt('maps_cost_directions', 20),
                'place_details'  => $settingsModel->getInt('maps_cost_place_details', 15),
            ];

            foreach ($mapsUsage as $type => $count) {
                if (isset($costMap[$type]) && is_numeric($count) && $count > 0) {
                    $mapsApiCost += $costMap[$type] * (int) $count;
                }
            }
        }

        // Apply min/max price (before adding Maps cost)
        $price = max($price, (int) $rule['min_price']);
        if (isset($rule['max_price']) && (int) $rule['max_price'] > 0) {
            $price = min($price, (int) $rule['max_price']);
        }

        // Add Maps API cost to final price
        $finalPrice = $price + $mapsApiCost;

        Response::success([
            'distance_km' => $distanceKm,
            'base_price' => $basePrice,
            'distance_fee' => $distanceFee,
            'night_fee' => $nightFee,
            'peak_fee' => $peakFee,
            'value_surcharge' => $valueSurcharge,
            'maps_api_cost' => $mapsApiCost,
            'total_price' => $finalPrice,
            'min_price' => (int) $rule['min_price'],
            'breakdown' => [
                'base' => $basePrice,
                'distance' => $distanceFee,
                'night' => $nightFee,
                'peak' => $peakFee,
                'value_insurance' => $valueSurcharge,
                'maps_api' => $mapsApiCost,
            ],
            'currency' => 'XAF'
        ]);
    }
}
