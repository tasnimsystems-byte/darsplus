<?php

declare(strict_types=1);

namespace App\Services;

use App\Controllers\TimetableController;
use App\Core\Database;

/**
 * One teacher's timetable for one calendar week (Sunday to Saturday), with the real
 * date of every lesson and whether it was already recorded. Shown on the teacher's
 * home page; a lesson that can be recorded opens its lesson page with one click.
 */
final class TeacherWeek
{
    /**
     * @return array|null null when the teacher has no timetable this year
     */
    public static function build(Database $db, int $schoolId, array $year, int $teacherId, string $anyDay, bool $canRecord): ?array
    {
        $yearId = (int) $year['id'];
        $slots = $db->all(
            "SELECT ts.weekday, ts.period_number, ts.teaching_assignment_id, s.name AS subject_name, c.name AS class_name,
                    ta.group_name, ta.study_group_id, sg.name AS study_group_name, r.name AS room_name
               FROM timetable_slots ts JOIN teaching_assignments ta ON ta.id = ts.teaching_assignment_id
               LEFT JOIN study_groups sg ON sg.id = ta.study_group_id
               JOIN subjects s ON s.id = ta.subject_id JOIN classes c ON c.id = ta.class_id LEFT JOIN rooms r ON r.id = ts.room_id
              WHERE ts.school_id = ? AND ts.academic_year_id = ? AND ta.teacher_id = ?
              ORDER BY c.name",
            [$schoolId, $yearId, $teacherId]
        );
        if ($slots === []) {
            return null;
        }

        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $anyDay) && strtotime($anyDay) !== false ? $anyDay : date('Y-m-d');
        $start = date('Y-m-d', strtotime($day . ' -' . date('w', strtotime($day)) . ' days'));
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $dates[$i] = date('Y-m-d', strtotime("$start +$i days"));
        }
        $today = date('Y-m-d');

        // Lessons of this week that were already opened.
        $sessions = [];
        foreach ($db->all(
            "SELECT ls.id, ls.teaching_assignment_id, ls.lesson_date, ls.period_number, ls.status, ls.topic,
                    (SELECT COUNT(*) FROM attendance_records ar WHERE ar.lesson_session_id = ls.id AND ar.status IN ('absent', 'justified')) AS missing
               FROM lesson_sessions ls JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
              WHERE ls.school_id = ? AND ta.teacher_id = ? AND ls.lesson_date BETWEEN ? AND ?",
            [$schoolId, $teacherId, $dates[0], $dates[6]]
        ) as $row) {
            $sessions["{$row['teaching_assignment_id']}|{$row['lesson_date']}|{$row['period_number']}"] = $row;
        }

        $grid = [];
        foreach ($slots as $slot) {
            $date = $dates[(int) $slot['weekday']];
            $slot['date'] = $date;
            $slot['session'] = $sessions["{$slot['teaching_assignment_id']}|$date|{$slot['period_number']}"] ?? null;
            $slot['openable'] = $canRecord && !$year['is_locked'] && $date <= $today
                && $date >= $year['start_date'] && $date <= $year['end_date'];
            // The classes of one study group taught together are shown (and opened) as one lesson.
            $key = $slot['study_group_id'] !== null ? 'g' . $slot['study_group_id'] : 'a' . $slot['teaching_assignment_id'];
            $cell = &$grid[(int) $slot['period_number']][(int) $slot['weekday']];
            if (isset($cell[$key])) {
                $cell[$key]['classes'][] = $slot['class_name'];
                $cell[$key]['session'] ??= $slot['session'];
            } else {
                $slot['classes'] = [$slot['class_name']];
                $cell[$key] = $slot;
            }
            unset($cell);
        }

        $periods = TimetableController::periods($db, $schoolId, $yearId);
        foreach (array_keys($grid) as $number) {
            $periods[$number] ??= ['period_number' => $number, 'name' => '', 'start_time' => null, 'end_time' => null, 'is_break' => 0];
        }
        ksort($periods);

        // The lesson happening now (or the next one today), by the imported period times.
        $now = date('H:i');
        $current = null;
        if (in_array($today, $dates, true)) {
            foreach ($periods as $number => $period) {
                if (!$period['is_break'] && $period['end_time'] !== null && substr($period['end_time'], 0, 5) >= $now
                    && isset($grid[$number][(int) date('w')])) {
                    $current = $number;
                    break;
                }
            }
        }

        return [
            'dates' => $dates, 'days' => TimetableController::days($db, $schoolId, $yearId), 'periods' => $periods,
            'grid' => $grid, 'today' => $today, 'current' => $current,
            'prev' => date('Y-m-d', strtotime("$start -7 days")), 'next' => date('Y-m-d', strtotime("$start +7 days")),
            'lessons' => array_sum(array_map(static fn ($days) => array_sum(array_map('count', $days)), $grid)), 'recorded' => count(array_filter($sessions, static fn ($s) => $s['status'] === 'held')),
        ];
    }
}
