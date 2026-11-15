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

/**
 * Attendance per lesson. The teacher opens a lesson (assignment + date + period)
 * and marks only the exceptions. Recording is for the assignment's own teacher
 * or a user who sees the whole school; viewing follows the access scope.
 */
final class AttendanceController extends Controller
{
    public const STATUSES = ['absent', 'late', 'justified'];

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
            "SELECT ls.*, ta.class_id, c.name AS class_name, s.name AS subject_name, CONCAT(t.last_name, ' ', t.first_name) AS teacher_name,
                    y.is_locked
               FROM lesson_sessions ls
               JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
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
        return $this->view('attendance/index', [
            'date' => $date, 'year' => $year, 'assignments' => $this->recordable($year),
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

    public function show(Request $request, int $id): Response
    {
        $session = $this->session($id);
        return $this->view('attendance/session', [
            'session' => $session,
            'students' => $this->db()->all(
                'SELECT s.id, s.first_name, s.last_name, ar.status, ar.note
                   FROM students s
                   LEFT JOIN attendance_records ar ON ar.student_id = s.id AND ar.lesson_session_id = ?
                  WHERE s.deleted_at IS NULL AND (ar.id IS NOT NULL OR s.id IN (
                        SELECT e.student_id FROM class_enrollments e WHERE e.class_id = ? AND e.left_on IS NULL))
                  ORDER BY s.last_name, s.first_name, s.id',
                [$id, $session['class_id']]
            ),
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
        $input = is_array($request->post['students'] ?? null) ? $request->post['students'] : [];
        $roster = array_map('intval', $this->db()->column(
            'SELECT e.student_id FROM class_enrollments e WHERE e.class_id = ? AND e.left_on IS NULL
             UNION SELECT ar.student_id FROM attendance_records ar WHERE ar.lesson_session_id = ?',
            [$session['class_id'], $id]
        ));
        $exceptions = 0;
        $this->db()->transaction(function () use ($id, $input, $roster, &$exceptions): void {
            foreach ($roster as $studentId) {
                $row = is_array($input[$studentId] ?? null) ? $input[$studentId] : [];
                $status = is_scalar($row['status'] ?? null) ? (string) $row['status'] : 'present';
                if (!in_array($status, self::STATUSES, true)) {
                    // Present: no row is kept.
                    $this->db()->run('DELETE FROM attendance_records WHERE lesson_session_id = ? AND student_id = ?', [$id, $studentId]);
                    continue;
                }
                $note = is_scalar($row['note'] ?? null) ? mb_substr(trim((string) $row['note']), 0, 255) : '';
                $this->db()->run(
                    'INSERT INTO attendance_records (school_id, lesson_session_id, student_id, status, note) VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note)',
                    [$this->schoolId(), $id, $studentId, $status, $note === '' ? null : $note]
                );
                $exceptions++;
            }
            $this->db()->update('lesson_sessions', ['recorded_by' => $this->auth()->id()], ['id' => $id]);
        });
        $this->audit('update', 'lesson_session', $id, null, ['exceptions' => $exceptions]);
        return $this->redirect("/attendance/sessions/$id", 'attendance.saved');
    }

    /** A lesson that did not take place is kept but excluded from every figure. */
    public function toggleCancel(Request $request, int $id): Response
    {
        $session = $this->editable($id);
        $status = $session['status'] === 'held' ? 'cancelled' : 'held';
        $this->db()->update('lesson_sessions', ['status' => $status], ['id' => $id]);
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
