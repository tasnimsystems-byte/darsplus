<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Reports\Reports;
use App\Services\AccessScope;
use App\Services\AttendanceStats;
use App\Services\GoalService;
use App\Services\GradeStats;
use App\Services\Notifier;

/**
 * The dashboard. Every figure is computed from the database on each request and
 * through the viewer's own access scope and permissions: a principal sees the
 * school, a teacher sees their classes. No personal data appears here — only
 * counts and averages.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $db = $this->db();
        $auth = $this->auth();
        $schoolId = $this->schoolId();
        $me = (int) $auth->id();
        $year = $auth->year();
        $yearId = (int) ($year['id'] ?? 0);
        $scope = new AccessScope($db, $auth);
        [$classCondition, $classParams] = $scope->classCondition('c.id');
        $assignmentScope = $scope->assignmentCondition('ta');
        $card = static fn (string $label, string $icon, string $link, mixed $value): array => compact('label', 'icon', 'link', 'value');

        // ---- Filters (term, grade level, class, subject) for the pedagogical figures
        $options = $filters = [];
        if ($year !== null && ($auth->can('assessments.view') || $auth->can('attendance.view'))) {
            $reports = new Reports($db, $auth, $scope);
            foreach (['term_id', 'grade_level_id', 'class_id', 'subject_id'] as $name) {
                $options[$name] = $reports->options($name);
                $value = $request->int($name);
                if (isset($options[$name][$value])) {
                    $filters[$name] = $value;
                }
            }
        }
        $termId = $filters['term_id'] ?? null;
        $structural = array_diff_key($filters, ['term_id' => 1]);

        // ---- Structure
        $general = [];
        if ($auth->can('students.view')) {
            $general[] = $card($scope->isUnrestricted() ? 'dashboard.students' : 'dashboard.my_students', 'mortarboard', '/students', (int) $db->value(
                "SELECT COUNT(DISTINCT s.id) FROM students s
                   JOIN class_enrollments e ON e.student_id = s.id AND e.left_on IS NULL JOIN classes c ON c.id = e.class_id
                  WHERE s.school_id = ? AND s.deleted_at IS NULL AND s.status = 'active' AND c.academic_year_id = ? AND $classCondition",
                [$schoolId, $yearId, ...$classParams]
            ));
        }
        if ($auth->can('structure.view')) {
            $general[] = $card($scope->isUnrestricted() ? 'dashboard.classes' : 'dashboard.my_classes', 'grid-3x3-gap', '/classes', (int) $db->value(
                "SELECT COUNT(*) FROM classes c WHERE c.school_id = ? AND c.academic_year_id = ? AND $classCondition",
                [$schoolId, $yearId, ...$classParams]
            ));
            if ($scope->isUnrestricted()) {
                $general[] = $card('dashboard.grade_levels', 'layers', '/classes', (int) $db->value(
                    'SELECT COUNT(DISTINCT c.grade_level_id) FROM classes c WHERE c.school_id = ? AND c.academic_year_id = ?',
                    [$schoolId, $yearId]
                ));
            }
        }
        if ($auth->can('teachers.view')) {
            $general[] = $card('dashboard.teachers', 'person-workspace', '/teachers', (int) $db->value(
                'SELECT COUNT(*) FROM teachers WHERE school_id = ? AND deleted_at IS NULL AND is_active = 1',
                [$schoolId]
            ));
        }

        // ---- Pedagogy
        $pedagogy = $charts = [];
        if ($auth->can('assessments.view') && $year !== null) {
            $stats = new GradeStats($db, $schoolId, $yearId, $assignmentScope, $structural);
            $overall = $stats->overall($termId);
            $pedagogy[] = $card('dashboard.average', 'graph-up', '/achievements', $overall['average'] === null ? '—' : format_hours($overall['average']));
            if (!$stats->isEmpty()) {
                $bars = static function (array $data, array $names): array {
                    $rows = [];
                    foreach ($data as $id => $cell) {
                        $rows[$names[$id] ?? '?'] = $cell['average'];
                    }
                    ksort($rows, SORT_NATURAL);
                    return $rows;
                };
                $charts[] = ['title' => 'dashboard.chart_subjects', 'type' => 'bar', 'max' => 100, 'data' => $bars($stats->by('subject_id', $termId), $options['subject_id'])];
                $charts[] = ['title' => 'dashboard.chart_levels', 'type' => 'bar', 'max' => 100, 'data' => $bars($stats->by('grade_level_id', $termId), $options['grade_level_id'])];
                $charts[] = ['title' => 'dashboard.chart_distribution', 'type' => 'bar', 'max' => null,
                    'data' => array_combine(['0–54', '55–69', '70–84', '85–100'], $stats->distribution($termId))];
                $byTerm = $stats->by('term_id');
                if (count($byTerm) > 1) {
                    $charts[] = ['title' => 'dashboard.chart_terms', 'type' => 'line', 'max' => 100, 'data' => $bars($byTerm, $options['term_id'])];
                }
            }
        }
        if ($auth->can('attendance.view') && $year !== null) {
            $attendanceScope = $assignmentScope;
            foreach (['class_id' => 'ta.class_id = ?', 'subject_id' => 'ta.subject_id = ?',
                         'grade_level_id' => 'ta.class_id IN (SELECT id FROM classes WHERE grade_level_id = ?)'] as $name => $sql) {
                if (isset($filters[$name])) {
                    $attendanceScope = [$attendanceScope[0] . " AND $sql", [...$attendanceScope[1], $filters[$name]]];
                }
            }
            [$from, $to] = [null, null];
            if ($termId !== null) {
                $term = $db->one('SELECT start_date, end_date FROM academic_terms WHERE id = ?', [$termId]);
                [$from, $to] = [$term['start_date'], $term['end_date']];
            }
            $rate = AttendanceStats::rate($db, $schoolId, $yearId, $attendanceScope, $from, $to);
            $pedagogy[] = $card('dashboard.attendance_rate', 'person-check', '/attendance/report', $rate === null ? '—' : format_hours($rate) . '%');
        }
        if ($auth->can('curriculum.view') && $year !== null) {
            $progress = $db->one(
                "SELECT COUNT(*) AS total, COALESCE(SUM(cp.status = 'done'), 0) AS done
                   FROM curriculum_plans p
                   JOIN curriculum_units u ON u.plan_id = p.id JOIN curriculum_topics tp ON tp.unit_id = u.id
                   JOIN teaching_assignments ta ON ta.academic_year_id = p.academic_year_id AND ta.subject_id = p.subject_id
                   JOIN classes c ON c.id = ta.class_id AND c.grade_level_id = p.grade_level_id AND (p.class_id IS NULL OR p.class_id = c.id)
                   LEFT JOIN curriculum_progress cp ON cp.topic_id = tp.id AND cp.teaching_assignment_id = ta.id
                  WHERE p.school_id = ? AND p.academic_year_id = ? AND p.deleted_at IS NULL AND {$assignmentScope[0]}",
                [$schoolId, $yearId, ...$assignmentScope[1]]
            );
            $pedagogy[] = $card('dashboard.curriculum_progress', 'journal-bookmark', '/curriculum',
                (int) $progress['total'] === 0 ? '—' : round((int) $progress['done'] / (int) $progress['total'] * 100) . '%');
        }
        if ($auth->can('work_plan.view') && $year !== null) {
            $goalRows = $db->all(
                'SELECT status, end_date, progress_percent FROM annual_goals WHERE school_id = ? AND academic_year_id = ? AND deleted_at IS NULL',
                [$schoolId, $yearId]
            );
            $goals = GoalService::summary($goalRows);
            $pedagogy[] = $card('dashboard.goals_progress', 'bullseye', '/work-plan', $goals['average_progress'] === null ? '—' : $goals['average_progress'] . '%');
            $pedagogy[] = $card('dashboard.goals_completed', 'check2-circle', '/goals', $goals['completed']);
            $pedagogy[] = $card('dashboard.goals_delayed', 'hourglass-split', '/goals', $goals['delayed']);
            if ($goalRows !== []) {
                $byStatus = [];
                foreach ($goalRows as $goal) {
                    $label = __('goals.statuses.' . GoalService::displayStatus($goal));
                    $byStatus[$label] = ($byStatus[$label] ?? 0) + 1;
                }
                $charts[] = ['title' => 'dashboard.chart_goals', 'type' => 'doughnut', 'max' => null, 'data' => $byStatus];
            }
        }
        if ($auth->can('risk.view') && $year !== null) {
            [$fullCondition, $fullParams] = $scope->fullClassCondition('e.class_id');
            $pedagogy[] = $card('dashboard.indications_new', 'flag', '/risk?status=new', (int) $db->value(
                "SELECT COUNT(*) FROM risk_indications ri JOIN academic_terms tm ON tm.id = ri.term_id
                   JOIN class_enrollments e ON e.student_id = ri.student_id AND e.left_on IS NULL
                   JOIN classes c ON c.id = e.class_id AND c.academic_year_id = tm.academic_year_id
                  WHERE ri.school_id = ? AND tm.academic_year_id = ? AND ri.review_status = 'new' AND $fullCondition",
                [$schoolId, $yearId, ...$fullParams]
            ));
        }

        // ---- Work waiting for this user
        $work = [];
        if ($auth->can('tasks.view')) {
            $tasks = $db->one(
                "SELECT COUNT(*) AS open, COALESCE(SUM(due_date < CURDATE()), 0) AS overdue FROM tasks
                  WHERE school_id = ? AND assignee_user_id = ? AND deleted_at IS NULL AND status IN ('open', 'in_progress')",
                [$schoolId, $me]
            );
            $work[] = $card('dashboard.my_tasks', 'check2-square', '/tasks?mine=1', (int) $tasks['open']);
            $work[] = $card('dashboard.my_overdue', 'alarm', '/tasks?mine=1', (int) $tasks['overdue']);
        }
        if ($auth->can('meetings.view') && $year !== null) {
            $visible = $scope->isUnrestricted() ? '1 = 1'
                : "(m.organizer_user_id = $me OR EXISTS (SELECT 1 FROM meeting_participants mp WHERE mp.meeting_id = m.id AND mp.user_id = $me))";
            $work[] = $card('dashboard.upcoming_meetings', 'calendar-event', '/meetings?status=planned', (int) $db->value(
                "SELECT COUNT(*) FROM meetings m WHERE m.school_id = ? AND m.academic_year_id = ? AND m.deleted_at IS NULL
                    AND m.status = 'planned' AND m.meeting_date >= CURDATE() AND $visible",
                [$schoolId, $yearId]
            ));
            $work[] = $card('dashboard.open_decisions', 'list-check', '/meetings', (int) $db->value(
                "SELECT COUNT(*) FROM meeting_decisions d JOIN meetings m ON m.id = d.meeting_id
                  WHERE m.school_id = ? AND m.academic_year_id = ? AND m.deleted_at IS NULL AND d.status = 'open' AND $visible",
                [$schoolId, $yearId]
            ));
        }
        if ($auth->can('interventions.view') && $year !== null) {
            $work[] = $card('dashboard.my_interventions', 'life-preserver', '/interventions?status=active', (int) $db->value(
                "SELECT COUNT(*) FROM student_interventions WHERE school_id = ? AND academic_year_id = ? AND deleted_at IS NULL
                    AND status = 'active' AND owner_user_id = ?",
                [$schoolId, $yearId, $me]
            ));
        }
        if ($auth->can('users.view')) {
            $work[] = $card('dashboard.active_users', 'people', '/users', (int) $db->value(
                'SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id
                  WHERE ur.school_id = ? AND u.is_active = 1 AND u.deleted_at IS NULL',
                [$schoolId]
            ));
        }
        $work[] = $card('dashboard.unread_notifications', 'bell', '/notifications', Notifier::unreadCount($me, $schoolId));

        $activity = [];
        if ($auth->can('audit.view')) {
            $activity = $db->all(
                'SELECT a.action, a.entity_type, a.created_at, u.full_name
                   FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                  WHERE a.school_id = ? ORDER BY a.id DESC LIMIT 8',
                [$schoolId]
            );
        }

        return $this->view('dashboard/index', [
            'groups' => array_filter(['dashboard.group_general' => $general, 'dashboard.group_pedagogy' => $pedagogy, 'dashboard.group_work' => $work]),
            'charts' => $charts, 'options' => $options, 'filters' => $filters, 'activity' => $activity, 'year' => $year,
        ]);
    }
}
