<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Report cards (term certificates) of one class.
 *
 * For every term up to the chosen one, a student's grade in a subject is the term grade of the
 * grade sheet (GradeStats: components and weights, rounded up). The annual average is the mean of
 * those term grades; the general average of a column is the mean of the subjects in it.
 * Absences and late arrivals are counted from the lesson attendance of the chosen term.
 */
final class ReportCards
{
    /** Lower bounds of the verbal grades, from the top. */
    public const SCALE = [95 => 'excellent', 85 => 'very_good', 75 => 'good', 65 => 'almost_good', 55 => 'sufficient', 45 => 'barely', 0 => 'insufficient'];

    /** Absences and late arrivals of students in a term, counted from the lesson attendance. */
    public static function attendance(Database $db, int $schoolId, array $term, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }
        $counts = [];
        foreach ($db->all(
            "SELECT ar.student_id, ar.status, COUNT(*) AS n FROM attendance_records ar JOIN lesson_sessions ls ON ls.id = ar.lesson_session_id
              WHERE ls.school_id = ? AND ls.status = 'held' AND ls.lesson_date BETWEEN ? AND ?
                AND ar.student_id IN (" . implode(',', array_map('intval', $studentIds)) . ") AND ar.status IN ('absent', 'late')
              GROUP BY ar.student_id, ar.status",
            [$schoolId, $term['start_date'], $term['end_date']]
        ) as $row) {
            $counts[(int) $row['student_id']][$row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /**
     * Writes part of a student's homeroom record for a term (remark, absences, lates); null clears a part.
     * The row disappears when nothing is left in it. Returns whether anything changed.
     *
     * @param array{remark?: ?string, absences?: ?int, lates?: ?int} $changes
     */
    public static function saveRecord(Database $db, int $schoolId, int $studentId, int $termId, array $changes, ?int $userId): bool
    {
        $current = $db->one('SELECT remark, absences, lates FROM report_card_remarks WHERE student_id = ? AND term_id = ?', [$studentId, $termId]);
        $before = ['remark' => $current['remark'] ?? null, 'absences' => isset($current['absences']) ? (int) $current['absences'] : null,
            'lates' => isset($current['lates']) ? (int) $current['lates'] : null];
        $after = array_replace($before, array_intersect_key($changes, $before));
        if ($after === $before) {
            return false;
        }
        if ($after['remark'] === null && $after['absences'] === null && $after['lates'] === null) {
            $db->run('DELETE FROM report_card_remarks WHERE student_id = ? AND term_id = ?', [$studentId, $termId]);
            return true;
        }
        $db->run(
            'INSERT INTO report_card_remarks (school_id, student_id, term_id, remark, absences, lates, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE remark = VALUES(remark), absences = VALUES(absences), lates = VALUES(lates), updated_by = VALUES(updated_by)',
            [$schoolId, $studentId, $termId, $after['remark'], $after['absences'], $after['lates'], $userId]
        );
        return true;
    }

    public static function verbal(?float $grade): ?string
    {
        if ($grade === null) {
            return null;
        }
        foreach (self::SCALE as $from => $key) {
            if ($grade >= $from) {
                return $key;
            }
        }
        return 'insufficient';
    }

    private static function mean(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn ($v) => $v !== null));
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }

    /**
     * @param int[] $studentIds students of the class to print (in this order)
     * @return array{terms: array, cards: array}
     */
    public static function build(Database $db, int $schoolId, array $year, int $termId, int $classId, array $studentIds): array
    {
        $yearId = (int) $year['id'];
        $allTerms = $db->all('SELECT id, name, start_date, end_date FROM academic_terms WHERE school_id = ? AND academic_year_id = ? ORDER BY sort_order, start_date', [$schoolId, $yearId]);
        // The chosen term and every term before it.
        $terms = [];
        foreach ($allTerms as $term) {
            $terms[(int) $term['id']] = $term;
            if ((int) $term['id'] === $termId) {
                break;
            }
        }
        $current = $terms[$termId] ?? null;
        if ($current === null || $studentIds === []) {
            return ['terms' => $terms, 'cards' => []];
        }

        // Grades: student → subject → term.
        $grades = [];
        $subjectIds = [];
        foreach ((new GradeStats($db, $schoolId, $yearId, ['1 = 1', []], ['class_id' => $classId]))->rows() as $row) {
            if (isset($terms[$row['term_id']]) && in_array($row['student_id'], $studentIds, true)) {
                $grades[$row['student_id']][$row['subject_id']][$row['term_id']] = GradeStats::finalGrade($row['avg']);
                $subjectIds[$row['subject_id']] = true;
            }
        }
        $subjects = $subjectIds === [] ? [] : array_column($db->all(
            'SELECT id, name FROM subjects WHERE school_id = ? AND id IN (' . implode(',', array_map('intval', array_keys($subjectIds))) . ') ORDER BY id',
            [$schoolId]
        ), 'name', 'id');

        // Attendance in the chosen term.
        $in = implode(',', array_map('intval', $studentIds));
        $attendance = self::attendance($db, $schoolId, $current, $studentIds);

        $records = array_column($db->all(
            "SELECT student_id, remark, absences, lates FROM report_card_remarks WHERE term_id = ? AND student_id IN ($in)",
            [$termId]
        ), null, 'student_id');
        $class = $db->one(
            "SELECT c.name, CONCAT(t.first_name, ' ', t.last_name) AS homeroom FROM classes c LEFT JOIN teachers t ON t.id = c.homeroom_teacher_id WHERE c.id = ?",
            [$classId]
        );
        $students = array_column($db->all(
            "SELECT id, first_name, last_name, student_code FROM students WHERE id IN ($in)"
        ), null, 'id');

        $cards = [];
        foreach ($studentIds as $studentId) {
            if (!isset($students[$studentId])) {
                continue;
            }
            $rows = [];
            foreach ($subjects as $subjectId => $name) {
                $byTerm = $grades[$studentId][$subjectId] ?? [];
                if ($byTerm === []) {
                    continue;   // the student has no grade in this subject this year
                }
                $cells = [];
                foreach (array_keys($terms) as $id) {
                    $cells[$id] = $byTerm[$id] ?? null;
                }
                $rows[] = ['subject' => $name, 'terms' => $cells, 'annual' => self::mean($cells)];
            }
            $totals = [];
            foreach (array_keys($terms) as $id) {
                $totals[$id] = self::mean(array_map(static fn ($r) => $r['terms'][$id], $rows));
            }
            $cards[] = [
                'student' => $students[$studentId], 'class' => $class['name'] ?? '', 'homeroom' => $class['homeroom'] ?? '',
                'rows' => $rows, 'totals' => $totals, 'annual' => self::mean(array_column($rows, 'annual')),
                // Counts set by the homeroom teacher win over the ones counted from the lessons.
                'absences' => isset($records[$studentId]['absences']) ? (int) $records[$studentId]['absences'] : ($attendance[$studentId]['absent'] ?? 0),
                'lates' => isset($records[$studentId]['lates']) ? (int) $records[$studentId]['lates'] : ($attendance[$studentId]['late'] ?? 0),
                'remark' => (string) ($records[$studentId]['remark'] ?? ''),
            ];
        }
        return ['terms' => $terms, 'cards' => $cards];
    }
}
