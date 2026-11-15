<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\Request;
use App\Core\Response;

/** Classes of the working year. Users with a restricted scope see only their own classes. */
final class ClassController extends CrudController
{
    protected function definition(): array
    {
        $schoolId = $this->schoolId();
        $levels = fn () => $this->options('SELECT id, name AS label FROM grade_levels WHERE school_id = ? ORDER BY level_number', [$schoolId]);
        return [
            'table' => 'classes', 'entity' => 'class', 'path' => '/classes', 'lang' => 'classes',
            'module' => 'structure', 'scope' => 'year', 'order' => 'level_sort, t.name',
            'unique' => [['name']], 'show' => true,
            'listExtra' => ['student_count' => 'classes.student_count'],
            'filters' => ['grade_level_id' => ['label' => 'classes.fields.grade_level_id', 'options' => $levels(), 'sql' => 't.grade_level_id = ?']],
            'fields' => [
                'name' => ['rules' => 'required|max:40', 'list' => true, 'attrs' => 'maxlength="40"'],
                'grade_level_id' => ['type' => 'select', 'rules' => 'required', 'options' => $levels, 'list' => true],
                'homeroom_teacher_id' => ['type' => 'select', 'list' => true, 'options' => fn () => $this->options(
                    "SELECT id, CONCAT(last_name, ' ', first_name) AS label FROM teachers
                      WHERE school_id = ? AND deleted_at IS NULL AND is_active = 1 ORDER BY last_name, first_name",
                    [$schoolId]
                )],
                'room_id' => ['type' => 'select', 'list' => true, 'options' => fn () => $this->options(
                    'SELECT id, name AS label FROM rooms WHERE school_id = ? ORDER BY name',
                    [$schoolId]
                )],
            ],
        ];
    }

    protected function listQuery(): array
    {
        [$condition, $params] = $this->accessScope()->classCondition('t.id');
        return [
            'select' => ", gl.level_number AS level_sort,
                (SELECT COUNT(*) FROM class_enrollments e JOIN students s ON s.id = e.student_id
                  WHERE e.class_id = t.id AND e.left_on IS NULL AND s.deleted_at IS NULL) AS student_count",
            'join' => 'JOIN grade_levels gl ON gl.id = t.grade_level_id',
            'where' => $condition,
            'params' => $params,
        ];
    }

    protected function rowVisible(array $row): bool
    {
        return $this->accessScope()->canSeeClass((int) $row['id']);
    }

    public function show(Request $request, int $id): Response
    {
        $class = $this->find($id);
        $schoolId = $this->schoolId();
        $detail = $this->db()->one(
            "SELECT gl.name AS level_name, r.name AS room_name, CONCAT(t.last_name, ' ', t.first_name) AS homeroom_name
               FROM classes c JOIN grade_levels gl ON gl.id = c.grade_level_id
               LEFT JOIN rooms r ON r.id = c.room_id
               LEFT JOIN teachers t ON t.id = c.homeroom_teacher_id
              WHERE c.id = ?",
            [$id]
        );
        return $this->view('classes/show', [
            'class' => $class + $detail,
            'readOnly' => (bool) $this->year()['is_locked'],
            'students' => can('students.view') ? $this->db()->all(
                'SELECT s.id, s.student_code, s.first_name, s.last_name, s.status
                   FROM class_enrollments e JOIN students s ON s.id = e.student_id
                  WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL
                  ORDER BY s.last_name, s.first_name',
                [$id]
            ) : null,
            'assignments' => $this->db()->all(
                "SELECT ta.id, ta.weekly_hours, ta.group_name, sub.name AS subject_name, t.id AS teacher_id,
                        CONCAT(t.last_name, ' ', t.first_name) AS teacher_name
                   FROM teaching_assignments ta
                   JOIN subjects sub ON sub.id = ta.subject_id
                   JOIN teachers t ON t.id = ta.teacher_id
                  WHERE ta.class_id = ? ORDER BY sub.name, t.last_name",
                [$id]
            ),
            'teachers' => $this->fields()['homeroom_teacher_id']['options'],
            'subjects' => $this->options('SELECT id, name AS label FROM subjects WHERE school_id = ? AND is_active = 1 ORDER BY name', [$schoolId]),
        ]);
    }
}
