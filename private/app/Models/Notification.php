<?php

namespace App\Models;

use App\Core\Model;
use App\Services\PushNotificationService;
use PDO;

class Notification extends Model
{
    protected string $table = 'notifications';

    public function getByUser(int $userId, int $page, int $perPage, bool $unreadOnly = false): array
    {
        $where = 'user_id = :uid';
        $params = ['uid' => $userId];

        if ($unreadOnly) {
            $where .= ' AND is_read = 0';
        }

        return $this->paginate($page, $perPage, $where, $params, 'created_at DESC');
    }

    public function markAsRead(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE {$this->table} SET is_read = 1 WHERE id = :id AND user_id = :uid");
        return $stmt->execute(['id' => $id, 'uid' => $userId]);
    }

    public function markAllAsRead(int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE {$this->table} SET is_read = 1 WHERE user_id = :uid AND is_read = 0");
        return $stmt->execute(['uid' => $userId]);
    }

    public function unreadCount(int $userId): int
    {
        return $this->count('user_id = :uid AND is_read = 0', ['uid' => $userId]);
    }

    public static function send(int $userId, string $title, string $message, string $type = 'system', ?array $data = null): void
    {
        $model = new self();
        $model->create([
            'user_id' => $userId,
            'title'   => $title,
            'message' => $message,
            'type'    => $type,
            'data'    => $data ? json_encode($data) : null,
        ]);

        // Envoi push PWA en parallèle (non bloquant si échec)
        try {
            $push = new PushNotificationService();
            $push->sendToUser($userId, $title, $message, $data ?? []);
        } catch (\Throwable $e) {
            error_log('[PushNotification] Erreur: ' . $e->getMessage());
        }
    }
}
