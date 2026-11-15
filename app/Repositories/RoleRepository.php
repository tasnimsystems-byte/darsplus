<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RoleRepository
{
    public function __construct(private Database $db)
    {
    }

    /** System roles plus the custom roles of one school, with the number of users holding each there. */
    public function forSchool(int $schoolId, bool $includeNetworkLevel): array
    {
        $level = $includeNetworkLevel ? '' : "AND r.level = 'school'";
        return $this->db->all(
            "SELECT r.*, (SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur
                           WHERE ur.role_id = r.id AND (ur.school_id = ? OR ur.school_id IS NULL)) AS user_count
               FROM roles r
              WHERE ((r.school_id IS NULL AND r.is_system = 1 $level) OR r.school_id = ?)
              ORDER BY r.is_system DESC, r.level, r.id",
            [$schoolId, $schoolId]
        );
    }

    /** A role as seen from one school: a system role or that school's own custom role. */
    public function findVisible(int $id, int $schoolId): ?array
    {
        return $this->db->one(
            'SELECT * FROM roles WHERE id = ? AND ((school_id IS NULL AND is_system = 1) OR school_id = ?)',
            [$id, $schoolId]
        );
    }

    public function allPermissions(): array
    {
        return $this->db->all('SELECT id, module, action, code FROM permissions ORDER BY id');
    }

    /** @return int[] */
    public function permissionIds(int $roleId): array
    {
        return array_map('intval', $this->db->column('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]));
    }

    /** @return array<int, string[]> role id => permission codes */
    public function permissionCodesByRole(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($roleIds), '?'));
        $map = array_fill_keys($roleIds, []);
        foreach ($this->db->all(
            "SELECT rp.role_id, p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id IN ($in)",
            $roleIds
        ) as $row) {
            $map[(int) $row['role_id']][] = $row['code'];
        }
        return $map;
    }

    public function codeTaken(int $schoolId, string $code, ?int $exceptId = null): bool
    {
        return $this->db->value(
            'SELECT 1 FROM roles WHERE (school_id = ? OR school_id IS NULL) AND code = ? AND id <> ?',
            [$schoolId, $code, $exceptId ?? 0]
        ) !== null;
    }

    public function create(int $schoolId, string $code, string $name, array $permissionIds, string $scopeMode = 'assigned'): int
    {
        return $this->db->transaction(function () use ($schoolId, $code, $name, $permissionIds, $scopeMode): int {
            $id = $this->db->insert('roles', [
                'school_id' => $schoolId, 'code' => $code, 'name' => $name, 'level' => 'school', 'is_system' => 0,
                'scope_mode' => $scopeMode,
            ]);
            $this->setPermissions($id, $permissionIds);
            return $id;
        });
    }

    public function rename(int $id, string $name): void
    {
        $this->db->update('roles', ['name' => $name], ['id' => $id]);
    }

    public function setScopeMode(int $id, string $scopeMode): void
    {
        $this->db->update('roles', ['scope_mode' => $scopeMode], ['id' => $id]);
    }

    public function setPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->transaction(function () use ($roleId, $permissionIds): void {
            $this->db->run('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
            foreach (array_unique($permissionIds) as $permissionId) {
                $this->db->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => (int) $permissionId]);
            }
        });
    }

    public function inUse(int $roleId): bool
    {
        return $this->db->value('SELECT 1 FROM user_roles WHERE role_id = ? LIMIT 1', [$roleId]) !== null;
    }

    public function delete(int $roleId): void
    {
        $this->db->run('DELETE FROM roles WHERE id = ? AND is_system = 0', [$roleId]);
    }
}
