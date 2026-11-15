<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\AccessScope;

/**
 * Shared list / create / edit / delete for school-owned records.
 *
 * A subclass returns a definition:
 *   table       table name (from code, never from input)
 *   entity      name used in the audit log
 *   path        route prefix, e.g. /subjects
 *   lang        translation file, e.g. subjects → subjects.title, subjects.fields.<name>
 *   module      permission module (view/create/update/delete are checked by the routes)
 *   scope       'school' (default) or 'year' (rows belong to the working academic year)
 *   fields      name => [type, rules, list, options, default, virtual, help]
 *               type: text | number | date | select | checkbox | textarea | multicheck
 *               virtual fields are validated but not stored in the row (see afterSave)
 *   unique      list of column sets that must be unique inside the scope
 *   search      columns matched by the search box
 *   filters     name => [label, options, sql] extra list filters
 *   order       ORDER BY expression
 *   softDelete  true when the table has deleted_at
 *   userstamps  true when the table has created_by / updated_by
 *   show        true when rows link to a detail page at <path>/<id>
 *
 * Every query is restricted to the active school (and year), so an id belonging
 * to another school simply does not exist here.
 */
abstract class CrudController extends Controller
{
    private ?array $definition = null;
    private ?array $fields = null;
    /** Values of virtual fields from the last validated request. */
    protected array $virtual = [];

    abstract protected function definition(): array;

    final protected function def(): array
    {
        return $this->definition ??= $this->definition() + [
            'scope' => 'school', 'unique' => [], 'search' => [], 'filters' => [], 'order' => 't.id',
            'softDelete' => false, 'userstamps' => false, 'show' => false,
            'listExtra' => [], 'rowActions' => [], 'headerActions' => [], 'nameField' => 'name',
        ];
    }

    /** Field definitions with select options resolved. */
    final protected function fields(): array
    {
        if ($this->fields === null) {
            $this->fields = [];
            foreach ($this->def()['fields'] as $name => $field) {
                $field += ['type' => 'text', 'rules' => '', 'list' => false, 'virtual' => false, 'default' => ''];
                if (isset($field['options']) && $field['options'] instanceof \Closure) {
                    $field['options'] = ($field['options'])();
                }
                $this->fields[$name] = $field;
            }
        }
        return $this->fields;
    }

    // ---- Hooks ---------------------------------------------------------------

    /** Extra SELECT columns and JOINs for the list. */
    protected function listQuery(): array
    {
        return ['select' => '', 'join' => '', 'where' => '1 = 1', 'params' => []];
    }

    /** Further cross-field validation. */
    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        return $data;
    }

    protected function afterSave(int $id, ?array $existing): void
    {
    }

    /** Translation key explaining why the row cannot be deleted, or null. */
    protected function deleteBlocker(array $row): ?string
    {
        return null;
    }

    /** False hides the row from this user (reported as 404). */
    protected function rowVisible(array $row): bool
    {
        return true;
    }

    /** False makes the row read-only for this user (edit, update and delete answer 403). */
    protected function rowEditable(array $row): bool
    {
        return true;
    }

    /** Values for virtual fields when editing. */
    protected function virtualValues(array $row): array
    {
        return [];
    }

    protected function afterStoreRedirect(int $id): string
    {
        return $this->def()['path'];
    }

    // ---- Context -------------------------------------------------------------

    protected function year(): array
    {
        return $this->auth()->year() ?? throw new RedirectException('/years', 'app.errors.no_year');
    }

    protected function yearId(): int
    {
        return (int) $this->year()['id'];
    }

    private ?AccessScope $accessScope = null;

    protected function accessScope(): AccessScope
    {
        return $this->accessScope ??= new AccessScope($this->db(), $this->auth());
    }

    /** Year-scoped data of a locked year is read-only. */
    protected function guardWritable(): void
    {
        if ($this->def()['scope'] === 'year' && $this->year()['is_locked']) {
            throw new RedirectException($this->def()['path'], 'app.errors.year_locked');
        }
    }

    /** @return array{0: string, 1: array} */
    protected function scopeCondition(): array
    {
        $sql = 't.school_id = ?';
        $params = [$this->schoolId()];
        if ($this->def()['scope'] === 'year') {
            $sql .= ' AND t.academic_year_id = ?';
            $params[] = $this->yearId();
        }
        if ($this->def()['softDelete']) {
            $sql .= ' AND t.deleted_at IS NULL';
        }
        return [$sql, $params];
    }

    protected function findEditable(int $id): array
    {
        $row = $this->find($id);
        if (!$this->rowEditable($row)) {
            throw new HttpException(403);
        }
        return $row;
    }

    protected function find(int $id): array
    {
        [$scope, $params] = $this->scopeCondition();
        $row = $this->db()->one("SELECT t.* FROM `{$this->def()['table']}` t WHERE t.id = ? AND $scope", [$id, ...$params]);
        if ($row === null || !$this->rowVisible($row)) {
            throw new HttpException(404);
        }
        return $row;
    }

    // ---- Actions -------------------------------------------------------------

    public function index(Request $request): Response
    {
        $def = $this->def();
        [$where, $params] = $this->scopeCondition();
        $extra = $this->listQuery() + ['select' => '', 'join' => '', 'where' => '1 = 1', 'params' => []];
        $where .= ' AND ' . $extra['where'];
        $params = [...$params, ...$extra['params']];

        $search = mb_substr($request->str('q'), 0, 100);
        if ($search !== '' && $def['search'] !== []) {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where .= ' AND (' . implode(' OR ', array_map(static fn ($c) => "$c LIKE ?", $def['search'])) . ')';
            array_push($params, ...array_fill(0, count($def['search']), $like));
        }

        $active = [];
        foreach ($def['filters'] as $name => $filter) {
            $value = $request->str($name);
            if ($value !== '' && array_key_exists($value, $filter['options'])) {
                $where .= ' AND ' . $filter['sql'];
                $params[] = ctype_digit($value) ? (int) $value : $value;
                $active[$name] = $value;
            }
        }

        $perPage = (int) App::config('app.per_page');
        $page = $this->page($request);
        $from = "FROM `{$def['table']}` t {$extra['join']} WHERE $where";
        $total = (int) $this->db()->value("SELECT COUNT(DISTINCT t.id) $from", $params);
        $rows = $this->db()->all(
            "SELECT DISTINCT t.*{$extra['select']} $from ORDER BY {$def['order']} LIMIT ? OFFSET ?",
            [...$params, $perPage, ($page - 1) * $perPage]
        );

        return $this->view('crud/index', [
            'def' => $def, 'fields' => $this->fields(), 'rows' => $rows, 'total' => $total, 'page' => $page,
            'perPage' => $perPage, 'search' => $search, 'activeFilters' => $active,
            'readOnly' => $def['scope'] === 'year' && (bool) $this->year()['is_locked'],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->guardWritable();
        return $this->view('crud/form', ['def' => $this->def(), 'fields' => $this->fields(), 'row' => null, 'values' => []]);
    }

    public function store(Request $request): Response
    {
        $this->guardWritable();
        $def = $this->def();
        [$data, $v] = $this->validateInput($request, null);
        if ($v->fails()) {
            return $this->invalid($def['path'] . '/create', $v->errors(), $request->post);
        }

        $id = $this->db()->transaction(function () use ($def, $data): int {
            $data = $this->beforeSave($data, null);
            $audit = $data;
            $data['school_id'] = $this->schoolId();
            if ($def['scope'] === 'year') {
                $data['academic_year_id'] = $this->yearId();
            }
            if ($def['userstamps']) {
                $data['created_by'] = $this->auth()->id();
            }
            $id = $this->db()->insert($def['table'], $data);
            $this->afterSave($id, null);
            $this->audit('create', $def['entity'], $id, null, $audit);
            return $id;
        });
        return $this->redirect($this->afterStoreRedirect($id), 'app.created');
    }

    public function edit(Request $request, int $id): Response
    {
        $this->guardWritable();
        $row = $this->findEditable($id);
        return $this->view('crud/form', [
            'def' => $this->def(), 'fields' => $this->fields(), 'row' => $row, 'values' => $row + $this->virtualValues($row),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $this->guardWritable();
        $def = $this->def();
        $row = $this->findEditable($id);
        [$data, $v] = $this->validateInput($request, $row);
        if ($v->fails()) {
            return $this->invalid("{$def['path']}/$id/edit", $v->errors(), $request->post);
        }

        $this->db()->transaction(function () use ($def, $data, $row, $id): void {
            $data = $this->beforeSave($data, $row);
            $audit = $data;
            if ($def['userstamps']) {
                $data['updated_by'] = $this->auth()->id();
            }
            if ($data !== []) {
                $this->db()->update($def['table'], $data, ['id' => $id]);
            }
            $this->afterSave($id, $row);
            $this->audit('update', $def['entity'], $id, array_intersect_key($row, $audit), $audit);
        });
        return $this->redirect($this->afterStoreRedirect($id), 'app.updated');
    }

    public function destroy(Request $request, int $id): Response
    {
        $this->guardWritable();
        $def = $this->def();
        $row = $this->findEditable($id);
        $blocker = $this->deleteBlocker($row);
        if ($blocker !== null) {
            return $this->redirect($def['path'], null, $blocker);
        }
        try {
            if ($def['softDelete']) {
                $this->db()->update($def['table'], ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            } else {
                // Foreign keys are RESTRICT: a record that is still referenced cannot be removed.
                $this->db()->run("DELETE FROM `{$def['table']}` WHERE id = ?", [$id]);
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirect($def['path'], null, 'app.errors.in_use');
            }
            throw $e;
        }
        $this->audit('delete', $def['entity'], $id, array_intersect_key($row, $this->def()['fields']), null);
        return $this->redirect($def['path'], 'app.deleted');
    }

    // ---- Validation ----------------------------------------------------------

    /** @return array{0: array, 1: Validator} stored columns and the validator */
    protected function validateInput(Request $request, ?array $existing): array
    {
        $def = $this->def();
        $input = $request->post;
        $rules = [];
        foreach ($this->fields() as $name => $field) {
            if (in_array($field['type'], ['checkbox', 'multicheck'], true)) {
                continue;
            }
            $rule = is_array($field['rules']) ? $field['rules'] : array_filter(explode('|', $field['rules']));
            if ($field['type'] === 'select') {
                $rule[] = 'in:' . implode(',', array_keys($field['options']));
            }
            if ($field['type'] === 'date') {
                $rule[] = 'date';
            }
            $rules[$name] = $rule;
        }
        $v = Validator::make($input, $rules, $def['lang'] . '.fields');
        $validated = $v->validated();

        $data = [];
        $this->virtual = [];
        foreach ($this->fields() as $name => $field) {
            $value = match ($field['type']) {
                'checkbox' => $request->has($name) ? 1 : 0,
                'multicheck' => array_values(array_intersect($request->ids($name), array_keys($field['options']))),
                default => $validated[$name] ?? null,
            };
            if ($field['type'] === 'select' && $value !== null && ctype_digit((string) $value)) {
                $value = (int) $value;
            }
            if ($field['virtual']) {
                $this->virtual[$name] = $value;
            } else {
                $data[$name] = $value;
            }
        }

        if (!$v->fails()) {
            [$scope, $params] = $this->scopeCondition();
            $scope = str_replace(' AND t.deleted_at IS NULL', '', $scope); // codes stay reserved after a soft delete
            foreach ($def['unique'] as $columns) {
                if (in_array(null, array_intersect_key($data, array_flip($columns)), true)) {
                    continue;
                }
                $cond = implode(' AND ', array_map(static fn ($c) => "t.`$c` = ?", $columns));
                $taken = $this->db()->value(
                    "SELECT 1 FROM `{$def['table']}` t WHERE $scope AND $cond AND t.id <> ? LIMIT 1",
                    [...$params, ...array_map(static fn ($c) => $data[$c], $columns), $existing['id'] ?? 0]
                );
                if ($taken !== null) {
                    $v->addError($columns[0], __('app.errors.unique', ['field' => __("{$def['lang']}.fields.$columns[0]")]));
                }
            }
            $this->validateExtra($v, $data + $this->virtual, $existing);
        }
        return [$data, $v];
    }

    /** Active users of the school, for "responsible person" selects. */
    protected function userOptions(): array
    {
        return $this->options(
            'SELECT u.id, u.full_name AS label FROM users u WHERE u.deleted_at IS NULL AND u.is_active = 1
                AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.school_id = ?) ORDER BY u.full_name',
            [$this->schoolId()]
        );
    }

    /** Students of the working year whose class the user sees in full (all subjects). */
    protected function studentOptions(): array
    {
        [$condition, $params] = $this->accessScope()->fullClassCondition('c.id');
        return $this->options(
            "SELECT s.id, CONCAT(s.last_name, ' ', s.first_name, ' (', c.name, ')') AS label
               FROM students s JOIN class_enrollments e ON e.student_id = s.id AND e.left_on IS NULL JOIN classes c ON c.id = e.class_id
              WHERE s.school_id = ? AND s.deleted_at IS NULL AND c.academic_year_id = ? AND $condition
              ORDER BY s.last_name, s.first_name",
            [$this->schoolId(), (int) ($this->auth()->year()['id'] ?? 0), ...$params]
        );
    }

    /** id => label pairs for a select, from a table of the active school. */
    protected function options(string $sql, array $params = []): array
    {
        $options = [];
        foreach ($this->db()->all($sql, $params) as $row) {
            $options[(int) $row['id']] = (string) $row['label'];
        }
        return $options;
    }
}
