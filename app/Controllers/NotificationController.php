<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

/** A user's own notifications, for the active school and network-wide ones. */
final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('notifications/index', [
            'notifications' => $this->db()->all(
                'SELECT id, title, body, link, read_at, created_at FROM notifications
                  WHERE user_id = ? AND (school_id = ? OR school_id IS NULL)
                  ORDER BY id DESC LIMIT 100',
                [$this->auth()->id(), $this->schoolId()]
            ),
        ]);
    }

    public function markRead(Request $request, int $id): Response
    {
        $this->db()->run(
            'UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL',
            [$id, $this->auth()->id()]
        );
        return Response::redirect('/notifications');
    }

    public function markAllRead(Request $request): Response
    {
        $this->db()->run(
            'UPDATE notifications SET read_at = NOW()
              WHERE user_id = ? AND read_at IS NULL AND (school_id = ? OR school_id IS NULL)',
            [$this->auth()->id(), $this->schoolId()]
        );
        return Response::redirect('/notifications');
    }
}
