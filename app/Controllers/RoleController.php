<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\RoleRepository;
use App\Services\AccessScope;

/**
 * System roles are shared by every school, so only network-level users may change
 * their permissions. A school may add custom roles of its own; whoever defines one
 * can grant only permissions they hold themselves.
 */
final class RoleController extends Controller
{
    private RoleRepository $roles;

    public function __construct()
    {
        $this->roles = new RoleRepository($this->db());
    }

    public function index(Request $request): Response
    {
        return $this->view('roles/index', [
            'roles' => $this->roles->forSchool($this->schoolId(), true),
            'isNetwork' => $this->auth()->isNetwork(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, []);
    }

    public function store(Request $request): Response
    {
        $schoolId = $this->schoolId();
        $v = Validator::make($request->post, ['name' => 'required|string|max:100'], 'roles.fields');
        if ($v->fails()) {
            return $this->invalid('/roles/create', $v->errors(), $request->post);
        }
        $permissionIds = $this->grantable($request->ids('permissions'));

        $code = 'custom_' . bin2hex(random_bytes(4));
        $id = $this->roles->create($schoolId, $code, $v->validated()['name'], $permissionIds, $this->scopeMode($request, 'assigned'));
        $this->audit('create', 'role', $id, null, ['name' => $v->validated()['name'], 'permissions' => $permissionIds]);
        return $this->redirect('/roles', __('roles.created'));
    }

    public function edit(Request $request, int $id): Response
    {
        $role = $this->editable($id);
        return $this->form($role, $this->roles->permissionIds($id));
    }

    public function update(Request $request, int $id): Response
    {
        $role = $this->editable($id);
        $before = $this->roles->permissionIds($id);
        $requested = $this->grantable($request->ids('permissions'));
        // Permissions the actor cannot grant stay exactly as they were.
        $grantableIds = $this->grantableIds();
        $permissionIds = [...$requested, ...array_diff($before, $grantableIds)];

        if (!$role['is_system']) {
            $v = Validator::make($request->post, ['name' => 'required|string|max:100'], 'roles.fields');
            if ($v->fails()) {
                return $this->invalid("/roles/$id/edit", $v->errors(), $request->post);
            }
            $this->roles->rename($id, $v->validated()['name']);
        }
        $this->roles->setPermissions($id, $permissionIds);
        $this->roles->setScopeMode($id, $this->scopeMode($request, $role['scope_mode']));
        sort($before);
        sort($permissionIds);
        $this->audit('update', 'role', $id, ['permissions' => $before], ['permissions' => $permissionIds]);
        return $this->redirect('/roles', __('roles.updated'));
    }

    public function destroy(Request $request, int $id): Response
    {
        $role = $this->editable($id);
        if ($role['is_system']) {
            throw new HttpException(403);
        }
        if ($this->roles->inUse($id)) {
            return $this->redirect('/roles', null, __('roles.errors.in_use'));
        }
        $this->roles->delete($id);
        $this->audit('delete', 'role', $id, ['name' => $role['name']], null);
        return $this->redirect('/roles', __('roles.deleted'));
    }

    private function form(?array $role, array $selected): Response
    {
        $grantable = $this->grantableIds();
        $matrix = [];
        foreach ($this->roles->allPermissions() as $permission) {
            $matrix[$permission['module']][$permission['action']] = [
                'id' => (int) $permission['id'],
                'checked' => in_array((int) $permission['id'], $selected, true),
                'grantable' => in_array((int) $permission['id'], $grantable, true),
            ];
        }
        return $this->view('roles/form', [
            'role' => $role,
            'matrix' => $matrix,
            'actions' => array_values(App::config('permissions.actions')),
            'canGrantAll' => $this->canGrantAll(),
        ]);
    }

    /** Only someone who sees the whole school can create a role that does. */
    private function canGrantAll(): bool
    {
        return (new AccessScope($this->db(), $this->auth()))->isUnrestricted();
    }

    private function scopeMode(Request $request, string $current): string
    {
        $requested = $request->str('scope_mode');
        if (!in_array($requested, ['all', 'assigned'], true) || $requested === $current) {
            return $current;
        }
        if ($requested === 'all' && !$this->canGrantAll()) {
            throw new HttpException(403);
        }
        return $requested;
    }

    /** The role if the current user may edit it from the active school. */
    private function editable(int $id): array
    {
        $role = $this->roles->findVisible($id, $this->schoolId());
        if ($role === null) {
            throw new HttpException(404);
        }
        if ($role['is_system'] && !$this->auth()->isNetwork()) {
            throw new HttpException(403);
        }
        return $role;
    }

    /** @return int[] ids of permissions the current user may put on a role */
    private function grantableIds(): array
    {
        $own = $this->auth()->permissions();
        $ids = [];
        foreach ($this->roles->allPermissions() as $permission) {
            if ($this->auth()->isNetwork() || in_array($permission['code'], $own, true)) {
                $ids[] = (int) $permission['id'];
            }
        }
        return $ids;
    }

    /** Requested ids reduced to real, grantable permissions; anything else is a forged request. */
    private function grantable(array $requested): array
    {
        if (array_diff($requested, $this->grantableIds()) !== []) {
            throw new HttpException(403);
        }
        return $requested;
    }
}
