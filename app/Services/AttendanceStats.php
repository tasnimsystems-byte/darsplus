<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Attendance figures from per-lesson records. Only exceptions are stored
 * (absent / justified / late), so presence = held lessons minus exceptions.
 * The expected attendance of a lesson is the number of students currently
 * enrolled in its class — an approximation for classes whose roster changed
 * during the period, and for subjects taught in split groups.
 */
final class AttendanceStats
{
    /** @return array{0: string, 1: array} extra WHERE on lesson_sessions "ls" */
    private static function range(?string $from, ?string $to): array
    {
        $sql = '';
        $params = [];
        if ($from !== null) {
            $sql .= ' AND ls.lesson_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND ls.lesson_date <= ?';
            $params[] = $to;
        }
        return [$sql, $params];
    }

    /**
     * Attendance rate in percent over everything the scope covers, or null when no lesson was recorded.
     *
     * @param array{0: string, 1: array} $scope condition on teaching_assignments "ta"
     */
    public static function rate(Database $db, int $schoolId, int $yearId, array $scope, ?string $from = null, ?string $to = null): ?float
    {
        [$range, $rangeParams] = self::range($from, $to);
        $base = "FROM lesson_sessions ls JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
                 WHERE ls.school_id = ? AND ta.academic_year_id = ? AND ls.status = 'held' AND {$scope[0]} $range";
        $params = [$schoolId, $yearId, ...$scope[1], ...$rangeParams];

        $expected = (int) $db->value(
            "SELECT COALESCE(SUM(x.sessions * x.students), 0) FROM (
                SELECT COUNT(*) AS sessions,
                       (SELECT COUNT(*) FROM class_enrollments e JOIN students s ON s.id = e.student_id
                         WHERE e.class_id = ta.class_id AND e.left_on IS NULL AND s.deleted_at IS NULL) AS students
                $base GROUP BY ta.class_id) x",
            $params
        );
        if ($expected === 0) {
            return null;
        }
        $missed = (int) $db->value(
            "SELECT COUNT(*) FROM attendance_records ar JOIN lesson_sessions ls ON ls.id = ar.lesson_session_id
               JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
              WHERE ar.status IN ('absent', 'justified') AND ls.school_id = ? AND ta.academic_year_id = ? AND ls.status = 'held' AND {$scope[0]} $range",
            $params
        );
        return round(max(0, $expected - $missed) / $expected * 100, 1);
    }

    /**
     * Per student of one class: lessons held, absences, justified absences, lates and attendance rate.
     *
     * @param array{0: string, 1: array} $scope
     */
    public static function byStudent(Database $db, int $classId, array $scope, ?string $from = null, ?string $to = null): array
    {
        [$range, $rangeParams] = self::range($from, $to);
        $where = "ta.class_id = ? AND ls.status = 'held' AND {$scope[0]} $range";
        $params = [$classId, ...$scope[1], ...$rangeParams];

        $sessions = (int) $db->value(
            "SELECT COUNT(*) FROM lesson_sessions ls JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id WHERE $where",
            $params
        );
        $counts = [];
        foreach ($db->all(
            "SELECT ar.student_id, ar.status, COUNT(*) AS n FROM attendance_records ar
               JOIN lesson_sessions ls ON ls.id = ar.lesson_session_id
               JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
              WHERE $where GROUP BY ar.student_id, ar.status",
            $params
        ) as $row) {
            $counts[(int) $row['student_id']][$row['status']] = (int) $row['n'];
        }
        $rows = [];
        foreach ($db->all(
            'SELECT s.id, s.first_name, s.last_name FROM students s JOIN class_enrollments e ON e.student_id = s.id
              WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL ORDER BY s.last_name, s.first_name',
            [$classId]
        ) as $student) {
            $c = $counts[(int) $student['id']] ?? [];
            $missed = ($c['absent'] ?? 0) + ($c['justified'] ?? 0);
            $rows[] = $student + [
                'sessions' => $sessions, 'absent' => $c['absent'] ?? 0, 'justified' => $c['justified'] ?? 0, 'late' => $c['late'] ?? 0,
                'rate' => $sessions === 0 ? null : round(max(0, $sessions - $missed) / $sessions * 100, 1),
            ];
        }
        return $rows;
    }

    /** One student's exceptions inside the scope. */
    public static function forStudent(Database $db, int $studentId, int $yearId, array $scope): array
    {
        $counts = ['absent' => 0, 'justified' => 0, 'late' => 0];
        foreach ($db->all(
            "SELECT ar.status, COUNT(*) AS n FROM attendance_records ar
               JOIN lesson_sessions ls ON ls.id = ar.lesson_session_id
               JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
              WHERE ar.student_id = ? AND ta.academic_year_id = ? AND ls.status = 'held' AND {$scope[0]}
              GROUP BY ar.status",
            [$studentId, $yearId, ...$scope[1]]
        ) as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /** @return array<int, int> student id => unjustified absences between the dates (whole school; used by identification) */
    public static function unjustifiedAbsences(Database $db, int $schoolId, string $from, string $to): array
    {
        $result = [];
        foreach ($db->all(
            "SELECT ar.student_id, COUNT(*) AS n FROM attendance_records ar JOIN lesson_sessions ls ON ls.id = ar.lesson_session_id
              WHERE ar.school_id = ? AND ar.status = 'absent' AND ls.status = 'held' AND ls.lesson_date BETWEEN ? AND ?
              GROUP BY ar.student_id",
            [$schoolId, $from, $to]
        ) as $row) {
            $result[(int) $row['student_id']] = (int) $row['n'];
        }
        return $result;
    }
}
