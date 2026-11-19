<?php

declare(strict_types=1);

namespace App\Services;

use App\Controllers\TimetableController;
use App\Core\Database;

/**
 * One school day's attendance of a group of students, period by period, across all
 * teachers: what every lesson already recorded for each student. Shown beside each
 * name on the lesson page, so a teacher sees whether a student was in the earlier lessons.
 */
final class StudentDay
{
    private const DEFAULT_PERIODS = 8;

    /**
     * The day's lesson periods (breaks left out) with a short label for each box.
     * A first period without a number in its name (such as the morning homeroom
     * meeting) is labelled 0; the others carry their lesson number.
     *
     * @return array<int, array{label: string, name: string}> period number => label and full name
     */
    public static function periods(Database $db, int $schoolId, int $yearId): array
    {
        $teaching = array_filter(TimetableController::periods($db, $schoolId, $yearId), static fn ($p) => !$p['is_break']);
        if ($teaching === []) {
            $out = [];
            for ($n = 1; $n <= self::DEFAULT_PERIODS; $n++) {
                $out[$n] = ['label' => (string) $n, 'name' => __('attendance.period', ['n' => $n])];
            }
            return $out;
        }
        $first = reset($teaching);
        $index = preg_match('/\d/', (string) $first['name']) ? 1 : 0;
        $out = [];
        foreach ($teaching as $number => $period) {
            $label = preg_match('/(\d{1,2})/', (string) $period['name'], $m) ? $m[1] : (string) $index;
            $out[(int) $number] = ['label' => $label, 'name' => $period['name'] !== '' ? $period['name'] : __('attendance.period', ['n' => $number])];
            $index++;
        }
        return $out;
    }

    /**
     * @param int[] $studentIds
     * @return array<int, array<int, string>> student id => period number => present|absent|late|justified|cancelled
     *         (a period with no recorded lesson for the student is missing)
     */
    public static function grid(Database $db, int $schoolId, string $date, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }
        $ids = implode(',', array_map('intval', $studentIds));
        // The classes each student belongs to on that day.
        $classesOf = [];
        foreach ($db->all(
            "SELECT student_id, class_id FROM class_enrollments
              WHERE school_id = ? AND student_id IN ($ids) AND enrolled_on <= ? AND (left_on IS NULL OR left_on > ?)",
            [$schoolId, $date, $date]
        ) as $row) {
            $classesOf[(int) $row['student_id']][] = (int) $row['class_id'];
        }
        $sessions = [];  // class id => period => [session id => status]
        foreach ($db->all(
            'SELECT ls.id, ls.period_number, ls.status, ta.class_id FROM lesson_sessions ls
               JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
              WHERE ls.school_id = ? AND ls.lesson_date = ?',
            [$schoolId, $date]
        ) as $row) {
            $sessions[(int) $row['class_id']][(int) $row['period_number']][(int) $row['id']] = $row['status'];
        }
        $records = [];
        foreach ($db->all(
            "SELECT ar.lesson_session_id, ar.student_id, ar.status FROM attendance_records ar
               JOIN lesson_sessions ls ON ls.id = ar.lesson_session_id
              WHERE ls.school_id = ? AND ls.lesson_date = ? AND ar.student_id IN ($ids)",
            [$schoolId, $date]
        ) as $row) {
            $records[(int) $row['student_id']][(int) $row['lesson_session_id']] = $row['status'];
        }

        $grid = [];
        foreach ($studentIds as $studentId) {
            $studentId = (int) $studentId;
            foreach ($classesOf[$studentId] ?? [] as $classId) {
                foreach ($sessions[$classId] ?? [] as $period => $list) {
                    foreach ($list as $sessionId => $status) {
                        // A recorded exception wins; then a held lesson (present); then a cancelled one.
                        $value = $status === 'cancelled' ? 'cancelled' : ($records[$studentId][$sessionId] ?? 'present');
                        $current = $grid[$studentId][$period] ?? null;
                        if ($current === null || $current === 'cancelled' || ($current === 'present' && $value !== 'cancelled')) {
                            $grid[$studentId][$period] = $value;
                        }
                    }
                }
            }
        }
        return $grid;
    }
}
