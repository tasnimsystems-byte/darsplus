<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\Request;
use App\Core\Response;
use App\Services\SharedLessons;

/**
 * Study groups for shared lessons (principal / vice principal): a list of students,
 * possibly from several classes, and the teaching assignments that teach them.
 * A lesson of a linked assignment shows only the group's students.
 */
final class StudyGroupController extends CrudController
{
    protected function definition(): array
    {
        $schoolId = $this->schoolId();
        return [
            'table' => 'study_groups', 'entity' => 'study_group', 'path' => '/study-groups', 'lang' => 'study_groups',
            'module' => 'structure', 'scope' => 'year', 'order' => 't.name', 'unique' => [['name']], 'userstamps' => true,
            'show' => true, 'search' => ['t.name'], 'floatFilters' => true,
            'filters' => [
                'status' => ['label' => 'study_groups.filters.status', 'options' => [
                    'active' => __('study_groups.statuses.active'), 'no_members' => __('study_groups.statuses.no_members'),
                    'no_lessons' => __('study_groups.statuses.no_lessons'),
                ], 'sql' => "(CASE WHEN NOT EXISTS (SELECT 1 FROM teaching_assignments fa WHERE fa.study_group_id = t.id) THEN 'no_lessons'
                                   WHEN NOT EXISTS (SELECT 1 FROM study_group_students fm WHERE fm.group_id = t.id) THEN 'no_members'
                                   ELSE 'active' END) = ?"],
                'teacher_id' => ['label' => 'study_groups.filters.teachers', 'options' => $this->options(
                    "SELECT DISTINCT tc.id, CONCAT(tc.last_name, ' ', tc.first_name) AS label FROM teachers tc
                       JOIN teaching_assignments ta ON ta.teacher_id = tc.id JOIN study_groups g ON g.id = ta.study_group_id
                      WHERE tc.school_id = ? ORDER BY label",
                    [$schoolId]
                ), 'sql' => 't.id IN (SELECT fa.study_group_id FROM teaching_assignments fa WHERE fa.teacher_id = ?)'],
                'class_id' => ['label' => 'study_groups.filters.classes', 'options' => $this->options(
                    'SELECT DISTINCT c.id, c.name AS label FROM classes c JOIN teaching_assignments ta ON ta.class_id = c.id
                       JOIN study_groups g ON g.id = ta.study_group_id JOIN grade_levels gl ON gl.id = c.grade_level_id
                      WHERE c.school_id = ? ORDER BY gl.level_number, c.name',
                    [$schoolId]
                ), 'sql' => 't.id IN (SELECT fa.study_group_id FROM teaching_assignments fa WHERE fa.class_id = ?)'],
                'subject_id' => ['label' => 'study_groups.filters.subjects', 'options' => $this->options(
                    'SELECT DISTINCT s.id, s.name AS label FROM subjects s JOIN study_groups g ON g.subject_id = s.id WHERE s.school_id = ? ORDER BY s.name',
                    [$schoolId]
                ), 'sql' => 't.subject_id = ?'],
                'origin' => ['label' => 'study_groups.filters.origin', 'options' => [
                    'shared' => __('study_groups.origins.shared'), 'split' => __('study_groups.origins.split'), 'manual' => __('study_groups.origins.manual'),
                ], 'sql' => 't.origin = ?'],
            ],
            'headerActions' => [['path' => '/study-groups/shared', 'label' => 'study_groups.shared.title', 'icon' => 'diagram-3', 'permission' => 'structure.update']],
            'listExtra' => ['members_count' => 'study_groups.members_count', 'lessons_count' => 'study_groups.lessons_count'],
            'fields' => [
                'name' => ['rules' => 'required|max:80', 'list' => true, 'attrs' => 'maxlength="80"', 'help' => 'study_groups.name_help'],
                'subject_id' => ['type' => 'select', 'list' => true, 'help' => 'study_groups.subject_help', 'options' => fn () => $this->options(
                    'SELECT id, name AS label FROM subjects WHERE school_id = ? ORDER BY name', [$schoolId]
                )],
                'notes' => ['type' => 'textarea', 'rules' => 'max:500', 'attrs' => 'maxlength="500" rows="2"'],
            ],
        ];
    }

    protected function listQuery(): array
    {
        return [
            'select' => ', (SELECT COUNT(*) FROM study_group_students m WHERE m.group_id = t.id) AS members_count,
                          (SELECT COUNT(*) FROM teaching_assignments ta WHERE ta.study_group_id = t.id) AS lessons_count',
            'join' => '', 'where' => '1 = 1', 'params' => [],
        ];
    }

    protected function afterStoreRedirect(int $id): string
    {
        return "/study-groups/$id";
    }

    /** Members chosen on the edit form: class id => student ids, saved with the group's fields. */
    private ?array $memberInput = null;

    /**
     * The group's classes for the edit form: the classes of its linked lessons and of its members,
     * each with its students and whether they are in the group.
     */
    private function memberClasses(int $id): array
    {
        $yearId = $this->yearId();
        $classes = [];
        foreach ($this->db()->all(
            'SELECT DISTINCT c.id, c.name, gl.level_number FROM classes c JOIN grade_levels gl ON gl.id = c.grade_level_id
              WHERE c.school_id = ? AND c.academic_year_id = ? AND (
                    c.id IN (SELECT ta.class_id FROM teaching_assignments ta WHERE ta.study_group_id = ?)
                 OR c.id IN (SELECT e.class_id FROM class_enrollments e JOIN study_group_students m ON m.student_id = e.student_id
                              WHERE m.group_id = ? AND e.left_on IS NULL))
              ORDER BY gl.level_number, c.name',
            [$this->schoolId(), $yearId, $id, $id]
        ) as $class) {
            $classes[(int) $class['id']] = ['name' => $class['name'], 'students' => $this->db()->all(
                'SELECT s.id, s.first_name, s.last_name, (m.student_id IS NOT NULL) AS member
                   FROM students s JOIN class_enrollments e ON e.student_id = s.id
                   LEFT JOIN study_group_students m ON m.student_id = s.id AND m.group_id = ?
                  WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL ORDER BY s.last_name, s.first_name',
                [$id, $class['id']]
            )];
        }
        return $classes;
    }

    public function edit(Request $request, int $id): Response
    {
        $this->guardWritable();
        $row = $this->findEditable($id);
        return $this->view('crud/form', [
            'def' => $this->def(), 'fields' => $this->fields(), 'row' => $row, 'values' => $row + $this->virtualValues($row),
            'extraView' => 'study_groups/members_picker', 'extraData' => ['groupId' => $id, 'classes' => $this->memberClasses($id)],
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        // The form lists the students of these classes; only their membership is replaced.
        $classes = array_intersect($request->ids('member_classes'), array_keys($this->classes()));
        if ($classes !== []) {
            $chosen = $request->ids('student_ids');
            $this->memberInput = [];
            foreach ($classes as $classId) {
                $inClass = array_map('intval', $this->db()->column(
                    'SELECT e.student_id FROM class_enrollments e JOIN students s ON s.id = e.student_id
                      WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL',
                    [$classId]
                ));
                $this->memberInput[$classId] = ['all' => $inClass, 'chosen' => array_values(array_intersect($chosen, $inClass))];
            }
        }
        return parent::update($request, $id);
    }

    protected function afterSave(int $id, ?array $existing): void
    {
        if ($this->memberInput === null) {
            return;
        }
        $counts = [];
        foreach ($this->memberInput as $classId => ['all' => $all, 'chosen' => $chosen]) {
            if ($all !== []) {
                $this->db()->run('DELETE FROM study_group_students WHERE group_id = ? AND student_id IN (' . implode(',', $all) . ')', [$id]);
            }
            foreach ($chosen as $studentId) {
                $this->db()->insert('study_group_students', ['school_id' => $this->schoolId(), 'group_id' => $id, 'student_id' => $studentId]);
            }
            $counts[$classId] = count($chosen);
        }
        $this->audit('update', 'study_group', $id, null, ['members_by_class' => $counts]);
    }

    /** Classes of the working year, by grade level. */
    private function classes(): array
    {
        return array_column($this->db()->all(
            'SELECT c.id, c.name FROM classes c JOIN grade_levels gl ON gl.id = c.grade_level_id
              WHERE c.school_id = ? AND c.academic_year_id = ? ORDER BY gl.level_number, c.name',
            [$this->schoolId(), $this->yearId()]
        ), 'name', 'id');
    }

    /**
     * Building a group step by step: a teacher, one of their subjects, the classes in which
     * they teach it (the shared lesson) and, for each chosen class, the students of the group.
     */
    public function create(Request $request): Response
    {
        $this->guardWritable();
        $schoolId = $this->schoolId();
        $yearId = $this->yearId();
        $teachers = array_column($this->db()->all(
            "SELECT DISTINCT t.id, CONCAT(t.last_name, ' ', t.first_name) AS name
               FROM teachers t JOIN teaching_assignments ta ON ta.teacher_id = t.id
              WHERE ta.school_id = ? AND ta.academic_year_id = ? AND t.deleted_at IS NULL
              ORDER BY name",
            [$schoolId, $yearId]
        ), 'name', 'id');
        $teacherId = $request->int('teacher_id');
        $teacherId = isset($teachers[$teacherId]) ? $teacherId : 0;

        $subjects = $teacherId === 0 ? [] : array_column($this->db()->all(
            'SELECT DISTINCT s.id, s.name FROM subjects s JOIN teaching_assignments ta ON ta.subject_id = s.id
              WHERE ta.school_id = ? AND ta.academic_year_id = ? AND ta.teacher_id = ? ORDER BY s.name',
            [$schoolId, $yearId, $teacherId]
        ), 'name', 'id');
        $subjectId = $request->int('subject_id');
        $subjectId = isset($subjects[$subjectId]) ? $subjectId : (count($subjects) === 1 ? (int) array_key_first($subjects) : 0);

        $lessons = $subjectId === 0 ? [] : $this->teacherLessons($teacherId, $subjectId);
        $students = [];
        foreach ($lessons as $lesson) {
            $students[(int) $lesson['class_id']] ??= $this->db()->all(
                'SELECT s.id, s.first_name, s.last_name FROM students s JOIN class_enrollments e ON e.student_id = s.id
                  WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL ORDER BY s.last_name, s.first_name',
                [(int) $lesson['class_id']]
            );
        }

        return $this->view('study_groups/build', [
            'teachers' => $teachers, 'teacherId' => $teacherId, 'subjects' => $subjects, 'subjectId' => $subjectId,
            'lessons' => $lessons, 'students' => $students,
            'suggestedName' => $subjectId === 0 ? '' : $subjects[$subjectId] . ' – ' . $teachers[$teacherId],
        ]);
    }

    /** The teacher's lessons of one subject this year, one per class (or split group). */
    private function teacherLessons(int $teacherId, int $subjectId): array
    {
        return $this->db()->all(
            'SELECT ta.id, ta.class_id, ta.group_name, c.name AS class_name, g.name AS other_group
               FROM teaching_assignments ta JOIN classes c ON c.id = ta.class_id JOIN grade_levels gl ON gl.id = c.grade_level_id
               LEFT JOIN study_groups g ON g.id = ta.study_group_id
              WHERE ta.school_id = ? AND ta.academic_year_id = ? AND ta.teacher_id = ? AND ta.subject_id = ?
              ORDER BY gl.level_number, c.name, ta.group_name',
            [$this->schoolId(), $this->yearId(), $teacherId, $subjectId]
        );
    }

    /** Creates the group from the step-by-step page: its students, and the chosen classes' lessons linked to it. */
    public function build(Request $request): Response
    {
        $this->guardWritable();
        $teacherId = $request->int('teacher_id');
        $subjectId = $request->int('subject_id');
        $back = "/study-groups/create?teacher_id=$teacherId&subject_id=$subjectId";
        $lessons = array_column($this->teacherLessons($teacherId, $subjectId), null, 'id');
        $chosenLessons = array_values(array_intersect($request->ids('assignment_ids'), array_map('intval', array_keys($lessons))));
        if ($chosenLessons === []) {
            return $this->redirect($back, null, __('study_groups.errors.lessons'));
        }

        // Only students enrolled in a chosen class count; a class without chosen students is not linked.
        $picked = $request->input('students', []);
        $members = [];
        $linked = [];
        foreach ($chosenLessons as $lessonId) {
            $ids = is_array($picked) && is_array($picked[$lessonId] ?? null)
                ? array_map('intval', array_filter($picked[$lessonId], static fn ($v) => is_scalar($v) && preg_match('/^\d+$/', (string) $v)))
                : [];
            if ($ids === []) {
                continue;
            }
            $inClass = array_map('intval', $this->db()->column(
                'SELECT e.student_id FROM class_enrollments e JOIN students s ON s.id = e.student_id
                  WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL',
                [(int) $lessons[$lessonId]['class_id']]
            ));
            $ids = array_intersect($ids, $inClass);
            if ($ids !== []) {
                $linked[] = $lessonId;
                foreach ($ids as $studentId) {
                    $members[$studentId] = true;
                }
            }
        }
        if ($members === []) {
            return $this->redirect($back, null, __('study_groups.errors.no_students'));
        }

        $name = $request->str('name');
        if ($name === '' || mb_strlen($name) > 80) {
            return $this->redirect($back, null, __('study_groups.errors.name'));
        }
        if ($this->db()->value('SELECT id FROM study_groups WHERE school_id = ? AND academic_year_id = ? AND name = ?', [$this->schoolId(), $this->yearId(), $name]) !== null) {
            return $this->redirect($back, null, __('study_groups.errors.name_taken', ['name' => $name]));
        }

        $id = $this->db()->transaction(function () use ($name, $subjectId, $members, $linked): int {
            $id = $this->db()->insert('study_groups', [
                'school_id' => $this->schoolId(), 'academic_year_id' => $this->yearId(), 'name' => $name,
                'subject_id' => $subjectId, 'created_by' => $this->auth()->id(),
            ]);
            foreach (array_keys($members) as $studentId) {
                $this->db()->insert('study_group_students', ['school_id' => $this->schoolId(), 'group_id' => $id, 'student_id' => $studentId]);
            }
            $this->db()->run(
                'UPDATE teaching_assignments SET study_group_id = ? WHERE school_id = ? AND id IN (' . implode(',', $linked) . ')',
                [$id, $this->schoolId()]
            );
            return $id;
        });
        $this->audit('create', 'study_group', $id, null, ['name' => $name, 'subject_id' => $subjectId, 'members' => count($members), 'assignments' => $linked]);
        return $this->redirect("/study-groups/$id", __('study_groups.created', ['name' => $name, 'students' => count($members), 'lessons' => count($linked)]));
    }

    public function show(Request $request, int $id): Response
    {
        $group = $this->find($id);
        $yearId = $this->yearId();
        $members = $this->db()->all(
            'SELECT s.id, s.first_name, s.last_name, c.id AS class_id, c.name AS class_name
               FROM study_group_students m JOIN students s ON s.id = m.student_id
               LEFT JOIN class_enrollments e ON e.student_id = s.id AND e.left_on IS NULL
                    AND e.class_id IN (SELECT id FROM classes WHERE academic_year_id = ?)
               LEFT JOIN classes c ON c.id = e.class_id
              WHERE m.group_id = ? AND s.deleted_at IS NULL
              ORDER BY c.name, s.last_name, s.first_name',
            [$yearId, $id]
        );
        $classes = $this->classes();
        $classId = $request->int('class_id');
        $classId = isset($classes[$classId]) ? $classId : (int) ($members[0]['class_id'] ?? array_key_first($classes) ?? 0);
        $memberIds = array_flip(array_map(static fn ($m) => (int) $m['id'], $members));
        $classStudents = $classId === 0 ? [] : $this->db()->all(
            'SELECT s.id, s.first_name, s.last_name FROM students s JOIN class_enrollments e ON e.student_id = s.id
              WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL ORDER BY s.last_name, s.first_name',
            [$classId]
        );

        $allSubjects = $request->str('subjects') === 'all' || $group['subject_id'] === null;
        $assignments = $this->db()->all(
            "SELECT ta.id, ta.class_id, ta.group_name, ta.study_group_id, c.name AS class_name, s.name AS subject_name,
                    CONCAT(t.last_name, ' ', t.first_name) AS teacher_name, g.name AS other_group
               FROM teaching_assignments ta JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id
               JOIN teachers t ON t.id = ta.teacher_id JOIN grade_levels gl ON gl.id = c.grade_level_id
               LEFT JOIN study_groups g ON g.id = ta.study_group_id AND g.id <> ?
              WHERE ta.school_id = ? AND ta.academic_year_id = ? AND (? OR ta.subject_id = ? OR ta.study_group_id = ?)
              ORDER BY s.name, gl.level_number, c.name, t.last_name",
            [$id, $this->schoolId(), $yearId, $allSubjects ? 1 : 0, (int) $group['subject_id'], $id]
        );
        // Members whose class has no linked lesson would not appear in any lesson.
        $linkedClasses = [];
        foreach ($assignments as $a) {
            if ((int) $a['study_group_id'] === $id) {
                $linkedClasses[(int) $a['class_id']] = true;
            }
        }
        $uncovered = $linkedClasses === [] ? [] : array_values(array_filter($members, static fn ($m) => !isset($linkedClasses[(int) $m['class_id']])));

        return $this->view('study_groups/show', [
            'group' => $group, 'members' => $members, 'classes' => $classes, 'classId' => $classId,
            'classStudents' => $classStudents, 'memberIds' => $memberIds, 'assignments' => $assignments,
            'allSubjects' => $allSubjects, 'uncovered' => $uncovered,
            'subjectName' => $group['subject_id'] === null ? null : $this->db()->value('SELECT name FROM subjects WHERE id = ?', [$group['subject_id']]),
            'canEdit' => can('structure.update') && !$this->year()['is_locked'],
        ]);
    }

    /** Shared lessons of the timetable (one teacher, one subject, several classes at once) that are not yet study groups. */
    public function shared(Request $request): Response
    {
        return $this->view('study_groups/shared', [
            'shared' => SharedLessons::detect($this->db(), $this->schoolId(), $this->yearId()),
            'canEdit' => !$this->year()['is_locked'],
        ]);
    }

    /** Makes each detected shared lesson a study group (members: the students of its classes). */
    public function createShared(Request $request): Response
    {
        $this->guardWritable();
        $shared = SharedLessons::detect($this->db(), $this->schoolId(), $this->yearId());
        $count = SharedLessons::create($this->db(), $this->schoolId(), $this->yearId(), $this->auth()->id(), $shared, __('study_groups.shared.note'));
        $this->audit('create', 'study_group', null, null, ['shared_lessons' => $count]);
        return $this->redirect('/study-groups', __('study_groups.shared.created', ['count' => $count]));
    }

    /** Sets which students of one class belong to the group. */
    public function saveMembers(Request $request, int $id): Response
    {
        $this->guardWritable();
        $group = $this->find($id);
        $classId = $request->int('class_id');
        if (!isset($this->classes()[$classId])) {
            return $this->redirect("/study-groups/$id", null, 'study_groups.errors.class');
        }
        $inClass = array_map('intval', $this->db()->column(
            'SELECT e.student_id FROM class_enrollments e JOIN students s ON s.id = e.student_id
              WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL',
            [$classId]
        ));
        $chosen = array_values(array_intersect($request->ids('student_ids'), $inClass));
        $this->db()->transaction(function () use ($id, $inClass, $chosen): void {
            if ($inClass !== []) {
                $this->db()->run(
                    'DELETE FROM study_group_students WHERE group_id = ? AND student_id IN (' . implode(',', $inClass) . ')',
                    [$id]
                );
            }
            foreach ($chosen as $studentId) {
                $this->db()->insert('study_group_students', ['school_id' => $this->schoolId(), 'group_id' => $id, 'student_id' => $studentId]);
            }
        });
        $this->audit('update', 'study_group', $id, null, ['class_id' => $classId, 'members_in_class' => count($chosen)]);
        return $this->redirect("/study-groups/$id?class_id=$classId", __('study_groups.members_saved', ['count' => count($chosen), 'group' => $group['name']]));
    }

    /** Sets which teaching assignments (lessons) the group attends. */
    public function saveLessons(Request $request, int $id): Response
    {
        $this->guardWritable();
        $this->find($id);
        $valid = array_map('intval', $this->db()->column(
            'SELECT id FROM teaching_assignments WHERE school_id = ? AND academic_year_id = ?',
            [$this->schoolId(), $this->yearId()]
        ));
        $chosen = array_values(array_intersect($request->ids('assignment_ids'), $valid));
        $this->db()->transaction(function () use ($id, $chosen): void {
            $keep = $chosen === [] ? '' : ' AND id NOT IN (' . implode(',', $chosen) . ')';
            $this->db()->run("UPDATE teaching_assignments SET study_group_id = NULL WHERE study_group_id = ?$keep", [$id]);
            if ($chosen !== []) {
                $this->db()->run(
                    'UPDATE teaching_assignments SET study_group_id = ? WHERE school_id = ? AND id IN (' . implode(',', $chosen) . ')',
                    [$id, $this->schoolId()]
                );
            }
        });
        $this->audit('update', 'study_group', $id, null, ['assignments' => $chosen]);
        return $this->redirect("/study-groups/$id", __('study_groups.lessons_saved', ['count' => count($chosen)]));
    }
}
