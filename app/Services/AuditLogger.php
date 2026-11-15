<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * Append-only audit trail. Callers pass only the fields worth recording —
 * never password hashes, tokens or file contents.
 */
final class AuditLogger
{
    private static ?string $ip = null;

    public static function setIp(?string $ip): void
    {
        self::$ip = $ip;
    }

    public static function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $old = null,
        ?array $new = null,
        ?int $userId = null,
    ): void {
        $auth = App::auth();
        App::db()->insert('audit_logs', [
            'school_id' => $auth->schoolId(),
            'user_id' => $userId ?? $auth->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_values' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            'ip_address' => self::$ip,
        ]);
    }
}
