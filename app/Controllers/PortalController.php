<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\GradeStats;
use App\Services\Portal;
use App\Services\ReportCards;

/**
 * The student and parent portal (/me): read only, and always about the one student the account may see
 * (Portal::current). Nothing here accepts a student id from the address.
 */
final class PortalController extends Controller
{
    private function student(): array
    {
        $student = Portal::current($this->db(), $this->auth());
        if ($student === null) {
            throw new HttpException(403);
        }
        return $student;
    }

    private function terms(): array
    {
        return $this->db()->all(
            'SELECT id, name, start_date, end_date FROM academic_terms WHERE school_id = ? AND academic_year_id = ? ORDER BY sort_order, start_date',
            [$this->schoolId(), (int) ($this->auth()->year()['id'] ?? 0)]
        );
    }

    /** The term asked for, else today's, else the first. */
    private function term(Request $request): ?array
    {
        $terms = array_column($this->terms(), null, 'id');
        $id = $request->int('term_id');
        if (isset($terms[$id])) {
            return $terms[$id];
        }
        foreach ($terms as $term) {
            if (date('Y-m-d') >= $term['start_date'] && date('Y-m-d') <= $term['end_date']) {
                return $term;
            }
        }
        return $terms === [] ? null : reset($terms);
    }

    private function render(string $view, array $data): Response
    {
        $students = Portal::students($this->db(), $this->auth());
        return $this->view($view, $data + ['children' => count($students) > 1 ? $students : [], 'terms' => $this->terms()]);
    }

    public function chooseChild(Request $request): Response
    {
        if (!Portal::choose($this->db(), $this->auth(), $request->int('student_id'))) {
            throw new HttpException(403);
        }
        $back = $request->str('back');
        return Response::redirect(preg_match('#^/me(/[a-z-]*)?$#', $back) ? $back : '/me');
    }

    // ---- Home ---------------------------------------------------------------

    public function home(Request $request): Response
    {
        $student = $this->student();
        $assignments = Portal::assignments($this->db(), $student);
        $in = $assignments === [] ? '0' : implode(',', $assignments);
        $yearId = (int) ($this->auth()->year()['id'] ?? 0);

        // This week's timetable of the student's own lessons.
        $week = [];
        foreach ($this->db()->all(
            "SELECT ts.weekday, ts.period_number, s.name AS subject, CONCAT(t.first_name, ' ', t.last_name) AS teacher, r.name AS room
               FROM timetable_slots ts JOIN teaching_assignments ta ON ta.id = ts.teaching_assignment_id
               JOIN subjects s ON s.id = ta.subject_id JOIN teachers t ON t.id = ta.teacher_id LEFT JOIN rooms r ON r.id = ts.room_id
              WHERE ts.teaching_assignment_id IN ($in) ORDER BY ts.period_number, s.name"
        ) as $slot) {
            $week[(int) $slot['period_number']][(int) $slot['weekday']][] = $slot;
        }
        $periods = TimetableController::periods($this->db(), $this->schoolId(), $yearId);
        foreach (array_keys($week) as $number) {
            $periods[$number] ??= ['period_number' => $number, 'name' => '', 'start_time' => null, 'end_time' => null, 'is_break' => 0];
        }
        ksort($periods);

        $latest = $this->db()->all(
            "SELECT a.title, a.assessment_date, a.max_score, r.score, r.result_status, s.name AS subject
               FROM assessment_results r JOIN assessments a ON a.id = r.assessment_id
               JOIN teaching_assignments ta ON ta.id = a.teaching_assignment_id JOIN subjects s ON s.id = ta.subject_id
              WHERE r.student_id = ? AND a.teaching_assignment_id IN ($in) AND a.status = 'published' AND a.deleted_at IS NULL
                AND a.is_summary = 0 AND s.is_graded = 1
              ORDER BY a.assessment_date DESC, a.id DESC LIMIT 5",
            [$student['id']]
        );
        $homework = $this->homeworkRows($in, 'ls.lesson_date >= ?', [date('Y-m-d', strtotime('-7 days'))], 5);
        $absentWeek = (int) $this->db()->value(
            "SELECT COUNT(*) FROM attendance_records ar JOIN lesson_sessions ls ON ls.id = ar.lesson_session_id
              WHERE ar.student_id = ? AND ar.status = 'absent' AND ls.status = 'held' AND ls.lesson_date >= ?",
            [$student['id'], date('Y-m-d', strtotime('-6 days'))]
        );

        return $this->render('portal/home', [
            'student' => $student, 'week' => $week, 'periods' => $periods,
            'days' => TimetableController::days($this->db(), $this->schoolId(), $yearId), 'today' => (int) date('w'),
            'latest' => $latest, 'homework' => $homework, 'absentWeek' => $absentWeek,
            // Live lessons now – for the student's own account only (a parent does not enter the lesson).
            'live' => \App\Services\LiveKit::enabled() && \App\Services\OnlineLessons::ownStudent($this->db(), $this->auth()) !== null
                ? \App\Services\OnlineLessons::liveForStudent($this->db(), $student) : [],
        ]);
    }

    // ---- My grades ------------------------------------------------------------

    public function grades(Request $request): Response
    {
        $student = $this->student();
        $term = $this->term($request);
        $assignments = Portal::assignments($this->db(), $student);
        $subjects = [];
        if ($term !== null && $assignments !== []) {
            $in = implode(',', $assignments);
            $weights = GradeStats::componentWeights($this->db(), $this->schoolId());
            $types = array_column($this->db()->all('SELECT id, name, is_numeric FROM assessment_types WHERE school_id = ?', [$this->schoolId()]), null, 'id');

            foreach ($this->db()->all(
                "SELECT a.id, a.title, a.assessment_date, a.max_score, a.weight, a.type_id, a.is_summary, ta.subject_id, s.name AS subject,
                        r.score, r.result_status, r.verbal_evaluation
                   FROM assessments a JOIN teaching_assignments ta ON ta.id = a.teaching_assignment_id JOIN subjects s ON s.id = ta.subject_id
                   LEFT JOIN assessment_results r ON r.assessment_id = a.id AND r.student_id = ?
                  WHERE a.teaching_assignment_id IN ($in) AND a.term_id = ? AND a.status = 'published' AND a.deleted_at IS NULL AND s.is_graded = 1
                  ORDER BY s.name, a.assessment_date, a.id",
                [$student['id'], $term['id']]
            ) as $row) {
                $sid = (int) $row['subject_id'];
                $subjects[$sid] ??= ['name' => $row['subject'], 'assessments' => [], 'parts' => [], 'final' => null];
                if (!$row['is_summary']) {
                    $subjects[$sid]['assessments'][] = $row;
                }
                if ($row['result_status'] === 'graded' && $row['score'] !== null && (float) $row['max_score'] > 0 && (float) $row['weight'] > 0
                    && !empty($types[(int) $row['type_id']]['is_numeric'])) {
                    // One assessment at a time: Σ(score% × weight), Σ(weight), as in GradeStats.
                    $part = $subjects[$sid]['parts'][(int) $row['type_id']] ?? null;
                    $points = (float) $row['score'] / (float) $row['max_score'] * 100 * (float) $row['weight'];
                    if ($row['is_summary'] || !($part[2] ?? false)) {
                        $subjects[$sid]['parts'][(int) $row['type_id']] = $row['is_summary']
                            ? [$points, (float) $row['weight'], true]
                            : [($part[0] ?? 0) + $points, ($part[1] ?? 0) + (float) $row['weight']];
                    }
                }
            }
            // The term grade, exactly as on the grade sheet and the certificate (typed final grades included).
            foreach ((new GradeStats($this->db(), $this->schoolId(), (int) $this->auth()->year()['id'], ['1 = 1', []],
                ['student_id' => (int) $student['id'], 'term_id' => (int) $term['id'], 'class_id' => (int) $student['class_id']]))->rows() as $row) {
                $subjects[$row['subject_id']] ??= ['name' => (string) $this->db()->value('SELECT name FROM subjects WHERE id = ?', [$row['subject_id']]), 'assessments' => [], 'parts' => [], 'final' => null];
                $subjects[$row['subject_id']]['final'] = GradeStats::finalGrade($row['avg']);
            }
            foreach ($subjects as &$subject) {
                $components = [];
                foreach ($subject['parts'] as $typeId => $part) {
                    [$points, $weight] = $part;
                    if ($weights === [] || ($weights[$typeId] ?? 0) > 0) {
                        $components[] = ['name' => $types[$typeId]['name'] ?? '', 'weight' => $weights[$typeId] ?? null, 'avg' => $points / $weight];
                    }
                }
                $subject['components'] = $components;
            }
            unset($subject);
        }
        $finals = array_filter(array_column($subjects, 'final'), static fn ($v) => $v !== null);
        // Three ways to look at it: a list that opens per subject (default), one subject at a time, or every card.
        $mode = in_array($request->str('mode'), ['list', 'subject', 'cards'], true) ? $request->str('mode') : 'list';
        $subjectId = $request->int('subject_id');
        $subjectId = isset($subjects[$subjectId]) ? $subjectId : (int) array_key_first($subjects);
        return $this->render('portal/grades', [
            'student' => $student, 'term' => $term, 'subjects' => $subjects, 'mode' => $mode, 'subjectId' => $subjectId,
            'average' => $finals === [] ? null : round(array_sum($finals) / count($finals), 1),
        ]);
    }

    // ---- My attendance -------------------------------------------------------

    public function attendance(Request $request): Response
    {
        $student = $this->student();
        $term = $this->term($request);
        $records = [];
        $counts = ['absent' => 0, 'late' => 0, 'justified' => 0];
        if ($term !== null) {
            foreach ($this->db()->all(
                "SELECT ls.lesson_date, ls.period_number, s.name AS subject, ar.status
                   FROM attendance_records ar JOIN lesson_sessions ls ON ls.id = ar.lesson_session_id
                   JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id JOIN subjects s ON s.id = ta.subject_id
                  WHERE ar.student_id = ? AND ls.status = 'held' AND ls.lesson_date BETWEEN ? AND ?
                  ORDER BY ls.lesson_date DESC, ls.period_number DESC",
                [$student['id'], $term['start_date'], $term['end_date']]
            ) as $row) {
                if (isset($counts[$row['status']])) {
                    $counts[$row['status']]++;
                    $records[] = $row;
                }
            }
        }
        return $this->render('portal/attendance', ['student' => $student, 'term' => $term, 'records' => $records, 'counts' => $counts]);
    }

    // ---- My remarks ----------------------------------------------------------

    /** The remarks teachers gave in lessons this term, of praise and for improvement. */
    public function remarks(Request $request): Response
    {
        $student = $this->student();
        $term = $this->term($request);
        $kind = in_array($request->str('kind'), ['positive', 'negative'], true) ? $request->str('kind') : 'all';
        $rows = [];
        $counts = ['positive' => 0, 'negative' => 0];
        if ($term !== null) {
            $kindOf = [];
            foreach (AttendanceController::REMARKS as $group => $codes) {
                foreach ($codes as $code) {
                    $kindOf[$code] = $group;
                }
            }
            foreach ($this->db()->all(
                "SELECT ls.lesson_date, ls.period_number, s.name AS subject, CONCAT(t.first_name, ' ', t.last_name) AS teacher, ar.remark
                   FROM attendance_records ar JOIN lesson_sessions ls ON ls.id = ar.lesson_session_id
                   JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id JOIN subjects s ON s.id = ta.subject_id
                   JOIN teachers t ON t.id = ta.teacher_id
                  WHERE ar.student_id = ? AND ar.remark IS NOT NULL AND ls.status = 'held' AND ls.lesson_date BETWEEN ? AND ?
                  ORDER BY ls.lesson_date DESC, ls.period_number DESC",
                [$student['id'], $term['start_date'], $term['end_date']]
            ) as $row) {
                $group = $kindOf[$row['remark']] ?? null;
                if ($group === null) {
                    continue;   // a code no longer on the list
                }
                $counts[$group]++;
                if ($kind === 'all' || $kind === $group) {
                    $rows[] = $row + ['kind' => $group];
                }
            }
        }
        return $this->render('portal/remarks', ['student' => $student, 'term' => $term, 'rows' => $rows, 'counts' => $counts, 'kind' => $kind]);
    }

    // ---- My homework ---------------------------------------------------------

    private function homeworkRows(string $in, string $where, array $params, int $limit): array
    {
        return $this->db()->all(
            "SELECT ls.lesson_date, ls.period_number, ls.topic, ls.homework, s.name AS subject
               FROM lesson_sessions ls JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id JOIN subjects s ON s.id = ta.subject_id
              WHERE ls.teaching_assignment_id IN ($in) AND ls.status = 'held' AND ls.homework IS NOT NULL AND ls.homework <> '' AND $where
              ORDER BY ls.lesson_date DESC, ls.period_number DESC LIMIT $limit",
            $params
        );
    }

    public function homework(Request $request): Response
    {
        $student = $this->student();
        $assignments = Portal::assignments($this->db(), $student);
        $rows = $assignments === [] ? [] : $this->homeworkRows(implode(',', $assignments), 'ls.lesson_date >= ?', [date('Y-m-d', strtotime('-60 days'))], 100);
        return $this->render('portal/homework', ['student' => $student, 'rows' => $rows]);
    }

    // ---- My certificates -----------------------------------------------------

    private function publishedTerms(array $student): array
    {
        if (empty($student['class_id'])) {
            return [];
        }
        return $this->db()->all(
            'SELECT t.id, t.name, p.published_at FROM certificate_publications p JOIN academic_terms t ON t.id = p.term_id
              WHERE p.class_id = ? ORDER BY t.sort_order, t.start_date',
            [$student['class_id']]
        );
    }

    public function certificates(Request $request): Response
    {
        $student = $this->student();
        return $this->render('portal/certificates', ['student' => $student, 'published' => $this->publishedTerms($student)]);
    }

    public function certificate(Request $request, int $term): Response
    {
        $student = $this->student();
        if (!in_array($term, array_map(static fn ($t) => (int) $t['id'], $this->publishedTerms($student)), true)) {
            throw new HttpException(404);
        }
        $year = $this->auth()->year();
        $data = ReportCards::build($this->db(), $this->schoolId(), $year, $term, (int) $student['class_id'], [(int) $student['id']]);
        $lang = (string) ($this->auth()->school()['default_locale'] ?? 'he') === 'ar' ? 'ar' : 'he';
        $this->audit('view', 'certificate', (int) $student['id'], null, ['term_id' => $term]);
        App::lang()->setLocale($lang);
        return $this->view('certificates/print', $data + [
            'year' => $year, 'termId' => $term, 'lang' => $lang,
            'header' => CertificateController::headerFor($this->db(), $this->auth()),
        ], 'layouts/print');
    }
}
