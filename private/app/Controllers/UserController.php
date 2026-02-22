<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;

class UserController extends Controller
{
    /**
     * GET /api/user/profile
     */
    public function profile(Request $request): void
    {
        $userModel = new User();
        $profile = $userModel->profile($this->userId());

        if (!$profile) {
            Response::notFound('User not found');
        }

        Response::success($profile);
    }

    /**
     * PUT /api/user/profile
     * Body: { "first_name": "John", "last_name": "Doe", "email": "john@example.com" }
     */
    public function updateProfile(Request $request): void
    {
        $userModel = new User();
        $allowed = ['first_name', 'last_name', 'email'];
        $data = [];

        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if (empty($data)) {
            Response::error('No data to update', 422);
        }

        // Validate email format if provided
        if (isset($data['email']) && $data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email format', 422);
        }

        $userModel->update($this->userId(), $data);
        $profile = $userModel->profile($this->userId());

        Response::success($profile, 'Profile updated');
    }

    /**
     * POST /api/user/profile-photo
     * Multipart: profile_photo (file)
     */
    public function updatePhoto(Request $request): void
    {
        $file = $request->file('profile_photo');
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Response::error('No file uploaded', 422);
        }

        // Validate type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            Response::error('Only JPG, PNG and WebP images are allowed', 422);
        }

        // Validate size
        if ($file['size'] > MAX_UPLOAD_SIZE) {
            Response::error('File too large (max 5MB)', 422);
        }

        // Create upload directory
        $uploadDir = UPLOAD_DIR . '/profiles';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Save file
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'user_' . $this->userId() . '_' . time() . '.' . $ext;
        $filepath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            Response::error('Failed to save file', 500);
        }

        // Update user
        $photoUrl = UPLOAD_URL . '/profiles/' . $filename;
        $userModel = new User();
        $userModel->update($this->userId(), ['profile_photo' => $photoUrl]);

        Response::success(['profile_photo' => $photoUrl], 'Photo updated');
    }

    /**
     * DELETE /api/user/account
     */
    public function deleteAccount(Request $request): void
    {
        $userModel = new User();
        $userModel->update($this->userId(), ['is_active' => 0]);
        Response::success(null, 'Account deactivated');
    }
}
