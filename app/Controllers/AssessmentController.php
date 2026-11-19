<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\GradeStats;
use App\Services\ReportCards;

/**
 * Assessment events and their results.
 *
 * Who sees what: the teacher of the assignment; the homeroom teacher and anyone
 * whose scope covers the whole class; a coordinator of the subject; and users
 * who see the whole school. Drafts are visible only to those who may edit them.
 * Editing (the event and its scores) is for the assignment's own teacher or a
 * user who sees the whole school.
 */
final class AssessmentController extends CrudController
{
    public const RESULT_STATUSES = ['graded', 'absent', 'exempt', 'pending'];

    /** Ready-made homeroom remarks offered in the homeroom sheet (lang: gradebook.presets.<group>.<n>). */
    public const REMARK_PRESETS = ['positive' => 8, 'improve' => 8];

    /** A lesson of a subject without grades (the homeroom lesson) keeps absences, lates and a remark instead. */
    private function lessonIsGraded(array $lesson): bool
    {
        return (int) $this->db()->value(
            'SELECT MIN(s.is_graded) FROM subjects s JOIN teaching_assignments ta ON ta.subject_id = s.id
              WHERE ta.id IN (' . implode(',', array_map('intval', $lesson['assignments'])) . ')'
        ) === 1;
    }

    protected function definition(): array
    {
        $schoolId = $this->schoolId();
        $yearId = (int) ($this->auth()->year()['id'] ?? 0);
        $decimal = static fn (int $digits): array => ['required', "regex:/^\\d{1,$digits}([.,]\\d{1,2})?$/"];
        return [
            'table' => 'assessments', 'entity' => 'assessment', 'path' => '/assessments', 'lang' => 'assessments',
            'module' => 'assessments', 'scope' => 'year', 'order' => 't.assessment_date DESC, t.id DESC',
            'softDelete' => true, 'userstamps' => true, 'show' => true, 'nameField' => 'title', 'search' => ['t.title'],
            'headerActions' => [['path' => '/assessments/gradebook', 'label' => 'gradebook.title', 'icon' => 'table', 'permission' => 'assessments.view']],
            'listExtra' => ['assignment_label' => 'assessments.assignment', 'status_label' => 'assessments.fields.status', 'graded_count' => 'assessments.graded_count'],
            'filters' => [
                'term_id' => ['label' => 'assessments.fields.term_id', 'sql' => 't.term_id = ?', 'options' => $this->options(
                    'SELECT id, name AS label FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order',
                    [$yearId]
                )],
                'subject_id' => ['label' => 'assignments.fields.subject_id', 'sql' => 'ta.subject_id = ?', 'options' => $this->options(
                    'SELECT id, name AS label FROM subjects WHERE school_id = ? ORDER BY name',
                    [$schoolId]
                )],
            ],
            'fields' => [
                'title' => ['rules' => 'required|max:150', 'list' => true, 'attrs' => 'maxlength="150"'],
                // Only assignments the user may record for are offered.
                'teaching_assignment_id' => ['type' => 'select', 'rules' => 'required',
                    'options' => fn () => $this->assignmentOptions(true)],
                'term_id' => ['type' => 'select', 'rules' => 'required', 'list' => true, 'options' => fn () => $this->options(
                    'SELECT id, name AS label FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order',
                    [$yearId]
                )],
                'type_id' => ['type' => 'select', 'rules' => 'required', 'list' => true, 'options' => fn () => $this->options(
                    'SELECT id, name AS label FROM assessment_types WHERE school_id = ? ORDER BY id',
                    [$schoolId]
                )],
                'assessment_date' => ['type' => 'date', 'rules' => 'required', 'list' => true],
                'max_score' => ['rules' => $decimal(4), 'default' => '100', 'attrs' => 'inputmode="decimal" dir="ltr" maxlength="7"'],
                'weight' => ['rules' => $decimal(2), 'default' => '1', 'help' => 'assessments.weight_help', 'attrs' => 'inputmode="decimal" dir="ltr" maxlength="5"'],
            ],
        ];
    }

    private function assignmentOptions(bool $editableOnly): array
    {
        $scope = $this->accessScope();
        [$condition, $params] = $scope->assignmentCondition('ta');
        $options = [];
        foreach ($this->db()->all(
            "SELECT ta.id, CONCAT(c.name, ' · ', s.name, ' · ', t.last_name, ' ', t.first_name,
                           CASE WHEN ta.group_name <> '' THEN CONCAT(' (', ta.group_name, ')') ELSE '' END) AS label
               FROM teaching_assignments ta
               JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id JOIN teachers t ON t.id = ta.teacher_id
              WHERE ta.school_id = ? AND ta.academic_year_id = ? AND $condition
              ORDER BY c.name, s.name",
            [$this->schoolId(), (int) ($this->auth()->year()['id'] ?? 0), ...$params]
        ) as $row) {
            if (!$editableOnly || $scope->canEditAssignment((int) $row['id'])) {
                $options[(int) $row['id']] = $row['label'];
            }
        }
        return $options;
    }

    protected function listQuery(): array
    {
        $scope = $this->accessScope();
        [$condition, $params] = $scope->assignmentCondition('ta');
        $draft = '1 = 1';
        if (!$scope->isUnrestricted()) {
            // Drafts of colleagues stay private until published.
            $own = array_keys($this->assignmentOptions(true));
            $draft = "(t.status = 'published'" . ($own === [] ? '' : ' OR ta.id IN (' . implode(',', array_fill(0, count($own), '?')) . ')') . ')';
            $params = [...$params, ...$own];
        }
        $pdo = $this->db()->pdo();
        return [
            'select' => ", CONCAT(lc.name, ' · ', lsj.name) AS assignment_label, CASE t.status WHEN 'published' THEN " . $pdo->quote(__('assessments.statuses.published'))
                . ' ELSE ' . $pdo->quote(__('assessments.statuses.draft')) . " END AS status_label,
                (SELECT COUNT(*) FROM assessment_results r WHERE r.assessment_id = t.id AND r.result_status = 'graded') AS graded_count",
            'join' => 'JOIN teaching_assignments ta ON ta.id = t.teaching_assignment_id
                       JOIN classes lc ON lc.id = ta.class_id JOIN subjects lsj ON lsj.id = ta.subject_id',
            'where' => "$condition AND $draft",
            'params' => $params,
        ];
    }

    protected function rowVisible(array $row): bool
    {
        $scope = $this->accessScope();
        if ($scope->canEditAssignment((int) $row['teaching_assignment_id'])) {
            return true;
        }
        [$condition, $params] = $scope->assignmentCondition('ta');
        return $row['status'] === 'published' && $this->db()->value(
            "SELECT 1 FROM teaching_assignments ta WHERE ta.id = ? AND $condition",
            [$row['teaching_assignment_id'], ...$params]
        ) !== null;
    }

    protected function rowEditable(array $row): bool
    {
        return $this->accessScope()->canEditAssignment((int) $row['teaching_assignment_id']);
    }

    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
        $year = $this->year();
        if ($data['assessment_date'] < $year['start_date'] || $data['assessment_date'] > $year['end_date']) {
            $v->addError('assessment_date', __('assessments.errors.date_outside'));
        }
        if ((float) str_replace(',', '.', $data['max_score']) <= 0) {
            $v->addError('max_score', __('assessments.errors.max_positive'));
        }
        // Lowering the maximum below a score that was already entered would corrupt the results.
        if ($existing !== null && (float) str_replace(',', '.', $data['max_score']) < (float) $this->db()->value(
            'SELECT COALESCE(MAX(score), 0) FROM assessment_results WHERE assessment_id = ?',
            [$existing['id']]
        )) {
            $v->addError('max_score', __('assessments.errors.max_below_scores'));
        }
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        $data['max_score'] = str_replace(',', '.', $data['max_score']);
        $data['weight'] = str_replace(',', '.', $data['weight']);
        return $data;
    }

    protected function afterStoreRedirect(int $id): string
    {
        return "/assessments/$id";
    }

    /**
     * The lessons offered in the grade sheet. By default the ones the user teaches: one entry per class,
     * and one entry for a study group taught together in several classes. With $all, every lesson the
     * user may see (principals, coordinators, homeroom teachers).
     *
     * @return array<string, array{label: string, assignments: array<int, int>, group_id: int|null}> key "a<id>" or "g<group>t<teacher>"
     */
    private function gradebookLessons(bool $all): array
    {
        $params = [$this->schoolId(), (int) ($this->auth()->year()['id'] ?? 0)];
        if ($all) {
            [$condition, $scopeParams] = $this->accessScope()->assignmentCondition('ta');
            array_push($params, ...$scopeParams);
        } else {
            $condition = 'ta.teacher_id IN (SELECT mt.id FROM teachers mt WHERE mt.user_id = ? AND mt.school_id = ta.school_id AND mt.deleted_at IS NULL)';
            $params[] = (int) $this->auth()->id();
        }
        $lessons = [];
        foreach ($this->db()->all(
            "SELECT ta.id, ta.class_id, ta.teacher_id, ta.subject_id, ta.group_name, ta.study_group_id, c.name AS class_name, s.name AS subject_name,
                    sg.name AS study_group_name, CONCAT(t.last_name, ' ', t.first_name) AS teacher_name
               FROM teaching_assignments ta
               JOIN classes c ON c.id = ta.class_id JOIN grade_levels gl ON gl.id = c.grade_level_id
               JOIN subjects s ON s.id = ta.subject_id JOIN teachers t ON t.id = ta.teacher_id
               LEFT JOIN study_groups sg ON sg.id = ta.study_group_id
              WHERE ta.school_id = ? AND ta.academic_year_id = ? AND $condition
              ORDER BY gl.level_number, c.name, s.name, ta.id",
            $params
        ) as $row) {
            $teacher = $all ? ' · ' . $row['teacher_name'] : '';
            $common = ['teacher_id' => (int) $row['teacher_id'], 'teacher_name' => $row['teacher_name'],
                'subject_id' => (int) $row['subject_id'], 'subject_name' => $row['subject_name']];
            if ($row['study_group_id'] !== null) {
                $key = 'g' . $row['study_group_id'] . 't' . $row['teacher_id'];
                $lessons[$key] ??= $common + ['classes' => [], 'assignments' => [], 'group_id' => (int) $row['study_group_id'],
                    'name' => $row['study_group_name'], 'suffix' => ' · ' . $row['subject_name'] . $teacher, 'class_key' => 'g' . $row['study_group_id']];
                $lessons[$key]['classes'][] = $row['class_name'];
                $lessons[$key]['assignments'][(int) $row['class_id']] ??= (int) $row['id'];
            } else {
                // A class split into named groups (group_name) offers each group as its own "class".
                $classLabel = $row['class_name'] . ($row['group_name'] !== '' ? ' (' . $row['group_name'] . ')' : '');
                $lessons['a' . $row['id']] = $common + ['assignments' => [(int) $row['class_id'] => (int) $row['id']], 'group_id' => null,
                    'class_key' => $row['group_name'] !== '' ? 'a' . $row['id'] : 'c' . $row['class_id'], 'class_label' => $classLabel,
                    'label' => $classLabel . ' · ' . $row['subject_name'] . $teacher];
            }
        }
        foreach ($lessons as &$lesson) {
            if ($lesson['group_id'] !== null) {
                $lesson['class_label'] = $lesson['name'] . ' (' . implode('، ', array_unique($lesson['classes'])) . ')';
                $lesson['label'] = $lesson['class_label'] . $lesson['suffix'];
                unset($lesson['classes'], $lesson['name'], $lesson['suffix']);
            }
        }
        unset($lesson);
        return $lessons;
    }

    /**
     * The students of a lesson and the assignment each one is graded in: the class, or for a study group
     * its members in each class; students already graded in the lesson this term stay listed.
     *
     * @return array<int, array{id: int, first_name: string, last_name: string, class_name: string, assignment_id: int}>
     */
    private function gradebookRoster(array $lesson, int $termId): array
    {
        $students = [];
        foreach ($lesson['assignments'] as $classId => $assignmentId) {
            $group = $lesson['group_id'] !== null
                ? ' AND s.id IN (SELECT m.student_id FROM study_group_students m WHERE m.group_id = ' . (int) $lesson['group_id'] . ')' : '';
            foreach ($this->db()->all(
                'SELECT s.id, s.first_name, s.last_name, c.name AS class_name FROM students s
                   JOIN classes c ON c.id = ?
                  WHERE s.school_id = ? AND s.deleted_at IS NULL AND (
                        s.id IN (SELECT e.student_id FROM class_enrollments e WHERE e.class_id = ? AND e.left_on IS NULL)' . $group . '
                        OR s.id IN (SELECT r.student_id FROM assessment_results r JOIN assessments a ON a.id = r.assessment_id
                                     WHERE a.teaching_assignment_id = ? AND a.term_id = ? AND a.deleted_at IS NULL))',
                [$classId, $this->schoolId(), $classId, $assignmentId, $termId]
            ) as $row) {
                $students[(int) $row['id']] ??= ['id' => (int) $row['id'], 'first_name' => $row['first_name'], 'last_name' => $row['last_name'],
                    'class_name' => $row['class_name'], 'assignment_id' => $assignmentId];
            }
        }
        uasort($students, static fn ($a, $b) => [$a['last_name'], $a['first_name'], $a['id']] <=> [$b['last_name'], $b['first_name'], $b['id']]);
        return $students;
    }

    /**
     * Grade sheet: a term and one of the user's lessons (a class, or a study group over several classes),
     * then every student with their grade in each component of the term grade and the final grade,
     * rounded up. Computed exactly like every other grade figure (GradeStats), from published assessments;
     * a component grade typed here replaces the average of that component's assessments.
     */
    public function gradebook(Request $request): Response
    {
        $year = $this->year();
        $terms = array_column($this->db()->all(
            'SELECT id, name FROM academic_terms WHERE school_id = ? AND academic_year_id = ? ORDER BY sort_order, start_date',
            [$this->schoolId(), $year['id']]
        ), 'name', 'id');
        $termId = $request->int('term_id');
        if (!isset($terms[$termId])) {
            // Default: the term of today, else the first one.
            $termId = (int) ($this->db()->value(
                'SELECT id FROM academic_terms WHERE school_id = ? AND academic_year_id = ? AND ? BETWEEN start_date AND end_date',
                [$this->schoolId(), $year['id'], date('Y-m-d')]
            ) ?? array_key_first($terms) ?? 0);
        }

        // Only the user's own lessons, unless they ask for every class they may see (or teach nothing).
        $own = $this->gradebookLessons(false);
        $wider = $this->gradebookLessons(true);
        $showAll = $own === [] || ($request->str('all') === '1' && count($wider) > count($own));
        $lessons = $showAll ? $wider : $own;
        $pick = ['class' => $request->str('class'), 'teacher' => $request->int('teacher_id'), 'subject' => $request->int('subject_id')];
        if (isset($lessons[$request->str('lesson')])) {
            // Coming back from saving: the lesson decides the three lists.
            $from = $lessons[$request->str('lesson')];
            $pick = ['class' => $from['class_key'], 'teacher' => $from['teacher_id'], 'subject' => $from['subject_id']];
        }
        $fits = static fn (array $l, array $p, string $except): bool =>
            ($except === 'class' || $p['class'] === '' || $l['class_key'] === $p['class'])
            && ($except === 'teacher' || $p['teacher'] === 0 || $l['teacher_id'] === $p['teacher'])
            && ($except === 'subject' || $p['subject'] === 0 || $l['subject_id'] === $p['subject']);
        $options = [];
        for ($round = 0; $round < 3; $round++) {
            $options = ['class' => [], 'teacher' => [], 'subject' => []];
            foreach ($lessons as $l) {
                if ($fits($l, $pick, 'class')) {
                    $options['class'][$l['class_key']] = $l['class_label'];
                }
                if ($fits($l, $pick, 'teacher')) {
                    $options['teacher'][$l['teacher_id']] = $l['teacher_name'];
                }
                if ($fits($l, $pick, 'subject')) {
                    $options['subject'][$l['subject_id']] = $l['subject_name'];
                }
            }
            // A choice that no longer fits is dropped; a list with a single option chooses it.
            foreach (['class' => '', 'teacher' => 0, 'subject' => 0] as $name => $none) {
                if ($pick[$name] !== $none && !isset($options[$name][$pick[$name]])) {
                    $pick[$name] = $none;
                }
                if ($pick[$name] === $none && count($options[$name]) === 1) {
                    $pick[$name] = $name === 'class' ? (string) array_key_first($options[$name]) : (int) array_key_first($options[$name]);
                }
            }
        }
        asort($options['teacher']);
        asort($options['subject']);
        $matches = array_keys(array_filter($lessons, static fn ($l) => $fits($l, $pick, '')));
        $lessonKey = $pick['class'] !== '' && $pick['teacher'] !== 0 && $pick['subject'] !== 0 && $matches !== [] || count($matches) === 1
            ? (string) $matches[0] : '';
        $lesson = $lessons[$lessonKey] ?? null;

        $components = [];
        $students = [];
        $drafts = 0;
        $canEdit = false;
        $homeroom = null;
        if ($termId !== 0 && $lesson !== null && !$this->lessonIsGraded($lesson)) {
            $term = $this->db()->one('SELECT * FROM academic_terms WHERE id = ?', [$termId]);
            $roster = $this->gradebookRoster($lesson, $termId);
            $ids = array_keys($roster);
            $counted = ReportCards::attendance($this->db(), $this->schoolId(), $term, $ids);
            $records = $ids === [] ? [] : array_column($this->db()->all(
                'SELECT student_id, remark, absences, lates FROM report_card_remarks WHERE term_id = ? AND student_id IN (' . implode(',', $ids) . ')',
                [$termId]
            ), null, 'student_id');
            $homeroom = [];
            foreach ($roster as $id => $student) {
                $homeroom[] = $student + [
                    'counted_absences' => $counted[$id]['absent'] ?? 0, 'counted_lates' => $counted[$id]['late'] ?? 0,
                    'absences' => $records[$id]['absences'] ?? null, 'lates' => $records[$id]['lates'] ?? null,
                    'remark' => (string) ($records[$id]['remark'] ?? ''),
                ];
            }
            $canEdit = !$year['is_locked'] && can('assessments.update')
                && array_filter($lesson['assignments'], fn ($id) => !$this->accessScope()->canEditAssignment($id)) === [];
        }
        if ($termId !== 0 && $lesson !== null && $homeroom === null) {
            $ids = array_values($lesson['assignments']);
            $in = implode(',', array_map('intval', $ids));
            $weights = GradeStats::componentWeights($this->db(), $this->schoolId());
            $canEdit = !$year['is_locked'] && can('assessments.update')
                && array_filter($ids, fn ($id) => !$this->accessScope()->canEditAssignment($id)) === [];

            // Per student and assessment type: Σ(score% × weight), Σ(weight) — as in GradeStats.
            $parts = [];
            $computed = [];
            $typed = [];
            foreach ($this->db()->all(
                "SELECT r.student_id, a.type_id, a.is_summary, SUM(r.score / a.max_score * 100 * a.weight) AS points, SUM(a.weight) AS weight
                   FROM assessment_results r JOIN assessments a ON a.id = r.assessment_id JOIN assessment_types ty ON ty.id = a.type_id
                  WHERE a.teaching_assignment_id IN ($in) AND a.term_id = ? AND a.status = 'published' AND a.deleted_at IS NULL
                    AND ty.is_numeric = 1 AND a.weight > 0 AND a.max_score > 0 AND r.result_status = 'graded' AND r.score IS NOT NULL
                  GROUP BY r.student_id, a.type_id, a.is_summary",
                [$termId]
            ) as $row) {
                [$student, $type] = [(int) $row['student_id'], (int) $row['type_id']];
                $parts[$student] ??= [];
                GradeStats::addPart($parts[$student], $type, (bool) $row['is_summary'], (float) $row['points'], (float) $row['weight']);
                if ($row['is_summary']) {
                    $typed[$student][$type] = (float) $row['points'] / (float) $row['weight'];
                } else {
                    $computed[$student][$type] = (float) $row['points'] / (float) $row['weight'];
                }
            }
            $counts = [];
            foreach ($this->db()->all(
                "SELECT a.type_id, a.teaching_assignment_id, SUM(a.status = 'published') AS published, SUM(a.status <> 'published') AS drafts
                   FROM assessments a WHERE a.teaching_assignment_id IN ($in) AND a.term_id = ? AND a.deleted_at IS NULL AND a.is_summary = 0
                  GROUP BY a.type_id, a.teaching_assignment_id",
                [$termId]
            ) as $row) {
                // In a group lesson each class has its own assessment: count the most of any class.
                $counts[(int) $row['type_id']] = max($counts[(int) $row['type_id']] ?? 0, (int) $row['published']);
                $drafts += (int) $row['drafts'];
            }

            // Columns: the school's components; without components, every numeric type.
            foreach ($this->db()->all(
                'SELECT id, name, term_weight FROM assessment_types WHERE school_id = ? AND is_numeric = 1 ORDER BY sort_order, id',
                [$this->schoolId()]
            ) as $type) {
                $id = (int) $type['id'];
                if ($weights === [] || ($weights[$id] ?? 0) > 0) {
                    $components[$id] = ['name' => $type['name'], 'weight' => $weights[$id] ?? null, 'count' => $counts[$id] ?? 0];
                }
            }

            // Final grades typed directly (per student, subject and term).
            $subjects = array_map('intval', $this->db()->column("SELECT DISTINCT subject_id FROM teaching_assignments WHERE id IN ($in)"));
            $typedFinal = array_column($this->db()->all(
                'SELECT student_id, grade FROM grade_overrides WHERE term_id = ? AND subject_id IN (' . implode(',', $subjects) . ')',
                [$termId]
            ), 'grade', 'student_id');

            foreach ($this->gradebookRoster($lesson, $termId) as $student) {
                $mine = $parts[$student['id']] ?? [];
                $computedAverage = GradeStats::combine($mine, $weights);
                $override = isset($typedFinal[$student['id']]) ? (int) $typedFinal[$student['id']] : null;
                $average = $override !== null ? (float) $override : $computedAverage;
                $students[] = $student + [
                    'components' => array_map(static fn ($p) => $p[1] > 0 ? $p[0] / $p[1] : null, $mine),
                    'computed' => $computed[$student['id']] ?? [],
                    'typed' => $typed[$student['id']] ?? [],
                    'average' => $average,
                    'final' => GradeStats::finalGrade($average),
                    'computedFinal' => GradeStats::finalGrade($computedAverage),
                    'typedFinal' => $override,
                ];
            }
        }

        $finals = array_filter(array_column($students, 'final'), static fn ($v) => $v !== null);
        return $this->view('assessments/gradebook', [
            'terms' => $terms, 'termId' => $termId, 'lessons' => array_map(static fn ($l) => $l['label'], $lessons), 'lessonKey' => $lessonKey,
            'options' => $options, 'pick' => $pick,
            'isGroup' => $lesson !== null && count($lesson['assignments']) > 1,
            'showAll' => $showAll, 'canShowAll' => $own !== [] && count($wider) > count($own),
            'components' => $components, 'students' => $students, 'drafts' => $drafts, 'canEdit' => $canEdit, 'homeroom' => $homeroom,
            'weighted' => GradeStats::componentWeights($this->db(), $this->schoolId()) !== [],
            'classAverage' => $finals === [] ? null : round(array_sum($finals) / count($finals), 1),
        ]);
    }

    /**
     * Saves component grades typed in the grade sheet (0–100). Each assignment × term × component has one
     * summary assessment holding them; in a group lesson each student is saved in their own class's
     * assignment. An emptied cell removes the student's typed grade, so the average of that component's
     * assessments counts again.
     */
    public function saveGradebook(Request $request): Response
    {
        $year = $this->year();
        $termId = $request->int('term_id');
        $lessonKey = $request->str('lesson');
        $all = $request->str('all') === '1';
        $back = "/assessments/gradebook?term_id=$termId&lesson=" . rawurlencode($lessonKey) . ($all ? '&all=1' : '');
        $term = $this->db()->one('SELECT * FROM academic_terms WHERE id = ? AND school_id = ? AND academic_year_id = ?', [$termId, $this->schoolId(), $year['id']]);
        $lesson = $this->gradebookLessons(false)[$lessonKey] ?? $this->gradebookLessons(true)[$lessonKey] ?? null;
        if ($term === null || $lesson === null || $year['is_locked']
            || array_filter($lesson['assignments'], fn ($id) => !$this->accessScope()->canEditAssignment($id)) !== []) {
            throw new HttpException(403);
        }
        $types = array_column($this->db()->all('SELECT id, name FROM assessment_types WHERE school_id = ? AND is_numeric = 1', [$this->schoolId()]), 'name', 'id');
        $target = array_column($this->gradebookRoster($lesson, $termId), 'assignment_id', 'id');
        if (!$this->lessonIsGraded($lesson)) {
            return $this->saveHomeroom($request, $target, (int) $term['id'], $back);
        }
        $input = $request->input('grades', []);
        $input = is_array($input) ? $input : [];
        $invalid = 0;
        $changed = 0;
        $this->db()->transaction(function () use ($input, $types, $target, $lesson, $term, $year, &$invalid, &$changed): void {
            $summaries = [];
            foreach ($this->db()->all(
                'SELECT id, type_id, teaching_assignment_id FROM assessments
                  WHERE teaching_assignment_id IN (' . implode(',', array_map('intval', $lesson['assignments'])) . ') AND term_id = ? AND is_summary = 1 AND deleted_at IS NULL',
                [$term['id']]
            ) as $row) {
                $summaries[(int) $row['teaching_assignment_id']][(int) $row['type_id']] = (int) $row['id'];
            }
            $subjectOf = array_map('intval', array_column($this->db()->all(
                'SELECT id, subject_id FROM teaching_assignments WHERE id IN (' . implode(',', array_map('intval', $lesson['assignments'])) . ')'
            ), 'subject_id', 'id'));
            foreach ($input as $studentId => $cells) {
                $assignmentId = $target[(int) $studentId] ?? null;
                if (!is_array($cells) || $assignmentId === null) {
                    continue;
                }
                // The final grade itself: a whole number 0–100 replaces the computed one; empty brings it back.
                if (array_key_exists('final', $cells)) {
                    $final = is_scalar($cells['final']) ? trim((string) $cells['final']) : '';
                    $key = [(int) $studentId, $subjectOf[$assignmentId], $term['id']];
                    if ($final === '') {
                        $changed += $this->db()->run('DELETE FROM grade_overrides WHERE student_id = ? AND subject_id = ? AND term_id = ?', $key)->rowCount();
                    } elseif (!preg_match('/^\d{1,3}$/', $final) || (int) $final > 100) {
                        $invalid++;
                    } elseif ((string) $this->db()->value('SELECT grade FROM grade_overrides WHERE student_id = ? AND subject_id = ? AND term_id = ?', $key) !== (string) (int) $final) {
                        $this->db()->run(
                            'INSERT INTO grade_overrides (school_id, student_id, subject_id, term_id, grade, updated_by) VALUES (?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE grade = VALUES(grade), updated_by = VALUES(updated_by)',
                            [$this->schoolId(), ...$key, (int) $final, $this->auth()->id()]
                        );
                        $changed++;
                    }
                    unset($cells['final']);
                }
                foreach ($cells as $typeId => $value) {
                    $typeId = (int) $typeId;
                    if (!isset($types[$typeId]) || !is_scalar($value)) {
                        continue;
                    }
                    $value = str_replace(',', '.', trim((string) $value));
                    $summary = $summaries[$assignmentId][$typeId] ?? null;
                    if ($value === '') {
                        if ($summary !== null) {
                            $changed += $this->db()->run('DELETE FROM assessment_results WHERE assessment_id = ? AND student_id = ?', [$summary, (int) $studentId])->rowCount();
                        }
                        continue;
                    }
                    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) || (float) $value > 100) {
                        $invalid++;
                        continue;
                    }
                    if ($summary === null) {
                        $summary = $summaries[$assignmentId][$typeId] = $this->db()->insert('assessments', [
                            'school_id' => $this->schoolId(), 'academic_year_id' => $year['id'], 'teaching_assignment_id' => $assignmentId,
                            'term_id' => $term['id'], 'type_id' => $typeId, 'is_summary' => 1, 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
                            'title' => mb_substr(__('gradebook.summary_title', ['component' => $types[$typeId], 'term' => $term['name']]), 0, 150),
                            'assessment_date' => min($term['end_date'], max($term['start_date'], date('Y-m-d'))),
                            'max_score' => 100, 'weight' => 1, 'created_by' => $this->auth()->id(),
                        ]);
                    }
                    $current = $this->db()->value('SELECT score FROM assessment_results WHERE assessment_id = ? AND student_id = ?', [$summary, (int) $studentId]);
                    if ($current !== null && (float) $current === (float) $value) {
                        continue;
                    }
                    $this->db()->run(
                        "INSERT INTO assessment_results (school_id, assessment_id, student_id, score, result_status, updated_by)
                         VALUES (?, ?, ?, ?, 'graded', ?)
                         ON DUPLICATE KEY UPDATE score = VALUES(score), result_status = 'graded', updated_by = VALUES(updated_by)",
                        [$this->schoolId(), $summary, (int) $studentId, $value, $this->auth()->id()]
                    );
                    $changed++;
                }
            }
        });
        $this->audit('update', 'gradebook', null, null, ['term_id' => $termId, 'assignments' => array_values($lesson['assignments']), 'cells' => $changed]);
        return $invalid > 0
            ? $this->redirect($back, null, __('gradebook.invalid', ['count' => $invalid]))
            : $this->redirect($back, __('gradebook.saved', ['count' => $changed]));
    }

    /**
     * The homeroom sheet: per student, the number of absences and late arrivals (empty = counted from the
     * lessons) and the remark – written freely or taken from the list. All of it is printed on the certificate.
     */
    private function saveHomeroom(Request $request, array $students, int $termId, string $back): Response
    {
        $input = $request->input('homeroom', []);
        $input = is_array($input) ? $input : [];
        $invalid = 0;
        $changed = 0;
        $this->db()->transaction(function () use ($input, $students, $termId, &$invalid, &$changed): void {
            foreach ($input as $studentId => $cells) {
                if (!is_array($cells) || !isset($students[(int) $studentId])) {
                    continue;
                }
                $changes = [];
                foreach (['absences', 'lates'] as $field) {
                    if (!array_key_exists($field, $cells)) {
                        continue;
                    }
                    $value = is_scalar($cells[$field]) ? trim((string) $cells[$field]) : '';
                    if ($value === '') {
                        $changes[$field] = null;
                    } elseif (preg_match('/^\d{1,3}$/', $value)) {
                        $changes[$field] = (int) $value;
                    } else {
                        $invalid++;
                    }
                }
                if (array_key_exists('remark', $cells)) {
                    $text = mb_substr(trim(is_scalar($cells['remark']) ? (string) $cells['remark'] : ''), 0, 600);
                    $preset = is_scalar($cells['preset'] ?? null) ? (string) $cells['preset'] : '';
                    // Without JavaScript the chosen ready-made remark is not copied into the box: take it here.
                    if ($text === '' && preg_match('/^(positive|improve)\.(\d+)$/', $preset, $m) && (int) $m[2] >= 1 && (int) $m[2] <= self::REMARK_PRESETS[$m[1]]) {
                        $text = __("gradebook.presets.$preset");
                    }
                    $changes['remark'] = $text === '' ? null : $text;
                }
                if ($changes !== [] && ReportCards::saveRecord($this->db(), $this->schoolId(), (int) $studentId, $termId, $changes, $this->auth()->id())) {
                    $changed++;
                }
            }
        });
        $this->audit('update', 'report_card_remarks', null, null, ['term_id' => $termId, 'students' => $changed]);
        return $invalid > 0
            ? $this->redirect($back, null, __('gradebook.invalid_counts', ['count' => $invalid]))
            : $this->redirect($back, __('gradebook.homeroom_saved', ['count' => $changed]));
    }

    /** Students currently in the class, plus anyone who already has a result here. */
    private function roster(array $assessment): array
    {
        return $this->db()->all(
            'SELECT s.id, s.first_name, s.last_name, s.student_code, r.score, r.result_status, r.verbal_evaluation, r.comment
               FROM students s
               LEFT JOIN assessment_results r ON r.student_id = s.id AND r.assessment_id = ?
              WHERE s.school_id = ? AND s.deleted_at IS NULL AND (
                    r.id IS NOT NULL OR s.id IN (
                        SELECT e.student_id FROM class_enrollments e JOIN teaching_assignments ta ON ta.class_id = e.class_id
                         WHERE ta.id = ? AND e.left_on IS NULL))
              ORDER BY s.last_name, s.first_name, s.id',
            [$assessment['id'], $this->schoolId(), $assessment['teaching_assignment_id']]
        );
    }

    public function show(Request $request, int $id): Response
    {
        $assessment = $this->find($id);
        $roster = $this->roster($assessment);
        $meta = $this->db()->one(
            "SELECT c.id AS class_id, c.name AS class_name, s.name AS subject_name, tm.name AS term_name, ty.name AS type_name, ty.is_numeric,
                    CONCAT(t.last_name, ' ', t.first_name) AS teacher_name
               FROM assessments a
               JOIN teaching_assignments ta ON ta.id = a.teaching_assignment_id
               JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id JOIN teachers t ON t.id = ta.teacher_id
               JOIN academic_terms tm ON tm.id = a.term_id JOIN assessment_types ty ON ty.id = a.type_id
              WHERE a.id = ?",
            [$id]
        );

        $scores = [];
        foreach ($roster as $row) {
            if ($row['result_status'] === 'graded' && $row['score'] !== null) {
                $scores[] = (float) $row['score'] / (float) $assessment['max_score'] * 100;
            }
        }
        sort($scores);
        $count = count($scores);
        $bands = array_fill(0, 4, 0);
        foreach ($scores as $score) {
            $bands[$score < 55 ? 0 : ($score < 70 ? 1 : ($score < 85 ? 2 : 3))]++;
        }
        return $this->view('assessments/show', [
            'assessment' => $assessment, 'meta' => $meta, 'roster' => $roster,
            'stats' => $count === 0 ? null : [
                'count' => $count, 'average' => round(array_sum($scores) / $count, 1),
                'median' => round($count % 2 ? $scores[intdiv($count, 2)] : ($scores[$count / 2 - 1] + $scores[$count / 2]) / 2, 1),
                'min' => round($scores[0], 1), 'max' => round($scores[$count - 1], 1), 'bands' => $bands,
            ],
            'canEdit' => can('assessments.update') && $this->rowEditable($assessment) && !$this->year()['is_locked'],
        ]);
    }

    public function saveResults(Request $request, int $id): Response
    {
        $this->guardWritable();
        $assessment = $this->findEditable($id);
        $numeric = (bool) $this->db()->value('SELECT is_numeric FROM assessment_types WHERE id = ?', [$assessment['type_id']]);
        $max = (float) $assessment['max_score'];
        $input = is_array($request->post['results'] ?? null) ? $request->post['results'] : [];
        $errors = [];
        $changed = 0;

        $this->db()->transaction(function () use ($assessment, $numeric, $max, $input, &$errors, &$changed): void {
            foreach ($this->roster($assessment) as $student) {
                $row = $input[$student['id']] ?? null;
                if (!is_array($row)) {
                    continue;
                }
                $scalar = static fn (string $key): string => is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';
                $status = $scalar('status');
                $rawScore = str_replace(',', '.', $scalar('score'));
                $verbal = mb_substr($scalar('verbal'), 0, 255);
                $comment = mb_substr($scalar('comment'), 0, 500);
                if (!in_array($status, self::RESULT_STATUSES, true)) {
                    $status = $rawScore !== '' || $verbal !== '' ? 'graded' : 'pending';
                }

                $score = null;
                if ($status === 'graded') {
                    if ($numeric) {
                        if ($rawScore === '') {
                            $status = 'pending';
                        } elseif (!preg_match('/^\d{1,4}(\.\d)?$/', $rawScore) || (float) $rawScore > $max) {
                            $errors[] = $student['last_name'] . ' ' . $student['first_name'];
                            continue;
                        } else {
                            $score = $rawScore;
                        }
                    } elseif ($verbal === '') {
                        $status = 'pending';
                    }
                }
                if ($status === 'pending' && $student['result_status'] === null && $comment === '') {
                    continue; // nothing entered for this student
                }
                $before = [$student['score'] === null ? null : (float) $student['score'], $student['result_status'], $student['verbal_evaluation'], $student['comment']];
                $after = [$score === null ? null : (float) $score, $status, $verbal === '' ? null : $verbal, $comment === '' ? null : $comment];
                if ($before === $after) {
                    continue;
                }
                $this->db()->run(
                    'INSERT INTO assessment_results (school_id, assessment_id, student_id, score, result_status, verbal_evaluation, comment, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE score = VALUES(score), result_status = VALUES(result_status),
                        verbal_evaluation = VALUES(verbal_evaluation), comment = VALUES(comment), updated_by = VALUES(updated_by)',
                    [$this->schoolId(), (int) $assessment['id'], (int) $student['id'], $score, $status, $after[2], $after[3], $this->auth()->id()]
                );
                $changed++;
            }
        });

        if ($changed > 0) {
            // A change after publication is a correction of record and is marked as such in the log.
            $this->audit($assessment['status'] === 'published' ? 'correct_results' : 'update', 'assessment', $id, null, ['results_changed' => $changed]);
        }
        if ($errors !== []) {
            return $this->redirect("/assessments/$id", null, __('assessments.errors.bad_scores', ['max' => format_hours($max), 'names' => implode(', ', array_slice($errors, 0, 5))]));
        }
        return $this->redirect("/assessments/$id", 'assessments.saved');
    }

    public function publish(Request $request, int $id): Response
    {
        $this->guardWritable();
        $assessment = $this->findEditable($id);
        $publish = $assessment['status'] !== 'published';
        $this->db()->update('assessments', [
            'status' => $publish ? 'published' : 'draft', 'published_at' => $publish ? date('Y-m-d H:i:s') : null,
        ], ['id' => $id]);
        $this->audit($publish ? 'publish' : 'unpublish', 'assessment', $id);
        return $this->redirect("/assessments/$id", $publish ? 'assessments.published' : 'assessments.unpublished');
    }
}
