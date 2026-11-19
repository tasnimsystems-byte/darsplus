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
        $setup = $this->setupInput($request, $errors);
        if ($errors !== []) {
            return $this->invalid('/schools/create', $errors, $request->post);
        }
        // The school ready to work: defaults, first year and terms, grade levels and classes, principal's account.
        $result = \App\Services\SchoolSetup::create($this->db(), $data + $setup, $this->auth()->id());
        $this->audit('create', 'school', $result['school_id'], null, $data + $result['summary'] + ['principal' => $result['principal_username']]);
        if ($result['password'] !== null) {
            \App\Core\App::session()->flash('temp_password', $result['password']);
        }
        return $this->redirect('/schools', __('schools.setup.created', $result['summary'] + [
            'principal' => $result['principal_username'] ?? '—',
        ]));
    }

    /** The optional parts of a new school; each is checked here and reported on its own field. */
    private function setupInput(Request $request, ?array &$errors): array
    {
        $errors = [];
        $date = static fn (string $v): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
        $setup = [
            'year_name' => mb_substr($request->str('year_name'), 0, 30), 'year_start' => $request->str('year_start'), 'year_end' => $request->str('year_end'),
            'term_count' => $request->int('term_count', 2), 'levels_from' => $request->int('levels_from'), 'levels_to' => $request->int('levels_to'),
            'classes_per_level' => $request->int('classes_per_level'),
            'principal_name' => mb_substr($request->str('principal_name'), 0, 150), 'principal_username' => strtolower($request->str('principal_username')),
        ];
        if ($setup['year_start'] !== '' || $setup['year_end'] !== '') {
            if (!$date($setup['year_start']) || !$date($setup['year_end'])) {
                $errors['year_start'] = [__('schools.setup.errors.dates')];
            } elseif ($setup['year_end'] <= $setup['year_start']) {
                $errors['year_end'] = [__('app.errors.date_order')];
            }
        }
        if ($setup['term_count'] < 1 || $setup['term_count'] > 4) {
            $errors['term_count'] = [__('school_settings.errors.term_count')];
        }
        if ($setup['levels_from'] !== 0 || $setup['levels_to'] !== 0) {
            if ($setup['levels_from'] < 1 || $setup['levels_to'] > 12 || $setup['levels_to'] < $setup['levels_from']) {
                $errors['levels_from'] = [__('schools.setup.errors.levels')];
            }
        }
        if ($setup['classes_per_level'] < 0 || $setup['classes_per_level'] > 15) {
            $errors['classes_per_level'] = [__('schools.setup.errors.classes')];
        }
        if ($setup['principal_username'] !== '') {
            if (!preg_match('/^[a-z0-9._-]{3,60}$/', $setup['principal_username'])) {
                $errors['principal_username'] = [__('schools.setup.errors.username')];
            } elseif ($this->db()->value('SELECT 1 FROM users WHERE username = ?', [$setup['principal_username']]) !== null) {
                $errors['principal_username'] = [__('schools.setup.errors.username_taken')];
            }
        }
        return $setup;
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
