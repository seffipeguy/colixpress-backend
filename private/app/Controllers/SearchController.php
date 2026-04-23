<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Setting;
use App\Models\Shop;

class SearchController extends Controller
{
    /**
     * GET /api/search/shops
     * Query params:
     * - q: string (required) - The search query
     * - shop_id: int (optional) - ID of the shop to search in. If omitted, searches across all shops (if configured) or just returns search results.
     */
    public function searchShops(Request $request): void
    {
        $query = $request->query('q');
        if (!$query) {
            Response::error('Query parameter "q" is required', 400);
        }

        $userLat = $request->query('lat');
        $userLon = $request->query('lon');

        $shopId = $request->query('shop_id');
        $siteFilter = '';
        $shops = [];

        $shopModel = new Shop();

        if ($shopId) {
            $shop = $shopModel->find((int) $shopId);
            
            if (!$shop) {
                Response::notFound('Shop not found');
            }

            if (!empty($shop['website_url'])) {
                $siteFilter = 'site:' . $shop['website_url'];
                $shops[] = $shop;
            } else {
                Response::error('This shop does not have a website URL configured', 400);
            }
        } else {
            // No shop_id provided: Search across ALL active/approved shops
            $shops = $shopModel->getAllShopUrls(); // Now returns array of shops with id, website_url, lat, lon

            if (!empty($shops)) {
                // Construct query: site:url1 OR site:url2 OR ...
                $sites = array_map(fn($s) => 'site:' . $s['website_url'], $shops);
                $siteFilter = implode(' OR ', $sites);
            }
        }

        // Construct the final query
        $finalQuery = trim($query . ' ' . $siteFilter);

        // Perform Search using Serper.dev
        $result = $this->serperSearch($finalQuery);
        
        // Handle API errors gracefully
        if (isset($result['error'])) {
            Response::json($result, $result['status_code'] ?? 500);
        }

        // Enrich results with shop_id and distance
        if (isset($result['items'])) {
            foreach ($result['items'] as &$item) {
                $matchedShop = $this->matchShopByUrl($item['link'], $shops);
                
                if ($matchedShop) {
                    // Inject full shop details
                    $item['shop'] = $matchedShop;
                    
                    // Remove sensitive or unnecessary fields if needed, but user asked for "all infos"
                    // Optionally calculate distance and add it to the shop object or root item
                    if ($userLat && $userLon && !empty($matchedShop['latitude']) && !empty($matchedShop['longitude'])) {
                        $distance = $this->calculateDistance(
                            (float) $userLat,
                            (float) $userLon,
                            (float) $matchedShop['latitude'],
                            (float) $matchedShop['longitude']
                        );
                        $item['shop']['distance_km'] = $distance;
                        $item['distance_km'] = $distance; // Keep it at root too for convenience
                    } else {
                        $item['shop']['distance_km'] = null;
                        $item['distance_km'] = null;
                    }
                } else {
                    // Should theoretically not happen due to site: filter
                    $item['shop'] = null;
                    $item['distance_km'] = null;
                }
            }
        }

        Response::success($result);
    }

    private function matchShopByUrl(string $link, array $shops): ?array
    {
        $parsedLink = parse_url($link);
        if (!isset($parsedLink['host'])) {
            return null;
        }
        $linkHost = str_replace('www.', '', $parsedLink['host']);

        foreach ($shops as $shop) {
            $shopUrl = $shop['website_url'];
            $parsedShopUrl = parse_url($shopUrl);
            if (!isset($parsedShopUrl['host'])) continue;
            
            $shopHost = str_replace('www.', '', $parsedShopUrl['host']);

            // Check if link host contains shop host or vice versa
            if (strpos($linkHost, $shopHost) !== false || strpos($shopHost, $linkHost) !== false) {
                return $shop;
            }
        }
        return null;
    }

    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // Radius of the earth in km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = $earthRadius * $c;

        return round($distance, 2);
    }

    private function serperSearch(string $query): array
    {
        $settingModel = new Setting();
        $apiKey = $settingModel->get('serper_api_key');

        if (empty($apiKey)) {
            return [
                'error' => 'Serper.dev API is not configured. Please set serper_api_key in settings.',
                'items' => [],
                'status_code' => 503
            ];
        }

        $url = 'https://google.serper.dev/search';
        
        $data = [
            'q' => $query,
            'gl' => 'cm' // Cameroon
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local dev
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $apiKey,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return [
                'error' => 'Serper API request failed',
                'status_code' => $httpCode,
                'response' => json_decode($response, true)
            ];
        }

        $data = json_decode($response, true);

        // Simplify results for the frontend (Serper format -> ColiXpress format)
        $items = [];
        
        // Handle organic results
        if (isset($data['organic'])) {
            foreach ($data['organic'] as $item) {
                $items[] = [
                    'title' => $item['title'] ?? '',
                    'link' => $item['link'] ?? '',
                    'snippet' => $item['snippet'] ?? '',
                    'thumbnail' => null // Serper organic results usually don't have thumbnails in the main list
                ];
            }
        }

        return [
            'query' => $query,
            'total_results' => count($items), // Serper doesn't always give total results count
            'items' => $items
        ];
    }
}
