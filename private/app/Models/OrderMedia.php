<?php

namespace App\Models;

use App\Core\Model;

class OrderMedia extends Model
{
    protected string $table = 'order_media';

    public const MAX_PER_ORDER = 5;
    public const ALLOWED_MIME  = [
        'image/jpeg'  => 'jpg',
        'image/png'   => 'png',
        'image/webp'  => 'webp',
        'video/mp4'   => 'mp4',
    ];
    public const MAX_SIZE = 10 * 1024 * 1024; // 10 MB

    public function getByOrder(int $orderId): array
    {
        $rows = $this->where('order_id', $orderId);
        return array_map([$this, 'sanitize'], $rows);
    }

    private function sanitize(array $m): array
    {
        unset($m['file_path']);
        if (isset($m['file_url']) && !str_starts_with($m['file_url'], 'http')) {
            $m['file_url'] = APP_URL . $m['file_url'];
        }
        return $m;
    }

    public function countByOrder(int $orderId): int
    {
        return $this->count('order_id = :oid', ['oid' => $orderId]);
    }

    public function findForOrder(int $mediaId, int $orderId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE id = :id AND order_id = :oid LIMIT 1"
        );
        $stmt->execute(['id' => $mediaId, 'oid' => $orderId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }
}
