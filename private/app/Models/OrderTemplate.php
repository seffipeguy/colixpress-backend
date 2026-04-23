<?php

namespace App\Models;

use App\Core\Model;

class OrderTemplate extends Model
{
    protected string $table = 'order_templates';

    public function create(array $data): int
    {
        // Encode JSON fields if they are arrays
        if (isset($data['default_values']) && is_array($data['default_values'])) {
            $data['default_values'] = json_encode($data['default_values']);
        }
        if (isset($data['required_fields']) && is_array($data['required_fields'])) {
            $data['required_fields'] = json_encode($data['required_fields']);
        }

        return parent::create($data);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    public function getByUser(int $userId): array
    {
        return $this->where('user_id', $userId);
    }

    public function generateSlug(): string
    {
        do {
            $slug = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 8);
        } while ($this->findBySlug($slug));
        
        return $slug;
    }

    /**
     * Préparer un template pour la réponse API :
     * - Décoder les champs JSON
     * - Supprimer les champs sensibles (file_path, user_id si demandé)
     */
    public static function sanitizeForResponse(array $template, bool $hideUserId = false): array
    {
        $template['default_values']  = isset($template['default_values'])
            ? json_decode($template['default_values'], true) ?? []
            : [];
        $template['required_fields'] = isset($template['required_fields'])
            ? json_decode($template['required_fields'], true) ?? []
            : [];

        // Nettoyer les package_media : supprimer file_path, reconstruire file_url absolue
        if (!empty($template['default_values']['package_media'])) {
            $template['default_values']['package_media'] = array_map(function ($m) {
                unset($m['file_path']);
                if (isset($m['file_url']) && !str_starts_with($m['file_url'], 'http')) {
                    // /uploads/media/file.png → https://api.colixpress.com/uploads/media/file.png
                    $m['file_url'] = APP_URL . $m['file_url'];
                }
                return $m;
            }, $template['default_values']['package_media']);
        }

        if ($hideUserId) {
            unset($template['user_id']);
        }

        return $template;
    }
}
