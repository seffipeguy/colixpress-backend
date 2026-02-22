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

        $shopId = $request->query('shop_id');
        $siteFilter = '';

        if ($shopId) {
            $shopModel = new Shop();
            $shop = $shopModel->find((int) $shopId);
            
            if (!$shop) {
                Response::notFound('Shop not found');
            }

            if (!empty($shop['website_url'])) {
                $siteFilter = 'site:' . $shop['website_url'];
            } else {
                Response::error('This shop does not have a website URL configured', 400);
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

        Response::success($result);
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
