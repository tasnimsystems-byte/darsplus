<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AccessScope;
use App\Services\AttendanceStats;
use App\Services\StudentDay;
use App\Controllers\TimetableController;

/**
 * Attendance per lesson. The teacher opens a lesson (assignment + date + period)
 * and marks only the exceptions. Recording is for the assignment's own teacher
 * or a user who sees the whole school; viewing follows the access scope.
 */
final class AttendanceController extends Controller
{
    public const STATUSES = ['absent', 'late', 'justified'];

    /** Remarks a teacher can give a student in a lesson, chosen from a list (labels in attendance.remarks.*). */
    public const REMARKS = [
        'negative' => ['no_homework', 'no_materials', 'inappropriate_behavior', 'negligence', 'disruption',
            'phone_use', 'no_participation', 'left_without_permission', 'refused_task'],
        'positive' => ['active_participation', 'excellent_work', 'notable_improvement', 'helped_peers'],
    ];

    /** @return string[] */
    public static function remarkCodes(): array
    {
        return array_merge(...array_values(self::REMARKS));
    }

    private ?AccessScope $scope = null;

    private function scope(): AccessScope
    {
        return $this->scope ??= new AccessScope($this->db(), $this->auth());
    }

    private function year(): array
    {
        return $this->auth()->year() ?? throw new RedirectException('/years', 'app.errors.no_year');
    }

    /** @return array<int, string> assignments the user may record attendance for */
    private function recordable(array $year): array
    {
        [$condition, $params] = $this->scope()->assignmentCondition('ta');
        $options = [];
        foreach ($this->db()->all(
            "SELECT ta.id, CONCAT(c.name, ' · ', s.name, ' · ', t.last_name, ' ', t.first_name) AS label
               FROM teaching_assignments ta JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id
               JOIN teachers t ON t.id = ta.teacher_id
              WHERE ta.school_id = ? AND ta.academic_year_id = ? AND $condition ORDER BY c.name, s.name",
            [$this->schoolId(), $year['id'], ...$params]
        ) as $row) {
            if ($this->scope()->canEditAssignment((int) $row['id'])) {
                $options[(int) $row['id']] = $row['label'];
            }
        }
        return $options;
    }

    private function session(int $id): array
    {
        [$condition, $params] = $this->scope()->assignmentCondition('ta');
        return $this->db()->one(
            "SELECT ls.*, ta.class_id, ta.subject_id, ta.teacher_id, ta.academic_year_id, ta.group_name, ta.study_group_id, c.grade_level_id,
                    c.name AS class_name, s.name AS subject_name, CONCAT(t.last_name, ' ', t.first_name) AS teacher_name,
                    y.is_locked, sg.name AS study_group_name
               FROM lesson_sessions ls
               JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
               LEFT JOIN study_groups sg ON sg.id = ta.study_group_id AND sg.school_id = ta.school_id
               JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id JOIN teachers t ON t.id = ta.teacher_id
               JOIN academic_years y ON y.id = ta.academic_year_id
              WHERE ls.id = ? AND ls.school_id = ? AND $condition",
            [$id, $this->schoolId(), ...$params]
        ) ?? throw new HttpException(404);
    }

    public function index(Request $request): Response
    {
        $year = $this->year();
        $date = $request->str('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $year['start_date'] || $date > $year['end_date']) {
            $date = min(max(date('Y-m-d'), $year['start_date']), $year['end_date']);
        }
        [$condition, $params] = $this->scope()->assignmentCondition('ta');
        $assignments = $this->recordable($year);
        $scheduled = [];
        if ($assignments !== [] && can('attendance.create')) {
            $ids = implode(',', array_map('intval', array_keys($assignments)));
            $scheduled = $this->db()->all(
                "SELECT ts.teaching_assignment_id, ts.period_number, ls.id AS session_id
                   FROM timetable_slots ts
                   LEFT JOIN lesson_sessions ls ON ls.teaching_assignment_id = ts.teaching_assignment_id
                        AND ls.lesson_date = ? AND ls.period_number = ts.period_number
                  WHERE ts.school_id = ? AND ts.academic_year_id = ? AND ts.weekday = ? AND ts.teaching_assignment_id IN ($ids)
                  ORDER BY ts.period_number",
                [$date, $this->schoolId(), $year['id'], (int) date('w', strtotime($date))]
            );
        }
        return $this->view('attendance/index', [
            'date' => $date, 'year' => $year, 'assignments' => $assignments, 'scheduled' => $scheduled,
            'periods' => TimetableController::periods($this->db(), $this->schoolId(), (int) $year['id']),
            'hasTimetable' => $this->db()->value('SELECT 1 FROM timetable_slots WHERE school_id = ? AND academic_year_id = ? LIMIT 1', [$this->schoolId(), $year['id']]) !== null,
            'sessions' => $this->db()->all(
                "SELECT ls.id, ls.period_number, ls.status, c.name AS class_name, s.name AS subject_name,
                        CONCAT(t.last_name, ' ', t.first_name) AS teacher_name,
                        (SELECT COUNT(*) FROM attendance_records ar WHERE ar.lesson_session_id = ls.id AND ar.status IN ('absent', 'justified')) AS missing
                   FROM lesson_sessions ls JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
                   JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id JOIN teachers t ON t.id = ta.teacher_id
                  WHERE ls.school_id = ? AND ta.academic_year_id = ? AND ls.lesson_date = ? AND $condition
                  ORDER BY ls.period_number, c.name",
                [$this->schoolId(), $year['id'], $date, ...$params]
            ),
            'canRecord' => can('attendance.create') && !$year['is_locked'],
        ]);
    }

    public function store(Request $request): Response
    {
        $year = $this->year();
        if ($year['is_locked']) {
            return $this->redirect('/attendance', null, 'app.errors.year_locked');
        }
        $assignments = $this->recordable($year);
        $v = Validator::make($request->post, [
            'teaching_assignment_id' => 'required|in:' . implode(',', array_keys($assignments)),
            'lesson_date' => 'required|date', 'period_number' => 'required|int|min:1|max:14',
        ], 'attendance.fields');
        $data = $v->validated();
        if (!$v->fails() && ($data['lesson_date'] < $year['start_date'] || $data['lesson_date'] > min($year['end_date'], date('Y-m-d')))) {
            $v->addError('lesson_date', __('attendance.errors.date'));
        }
        if ($v->fails()) {
            return $this->invalid('/attendance', $v->errors(), $request->post);
        }
        $key = [(int) $data['teaching_assignment_id'], $data['lesson_date'], $data['period_number']];
        $existing = $this->db()->value(
            'SELECT id FROM lesson_sessions WHERE teaching_assignment_id = ? AND lesson_date = ? AND period_number = ?',
            $key
        );
        if ($existing === null) {
            $existing = $this->db()->insert('lesson_sessions', [
                'school_id' => $this->schoolId(), 'teaching_assignment_id' => $key[0], 'lesson_date' => $key[1],
                'period_number' => $key[2], 'recorded_by' => $this->auth()->id(),
            ]);
            $this->audit('create', 'lesson_session', (int) $existing, null, ['assignment' => $key[0], 'date' => $key[1], 'period' => $key[2]]);
        }
        return Response::redirect("/attendance/sessions/$existing");
    }

    /**
     * The assignments taught together in this lesson: for a study group, the group's assignments of the
     * same teacher (one per class); otherwise only this one.
     *
     * @return array<int, int> class id => assignment id
     */
    private function siblings(array $session): array
    {
        $map = [(int) $session['class_id'] => (int) $session['teaching_assignment_id']];
        if ($session['study_group_id'] !== null) {
            foreach ($this->db()->all(
                'SELECT id, class_id FROM teaching_assignments
                  WHERE school_id = ? AND study_group_id = ? AND teacher_id = ? AND academic_year_id = ? ORDER BY id',
                [$this->schoolId(), $session['study_group_id'], $session['teacher_id'], $session['academic_year_id']]
            ) as $row) {
                $map[(int) $row['class_id']] ??= (int) $row['id'];
            }
        }
        return $map;
    }

    /**
     * The lesson's students with the class each one is recorded in: the whole class, or for a study
     * group only its members (from all the classes taught together). Students already recorded in this
     * lesson stay listed.
     */
    private function roster(array $session, array $siblings): array
    {
        if ($session['study_group_id'] === null) {
            return $this->db()->all(
                'SELECT s.id, s.first_name, s.last_name, s.student_code, ? AS class_id
                   FROM students s
                  WHERE s.deleted_at IS NULL AND (s.id IN (SELECT e.student_id FROM class_enrollments e WHERE e.class_id = ? AND e.left_on IS NULL)
                        OR s.id IN (SELECT ar.student_id FROM attendance_records ar WHERE ar.lesson_session_id = ?))
                  ORDER BY s.last_name, s.first_name, s.id',
                [$session['class_id'], $session['class_id'], $session['id']]
            );
        }
        $classes = implode(',', array_map('intval', array_keys($siblings)));
        $rows = $this->db()->all(
            "SELECT s.id, s.first_name, s.last_name, s.student_code, e.class_id, c.name AS class_name
               FROM study_group_students m JOIN students s ON s.id = m.student_id
               JOIN class_enrollments e ON e.student_id = s.id AND e.left_on IS NULL AND e.class_id IN ($classes)
               JOIN classes c ON c.id = e.class_id
              WHERE m.group_id = ? AND s.deleted_at IS NULL
              ORDER BY s.last_name, s.first_name, s.id",
            [$session['study_group_id']]
        );
        $listed = array_flip(array_map(static fn ($r) => (int) $r['id'], $rows));
        foreach ($this->db()->all(
            'SELECT s.id, s.first_name, s.last_name, s.student_code, ? AS class_id, NULL AS class_name
               FROM attendance_records ar JOIN students s ON s.id = ar.student_id WHERE ar.lesson_session_id = ?',
            [$session['class_id'], $session['id']]
        ) as $row) {
            if (!isset($listed[(int) $row['id']])) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @return array<int, int> assignment id => session id, for the lessons taught together on this date and period */
    private function siblingSessions(array $session, array $siblings): array
    {
        $ids = implode(',', array_map('intval', $siblings));
        return array_map('intval', array_column($this->db()->all(
            "SELECT id, teaching_assignment_id FROM lesson_sessions
              WHERE teaching_assignment_id IN ($ids) AND lesson_date = ? AND period_number = ?",
            [$session['lesson_date'], $session['period_number']]
        ), 'id', 'teaching_assignment_id'));
    }

    /** Topics of the curriculum plans that apply to this lesson's class and subject, with this assignment's progress. */
    private function curriculumTopics(array $session): array
    {
        return $this->db()->all(
            "SELECT tp.id, tp.title, u.title AS unit_title, cp.status AS progress
               FROM curriculum_plans p JOIN curriculum_units u ON u.plan_id = p.id JOIN curriculum_topics tp ON tp.unit_id = u.id
               LEFT JOIN curriculum_progress cp ON cp.topic_id = tp.id AND cp.teaching_assignment_id = ?
              WHERE p.school_id = ? AND p.academic_year_id = ? AND p.subject_id = ? AND p.grade_level_id = ? AND p.deleted_at IS NULL
                AND (p.class_id IS NULL OR p.class_id = ?)
              ORDER BY p.id, u.sort_order, u.id, COALESCE(tp.parent_topic_id, tp.id), tp.parent_topic_id IS NOT NULL, tp.sort_order, tp.id",
            [$session['teaching_assignment_id'], $this->schoolId(), $session['academic_year_id'], $session['subject_id'], $session['grade_level_id'], $session['class_id']]
        );
    }

    public function show(Request $request, int $id): Response
    {
        $session = $this->session($id);
        $assignment = (int) $session['teaching_assignment_id'];
        $siblings = $this->siblings($session);
        $together = $this->siblingSessions($session, $siblings);
        $history = [];
        foreach ($this->db()->all(
            "SELECT ar.student_id, SUM(ar.status IN ('absent', 'justified')) AS absences, SUM(ar.status = 'late') AS lates,
                    SUM(ar.remark IN ('" . implode("', '", self::REMARKS['negative']) . "')) AS remarks
               FROM attendance_records ar JOIN lesson_sessions ls ON ls.id = ar.lesson_session_id
              WHERE ls.teaching_assignment_id IN (" . implode(',', $siblings) . ") AND ls.status = 'held'
                AND ls.id NOT IN (" . implode(',', $together) . ")
              GROUP BY ar.student_id",
            []
        ) as $row) {
            $history[(int) $row['student_id']] = $row;
        }
        [$condition, $params] = $this->scope()->assignmentCondition('ta');
        $sameDay = $this->db()->all(
            "SELECT ts.period_number, ts.teaching_assignment_id, c.name AS class_name, s.name AS subject_name, ls.id AS session_id
               FROM timetable_slots ts JOIN teaching_assignments ta ON ta.id = ts.teaching_assignment_id
               JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id
               LEFT JOIN lesson_sessions ls ON ls.teaching_assignment_id = ts.teaching_assignment_id AND ls.lesson_date = ? AND ls.period_number = ts.period_number
              WHERE ts.school_id = ? AND ts.academic_year_id = ? AND ta.teacher_id = ? AND ts.weekday = ? AND $condition
              ORDER BY ts.period_number, c.name",
            [$session['lesson_date'], $this->schoolId(), $session['academic_year_id'], $session['teacher_id'], (int) date('w', strtotime($session['lesson_date'])), ...$params]
        );
        $records = [];
        foreach ($this->db()->all(
            'SELECT student_id, status, remark, note FROM attendance_records WHERE lesson_session_id IN (' . implode(',', $together) . ')'
        ) as $row) {
            $records[(int) $row['student_id']] = $row;
        }
        $students = [];
        foreach ($this->roster($session, $siblings) as $student) {
            $record = $records[(int) $student['id']] ?? ['status' => null, 'remark' => null, 'note' => null];
            $students[] = $student + ['status' => $record['status'], 'remark' => $record['remark'], 'note' => $record['note']];
        }
        $groupClasses = $session['study_group_id'] === null ? [] : array_values(array_unique(array_filter(array_column($students, 'class_name'))));
        return $this->view('attendance/session', [
            'session' => $session, 'history' => $history, 'sameDay' => $sameDay, 'students' => $students, 'groupClasses' => $groupClasses,
            // The whole day of each student, across every teacher's lessons.
            'dayPeriods' => StudentDay::periods($this->db(), $this->schoolId(), (int) $session['academic_year_id']),
            'dayGrid' => StudentDay::grid($this->db(), $this->schoolId(), $session['lesson_date'], array_map(static fn ($s) => (int) $s['id'], $students)),
            'periods' => TimetableController::periods($this->db(), $this->schoolId(), (int) $session['academic_year_id']),
            'previous' => $this->db()->one(
                "SELECT id, lesson_date, period_number, topic, homework FROM lesson_sessions
                  WHERE teaching_assignment_id = ? AND status = 'held' AND (lesson_date < ? OR (lesson_date = ? AND period_number < ?))
                  ORDER BY lesson_date DESC, period_number DESC LIMIT 1",
                [$assignment, $session['lesson_date'], $session['lesson_date'], $session['period_number']]
            ),
            'topics' => $this->curriculumTopics($session),
            'canProgress' => can('curriculum.update'),
            'online' => [
                'enabled' => \App\Services\LiveKit::enabled(),
                'live' => \App\Services\OnlineLessons::liveFor($this->db(), array_values($together)),
                'joined' => \App\Services\OnlineLessons::joinedStudents($this->db(), array_values($together)),
                'held' => $this->db()->value('SELECT 1 FROM online_meetings WHERE lesson_session_id IN (' . implode(',', $together) . ') LIMIT 1') !== null,
                'today' => $session['lesson_date'] === date('Y-m-d'),
            ],
            'canEdit' => can('attendance.update') && !$session['is_locked']
                && $this->scope()->canEditAssignment((int) $session['teaching_assignment_id']),
        ]);
    }

    private function editable(int $id): array
    {
        $session = $this->session($id);
        if (!$this->scope()->canEditAssignment((int) $session['teaching_assignment_id'])) {
            throw new HttpException(403);
        }
        if ($session['is_locked']) {
            throw new RedirectException("/attendance/sessions/$id", 'app.errors.year_locked');
        }
        return $session;
    }

    public function save(Request $request, int $id): Response
    {
        $session = $this->editable($id);
        $v = Validator::make($request->post, [
            'topic' => 'max:255', 'homework' => 'max:2000', 'lesson_notes' => 'max:2000',
            'curriculum_topic_id' => 'int', 'topic_progress' => 'in:in_progress,done',
        ], 'attendance.record');
        $record = $v->validated();
        $topics = array_column($this->curriculumTopics($session), null, 'id');
        if (!$v->fails() && $record['curriculum_topic_id'] !== null && !isset($topics[$record['curriculum_topic_id']])) {
            $v->addError('curriculum_topic_id', __('validation.in', ['field' => __('attendance.record.curriculum_topic_id')]));
        }
        if ($v->fails()) {
            return $this->invalid("/attendance/sessions/$id", $v->errors(), $request->post);
        }
        $input = is_array($request->post['students'] ?? null) ? $request->post['students'] : [];
        $held = $session['status'] === 'held';
        $siblings = $this->siblings($session);
        $roster = $this->roster($session, $siblings);
        $exceptions = 0;
        $this->db()->transaction(function () use ($id, $input, $roster, $siblings, $held, $record, $session, &$exceptions): void {
            // The lessons of the other classes taught together are opened with this one.
            $sessions = $this->siblingSessions($session, $siblings);
            foreach ($siblings as $assignmentId) {
                $sessions[$assignmentId] ??= $this->db()->insert('lesson_sessions', [
                    'school_id' => $this->schoolId(), 'teaching_assignment_id' => $assignmentId, 'lesson_date' => $session['lesson_date'],
                    'period_number' => $session['period_number'], 'status' => $session['status'], 'recorded_by' => $this->auth()->id(),
                ]);
            }
            foreach ($sessions as $sessionId) {
                $this->db()->update('lesson_sessions', [
                    'topic' => $record['topic'], 'homework' => $record['homework'], 'lesson_notes' => $record['lesson_notes'],
                    'curriculum_topic_id' => $sessionId === $id ? $record['curriculum_topic_id'] : null, 'recorded_by' => $this->auth()->id(),
                ], ['id' => $sessionId]);
            }
            // Teaching a topic of the plan is also recorded as curriculum progress of this class.
            if ($record['curriculum_topic_id'] !== null && can('curriculum.update')) {
                $this->db()->run(
                    'INSERT INTO curriculum_progress (school_id, topic_id, teaching_assignment_id, status, actual_date, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), actual_date = COALESCE(actual_date, VALUES(actual_date)), updated_by = VALUES(updated_by)',
                    [$this->schoolId(), $record['curriculum_topic_id'], $session['teaching_assignment_id'], $record['topic_progress'] ?? 'in_progress',
                        $session['lesson_date'], $this->auth()->id()]
                );
            }
            foreach ($held ? $roster : [] as $student) {
                $studentId = (int) $student['id'];
                $target = $sessions[$siblings[(int) $student['class_id']] ?? 0] ?? $id;
                $row = is_array($input[$studentId] ?? null) ? $input[$studentId] : [];
                $status = is_scalar($row['status'] ?? null) ? (string) $row['status'] : 'present';
                $status = in_array($status, self::STATUSES, true) ? $status : 'present';
                $remark = is_scalar($row['remark'] ?? null) && in_array((string) $row['remark'], self::remarkCodes(), true) ? (string) $row['remark'] : null;
                $note = is_scalar($row['note'] ?? null) ? mb_substr(trim((string) $row['note']), 0, 255) : '';
                if ($status === 'present' && $remark === null && $note === '') {
                    // Present with nothing to say: no row is kept.
                    $this->db()->run('DELETE FROM attendance_records WHERE lesson_session_id = ? AND student_id = ?', [$target, $studentId]);
                    continue;
                }
                $this->db()->run(
                    'INSERT INTO attendance_records (school_id, lesson_session_id, student_id, status, remark, note) VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), remark = VALUES(remark), note = VALUES(note)',
                    [$this->schoolId(), $target, $studentId, $status, $remark, $note === '' ? null : $note]
                );
                $exceptions += $status === 'present' ? 0 : 1;
            }
        });
        $this->audit('update', 'lesson_session', $id, null, ['exceptions' => $exceptions, 'topic' => $record['topic'], 'curriculum_topic_id' => $record['curriculum_topic_id']]);
        return $this->redirect("/attendance/sessions/$id", 'attendance.saved');
    }

    /** A lesson that did not take place is kept but excluded from every figure. */
    public function toggleCancel(Request $request, int $id): Response
    {
        $session = $this->editable($id);
        $status = $session['status'] === 'held' ? 'cancelled' : 'held';
        foreach ($this->siblingSessions($session, $this->siblings($session)) as $sessionId) {
            $this->db()->update('lesson_sessions', ['status' => $status], ['id' => $sessionId]);
        }
        $this->audit('update', 'lesson_session', $id, ['status' => $session['status']], ['status' => $status]);
        return $this->redirect("/attendance/sessions/$id", 'app.updated');
    }

    public function report(Request $request): Response
    {
        $year = $this->year();
        $schoolId = $this->schoolId();
        [$classCondition, $classParams] = $this->scope()->classCondition('c.id');
        $classes = array_column($this->db()->all(
            "SELECT c.id, c.name FROM classes c JOIN grade_levels gl ON gl.id = c.grade_level_id
              WHERE c.school_id = ? AND c.academic_year_id = ? AND $classCondition ORDER BY gl.level_number, c.name",
            [$schoolId, $year['id'], ...$classParams]
        ), 'name', 'id');
        $terms = $this->db()->all('SELECT id, name, start_date, end_date FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order', [$year['id']]);

        $classId = $request->int('class_id');
        $classId = isset($classes[$classId]) ? $classId : (array_key_first($classes) ?? 0);
        $from = $to = null;
        $termId = $request->int('term_id');
        foreach ($terms as $term) {
            if ((int) $term['id'] === $termId) {
                [$from, $to] = [$term['start_date'], $term['end_date']];
            }
        }
        $scope = $this->scope()->assignmentCondition('ta');
        return $this->view('attendance/report', [
            'classes' => $classes, 'terms' => array_column($terms, 'name', 'id'), 'classId' => $classId,
            'termId' => $from === null ? 0 : $termId,
            'rows' => $classId === 0 ? [] : AttendanceStats::byStudent($this->db(), $classId, $scope, $from, $to),
            'rate' => AttendanceStats::rate($this->db(), $schoolId, (int) $year['id'], $scope, $from, $to),
            'partial' => !$this->scope()->isUnrestricted(),
        ]);
    }
}
