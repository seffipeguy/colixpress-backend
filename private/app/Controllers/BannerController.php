<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Models\Banner;

class BannerController extends Controller
{
    /**
     * GET /api/banners — Public: active banners for the slider
     * Query: ?city=Douala
     * If authenticated, filters by user role automatically
     */
    public function index(Request $request): void
    {
        $model = new Banner();

        // Try to detect user role without blocking unauthenticated requests
        Auth::tryAuthenticate();

        $role = null;
        $city = $request->query('city');

        if (Auth::user()) {
            $role = Auth::user()['role'];
        }

        $banners = $model->getActive($role, $city);

        // Clean output for the app
        $result = array_map(function ($b) {
            $data = [
                'id'          => (int) $b['id'],
                'title'       => $b['title'],
                'description' => $b['description'],
                'image_url'   => $b['image_url'],
                'background_color' => $b['background_color'],
                'link_url'    => $b['link_url'],
                'link_type'   => $b['link_type'],
                'link_data'   => $b['link_data'] ? json_decode($b['link_data'], true) : null,
            ];
            return $data;
        }, $banners);

        Response::success($result);
    }

    /**
     * GET /api/admin/banners — Admin: all banners
     */
    public function adminIndex(Request $request): void
    {
        $this->requireRole('admin');
        $model = new Banner();
        $result = $model->paginate($request->page(), $request->perPage(), '1=1', [], 'position ASC, created_at DESC');
        Response::paginated($result['data'], $result['total'], $request->page(), $request->perPage());
    }

    /**
     * POST /api/admin/banners — Admin: create banner
     */
    public function store(Request $request): void
    {
        $this->requireRole('admin');
        $request->validate(['title']);

        $model = new Banner();

        $data = [
            'title'         => $request->input('title'),
            'description'   => $request->input('description'),
            'image_url'     => $request->input('image_url'),
            'background_color' => $request->input('background_color'),
            'link_url'      => $request->input('link_url'),
            'link_type'     => $request->input('link_type', 'none'),
            'link_data'     => $request->input('link_data') ? json_encode($request->input('link_data')) : null,
            'target_roles'  => $request->input('target_roles'),
            'target_cities' => $request->input('target_cities'),
            'position'      => (int) $request->input('position', 0),
            'is_active'     => (int) $request->input('is_active', 1),
            'valid_from'    => $request->input('valid_from'),
            'valid_until'   => $request->input('valid_until'),
        ];

        $id = $model->create($data);
        Response::success($model->find($id), 'Banner created', 201);
    }

    /**
     * GET /api/admin/banners/{id}
     */
    public function show(Request $request): void
    {
        $this->requireRole('admin');
        $model = new Banner();
        $banner = $model->find((int) $request->param('id'));

        if (!$banner) {
            Response::notFound('Banner not found');
        }

        Response::success($banner);
    }

    /**
     * PUT /api/admin/banners/{id}
     */
    public function update(Request $request): void
    {
        $this->requireRole('admin');
        $model = new Banner();
        $banner = $model->find((int) $request->param('id'));

        if (!$banner) {
            Response::notFound('Banner not found');
        }

        $allowed = [
            'title', 'description', 'image_url', 'background_color', 'link_url', 'link_type',
            'target_roles', 'target_cities', 'position', 'is_active',
            'valid_from', 'valid_until',
        ];

        $data = [];
        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if ($request->has('link_data')) {
            $data['link_data'] = $request->input('link_data') ? json_encode($request->input('link_data')) : null;
        }

        if (!empty($data)) {
            $model->update((int) $banner['id'], $data);
        }

        Response::success($model->find((int) $banner['id']), 'Banner updated');
    }

    /**
     * DELETE /api/admin/banners/{id}
     */
    public function destroy(Request $request): void
    {
        $this->requireRole('admin');
        $model = new Banner();
        $banner = $model->find((int) $request->param('id'));

        if (!$banner) {
            Response::notFound('Banner not found');
        }

        $model->delete((int) $banner['id']);
        Response::success(null, 'Banner deleted');
    }

    /**
     * PUT /api/admin/banners/reorder
     * Body: { "ids": [3, 1, 2, 5] }
     */
    public function reorder(Request $request): void
    {
        $this->requireRole('admin');
        $ids = $request->input('ids');

        if (!is_array($ids) || empty($ids)) {
            Response::error('ids array required', 422);
        }

        $model = new Banner();
        $model->reorder($ids);

        Response::success(null, 'Banners reordered');
    }

    /**
     * POST /api/admin/banners/{id}/upload — Upload banner image
     */
    public function uploadImage(Request $request): void
    {
        $this->requireRole('admin');
        $model = new Banner();
        $banner = $model->find((int) $request->param('id'));

        if (!$banner) {
            Response::notFound('Banner not found');
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            Response::error('Image file required', 422);
        }

        $file = $_FILES['image'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        if (!in_array($file['type'], $allowedTypes)) {
            Response::error('Invalid image type. Allowed: jpg, png, webp, gif', 422);
        }

        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxSize) {
            Response::error('Image too large. Max: 5MB', 422);
        }

        $ext = match ($file['type']) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        };

        $uploadDir = PUBLIC_PATH . '/uploads/banners/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = 'banner_' . $banner['id'] . '_' . time() . '.' . $ext;
        $filepath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            Response::error('Failed to save image', 500);
        }

        // Delete old image if exists
        if ($banner['image_url']) {
            $oldFile = PUBLIC_PATH . parse_url($banner['image_url'], PHP_URL_PATH);
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
        }

        $imageUrl = '/uploads/banners/' . $filename;
        $model->update((int) $banner['id'], ['image_url' => $imageUrl]);

        Response::success([
            'image_url' => $imageUrl,
        ], 'Image uploaded');
    }
}
