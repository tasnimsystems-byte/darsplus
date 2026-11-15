<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AttendanceStats;
use App\Services\EnrollmentService;
use App\Services\GradeStats;

/**
 * Students of the school. A user with a restricted access scope sees — in the
 * list, the card and every action — only students currently enrolled in the
 * classes that scope covers.
 */
final class StudentController extends CrudController
{
    public const STATUSES = ['active', 'left', 'graduated'];

    protected function definition(): array
    {
        $schoolId = $this->schoolId();
        $yearId = (int) ($this->auth()->year()['id'] ?? 0);
        [$classCondition, $classParams] = $this->accessScope()->classCondition('c.id');
        $classes = $this->options(
            "SELECT c.id, c.name AS label FROM classes c JOIN grade_levels gl ON gl.id = c.grade_level_id
              WHERE c.school_id = ? AND c.academic_year_id = ? AND $classCondition ORDER BY gl.level_number, c.name",
            [$schoolId, $yearId, ...$classParams]
        );
        $statuses = [];
        foreach (self::STATUSES as $status) {
            $statuses[$status] = __('students.statuses.' . $status);
        }

        return [
            'table' => 'students', 'entity' => 'student', 'path' => '/students', 'lang' => 'students',
            'module' => 'students', 'order' => 't.last_name, t.first_name, t.id', 'softDelete' => true, 'userstamps' => true,
            'unique' => [['student_code']], 'search' => ['t.first_name', 't.last_name', 't.student_code'],
            'show' => true, 'rowName' => static fn ($row) => $row['last_name'] . ' ' . $row['first_name'],
            'listExtra' => ['class_name' => 'students.fields.class_id'],
            'headerActions' => [
                ['path' => '/students/import', 'label' => 'students.import.title', 'icon' => 'file-earmark-arrow-up', 'permission' => 'students.create'],
            ],
            'filters' => [
                'class_id' => ['label' => 'students.fields.class_id', 'options' => $classes, 'sql' => 'e.class_id = ?'],
                'status' => ['label' => 'students.fields.status', 'options' => $statuses, 'sql' => 't.status = ?'],
            ],
            'fields' => [
                'last_name' => ['rules' => 'required|max:80', 'list' => true, 'attrs' => 'maxlength="80"'],
                'first_name' => ['rules' => 'required|max:80', 'list' => true, 'attrs' => 'maxlength="80"'],
                'student_code' => ['rules' => ['max:30', 'regex:/^[A-Za-z0-9_-]+$/'], 'list' => true,
                    'attrs' => 'maxlength="30" dir="ltr"', 'help' => 'students.code_help'],
                'gender' => ['type' => 'select', 'options' => ['m' => __('students.genders.m'), 'f' => __('students.genders.f')]],
                'birth_date' => ['type' => 'date'],
                'class_id' => ['type' => 'select', 'virtual' => true, 'options' => $classes, 'help' => 'students.class_help'],
                'status' => ['type' => 'select', 'rules' => 'required', 'options' => $statuses, 'list' => true, 'default' => 'active'],
            ],
        ];
    }

    protected function listQuery(): array
    {
        // The year id is an integer read from the database, not user input.
        $yearId = (int) ($this->auth()->year()['id'] ?? 0);
        $scope = $this->accessScope();
        [$condition, $params] = $scope->isUnrestricted() ? ['1 = 1', []] : $scope->classCondition('e.class_id');
        return [
            'select' => ', c.name AS class_name',
            'join' => "LEFT JOIN class_enrollments e ON e.student_id = t.id AND e.left_on IS NULL
                         AND e.class_id IN (SELECT id FROM classes WHERE academic_year_id = $yearId)
                       LEFT JOIN classes c ON c.id = e.class_id",
            'where' => $condition,
            'params' => $params,
        ];
    }

    protected function rowVisible(array $row): bool
    {
        return $this->accessScope()->canSeeStudent((int) $row['id']);
    }

    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
        if ($data['birth_date'] !== null && $data['birth_date'] > date('Y-m-d')) {
            $v->addError('birth_date', __('students.errors.birth_future'));
        }
        // Without a class the student would be invisible to a user whose scope is their classes.
        if ($data['class_id'] === null && !$this->accessScope()->isUnrestricted()) {
            $v->addError('class_id', __('validation.required', ['field' => __('students.fields.class_id')]));
        }
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if ($data['student_code'] === null) {
            $data['student_code'] = $existing['student_code'] ?? self::nextCode($this->db(), $this->schoolId());
        }
        return $data;
    }

    /** Next free internal code of the form S00042. */
    public static function nextCode(\App\Core\Database $db, int $schoolId): string
    {
        $number = (int) $db->value(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(student_code, 2) AS UNSIGNED)), 0) FROM students
              WHERE school_id = ? AND student_code REGEXP '^S[0-9]+$'",
            [$schoolId]
        );
        do {
            $code = 'S' . str_pad((string) ++$number, 5, '0', STR_PAD_LEFT);
        } while ($db->value('SELECT 1 FROM students WHERE school_id = ? AND student_code = ?', [$schoolId, $code]) !== null);
        return $code;
    }

    protected function afterSave(int $id, ?array $existing): void
    {
        $year = $this->auth()->year();
        if ($year !== null && !$year['is_locked']) {
            (new EnrollmentService($this->db()))->setClass($this->schoolId(), $id, $this->virtual['class_id'], $year);
        }
    }

    protected function virtualValues(array $row): array
    {
        $year = $this->auth()->year();
        $current = $year === null ? null : (new EnrollmentService($this->db()))->current((int) $row['id'], (int) $year['id']);
        return ['class_id' => $current['class_id'] ?? ''];
    }

    protected function afterStoreRedirect(int $id): string
    {
        return "/students/$id";
    }

    public function show(Request $request, int $id): Response
    {
        $student = $this->find($id);
        // Opening a student's card is recorded.
        $this->audit('view', 'student', $id);
        $year = $this->auth()->year();
        $yearId = (int) ($year['id'] ?? 0);
        $scope = $this->accessScope()->assignmentCondition('ta');
        $grades = $results = [];
        if (can('assessments.view') && $year !== null) {
            // Only what this viewer's scope covers: a subject teacher sees their own subject.
            foreach ((new GradeStats($this->db(), $this->schoolId(), $yearId, $scope, ['student_id' => $id]))->rows() as $row) {
                $grades[$row['subject_id']][$row['term_id']] = round($row['avg'], 1);
            }
            $results = $this->db()->all(
                "SELECT a.title, a.assessment_date, a.max_score, r.score, r.result_status, r.verbal_evaluation,
                        sj.name AS subject_name, tm.name AS term_name, ty.name AS type_name
                   FROM assessment_results r
                   JOIN assessments a ON a.id = r.assessment_id AND a.status = 'published' AND a.deleted_at IS NULL
                   JOIN teaching_assignments ta ON ta.id = a.teaching_assignment_id
                   JOIN subjects sj ON sj.id = ta.subject_id JOIN academic_terms tm ON tm.id = a.term_id JOIN assessment_types ty ON ty.id = a.type_id
                  WHERE r.student_id = ? AND a.academic_year_id = ? AND {$scope[0]}
                  ORDER BY a.assessment_date DESC, a.id DESC LIMIT 100",
                [$id, $yearId, ...$scope[1]]
            );
        }
        return $this->view('students/show', [
            'student' => $student,
            'year' => $year,
            'grades' => $grades,
            'results' => $results,
            'subjectNames' => array_column($this->db()->all('SELECT id, name FROM subjects WHERE school_id = ?', [$this->schoolId()]), 'name', 'id'),
            'termNames' => array_column($this->db()->all('SELECT id, name FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order', [$yearId]), 'name', 'id'),
            'attendance' => can('attendance.view') && $year !== null ? AttendanceStats::forStudent($this->db(), $id, $yearId, $scope) : null,
            'partialScope' => $this->accessScope()->fullClassIds() !== null,
            // Family details, interventions and parent contacts: only for staff who see the student's whole class.
            'full' => $full = $this->accessScope()->canSeeStudentFully($id),
            'guardians' => $full && can('parent_contacts.view')
                ? $this->db()->all('SELECT * FROM guardians WHERE student_id = ? ORDER BY is_primary DESC, id', [$id]) : null,
            'interventions' => $full && can('interventions.view') ? $this->db()->all(
                'SELECT i.id, i.difficulty_area, i.status, i.start_date, i.review_date, u.full_name AS owner_name
                   FROM student_interventions i LEFT JOIN users u ON u.id = i.owner_user_id
                  WHERE i.student_id = ? AND i.deleted_at IS NULL ORDER BY i.start_date DESC',
                [$id]
            ) : null,
            'contacts' => $full && can('parent_contacts.view') ? $this->db()->all(
                'SELECT p.id, p.contact_type, p.contacted_on, p.follow_up_status, u.full_name AS staff_name
                   FROM parent_contacts p LEFT JOIN users u ON u.id = p.staff_user_id
                  WHERE p.student_id = ? AND p.deleted_at IS NULL ORDER BY p.contacted_on DESC LIMIT 30',
                [$id]
            ) : null,
            'enrollments' => $this->db()->all(
                'SELECT e.enrolled_on, e.left_on, c.id AS class_id, c.name AS class_name, y.name AS year_name, gl.name AS level_name
                   FROM class_enrollments e
                   JOIN classes c ON c.id = e.class_id
                   JOIN academic_years y ON y.id = c.academic_year_id
                   JOIN grade_levels gl ON gl.id = c.grade_level_id
                  WHERE e.student_id = ? ORDER BY y.start_date DESC, e.enrolled_on DESC, e.id DESC',
                [$id]
            ),
        ]);
    }
}
