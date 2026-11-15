<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Professional directory of the teaching staff. Holds pedagogical management
 * data only; contact details are shown just to those who may edit teachers.
 */
final class TeacherController extends CrudController
{
    public const POSITION_TYPES = [
        'grade_coordinator', 'subject_coordinator', 'pedagogical_coordinator', 'counselor', 'vice_principal', 'other',
    ];

    protected function definition(): array
    {
        $schoolId = $this->schoolId();
        return [
            'table' => 'teachers', 'entity' => 'teacher', 'path' => '/teachers', 'lang' => 'teachers',
            'module' => 'teachers', 'order' => 't.last_name, t.first_name', 'softDelete' => true, 'userstamps' => true,
            'unique' => [['staff_code'], ['user_id']], 'search' => ['t.first_name', 't.last_name', 't.staff_code'],
            'show' => true, 'rowName' => static fn ($row) => $row['last_name'] . ' ' . $row['first_name'],
            'listExtra' => ['subject_names' => 'teachers.fields.subject_ids'],
            'fields' => [
                'last_name' => ['rules' => 'required|max:80', 'list' => true, 'attrs' => 'maxlength="80"'],
                'first_name' => ['rules' => 'required|max:80', 'list' => true, 'attrs' => 'maxlength="80"'],
                'staff_code' => ['rules' => 'max:30', 'attrs' => 'maxlength="30" dir="ltr"', 'help' => 'teachers.staff_code_help'],
                'phone' => ['rules' => ['max:30', 'regex:/^[0-9+\-() ]+$/'], 'attrs' => 'maxlength="30" dir="ltr" inputmode="tel"'],
                'user_id' => ['type' => 'select', 'help' => 'teachers.user_help', 'options' => fn () => $this->options(
                    "SELECT u.id, CONCAT(u.full_name, ' (', u.username, ')') AS label FROM users u
                      WHERE u.deleted_at IS NULL
                        AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.school_id = ?)
                      ORDER BY u.full_name",
                    [$schoolId]
                )],
                'subject_ids' => ['type' => 'multicheck', 'virtual' => true, 'options' => fn () => $this->options(
                    'SELECT id, name AS label FROM subjects WHERE school_id = ? AND is_active = 1 ORDER BY name',
                    [$schoolId]
                )],
                'is_active' => ['type' => 'checkbox', 'list' => true, 'default' => 1],
            ],
        ];
    }

    protected function listQuery(): array
    {
        return ['select' => ", (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ')
            FROM teacher_subjects ts JOIN subjects s ON s.id = ts.subject_id WHERE ts.teacher_id = t.id) AS subject_names"];
    }

    protected function virtualValues(array $row): array
    {
        return ['subject_ids' => array_map('intval', $this->db()->column(
            'SELECT subject_id FROM teacher_subjects WHERE teacher_id = ?',
            [$row['id']]
        ))];
    }

    protected function afterSave(int $id, ?array $existing): void
    {
        $this->db()->run('DELETE FROM teacher_subjects WHERE teacher_id = ?', [$id]);
        foreach ($this->virtual['subject_ids'] as $subjectId) {
            $this->db()->insert('teacher_subjects', [
                'school_id' => $this->schoolId(), 'teacher_id' => $id, 'subject_id' => $subjectId,
            ]);
        }
    }

    protected function afterStoreRedirect(int $id): string
    {
        return "/teachers/$id";
    }

    protected function deleteBlocker(array $row): ?string
    {
        $busy = $this->db()->value(
            'SELECT 1 FROM teaching_assignments ta JOIN academic_years y ON y.id = ta.academic_year_id
              WHERE ta.teacher_id = ? AND y.is_locked = 0
              UNION
             SELECT 1 FROM classes c JOIN academic_years y ON y.id = c.academic_year_id
              WHERE c.homeroom_teacher_id = ? AND y.is_locked = 0 LIMIT 1',
            [$row['id'], $row['id']]
        );
        return $busy === null ? null : 'teachers.errors.has_assignments';
    }

    public function show(Request $request, int $id): Response
    {
        $teacher = $this->find($id);
        $year = $this->auth()->year();
        $yearId = (int) ($year['id'] ?? 0);
        $schoolId = $this->schoolId();
        return $this->view('teachers/show', [
            'teacher' => $teacher,
            'year' => $year,
            'account' => $teacher['user_id'] === null ? null
                : $this->db()->one('SELECT full_name, username FROM users WHERE id = ?', [$teacher['user_id']]),
            'subjects' => $this->db()->column(
                'SELECT s.name FROM teacher_subjects ts JOIN subjects s ON s.id = ts.subject_id WHERE ts.teacher_id = ? ORDER BY s.name',
                [$id]
            ),
            'homerooms' => $this->db()->all('SELECT id, name FROM classes WHERE homeroom_teacher_id = ? AND academic_year_id = ? ORDER BY name', [$id, $yearId]),
            'assignments' => $this->db()->all(
                'SELECT ta.weekly_hours, ta.group_name, c.id AS class_id, c.name AS class_name, s.name AS subject_name
                   FROM teaching_assignments ta JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id
                  WHERE ta.teacher_id = ? AND ta.academic_year_id = ? ORDER BY c.name, s.name',
                [$id, $yearId]
            ),
            'positions' => $this->db()->all(
                'SELECT sp.*, gl.name AS level_name, s.name AS subject_name
                   FROM staff_positions sp
                   LEFT JOIN grade_levels gl ON gl.id = sp.grade_level_id
                   LEFT JOIN subjects s ON s.id = sp.subject_id
                  WHERE sp.teacher_id = ? AND sp.academic_year_id = ? ORDER BY sp.id',
                [$id, $yearId]
            ),
            'levels' => $this->options('SELECT id, name AS label FROM grade_levels WHERE school_id = ? ORDER BY level_number', [$schoolId]),
            'subjectOptions' => $this->options('SELECT id, name AS label FROM subjects WHERE school_id = ? ORDER BY name', [$schoolId]),
            'canEdit' => can('teachers.update') && $year !== null && !$year['is_locked'],
        ]);
    }

    public function storePosition(Request $request, int $id): Response
    {
        $this->find($id);
        $year = $this->year();
        $back = "/teachers/$id";
        if ($year['is_locked']) {
            return $this->redirect($back, null, 'app.errors.year_locked');
        }
        $schoolId = $this->schoolId();
        $v = Validator::make($request->post, [
            'position_type' => 'required|in:' . implode(',', self::POSITION_TYPES),
            'grade_level_id' => 'int', 'subject_id' => 'int', 'responsibilities' => 'max:2000',
        ], 'teachers.position_fields');
        $data = $v->validated();
        if (!$v->fails()) {
            foreach (['grade_level_id' => 'grade_levels', 'subject_id' => 'subjects'] as $field => $table) {
                if ($data[$field] !== null && $this->db()->value("SELECT 1 FROM `$table` WHERE id = ? AND school_id = ?", [$data[$field], $schoolId]) === null) {
                    $v->addError($field, __('validation.in', ['field' => __("teachers.position_fields.$field")]));
                }
            }
        }
        if ($v->fails()) {
            return $this->invalid($back, $v->errors(), $request->post);
        }
        $row = $data + ['school_id' => $schoolId, 'academic_year_id' => (int) $year['id'], 'teacher_id' => $id];
        $positionId = $this->db()->insert('staff_positions', $row);
        $this->audit('create', 'staff_position', $positionId, null, $row);
        return $this->redirect($back, 'app.created');
    }

    public function destroyPosition(Request $request, int $id, int $position): Response
    {
        $this->find($id);
        $row = $this->db()->one(
            'SELECT sp.id, sp.position_type, y.is_locked FROM staff_positions sp JOIN academic_years y ON y.id = sp.academic_year_id
              WHERE sp.id = ? AND sp.teacher_id = ? AND sp.school_id = ?',
            [$position, $id, $this->schoolId()]
        ) ?? throw new HttpException(404);
        if ($row['is_locked']) {
            return $this->redirect("/teachers/$id", null, 'app.errors.year_locked');
        }
        $this->db()->run('DELETE FROM staff_positions WHERE id = ?', [$position]);
        $this->audit('delete', 'staff_position', $position, ['position_type' => $row['position_type']], null);
        return $this->redirect("/teachers/$id", 'app.deleted');
    }
}
