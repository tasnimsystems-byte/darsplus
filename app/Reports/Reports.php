<?php

declare(strict_types=1);

namespace App\Reports;

use App\Core\Auth;
use App\Core\Database;
use App\Services\AccessScope;
use App\Services\AttendanceStats;
use App\Services\GoalService;
use App\Services\GradeStats;

/**
 * The management reports. Every report is built from the same services and the
 * same access scope as the screens, so a report can never show more than its
 * reader may see elsewhere. A report is a plain table: columns, rows, notes —
 * which is what makes the HTML, Excel, PDF and print outputs identical.
 */
final class Reports
{
    /** key => [permission of the data it draws on, filters it accepts, filters it cannot run without] */
    public const ALL = [
        'achievements_school' => ['assessments.view', ['term_id'], []],
        'achievements_level' => ['assessments.view', ['term_id', 'grade_level_id'], ['grade_level_id']],
        'achievements_class' => ['assessments.view', ['term_id', 'class_id'], ['class_id']],
        'achievements_subject' => ['assessments.view', ['term_id', 'subject_id'], ['subject_id']],
        'student_progress' => ['assessments.view', ['student_id'], ['student_id']],
        'term_comparison' => ['assessments.view', ['grade_level_id', 'class_id'], []],
        'support_needed' => ['risk.view', ['term_id', 'class_id'], []],
        'attendance' => ['attendance.view', ['term_id', 'class_id'], ['class_id']],
        'curriculum' => ['curriculum.view', ['subject_id', 'grade_level_id'], []],
        'work_plan' => ['work_plan.view', [], []],
        'tasks' => ['tasks.view', [], []],
        'meetings' => ['meetings.view', [], []],
    ];

    private array $names = [];

    public function __construct(private Database $db, private Auth $auth, private AccessScope $scope)
    {
    }

    /** @return string[] reports the current user may run */
    public function available(): array
    {
        return array_keys(array_filter(self::ALL, fn (array $report): bool => $this->auth->can($report[0])));
    }

    private function schoolId(): int
    {
        return (int) $this->auth->schoolId();
    }

    private function yearId(): int
    {
        return (int) ($this->auth->year()['id'] ?? 0);
    }

    /** id => label choices for one filter, limited to what the user may see. */
    public function options(string $filter): array
    {
        if (isset($this->names[$filter])) {
            return $this->names[$filter];
        }
        [$classCondition, $classParams] = $this->scope->classCondition('c.id');
        $rows = match ($filter) {
            'term_id' => $this->db->all('SELECT id, name AS label FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order', [$this->yearId()]),
            'grade_level_id' => $this->db->all('SELECT id, name AS label FROM grade_levels WHERE school_id = ? ORDER BY level_number', [$this->schoolId()]),
            'subject_id' => $this->db->all('SELECT id, name AS label FROM subjects WHERE school_id = ? ORDER BY name', [$this->schoolId()]),
            'class_id' => $this->db->all(
                "SELECT c.id, c.name AS label FROM classes c JOIN grade_levels gl ON gl.id = c.grade_level_id
                  WHERE c.school_id = ? AND c.academic_year_id = ? AND $classCondition ORDER BY gl.level_number, c.name",
                [$this->schoolId(), $this->yearId(), ...$classParams]
            ),
            'student_id' => $this->db->all(
                "SELECT s.id, CONCAT(s.last_name, ' ', s.first_name, ' (', c.name, ')') AS label
                   FROM students s JOIN class_enrollments e ON e.student_id = s.id AND e.left_on IS NULL JOIN classes c ON c.id = e.class_id
                  WHERE s.school_id = ? AND s.deleted_at IS NULL AND c.academic_year_id = ? AND $classCondition
                  ORDER BY s.last_name, s.first_name",
                [$this->schoolId(), $this->yearId(), ...$classParams]
            ),
        };
        return $this->names[$filter] = array_column($rows, 'label', 'id');
    }

    /** Accepts only values that are among the offered options. */
    public function cleanFilters(string $key, array $input): array
    {
        $filters = [];
        foreach (self::ALL[$key][1] as $name) {
            $value = $input[$name] ?? null;
            if (is_scalar($value) && ctype_digit((string) $value) && isset($this->options($name)[(int) $value])) {
                $filters[$name] = (int) $value;
            }
        }
        return $filters;
    }

    /** @return string[] required filters that were not chosen */
    public function missing(string $key, array $filters): array
    {
        return array_values(array_diff(self::ALL[$key][2], array_keys($filters)));
    }

    /** @return array{columns: string[], rows: array<int, array<int, string|int|float|null>>, notes: string[]} */
    public function build(string $key, array $filters): array
    {
        if (!isset(self::ALL[$key]) || !$this->auth->can(self::ALL[$key][0]) || $this->missing($key, $filters) !== []) {
            return ['columns' => [], 'rows' => [], 'notes' => []];
        }
        return $this->{'report' . str_replace('_', '', ucwords($key, '_'))}($filters);
    }

    private function stats(array $filters): GradeStats
    {
        return new GradeStats($this->db, $this->schoolId(), $this->yearId(), $this->scope->assignmentCondition('ta'), $filters);
    }

    private function table(array $columns, array $rows, array $notes = []): array
    {
        return ['columns' => $columns, 'rows' => $rows, 'notes' => $notes];
    }

    /** Rows "name, average, students" for one dimension, sorted by name. */
    private function dimension(GradeStats $stats, string $dimension, array $names, ?int $termId): array
    {
        $rows = [];
        foreach ($stats->by($dimension, $termId) as $id => $cell) {
            $rows[] = [$names[$id] ?? '?', $cell['average'], $cell['students']];
        }
        usort($rows, static fn (array $a, array $b): int => strnatcmp((string) $a[0], (string) $b[0]));
        return $rows;
    }

    private function allClassNames(): array
    {
        return array_column($this->db->all('SELECT id, name FROM classes WHERE school_id = ? AND academic_year_id = ?', [$this->schoolId(), $this->yearId()]), 'name', 'id');
    }

    private function method(): string
    {
        return __('achievements.method');
    }

    // ---- 1–5, 12: achievements ---------------------------------------------------------

    private function reportAchievementsSchool(array $filters): array
    {
        $termId = $filters['term_id'] ?? null;
        $stats = $this->stats([]);
        $overall = $stats->overall($termId);
        $rows = [[__('reports.rows.school'), $overall['average'], $overall['students']]];
        foreach ([['grade_level_id', $this->options('grade_level_id'), 'reports.rows.level'],
                     ['subject_id', $this->options('subject_id'), 'reports.rows.subject']] as [$dimension, $names, $label]) {
            foreach ($this->dimension($stats, $dimension, $names, $termId) as $row) {
                $rows[] = [__($label, ['name' => $row[0]]), $row[1], $row[2]];
            }
        }
        $bands = $stats->distribution($termId);
        return $this->table(
            [__('reports.columns.group'), __('reports.columns.average'), __('reports.columns.students')],
            $rows,
            [$this->method(), __('reports.distribution_note', ['a' => $bands[0], 'b' => $bands[1], 'c' => $bands[2], 'd' => $bands[3]])]
        );
    }

    /** Classes of one level × subjects. */
    private function reportAchievementsLevel(array $filters): array
    {
        $termId = $filters['term_id'] ?? null;
        $stats = $this->stats(['grade_level_id' => $filters['grade_level_id']]);
        return $this->matrix($stats, 'class_id', $this->allClassNames(), $termId, __('students.fields.class_id'));
    }

    /** One subject: its classes. */
    private function reportAchievementsSubject(array $filters): array
    {
        $termId = $filters['term_id'] ?? null;
        $stats = $this->stats(['subject_id' => $filters['subject_id']]);
        $rows = $this->dimension($stats, 'class_id', $this->allClassNames(), $termId);
        $overall = $stats->overall($termId);
        $rows[] = [__('reports.rows.total'), $overall['average'], $overall['students']];
        return $this->table([__('students.fields.class_id'), __('reports.columns.average'), __('reports.columns.students')], $rows, [$this->method()]);
    }

    /** Rows = values of $dimension, columns = subjects. */
    private function matrix(GradeStats $stats, string $dimension, array $names, ?int $termId, string $firstColumn): array
    {
        $subjects = $this->options('subject_id');
        $cells = [];
        foreach ($stats->rows() as $row) {
            if ($termId === null || $row['term_id'] === $termId) {
                $cells[$row[$dimension]][$row['subject_id']][$row['student_id']][] = $row['avg'];
            }
        }
        $used = [];
        foreach ($cells as $bySubject) {
            $used += array_fill_keys(array_keys($bySubject), true);
        }
        $subjects = array_intersect_key($subjects, $used);
        $rows = [];
        foreach ($cells as $id => $bySubject) {
            $line = [$names[$id] ?? '?'];
            foreach (array_keys($subjects) as $subjectId) {
                $students = $bySubject[$subjectId] ?? [];
                $averages = array_map(static fn (array $v): float => array_sum($v) / count($v), $students);
                $line[] = $averages === [] ? null : round(array_sum($averages) / count($averages), 1);
            }
            $rows[] = $line;
        }
        usort($rows, static fn (array $a, array $b): int => strnatcmp((string) $a[0], (string) $b[0]));
        return $this->table([$firstColumn, ...array_values($subjects)], $rows, [$this->method()]);
    }

    /** Students of one class × subjects, with each student's average. */
    private function reportAchievementsClass(array $filters): array
    {
        $termId = $filters['term_id'] ?? null;
        $stats = $this->stats(['class_id' => $filters['class_id']]);
        $subjects = $this->options('subject_id');
        $perStudent = [];
        foreach ($stats->rows() as $row) {
            if ($termId === null || $row['term_id'] === $termId) {
                $perStudent[$row['student_id']][$row['subject_id']][] = $row['avg'];
            }
        }
        $used = [];
        foreach ($perStudent as $bySubject) {
            $used += array_fill_keys(array_keys($bySubject), true);
        }
        $subjects = array_intersect_key($subjects, $used);
        $averages = $stats->studentAverages($termId);
        $rows = [];
        foreach ($this->db->all(
            'SELECT s.id, s.last_name, s.first_name FROM students s JOIN class_enrollments e ON e.student_id = s.id
              WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL ORDER BY s.last_name, s.first_name',
            [$filters['class_id']]
        ) as $student) {
            $id = (int) $student['id'];
            $line = [$student['last_name'] . ' ' . $student['first_name']];
            foreach (array_keys($subjects) as $subjectId) {
                $values = $perStudent[$id][$subjectId] ?? [];
                $line[] = $values === [] ? null : round(array_sum($values) / count($values), 1);
            }
            $line[] = isset($averages[$id]) ? round($averages[$id], 1) : null;
            $rows[] = $line;
        }
        return $this->table([__('assessments.student'), ...array_values($subjects), __('reports.columns.average')], $rows, [$this->method()]);
    }

    /** One student: subjects × terms. */
    private function reportStudentProgress(array $filters): array
    {
        $stats = $this->stats(['student_id' => $filters['student_id']]);
        $terms = $this->options('term_id');
        $subjects = $this->options('subject_id');
        $cells = [];
        foreach ($stats->rows() as $row) {
            $cells[$row['subject_id']][$row['term_id']] = round($row['avg'], 1);
        }
        $rows = [];
        foreach ($cells as $subjectId => $byTerm) {
            $line = [$subjects[$subjectId] ?? '?'];
            foreach (array_keys($terms) as $termId) {
                $line[] = $byTerm[$termId] ?? null;
            }
            $rows[] = $line;
        }
        usort($rows, static fn (array $a, array $b): int => strnatcmp((string) $a[0], (string) $b[0]));
        $line = [__('reports.columns.average')];
        foreach (array_keys($terms) as $termId) {
            $average = $stats->studentAverages($termId)[$filters['student_id']] ?? null;
            $line[] = $average === null ? null : round($average, 1);
        }
        $rows[] = $line;
        $notes = [$this->method()];
        if ($this->scope->fullClassIds() !== null) {
            $notes[] = __('students.partial_scope');
        }
        return $this->table([__('assignments.fields.subject_id'), ...array_values($terms)], $rows, $notes);
    }

    private function reportTermComparison(array $filters): array
    {
        $stats = $this->stats($filters);
        $terms = $this->options('term_id');
        $subjects = $this->options('subject_id');
        $byTerm = $stats->by('term_id');
        $rows = [];
        foreach ($stats->subjectByTerm() as $subjectId => $cells) {
            $rows[] = $this->comparisonLine($subjects[$subjectId] ?? '?', $cells, array_keys($terms));
        }
        usort($rows, static fn (array $a, array $b): int => strnatcmp((string) $a[0], (string) $b[0]));
        $rows[] = $this->comparisonLine(__('achievements.overall'), array_map(static fn (array $c) => $c['average'], $byTerm), array_keys($terms));
        return $this->table([__('assignments.fields.subject_id'), ...array_values($terms), __('achievements.change')], $rows, [$this->method()]);
    }

    private function comparisonLine(string $label, array $cells, array $termIds): array
    {
        $line = [$label];
        $values = [];
        foreach ($termIds as $termId) {
            $line[] = $cells[$termId] ?? null;
            if (isset($cells[$termId])) {
                $values[] = $cells[$termId];
            }
        }
        $line[] = count($values) >= 2 ? round(end($values) - $values[0], 1) : null;
        return $line;
    }

    // ---- 6: students for whom an indication exists ---------------------------------------

    private function reportSupportNeeded(array $filters): array
    {
        [$condition, $params] = $this->scope->fullClassCondition('e.class_id');
        $where = '';
        foreach (['term_id' => 'ri.term_id', 'class_id' => 'c.id'] as $name => $column) {
            if (isset($filters[$name])) {
                $where .= " AND $column = ?";
                $params[] = $filters[$name];
            }
        }
        $rows = [];
        foreach ($this->db->all(
            "SELECT s.last_name, s.first_name, c.name AS class_name, rc.name AS criterion_name, tm.name AS term_name, ri.review_status,
                    (SELECT COUNT(*) FROM student_interventions si WHERE si.student_id = s.id AND si.deleted_at IS NULL AND si.status = 'active') AS active_plans
               FROM risk_indications ri
               JOIN students s ON s.id = ri.student_id AND s.deleted_at IS NULL
               JOIN risk_criteria rc ON rc.id = ri.criterion_id
               JOIN academic_terms tm ON tm.id = ri.term_id
               JOIN class_enrollments e ON e.student_id = s.id AND e.left_on IS NULL
               JOIN classes c ON c.id = e.class_id AND c.academic_year_id = tm.academic_year_id
              WHERE ri.school_id = ? AND tm.academic_year_id = ? AND ri.review_status <> 'dismissed' AND $condition $where
              ORDER BY c.name, s.last_name, s.first_name, ri.id",
            [$this->schoolId(), $this->yearId(), ...$params]
        ) as $row) {
            $rows[] = [$row['last_name'] . ' ' . $row['first_name'], $row['class_name'], $row['criterion_name'], $row['term_name'],
                __('risk.statuses.' . $row['review_status']), (int) $row['active_plans']];
        }
        return $this->table(
            [__('assessments.student'), __('students.fields.class_id'), __('risk.criterion'), __('assessments.fields.term_id'), __('risk.status'), __('reports.columns.active_plans')],
            $rows,
            [__('risk.principle')]
        );
    }

    // ---- 11: attendance --------------------------------------------------------------------

    private function reportAttendance(array $filters): array
    {
        [$from, $to] = [null, null];
        if (isset($filters['term_id'])) {
            $term = $this->db->one('SELECT start_date, end_date FROM academic_terms WHERE id = ?', [$filters['term_id']]);
            [$from, $to] = [$term['start_date'], $term['end_date']];
        }
        $rows = [];
        foreach (AttendanceStats::byStudent($this->db, $filters['class_id'], $this->scope->assignmentCondition('ta'), $from, $to) as $row) {
            $rows[] = [$row['last_name'] . ' ' . $row['first_name'], $row['sessions'], $row['absent'], $row['justified'], $row['late'], $row['rate']];
        }
        $notes = [__('attendance.method')];
        if (!$this->scope->isUnrestricted()) {
            $notes[] = __('attendance.partial_note');
        }
        return $this->table([__('assessments.student'), __('attendance.lessons'), __('attendance.statuses.absent'), __('attendance.statuses.justified'),
            __('attendance.statuses.late'), __('reports.columns.rate')], $rows, $notes);
    }

    // ---- 7: curriculum ----------------------------------------------------------------------

    private function reportCurriculum(array $filters): array
    {
        [$condition, $params] = $this->scope->assignmentCondition('ta');
        $where = '';
        foreach (['subject_id' => 'p.subject_id', 'grade_level_id' => 'p.grade_level_id'] as $name => $column) {
            if (isset($filters[$name])) {
                $where .= " AND $column = ?";
                $params[] = $filters[$name];
            }
        }
        $rows = [];
        foreach ($this->db->all(
            "SELECT p.title, sj.name AS subject_name, c.name AS class_name, CONCAT(t.last_name, ' ', t.first_name) AS teacher_name,
                    (SELECT COUNT(*) FROM curriculum_topics tp JOIN curriculum_units u ON u.id = tp.unit_id WHERE u.plan_id = p.id) AS topics,
                    (SELECT COUNT(*) FROM curriculum_topics tp JOIN curriculum_units u ON u.id = tp.unit_id
                      WHERE u.plan_id = p.id AND tp.planned_week_start <= CURDATE()) AS due,
                    (SELECT COUNT(*) FROM curriculum_progress cp JOIN curriculum_topics tp ON tp.id = cp.topic_id JOIN curriculum_units u ON u.id = tp.unit_id
                      WHERE u.plan_id = p.id AND cp.teaching_assignment_id = ta.id AND cp.status = 'done') AS done,
                    (SELECT COUNT(*) FROM curriculum_progress cp JOIN curriculum_topics tp ON tp.id = cp.topic_id JOIN curriculum_units u ON u.id = tp.unit_id
                      WHERE u.plan_id = p.id AND cp.teaching_assignment_id = ta.id AND cp.status = 'done' AND tp.planned_week_start <= CURDATE()) AS done_due
               FROM curriculum_plans p
               JOIN subjects sj ON sj.id = p.subject_id
               JOIN teaching_assignments ta ON ta.academic_year_id = p.academic_year_id AND ta.subject_id = p.subject_id
               JOIN classes c ON c.id = ta.class_id AND c.grade_level_id = p.grade_level_id AND (p.class_id IS NULL OR p.class_id = c.id)
               JOIN teachers t ON t.id = ta.teacher_id
              WHERE p.school_id = ? AND p.academic_year_id = ? AND p.deleted_at IS NULL AND $condition $where
              ORDER BY p.title, c.name",
            [$this->schoolId(), $this->yearId(), ...$params]
        ) as $row) {
            $topics = (int) $row['topics'];
            $rows[] = [$row['title'], $row['subject_name'], $row['class_name'], $row['teacher_name'], $topics, (int) $row['done'],
                $topics === 0 ? null : round((int) $row['done'] / $topics * 100, 1), (int) $row['due'] - (int) $row['done_due']];
        }
        return $this->table([__('curriculum.fields.title'), __('assignments.fields.subject_id'), __('assignments.fields.class_id'), __('assignments.fields.teacher_id'),
            __('reports.columns.topics'), __('curriculum.compare.done'), __('reports.columns.percent'), __('reports.columns.behind')], $rows);
    }

    // ---- 8–10: work plan, tasks, meetings ------------------------------------------------------

    private function reportWorkPlan(array $filters): array
    {
        $goals = $this->db->all(
            "SELECT g.title, g.status, g.end_date, g.progress_percent, o.title AS objective, u.full_name AS owner_name,
                    (SELECT COUNT(*) FROM goal_actions a WHERE a.goal_id = g.id) AS actions,
                    (SELECT COUNT(*) FROM goal_actions a WHERE a.goal_id = g.id AND a.status = 'done') AS actions_done
               FROM annual_goals g JOIN plan_objectives o ON o.id = g.plan_objective_id LEFT JOIN users u ON u.id = g.owner_user_id
              WHERE g.school_id = ? AND g.academic_year_id = ? AND g.deleted_at IS NULL ORDER BY o.sort_order, o.id, g.end_date",
            [$this->schoolId(), $this->yearId()]
        );
        $rows = [];
        foreach ($goals as $goal) {
            $rows[] = [$goal['objective'], $goal['title'], $goal['owner_name'] ?? '', date('d/m/Y', strtotime($goal['end_date'])),
                __('goals.statuses.' . GoalService::displayStatus($goal)), (int) $goal['progress_percent'], $goal['actions_done'] . '/' . $goal['actions']];
        }
        $summary = GoalService::summary($goals);
        return $this->table(
            [__('goals.fields.plan_objective_id'), __('goals.fields.title'), __('goals.fields.owner_user_id'), __('goals.fields.end_date'),
                __('goals.fields.status'), __('reports.columns.percent'), __('goals.actions')],
            $rows,
            [__('reports.work_plan_note', ['total' => $summary['total'], 'completed' => $summary['completed'], 'delayed' => $summary['delayed'], 'progress' => (int) $summary['average_progress']])]
        );
    }

    private function reportTasks(array $filters): array
    {
        $me = (int) $this->auth->id();
        $visible = $this->scope->isUnrestricted() ? '1 = 1' : "(t.assignee_user_id = $me OR t.assigned_by = $me)";
        $rows = [];
        foreach ($this->db->all(
            "SELECT t.title, t.status, t.priority, t.due_date, a.full_name AS assignee, b.full_name AS assigner,
                    (t.due_date < CURDATE() AND t.status IN ('open', 'in_progress')) AS overdue
               FROM tasks t LEFT JOIN users a ON a.id = t.assignee_user_id LEFT JOIN users b ON b.id = t.assigned_by
              WHERE t.school_id = ? AND t.deleted_at IS NULL AND $visible
              ORDER BY t.status IN ('done', 'cancelled'), t.due_date IS NULL, t.due_date LIMIT 2000",
            [$this->schoolId()]
        ) as $row) {
            $rows[] = [$row['title'], $row['assignee'] ?? '', $row['assigner'] ?? '', $row['due_date'] ? date('d/m/Y', strtotime($row['due_date'])) : '',
                __('tasks.priorities.' . $row['priority']), __('tasks.statuses.' . $row['status']), $row['overdue'] ? __('tasks.overdue') : ''];
        }
        return $this->table([__('tasks.fields.title'), __('tasks.fields.assignee_user_id'), __('tasks.assigned_by'), __('tasks.due'),
            __('tasks.fields.priority'), __('tasks.fields.status'), __('tasks.overdue')], $rows);
    }

    private function reportMeetings(array $filters): array
    {
        $me = (int) $this->auth->id();
        $visible = $this->scope->isUnrestricted() ? '1 = 1'
            : "(m.organizer_user_id = $me OR EXISTS (SELECT 1 FROM meeting_participants mp WHERE mp.meeting_id = m.id AND mp.user_id = $me))";
        $rows = [];
        foreach ($this->db->all(
            "SELECT m.title, m.meeting_type, m.meeting_date, m.status AS meeting_status, d.decision, d.due_date, d.status, u.full_name AS owner_name
               FROM meetings m LEFT JOIN meeting_decisions d ON d.meeting_id = m.id LEFT JOIN users u ON u.id = d.owner_user_id
              WHERE m.school_id = ? AND m.academic_year_id = ? AND m.deleted_at IS NULL AND $visible
              ORDER BY m.meeting_date DESC, m.id DESC, d.id LIMIT 3000",
            [$this->schoolId(), $this->yearId()]
        ) as $row) {
            $rows[] = [date('d/m/Y', strtotime($row['meeting_date'])), __('meetings.types.' . $row['meeting_type']), $row['title'],
                __('meetings.statuses.' . $row['meeting_status']), $row['decision'] ?? '', $row['owner_name'] ?? '',
                $row['due_date'] ? date('d/m/Y', strtotime($row['due_date'])) : '',
                $row['status'] === null ? '' : __($row['status'] === 'done' ? 'reports.decision_done' : 'reports.decision_open')];
        }
        return $this->table([__('meetings.fields.meeting_date'), __('meetings.fields.meeting_type'), __('meetings.fields.title'), __('meetings.fields.status'),
            __('meetings.decision_fields.decision'), __('meetings.decision_fields.owner_user_id'), __('meetings.decision_fields.due_date'), __('reports.columns.decision_status')], $rows);
    }
}
