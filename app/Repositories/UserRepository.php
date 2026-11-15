<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Users are global (one person may work in several schools); membership of a
 * school is expressed by user_roles. Every listing here is scoped to a school.
 */
final class UserRepository
{
    private const PUBLIC_COLUMNS = 'u.id, u.username, u.email, u.full_name, u.locale, u.is_active,
        u.must_change_password, u.last_login_at, u.created_at';

    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM users u WHERE u.id = ? AND u.deleted_at IS NULL',
            [$id]
        );
    }

    /** @return array{0: array, 1: int} rows and total count */
    public function paginate(int $schoolId, bool $includeNetwork, string $search, int $page, int $perPage): array
    {
        $where = 'u.deleted_at IS NULL AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND (ur.school_id = ?'
            . ($includeNetwork ? ' OR ur.school_id IS NULL' : '') . '))';
        $params = [$schoolId];
        if ($search !== '') {
            $where .= ' AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $total = (int) $this->db->value("SELECT COUNT(*) FROM users u WHERE $where", $params);
        $rows = $this->db->all(
            'SELECT ' . self::PUBLIC_COLUMNS . " FROM users u WHERE $where ORDER BY u.full_name, u.id LIMIT ? OFFSET ?",
            [...$params, $perPage, ($page - 1) * $perPage]
        );
        return [$rows, $total];
    }

    /** @return array<int, array[]> user id => roles held at the school (and network roles) */
    public function rolesByUser(array $userIds, int $schoolId): array
    {
        if ($userIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $map = [];
        foreach ($this->db->all(
            "SELECT ur.user_id, r.id, r.code, r.name, r.is_system, r.level
               FROM user_roles ur JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id IN ($in) AND (ur.school_id = ? OR ur.school_id IS NULL)
              ORDER BY r.id",
            [...$userIds, $schoolId]
        ) as $row) {
            $map[(int) $row['user_id']][] = $row;
        }
        return $map;
    }

    /** @return int[] role ids held at the given school, or at network level when $schoolId is null */
    public function roleIds(int $userId, ?int $schoolId): array
    {
        $rows = $schoolId === null
            ? $this->db->column('SELECT role_id FROM user_roles WHERE user_id = ? AND school_id IS NULL', [$userId])
            : $this->db->column('SELECT role_id FROM user_roles WHERE user_id = ? AND school_id = ?', [$userId, $schoolId]);
        return array_map('intval', $rows);
    }

    public function belongsToSchool(int $userId, int $schoolId): bool
    {
        return $this->db->value('SELECT 1 FROM user_roles WHERE user_id = ? AND school_id = ? LIMIT 1', [$userId, $schoolId]) !== null;
    }

    public function isNetworkUser(int $userId): bool
    {
        return $this->db->value('SELECT 1 FROM user_roles WHERE user_id = ? AND school_id IS NULL LIMIT 1', [$userId]) !== null;
    }

    public function hasAnyRole(int $userId): bool
    {
        return $this->db->value('SELECT 1 FROM user_roles WHERE user_id = ? LIMIT 1', [$userId]) !== null;
    }

    public function schoolCount(int $userId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(DISTINCT school_id) FROM user_roles WHERE user_id = ? AND school_id IS NOT NULL',
            [$userId]
        );
    }

    /** Usernames and e-mails stay reserved even after a soft delete, so audit history stays unambiguous. */
    public function usernameTaken(string $username, ?int $exceptId = null): bool
    {
        return $this->db->value('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, $exceptId ?? 0]) !== null;
    }

    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        return $this->db->value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $exceptId ?? 0]) !== null;
    }

    public function create(array $data): int
    {
        return $this->db->insert('users', $data);
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('users', $data, ['id' => $id]);
    }

    public function replaceRoles(int $userId, ?int $schoolId, array $roleIds, ?int $actorId): void
    {
        if ($schoolId === null) {
            $this->db->run('DELETE FROM user_roles WHERE user_id = ? AND school_id IS NULL', [$userId]);
        } else {
            $this->db->run('DELETE FROM user_roles WHERE user_id = ? AND school_id = ?', [$userId, $schoolId]);
        }
        foreach (array_unique($roleIds) as $roleId) {
            $this->db->insert('user_roles', [
                'user_id' => $userId, 'role_id' => (int) $roleId, 'school_id' => $schoolId, 'created_by' => $actorId,
            ]);
        }
    }

    public function softDelete(int $userId, ?int $actorId): void
    {
        $this->db->run('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
        $this->db->update(
            'users',
            ['deleted_at' => date('Y-m-d H:i:s'), 'is_active' => 0, 'updated_by' => $actorId],
            ['id' => $userId]
        );
    }

    public function passwordHash(int $userId): ?string
    {
        $hash = $this->db->value('SELECT password_hash FROM users WHERE id = ? AND deleted_at IS NULL', [$userId]);
        return $hash === null ? null : (string) $hash;
    }
}
