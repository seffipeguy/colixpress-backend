<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\Models\ShopTag;

class ShopController extends Controller
{
    /**
     * Helper to resolve category ID from request (id or name)
     */
    private function resolveCategoryId(Request $request): ?int
    {
        if ($request->query('category_id')) {
            return (int) $request->query('category_id');
        }

        $categoryName = $request->query('category');
        if ($categoryName) {
            $catModel = new ShopCategory();
            $category = $catModel->findByName($categoryName);
            return $category ? (int) $category['id'] : -1; // -1 to force empty result if not found
        }

        return null;
    }

    /**
     * GET /api/shops
     * Query: ?category_id=1&city=Douala&page=1&per_page=20
     */
    public function index(Request $request): void
    {
        $model = new Shop();
        $categoryId = $this->resolveCategoryId($request);
        
        $result = $model->getApproved(
            $request->page(),
            $request->perPage(),
            $categoryId,
            $request->query('city'),
            $request->query('q')
        );
        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * GET /api/shops/nearby
     * Query: ?lat=4.05&lng=9.70&radius=50
     */
    public function nearby(Request $request): void
    {
        $lat = $request->query('lat');
        $lng = $request->query('lng');

        if ($lat === null || $lng === null) {
            Response::error('Latitude (lat) and Longitude (lng) are required', 400);
        }

        $radius = (float) $request->query('radius', 50); // Default 50km
        $categoryId = $this->resolveCategoryId($request);
        $page = $request->page();
        $perPage = $request->perPage();
        $offset = ($page - 1) * $perPage;

        $model = new Shop();
        $shops = $model->getNearby((float) $lat, (float) $lng, $radius, $perPage, $offset, $categoryId);

        Response::success($shops);
    }

    /**
     * GET /api/shops/popular
     * Query: ?limit=10&category_id=1&city=Douala
     */
    public function popular(Request $request): void
    {
        $model = new Shop();
        $limit = (int) $request->query('limit', 10);
        $limit = max(1, min($limit, 50));
        $categoryId = $this->resolveCategoryId($request);

        $shops = $model->getPopular(
            $limit,
            $categoryId,
            $request->query('city')
        );

        Response::success($shops);
    }

    /**
     * GET /api/shops/{id}
     */
    public function show(Request $request): void
    {
        $model = new Shop();
        $shop = $model->findWithDetails((int) $request->param('id'));

        if (!$shop) {
            Response::notFound('Shop not found');
        }

        Response::success($shop);
    }

    /**
     * POST /api/shops — Shop owner creates a shop
     */
    public function store(Request $request): void
    {
        // Auth::requireRole('shop_owner', 'admin'); // Adjusted based on context, keeping simple
        if (!Auth::check()) {
            Response::unauthorized();
        }

        $request->validate(['name', 'address', 'country_id', 'phone']);

        $model = new Shop();
        
        // Prepare data
        $data = [
            'owner_id'          => Auth::userId(),
            'name'              => $request->input('name'),
            'short_description' => $request->input('short_description'),
            'description'       => $request->input('description'),
            'website_url'          => $request->input('website_url'),
            'product_url_pattern'  => $request->input('product_url_pattern'),
            'permissions'          => json_encode($request->input('permissions', [])),
            'address'           => $request->input('address'),
            'latitude'          => $request->input('latitude'),
            'longitude'         => $request->input('longitude'),
            'city'              => $request->input('city', 'Douala'),
            'quarter'           => $request->input('quarter'),
            'country_id'        => (int) $request->input('country_id'),
            'phone'             => $request->input('phone'),
            'opening_time'      => $request->input('opening_time'),
            'closing_time'      => $request->input('closing_time'),
        ];

        $id = $model->create($data);

        // Attach categories and tags
        if ($request->has('category_ids') && is_array($request->input('category_ids'))) {
            $model->attachCategories($id, $request->input('category_ids'));
        }
        
        if ($request->has('tag_ids') && is_array($request->input('tag_ids'))) {
            $model->attachTags($id, $request->input('tag_ids'));
        }

        Response::success($model->findWithDetails($id), 'Shop created, pending approval', 201);
    }

    /**
     * PUT /api/shops/{id}
     */
    public function update(Request $request): void
    {
        if (!Auth::check()) {
            Response::unauthorized();
        }

        $model = new Shop();
        $shop = $model->find((int) $request->param('id'));

        if (!$shop) {
            Response::notFound('Shop not found');
        }

        if ((int) $shop['owner_id'] !== Auth::userId() && !Auth::isAdmin()) {
            Response::forbidden();
        }

        $allowed = [
            'name', 'short_description', 'description', 'website_url',
            'product_url_pattern',
            'address', 'latitude', 'longitude', 'city', 'quarter',
            'phone', 'opening_time', 'closing_time'
        ];

        $data = [];
        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        // Handle permissions (JSON)
        if ($request->has('permissions')) {
            $data['permissions'] = json_encode($request->input('permissions'));
        }

        if (!empty($data)) {
            $model->update((int) $shop['id'], $data);
        }

        // Update relationships
        if ($request->has('category_ids') && is_array($request->input('category_ids'))) {
            $model->attachCategories((int) $shop['id'], $request->input('category_ids'));
        }
        
        if ($request->has('tag_ids') && is_array($request->input('tag_ids'))) {
            $model->attachTags((int) $shop['id'], $request->input('tag_ids'));
        }

        Response::success($model->findWithDetails((int) $shop['id']), 'Shop updated');
    }

    /**
     * GET /api/shops/my — Shop owner's shops
     */
    public function myShops(Request $request): void
    {
        if (!Auth::check()) {
            Response::unauthorized();
        }
        $model = new Shop();
        Response::success($model->getByOwner(Auth::userId()));
    }

    /**
     * GET /api/shop-categories
     */
    public function categories(Request $request): void
    {
        $model = new ShopCategory();
        Response::success($model->getActive());
    }

    /**
     * GET /api/shop-tags
     */
    public function tags(Request $request): void
    {
        $model = new ShopTag();
        if ($request->query('q')) {
            Response::success($model->search($request->query('q')));
        } else {
            Response::success($model->getAll());
        }
    }

    /**
     * PUT /api/shops/{id}/approve — Admin approves a shop
     */
    public function approve(Request $request): void
    {
        if (!Auth::isAdmin()) {
            Response::forbidden();
        }

        $model = new Shop();
        $shop = $model->find((int) $request->param('id'));

        if (!$shop) {
            Response::notFound('Shop not found');
        }

        $model->update((int) $shop['id'], ['is_approved' => 1]);
        Response::success(null, 'Shop approved');
    }

    /**
     * PUT /api/shops/{id}/feature — Admin met en avant / retire une boutique
     */
    public function feature(Request $request): void
    {
        if (!Auth::isAdmin()) {
            Response::forbidden();
        }

        $model = new Shop();
        $shop  = $model->find((int) $request->param('id'));

        if (!$shop) {
            Response::notFound('Shop not found');
        }

        $featured = (int) (bool) $request->input('is_featured', 1);
        $model->update((int) $shop['id'], ['is_featured' => $featured]);
        $msg = $featured ? 'Boutique mise en avant' : 'Boutique retirée de la mise en avant';
        Response::success(null, $msg);
    }

}
