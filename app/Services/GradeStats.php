<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Achievement figures. Every number starts from the same building block:
 *
 *   the average of one student in one subject in one term
 *   = Σ(score ÷ max_score × 100 × weight) ÷ Σ(weight)
 *
 * over published, numeric assessments in which the student was graded. Absent,
 * exempt and pending results are left out — they are never counted as zero.
 * A group figure (class, grade level, subject, school) is the mean of its
 * students' averages, so a large class does not outweigh a small one per
 * assessment and one extra quiz does not shift a subject.
 *
 * Nothing is stored: figures are recomputed from the results on every request.
 */
final class GradeStats
{
    public const BANDS = [[0, 55], [55, 70], [70, 85], [85, 100.01]];

    /** @var array<int, array{student_id:int, subject_id:int, class_id:int, term_id:int, grade_level_id:int, avg:float}> */
    private array $rows;

    /**
     * @param array{0: string, 1: array} $scope   condition on teaching_assignments alias "ta"
     * @param array<string, int>         $filters term_id, class_id, subject_id, grade_level_id, student_id
     */
    public function __construct(Database $db, int $schoolId, int $yearId, array $scope, array $filters = [])
    {
        $where = '';
        $params = [$schoolId, $yearId, ...$scope[1]];
        foreach (['term_id' => 'a.term_id', 'class_id' => 'ta.class_id', 'subject_id' => 'ta.subject_id',
                     'grade_level_id' => 'c.grade_level_id', 'student_id' => 'r.student_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where .= " AND $column = ?";
                $params[] = (int) $filters[$key];
            }
        }
        $this->rows = array_map(static fn (array $row): array => [
            'student_id' => (int) $row['student_id'], 'subject_id' => (int) $row['subject_id'], 'class_id' => (int) $row['class_id'],
            'term_id' => (int) $row['term_id'], 'grade_level_id' => (int) $row['grade_level_id'], 'avg' => (float) $row['avg_pct'],
        ], $db->all(
            "SELECT r.student_id, ta.subject_id, ta.class_id, a.term_id, c.grade_level_id,
                    SUM(r.score / a.max_score * 100 * a.weight) / SUM(a.weight) AS avg_pct
               FROM assessment_results r
               JOIN assessments a ON a.id = r.assessment_id
               JOIN assessment_types ty ON ty.id = a.type_id
               JOIN teaching_assignments ta ON ta.id = a.teaching_assignment_id
               JOIN classes c ON c.id = ta.class_id
               JOIN students s ON s.id = r.student_id
              WHERE a.school_id = ? AND a.academic_year_id = ? AND a.status = 'published' AND a.deleted_at IS NULL
                AND ty.is_numeric = 1 AND a.weight > 0 AND a.max_score > 0
                AND r.result_status = 'graded' AND r.score IS NOT NULL AND s.deleted_at IS NULL
                AND {$scope[0]} $where
              GROUP BY r.student_id, ta.subject_id, ta.class_id, a.term_id, c.grade_level_id",
            $params
        ));
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /** @return array<int, array> */
    public function rows(): array
    {
        return $this->rows;
    }

    private static function mean(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }

    /**
     * One average per student, over that student's subjects (and terms) in the given rows.
     *
     * @return array<int, float> student id => average
     */
    public function studentAverages(?int $termId = null): array
    {
        $bySubject = [];
        foreach ($this->rows as $row) {
            if ($termId === null || $row['term_id'] === $termId) {
                $bySubject[$row['student_id']][] = $row['avg'];
            }
        }
        return array_map(static fn (array $values): float => array_sum($values) / count($values), $bySubject);
    }

    /** Mean of the students' averages, and how many students it rests on. */
    public function overall(?int $termId = null): array
    {
        $averages = $this->studentAverages($termId);
        return ['average' => self::mean($averages), 'students' => count($averages)];
    }

    /**
     * Mean of student averages per value of one dimension.
     *
     * @param 'subject_id'|'class_id'|'grade_level_id'|'term_id' $dimension
     * @return array<int, array{average: float, students: int}>
     */
    public function by(string $dimension, ?int $termId = null): array
    {
        $perStudent = [];
        foreach ($this->rows as $row) {
            if ($termId === null || $row['term_id'] === $termId) {
                $perStudent[$row[$dimension]][$row['student_id']][] = $row['avg'];
            }
        }
        $result = [];
        foreach ($perStudent as $key => $students) {
            $averages = array_map(static fn (array $values): float => array_sum($values) / count($values), $students);
            $result[$key] = ['average' => self::mean($averages), 'students' => count($averages)];
        }
        return $result;
    }

    /** @return array<int, array<int, float>> subject id => term id => average */
    public function subjectByTerm(): array
    {
        $cells = [];
        foreach ($this->rows as $row) {
            $cells[$row['subject_id']][$row['term_id']][] = $row['avg'];
        }
        return array_map(static fn (array $terms): array => array_map([self::class, 'mean'], $terms), $cells);
    }

    /** How many students fall in each band of BANDS. */
    public function distribution(?int $termId = null): array
    {
        $counts = array_fill(0, count(self::BANDS), 0);
        foreach ($this->studentAverages($termId) as $average) {
            foreach (self::BANDS as $i => [$from, $to]) {
                if ($average >= $from && $average < $to) {
                    $counts[$i]++;
                    break;
                }
            }
        }
        return $counts;
    }

    /**
     * Change of each student between two terms, for those who have an average in both.
     *
     * @return array<int, array{previous: float, current: float, change: float}>
     */
    public function trend(int $previousTermId, int $currentTermId): array
    {
        $previous = $this->studentAverages($previousTermId);
        $result = [];
        foreach ($this->studentAverages($currentTermId) as $studentId => $current) {
            if (isset($previous[$studentId])) {
                $result[$studentId] = [
                    'previous' => round($previous[$studentId], 1), 'current' => round($current, 1),
                    'change' => round($current - $previous[$studentId], 1),
                ];
            }
        }
        return $result;
    }

    /** @return array<int, array<int, float>> student id => subject id => average in the term */
    public function studentSubjects(int $termId): array
    {
        $result = [];
        foreach ($this->rows as $row) {
            if ($row['term_id'] === $termId) {
                $result[$row['student_id']][$row['subject_id']] = round($row['avg'], 1);
            }
        }
        return $result;
    }
}
