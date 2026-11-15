<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\App;
use App\Core\Database;
use App\Services\SchoolDefaults;

/**
 * Required data: the permission catalogue and the system roles.
 * Idempotent — safe to run after every deployment.
 */
final class BaseSeeder
{
    public function __construct(private Database $db)
    {
    }

    public function run(): void
    {
        $config = App::config('permissions');
        SchoolDefaults::ensureAll($this->db);

        $this->db->transaction(function () use ($config): void {
            $existing = $this->db->column('SELECT code FROM permissions');
            foreach ($config['modules'] as $module) {
                foreach ($config['actions'] as $action) {
                    if (!in_array("$module.$action", $existing, true)) {
                        $this->db->insert('permissions', [
                            'module' => $module, 'action' => $action, 'code' => "$module.$action",
                        ]);
                    }
                }
            }
            $permissionIds = array_column($this->db->all('SELECT id, code FROM permissions'), 'id', 'code');

            foreach ($config['roles'] as $code => $definition) {
                $exists = $this->db->value('SELECT id FROM roles WHERE school_id IS NULL AND code = ?', [$code]);
                if ($exists !== null) {
                    continue; // never overwrite permissions edited by an administrator
                }
                $roleId = $this->db->insert('roles', [
                    'school_id' => null, 'code' => $code, 'name' => $code,
                    'level' => $definition['level'], 'is_system' => 1,
                    'scope_mode' => $definition['scope'] ?? 'assigned',
                ]);
                foreach ($definition['grants'] as $module => $letters) {
                    foreach (str_split($letters) as $letter) {
                        $this->db->insert('role_permissions', [
                            'role_id' => $roleId,
                            'permission_id' => $permissionIds[$module . '.' . $config['actions'][$letter]],
                        ]);
                    }
                }
            }
        });
    }
}
