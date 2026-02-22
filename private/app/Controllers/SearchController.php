<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
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
        } else {
            // Option: If no shop_id, we could potentially search across all shops if we had a list, 
            // but Google Custom Search has limits on query length. 
            // For now, we'll assume the user wants a general search or the CX is configured for specific sites.
            // Or we could fetch all shop URLs and construct a massive OR query (risky).
            // Let's stick to simple query or let the user provide 'site:...' in q if they want.
        }

        // Construct the final query
        $finalQuery = trim($query . ' ' . $siteFilter);

        // Perform Google Custom Search
        $result = $this->googleCustomSearch($finalQuery);
        
        // Handle API errors gracefully
        if (isset($result['error'])) {
            Response::json($result, $result['status_code'] ?? 500);
        }

        Response::success($result);
    }

    private function googleCustomSearch(string $query): array
    {
        $apiKey = defined('GOOGLE_API_KEY') ? GOOGLE_API_KEY : '';
        $cx = defined('GOOGLE_SEARCH_CX') ? GOOGLE_SEARCH_CX : '';

        if (empty($apiKey) || empty($cx) || $apiKey === 'YOUR_GOOGLE_API_KEY_HERE') {
            return [
                'error' => 'Google Custom Search API is not configured. Please set GOOGLE_API_KEY and GOOGLE_SEARCH_CX in App.php',
                'items' => [],
                'status_code' => 503
            ];
        }

        $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
            'key' => $apiKey,
            'cx' => $cx,
            'q' => $query
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local dev
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return [
                'error' => 'Google API request failed',
                'status_code' => $httpCode,
                'response' => json_decode($response, true)
            ];
        }

        $data = json_decode($response, true);

        // Simplify results for the frontend
        $items = [];
        if (isset($data['items'])) {
            foreach ($data['items'] as $item) {
                $items[] = [
                    'title' => $item['title'] ?? '',
                    'link' => $item['link'] ?? '',
                    'snippet' => $item['snippet'] ?? '',
                    'thumbnail' => $item['pagemap']['cse_thumbnail'][0]['src'] ?? null
                ];
            }
        }

        return [
            'query' => $query,
            'total_results' => $data['searchInformation']['totalResults'] ?? 0,
            'items' => $items
        ];
    }
}
