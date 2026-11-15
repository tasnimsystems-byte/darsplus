<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/** In-system notifications only — nothing leaves the application (no e-mail, no SMS). */
final class Notifier
{
    public static function send(int $userId, ?int $schoolId, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        App::db()->insert('notifications', [
            'user_id' => $userId, 'school_id' => $schoolId, 'type' => $type,
            'title' => mb_substr($title, 0, 255), 'body' => $body, 'link' => $link,
        ]);
    }

    public static function unreadCount(int $userId, ?int $schoolId): int
    {
        return (int) App::db()->value(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL AND (school_id = ? OR school_id IS NULL)',
            [$userId, $schoolId ?? 0]
        );
    }
}
