<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * School-level users see their own school only. Creating and editing schools
 * is a network-level operation regardless of what a custom role may grant.
 */
final class SchoolController extends Controller
{
    public function index(Request $request): Response
    {
        $sql = 'SELECT s.*, (SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur WHERE ur.school_id = s.id) AS user_count
                  FROM schools s WHERE s.deleted_at IS NULL';
        $schools = $this->auth()->isNetwork()
            ? $this->db()->all("$sql ORDER BY s.name")
            : $this->db()->all("$sql AND s.id = ?", [$this->schoolId()]);

        return $this->view('schools/index', ['schools' => $schools, 'isNetwork' => $this->auth()->isNetwork()]);
    }

    public function create(Request $request): Response
    {
        $this->requireNetwork();
        return $this->view('schools/form', ['school' => null]);
    }

    public function store(Request $request): Response
    {
        $this->requireNetwork();
        $v = $this->validator($request, null);
        if ($v->fails()) {
            return $this->invalid('/schools/create', $v->errors(), $request->post);
        }
        $data = $v->validated();
        $id = $this->db()->insert('schools', [
            'name' => $data['name'], 'code' => $data['code'], 'default_locale' => $data['default_locale'], 'is_active' => 1,
        ]);
        \App\Services\SchoolDefaults::ensure($this->db(), $id, $data['default_locale']);
        $this->audit('create', 'school', $id, null, $data);
        return $this->redirect('/schools', __('schools.created'));
    }

    public function edit(Request $request, int $id): Response
    {
        $this->requireNetwork();
        return $this->view('schools/form', ['school' => $this->find($id)]);
    }

    public function update(Request $request, int $id): Response
    {
        $this->requireNetwork();
        $school = $this->find($id);
        $v = $this->validator($request, $id);
        if ($v->fails()) {
            return $this->invalid("/schools/$id/edit", $v->errors(), $request->post);
        }
        $data = $v->validated();
        // The school being worked in cannot be deactivated from inside itself.
        $data['is_active'] = ($request->has('is_active') || $id === $this->schoolId()) ? 1 : 0;
        $this->db()->update('schools', $data, ['id' => $id]);
        $this->audit(
            'update',
            'school',
            $id,
            array_intersect_key($school, $data),
            $data
        );
        return $this->redirect('/schools', __('schools.updated'));
    }

    private function requireNetwork(): void
    {
        if (!$this->auth()->isNetwork()) {
            throw new HttpException(403);
        }
    }

    private function find(int $id): array
    {
        return $this->db()->one('SELECT * FROM schools WHERE id = ? AND deleted_at IS NULL', [$id])
            ?? throw new HttpException(404);
    }

    private function validator(Request $request, ?int $id): Validator
    {
        $input = $request->post;
        if (isset($input['code']) && is_string($input['code'])) {
            $input['code'] = strtoupper($input['code']);
        }
        $v = Validator::make($input, [
            'name' => 'required|string|max:150',
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/'],
            'default_locale' => 'required|in:he,ar',
        ], 'schools.fields');

        $code = $v->validated()['code'] ?? null;
        if ($code !== null && $this->db()->value('SELECT 1 FROM schools WHERE code = ? AND id <> ?', [$code, $id ?? 0]) !== null) {
            $v->addError('code', __('schools.errors.code_taken'));
        }
        return $v;
    }
}
