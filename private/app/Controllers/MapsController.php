<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\GeoCache;
use App\Services\GoogleMapsService;

class MapsController extends Controller
{
    /**
     * GET /api/maps/autocomplete?input=akwa&country=CM&location=4.05,9.7&radius=50000
     */
    public function autocomplete(Request $request): void
    {
        $input = $request->query('input');
        if (!$input || mb_strlen($input) < 2) {
            Response::error('Le paramètre input est requis (min 2 caractères)', 422);
        }

        $country  = $request->query('country');
        $location = $request->query('location');
        $radius   = $request->query('radius') ? (int) $request->query('radius') : null;

        $service = new GoogleMapsService();
        $data = $service->autocomplete($input, $country, $location, $radius);

        if (isset($data['error'])) {
            Response::error('Erreur Google Maps: ' . $data['error'], 502);
        }

        Response::success([
            'predictions' => $data['results'],
            'source'      => $data['source'],
        ]);
    }

    /**
     * GET /api/maps/geocode?address=Akwa+Douala&country=CM
     */
    public function geocode(Request $request): void
    {
        $address = $request->query('address');
        if (!$address) {
            Response::error('Le paramètre address est requis', 422);
        }

        $country = $request->query('country');

        $service = new GoogleMapsService();
        $data = $service->geocode($address, $country);

        if (isset($data['error'])) {
            Response::error('Erreur Google Maps: ' . $data['error'], 502);
        }

        Response::success([
            'location' => $data['result'],
            'source'   => $data['source'],
        ]);
    }

    /**
     * GET /api/maps/reverse-geocode?lat=4.0511&lng=9.7679
     */
    public function reverseGeocode(Request $request): void
    {
        $lat = $request->query('lat');
        $lng = $request->query('lng');

        if ($lat === null || $lng === null) {
            Response::error('Les paramètres lat et lng sont requis', 422);
        }

        $service = new GoogleMapsService();
        $data = $service->reverseGeocode((float) $lat, (float) $lng);

        if (isset($data['error'])) {
            Response::error('Erreur Google Maps: ' . $data['error'], 502);
        }

        Response::success([
            'address' => $data['result'],
            'source'  => $data['source'],
        ]);
    }

    /**
     * GET /api/maps/directions?origin_lat=4.05&origin_lng=9.76&dest_lat=4.06&dest_lng=9.78
     */
    public function directions(Request $request): void
    {
        $originLat = $request->query('origin_lat');
        $originLng = $request->query('origin_lng');
        $destLat   = $request->query('dest_lat');
        $destLng   = $request->query('dest_lng');

        if ($originLat === null || $originLng === null || $destLat === null || $destLng === null) {
            Response::error('Les paramètres origin_lat, origin_lng, dest_lat, dest_lng sont requis', 422);
        }

        $service = new GoogleMapsService();
        $data = $service->directions((float) $originLat, (float) $originLng, (float) $destLat, (float) $destLng);

        if (isset($data['error'])) {
            Response::error('Erreur Google Maps: ' . $data['error'], 502);
        }

        Response::success([
            'route'  => $data['result'],
            'source' => $data['source'],
        ]);
    }

    /**
     * GET /api/maps/place-details?place_id=ChIJ...
     */
    public function placeDetails(Request $request): void
    {
        $placeId = $request->query('place_id');
        if (!$placeId) {
            Response::error('Le paramètre place_id est requis', 422);
        }

        $service = new GoogleMapsService();
        $data = $service->placeDetails($placeId);

        if (isset($data['error'])) {
            Response::error('Erreur Google Maps: ' . $data['error'], 502);
        }

        Response::success([
            'place'  => $data['result'],
            'source' => $data['source'],
        ]);
    }

    /**
     * GET /api/admin/maps/cache-stats
     */
    public function cacheStats(Request $request): void
    {
        $this->requireRole('admin');

        $cache = new GeoCache();
        $stats = $cache->stats();

        $totalEntries = 0;
        $totalHits = 0;
        foreach ($stats as $s) {
            $totalEntries += (int) $s['total_entries'];
            $totalHits += (int) $s['total_hits'];
        }

        Response::success([
            'by_type'       => $stats,
            'total_entries' => $totalEntries,
            'total_hits'    => $totalHits,
        ]);
    }

    /**
     * POST /api/admin/maps/cache-purge
     */
    public function cachePurge(Request $request): void
    {
        $this->requireRole('admin');

        $cache = new GeoCache();
        $purged = $cache->purgeExpired();

        Response::success([
            'purged_entries' => $purged,
        ], "Cache purgé: {$purged} entrées expirées supprimées");
    }
}
