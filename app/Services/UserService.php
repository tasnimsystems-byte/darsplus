<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

/**
 * Rules for managing users inside a school:
 *  - A school-level administrator sees and edits only users who hold a role in the
 *    active school, and never network-level users.
 *  - Nobody can hand out more than they hold: a school-level actor may assign only
 *    school-level roles whose permissions are a subset of their own.
 *  - Network-level roles are assigned only by network-level actors.
 */
final class UserService
{
    public function __construct(
        private Database $db,
        private Auth $auth,
        private UserRepository $users,
        private RoleRepository $roles,
    ) {
    }

    /** Roles the current actor may assign in the active school. */
    public function assignableRoles(int $schoolId): array
    {
        $roles = $this->roles->forSchool($schoolId, $this->auth->isNetwork());
        if ($this->auth->isNetwork()) {
            return $roles;
        }
        $own = $this->auth->permissions();
        $codes = $this->roles->permissionCodesByRole(array_map(static fn ($r) => (int) $r['id'], $roles));
        return array_values(array_filter(
            $roles,
            static fn ($role) => array_diff($codes[(int) $role['id']], $own) === []
        ));
    }

    /** The user, if the actor may manage them from the active school; 404 otherwise (existence is not revealed). */
    public function findManageable(int $userId, int $schoolId): array
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            throw new HttpException(404);
        }
        if ($this->auth->isNetwork()) {
            if (!$this->users->belongsToSchool($userId, $schoolId) && !$this->users->isNetworkUser($userId)) {
                throw new HttpException(404);
            }
            return $user;
        }
        if (!$this->users->belongsToSchool($userId, $schoolId) || $this->users->isNetworkUser($userId)) {
            throw new HttpException(404);
        }
        return $user;
    }

    /** @return array{0: int, 1: string} new user id and the temporary password (shown once) */
    public function create(array $data, array $roleIds, int $schoolId): array
    {
        [$schoolRoles, $networkRoles] = $this->splitAndAuthorise($roleIds, $schoolId);
        $password = Passwords::generate();

        $userId = $this->db->transaction(function () use ($data, $schoolRoles, $networkRoles, $schoolId, $password): int {
            $id = $this->users->create([
                'username' => $data['username'],
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'locale' => $data['locale'],
                'password_hash' => Passwords::hash($password),
                'must_change_password' => 1,
                'created_by' => $this->auth->id(),
            ]);
            $this->users->replaceRoles($id, $schoolId, $schoolRoles, $this->auth->id());
            if ($networkRoles !== []) {
                $this->users->replaceRoles($id, null, $networkRoles, $this->auth->id());
            }
            AuditLogger::log('create', 'user', $id, null, [
                'username' => $data['username'], 'full_name' => $data['full_name'],
                'roles' => [...$schoolRoles, ...$networkRoles],
            ]);
            return $id;
        });
        return [$userId, $password];
    }

    public function update(array $user, array $data, array $roleIds, int $schoolId): void
    {
        $userId = (int) $user['id'];
        [$schoolRoles, $networkRoles] = $this->splitAndAuthorise($roleIds, $schoolId);

        $fields = ['full_name' => $data['full_name'], 'email' => $data['email'], 'locale' => $data['locale']];
        // Activation is global, so a school administrator may change it only for users of this school alone.
        if ($this->mayToggleActive($userId) && $userId !== $this->auth->id()) {
            $fields['is_active'] = $data['is_active'];
        }

        $this->db->transaction(function () use ($user, $userId, $fields, $schoolRoles, $networkRoles, $schoolId): void {
            $before = $this->users->roleIds($userId, $schoolId);
            // Roles the actor is not allowed to assign are not theirs to remove either.
            $assignable = array_map(static fn ($r) => (int) $r['id'], $this->assignableRoles($schoolId));
            $schoolRoles = [...$schoolRoles, ...array_diff($before, $assignable)];
            $this->users->update($userId, $fields + ['updated_by' => $this->auth->id()]);

            // An actor never edits their own roles (prevents accidental lock-out and self-escalation).
            if ($userId !== $this->auth->id()) {
                $this->users->replaceRoles($userId, $schoolId, $schoolRoles, $this->auth->id());
                if ($this->auth->isNetwork()) {
                    $this->users->replaceRoles($userId, null, $networkRoles, $this->auth->id());
                }
            }
            AuditLogger::log(
                'update',
                'user',
                $userId,
                ['full_name' => $user['full_name'], 'email' => $user['email'], 'is_active' => $user['is_active'], 'roles' => $before],
                $fields + ['roles' => $userId !== $this->auth->id() ? $schoolRoles : $before]
            );
        });
    }

    public function mayToggleActive(int $userId): bool
    {
        return $this->auth->isNetwork()
            || ($this->users->schoolCount($userId) <= 1 && !$this->users->isNetworkUser($userId));
    }

    /**
     * Network actor: the account is deleted (soft). School actor: the user is removed
     * from this school, and deleted only if no other role remains anywhere.
     */
    public function remove(array $user, int $schoolId): void
    {
        $userId = (int) $user['id'];
        if ($userId === $this->auth->id()) {
            throw new HttpException(403);
        }
        $this->db->transaction(function () use ($user, $userId, $schoolId): void {
            if ($this->auth->isNetwork()) {
                $this->users->softDelete($userId, $this->auth->id());
                $action = 'delete';
            } else {
                $this->users->replaceRoles($userId, $schoolId, [], $this->auth->id());
                $action = 'remove_from_school';
                if (!$this->users->hasAnyRole($userId)) {
                    $this->users->softDelete($userId, $this->auth->id());
                    $action = 'delete';
                }
            }
            AuditLogger::log($action, 'user', $userId, ['username' => $user['username']], null);
        });
    }

    public function resetPassword(array $user): string
    {
        $password = Passwords::generate();
        $this->users->update((int) $user['id'], [
            'password_hash' => Passwords::hash($password),
            'must_change_password' => 1,
            'password_changed_at' => date('Y-m-d H:i:s'),
            'updated_by' => $this->auth->id(),
        ]);
        AuditLogger::log('reset_password', 'user', (int) $user['id']);
        return $password;
    }

    /** @return array{0: int[], 1: int[]} school-level and network-level role ids, all verified as assignable */
    private function splitAndAuthorise(array $roleIds, int $schoolId): array
    {
        $assignable = [];
        foreach ($this->assignableRoles($schoolId) as $role) {
            $assignable[(int) $role['id']] = $role['level'];
        }
        $school = $network = [];
        foreach ($roleIds as $roleId) {
            if (!isset($assignable[$roleId])) {
                throw new HttpException(403);
            }
            if ($assignable[$roleId] === 'network') {
                $network[] = $roleId;
            } else {
                $school[] = $roleId;
            }
        }
        return [$school, $network];
    }
}
