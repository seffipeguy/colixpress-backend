<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Notification;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     * Query: ?unread_only=1&page=1&per_page=20
     */
    public function index(Request $request): void
    {
        $model = new Notification();
        $unreadOnly = (bool) $request->query('unread_only', 0);
        $result = $model->getByUser($this->userId(), $request->page(), $request->perPage(), $unreadOnly);

        $data = $result['data'];
        $total = $result['total'];
        $unreadCount = $model->unreadCount($this->userId());

        Response::json([
            'success'      => true,
            'data'         => $data,
            'unread_count' => $unreadCount,
            'meta'         => [
                'total'       => $total,
                'page'        => $request->page(),
                'per_page'    => $request->perPage(),
                'total_pages' => (int) ceil($total / max($request->perPage(), 1)),
            ],
        ]);
    }

    /**
     * PUT /api/notifications/{id}/read
     */
    public function markRead(Request $request): void
    {
        $model = new Notification();
        $model->markAsRead((int) $request->param('id'), $this->userId());
        Response::success(null, 'Marked as read');
    }

    /**
     * PUT /api/notifications/read-all
     */
    public function markAllRead(Request $request): void
    {
        $model = new Notification();
        $model->markAllAsRead($this->userId());
        Response::success(null, 'All notifications marked as read');
    }
}
