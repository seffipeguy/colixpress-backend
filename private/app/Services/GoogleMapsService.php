<?php

namespace App\Services;

use App\Models\GeoCache;
use App\Models\Setting;

class GoogleMapsService
{
    private string $apiKey;
    private GeoCache $cache;
    private const BASE_URL = 'https://maps.googleapis.com/maps/api';

    public function __construct()
    {
        $setting = new Setting();
        $this->apiKey = $setting->get('google_maps_server_key') ?? '';
        $this->cache = new GeoCache();
    }

    /**
     * Autocomplete — Suggestions d'adresses
     */
    public function autocomplete(string $input, ?string $country = null, ?string $location = null, ?int $radius = null): array
    {
        $extra = array_filter([
            'country'  => $country,
            'location' => $location,
            'radius'   => $radius,
        ]);
        $cacheKey = GeoCache::buildKey('autocomplete', $input, $extra);

        // Vérifier le cache
        $cached = $this->cache->lookup($cacheKey);
        if ($cached !== null) {
            return ['source' => 'cache', 'results' => $cached];
        }

        // Appel Google
        $params = [
            'input'    => $input,
            'key'      => $this->apiKey,
            'language' => 'fr',
        ];
        if ($country) {
            $params['components'] = 'country:' . $country;
        }
        if ($location) {
            $params['location'] = $location;
            $params['radius'] = $radius ?? 50000;
            $params['strictbounds'] = false;
            $params['origin'] = $location;
        }

        $response = $this->callGoogle('/place/autocomplete/json', $params);

        if ($response && ($response['status'] === 'OK' || $response['status'] === 'ZERO_RESULTS')) {
            $results = array_map(function ($p) {
                $item = [
                    'place_id'          => $p['place_id'],
                    'description'       => $p['description'],
                    'main_text'         => $p['structured_formatting']['main_text'] ?? '',
                    'secondary_text'    => $p['structured_formatting']['secondary_text'] ?? '',
                    'types'             => $p['types'] ?? [],
                    'matched_substrings' => $p['structured_formatting']['main_text_matched_substrings'] ?? [],
                ];
                if (isset($p['distance_meters'])) {
                    $item['distance_meters'] = $p['distance_meters'];
                }
                return $item;
            }, $response['predictions'] ?? []);

            $this->cache->store('autocomplete', $cacheKey, $input, $results);
            return ['source' => 'google', 'results' => $results];
        }

        return ['source' => 'google', 'results' => [], 'error' => $response['status'] ?? 'UNKNOWN_ERROR'];
    }

    /**
     * Geocode — Adresse → Coordonnées
     */
    public function geocode(string $address, ?string $country = null): array
    {
        $extra = array_filter(['country' => $country]);
        $cacheKey = GeoCache::buildKey('geocode', $address, $extra);

        $cached = $this->cache->lookup($cacheKey);
        if ($cached !== null) {
            return ['source' => 'cache', 'result' => $cached];
        }

        $params = [
            'address'  => $address,
            'key'      => $this->apiKey,
            'language' => 'fr',
        ];
        if ($country) {
            $params['components'] = 'country:' . $country;
        }

        $response = $this->callGoogle('/geocode/json', $params);

        if ($response && $response['status'] === 'OK' && !empty($response['results'])) {
            $r = $response['results'][0];
            $result = [
                'formatted_address' => $r['formatted_address'],
                'latitude'          => $r['geometry']['location']['lat'],
                'longitude'         => $r['geometry']['location']['lng'],
                'place_id'          => $r['place_id'],
                'types'             => $r['types'] ?? [],
            ];

            $this->cache->store('geocode', $cacheKey, $address, $result);
            return ['source' => 'google', 'result' => $result];
        }

        return ['source' => 'google', 'result' => null, 'error' => $response['status'] ?? 'UNKNOWN_ERROR'];
    }

    /**
     * Reverse Geocode — Coordonnées → Adresse
     */
    public function reverseGeocode(float $lat, float $lng): array
    {
        // Arrondir à 5 décimales (~1m de précision) pour maximiser les cache hits
        $roundedLat = round($lat, 5);
        $roundedLng = round($lng, 5);
        $input = "{$roundedLat},{$roundedLng}";

        $cacheKey = GeoCache::buildKey('reverse_geocode', $input);

        $cached = $this->cache->lookup($cacheKey);
        if ($cached !== null) {
            return ['source' => 'cache', 'result' => $cached];
        }

        $params = [
            'latlng'   => "{$lat},{$lng}",
            'key'      => $this->apiKey,
            'language' => 'fr',
        ];

        $response = $this->callGoogle('/geocode/json', $params);

        if ($response && $response['status'] === 'OK' && !empty($response['results'])) {
            $r = $response['results'][0];
            $result = [
                'formatted_address' => $r['formatted_address'],
                'place_id'          => $r['place_id'],
                'types'             => $r['types'] ?? [],
                'components'        => $this->extractComponents($r['address_components'] ?? []),
            ];

            $this->cache->store('reverse_geocode', $cacheKey, $input, $result);
            return ['source' => 'google', 'result' => $result];
        }

        return ['source' => 'google', 'result' => null, 'error' => $response['status'] ?? 'UNKNOWN_ERROR'];
    }

    /**
     * Directions — Itinéraire entre deux points
     */
    public function directions(float $originLat, float $originLng, float $destLat, float $destLng): array
    {
        // Arrondir à 4 décimales (~11m) pour les directions
        $input = round($originLat, 4) . ',' . round($originLng, 4) . '>' . round($destLat, 4) . ',' . round($destLng, 4);
        $cacheKey = GeoCache::buildKey('directions', $input);

        $cached = $this->cache->lookup($cacheKey);
        if ($cached !== null) {
            return ['source' => 'cache', 'result' => $cached];
        }

        $params = [
            'origin'      => "{$originLat},{$originLng}",
            'destination' => "{$destLat},{$destLng}",
            'key'         => $this->apiKey,
            'language'    => 'fr',
            'mode'        => 'driving',
        ];

        $response = $this->callGoogle('/directions/json', $params);

        if ($response && $response['status'] === 'OK' && !empty($response['routes'])) {
            $route = $response['routes'][0];
            $leg = $route['legs'][0];
            $result = [
                'distance_meters' => $leg['distance']['value'],
                'distance_text'   => $leg['distance']['text'],
                'duration_seconds' => $leg['duration']['value'],
                'duration_text'   => $leg['duration']['text'],
                'start_address'   => $leg['start_address'],
                'end_address'     => $leg['end_address'],
                'polyline'        => $route['overview_polyline']['points'] ?? '',
            ];

            $this->cache->store('directions', $cacheKey, $input, $result);
            return ['source' => 'google', 'result' => $result];
        }

        return ['source' => 'google', 'result' => null, 'error' => $response['status'] ?? 'UNKNOWN_ERROR'];
    }

    /**
     * Place Details — Détails d'un lieu par place_id
     */
    public function placeDetails(string $placeId): array
    {
        $cacheKey = GeoCache::buildKey('geocode', 'place:' . $placeId);

        $cached = $this->cache->lookup($cacheKey);
        if ($cached !== null) {
            return ['source' => 'cache', 'result' => $cached];
        }

        $params = [
            'place_id' => $placeId,
            'key'      => $this->apiKey,
            'language' => 'fr',
            'fields'   => 'formatted_address,geometry,name,place_id,address_components',
        ];

        $response = $this->callGoogle('/place/details/json', $params);

        if ($response && $response['status'] === 'OK' && !empty($response['result'])) {
            $r = $response['result'];
            $result = [
                'name'              => $r['name'] ?? '',
                'formatted_address' => $r['formatted_address'] ?? '',
                'latitude'          => $r['geometry']['location']['lat'] ?? null,
                'longitude'         => $r['geometry']['location']['lng'] ?? null,
                'place_id'          => $r['place_id'],
                'components'        => $this->extractComponents($r['address_components'] ?? []),
            ];

            $this->cache->store('geocode', $cacheKey, 'place:' . $placeId, $result);
            return ['source' => 'google', 'result' => $result];
        }

        return ['source' => 'google', 'result' => null, 'error' => $response['status'] ?? 'UNKNOWN_ERROR'];
    }

    /**
     * Appel HTTP vers l'API Google Maps
     */
    private function callGoogle(string $endpoint, array $params): ?array
    {
        if (empty($this->apiKey)) {
            return ['status' => 'API_KEY_MISSING'];
        }

        $url = self::BASE_URL . $endpoint . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            return ['status' => 'HTTP_ERROR', 'http_code' => $httpCode, 'error' => $error];
        }

        return json_decode($body, true);
    }

    /**
     * Extraire les composants d'adresse utiles
     */
    private function extractComponents(array $components): array
    {
        $map = [];
        foreach ($components as $c) {
            foreach ($c['types'] as $type) {
                $map[$type] = $c['long_name'];
            }
        }
        return [
            'street'  => $map['route'] ?? null,
            'quarter' => $map['sublocality_level_1'] ?? $map['sublocality'] ?? $map['neighborhood'] ?? null,
            'city'    => $map['locality'] ?? $map['administrative_area_level_2'] ?? null,
            'region'  => $map['administrative_area_level_1'] ?? null,
            'country' => $map['country'] ?? null,
        ];
    }
}
