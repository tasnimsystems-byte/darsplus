<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Key/value settings of one school (table school_settings). */
final class SchoolSettings
{
    public static function all(Database $db, int $schoolId, string $prefix = ''): array
    {
        return array_column($db->all(
            'SELECT setting_key, setting_value FROM school_settings WHERE school_id = ? AND setting_key LIKE ?',
            [$schoolId, addcslashes($prefix, '%_\\') . '%']
        ), 'setting_value', 'setting_key');
    }

    public static function set(Database $db, int $schoolId, string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            $db->run('DELETE FROM school_settings WHERE school_id = ? AND setting_key = ?', [$schoolId, $key]);
            return;
        }
        $db->run(
            'INSERT INTO school_settings (school_id, setting_key, setting_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$schoolId, $key, $value]
        );
    }
}
