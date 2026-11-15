<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\UserService;

final class UserController extends Controller
{
    private UserRepository $users;
    private UserService $service;

    public function __construct()
    {
        $this->users = new UserRepository($this->db());
        $this->service = new UserService($this->db(), $this->auth(), $this->users, new RoleRepository($this->db()));
    }

    public function index(Request $request): Response
    {
        $schoolId = $this->schoolId();
        $perPage = (int) App::config('app.per_page');
        $search = mb_substr($request->str('q'), 0, 100);
        [$rows, $total] = $this->users->paginate($schoolId, $this->auth()->isNetwork(), $search, $this->page($request), $perPage);

        return $this->view('users/index', [
            'users' => $rows,
            'roles' => $this->users->rolesByUser(array_map(static fn ($u) => (int) $u['id'], $rows), $schoolId),
            'total' => $total,
            'page' => $this->page($request),
            'perPage' => $perPage,
            'search' => $search,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('users/form', [
            'user' => null,
            'roles' => $this->service->assignableRoles($this->schoolId()),
            'selected' => [],
            'canToggleActive' => false,
            'isSelf' => false,
        ]);
    }

    public function store(Request $request): Response
    {
        $v = $this->validator($request, null);
        $roleIds = $request->ids('roles');
        if ($roleIds === []) {
            $v->addError('roles', __('users.errors.role_required'));
        }
        if ($v->fails()) {
            return $this->invalid('/users/create', $v->errors(), $request->post);
        }

        [, $password] = $this->service->create($v->validated(), $roleIds, $this->schoolId());
        // The temporary password is displayed exactly once, on the next page.
        App::session()->flash('temp_password', $password);
        return $this->redirect('/users', __('users.created'));
    }

    public function edit(Request $request, int $id): Response
    {
        $schoolId = $this->schoolId();
        $user = $this->service->findManageable($id, $schoolId);
        return $this->view('users/form', [
            'user' => $user,
            'roles' => $this->service->assignableRoles($schoolId),
            'selected' => [...$this->users->roleIds($id, $schoolId), ...$this->users->roleIds($id, null)],
            'canToggleActive' => $this->service->mayToggleActive($id) && $id !== $this->auth()->id(),
            'isSelf' => $id === $this->auth()->id(),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $schoolId = $this->schoolId();
        $user = $this->service->findManageable($id, $schoolId);
        $v = $this->validator($request, $id);
        $roleIds = $request->ids('roles');
        if ($roleIds === [] && $id !== $this->auth()->id()) {
            $v->addError('roles', __('users.errors.role_required'));
        }
        if ($v->fails()) {
            return $this->invalid("/users/$id/edit", $v->errors(), $request->post);
        }

        $data = $v->validated();
        $data['is_active'] = $request->has('is_active') ? 1 : 0;
        $this->service->update($user, $data, $roleIds, $schoolId);
        return $this->redirect('/users', __('users.updated'));
    }

    public function destroy(Request $request, int $id): Response
    {
        $schoolId = $this->schoolId();
        $this->service->remove($this->service->findManageable($id, $schoolId), $schoolId);
        return $this->redirect('/users', __('users.deleted'));
    }

    public function resetPassword(Request $request, int $id): Response
    {
        $user = $this->service->findManageable($id, $this->schoolId());
        App::session()->flash('temp_password', $this->service->resetPassword($user));
        return $this->redirect('/users', __('users.password_reset', ['name' => $user['full_name']]));
    }

    private function validator(Request $request, ?int $userId): Validator
    {
        $rules = [
            'full_name' => 'required|string|max:150',
            'email' => 'email|max:190',
            'locale' => 'in:he,ar',
        ];
        if ($userId === null) {
            $rules['username'] = ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9._-]+$/'];
        }
        $input = $request->post;
        if (isset($input['username']) && is_string($input['username'])) {
            $input['username'] = mb_strtolower($input['username']);
        }
        $v = Validator::make($input, $rules, 'users.fields');
        $data = $v->validated();

        if ($userId === null && !empty($data['username']) && $this->users->usernameTaken($data['username'])) {
            $v->addError('username', __('users.errors.username_taken'));
        }
        if (!empty($data['email']) && $this->users->emailTaken($data['email'], $userId)) {
            $v->addError('email', __('users.errors.email_taken'));
        }
        return $v;
    }
}
