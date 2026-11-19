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
 * over published, numeric assessments in which the student was graded. When the school
 * set components of the term grade (school settings: a weight in percent per assessment
 * type), it is instead Σ(component average × component weight) ÷ Σ(weights of the
 * components the student has), each component average computed as above; types
 * without a weight then do not count. A final grade typed in the grade sheet (grade_overrides)
 * replaces the computed grade of that student, subject and term. Absent,
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
        $componentWeights = self::componentWeights($db, $schoolId);
        $combined = [];
        foreach ($db->all(
            "SELECT r.student_id, ta.subject_id, ta.class_id, a.term_id, c.grade_level_id, a.type_id, a.is_summary,
                    SUM(r.score / a.max_score * 100 * a.weight) AS points, SUM(a.weight) AS weight
               FROM assessment_results r
               JOIN assessments a ON a.id = r.assessment_id
               JOIN assessment_types ty ON ty.id = a.type_id
               JOIN teaching_assignments ta ON ta.id = a.teaching_assignment_id
               JOIN subjects sj ON sj.id = ta.subject_id AND sj.is_graded = 1
               JOIN classes c ON c.id = ta.class_id
               JOIN students s ON s.id = r.student_id
              WHERE a.school_id = ? AND a.academic_year_id = ? AND a.status = 'published' AND a.deleted_at IS NULL
                AND ty.is_numeric = 1 AND a.weight > 0 AND a.max_score > 0
                AND r.result_status = 'graded' AND r.score IS NOT NULL AND s.deleted_at IS NULL
                AND {$scope[0]} $where
              GROUP BY r.student_id, ta.subject_id, ta.class_id, a.term_id, c.grade_level_id, a.type_id, a.is_summary",
            $params
        ) as $row) {
            $key = "{$row['student_id']}|{$row['subject_id']}|{$row['class_id']}|{$row['term_id']}";
            $combined[$key] ??= [
                'student_id' => (int) $row['student_id'], 'subject_id' => (int) $row['subject_id'], 'class_id' => (int) $row['class_id'],
                'term_id' => (int) $row['term_id'], 'grade_level_id' => (int) $row['grade_level_id'], 'parts' => [],
            ];
            self::addPart($combined[$key]['parts'], (int) $row['type_id'], (bool) $row['is_summary'], (float) $row['points'], (float) $row['weight']);
        }
        // Final grades typed in the grade sheet, located in the student's class of this year.
        $overrides = [];
        $oWhere = '';
        $oParams = [$yearId, $yearId, $schoolId, ...$scope[1]];
        foreach (['term_id' => 'o.term_id', 'class_id' => 'c.id', 'subject_id' => 'o.subject_id',
                     'grade_level_id' => 'c.grade_level_id', 'student_id' => 'o.student_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $oWhere .= " AND $column = ?";
                $oParams[] = (int) $filters[$key];
            }
        }
        foreach ($db->all(
            "SELECT DISTINCT o.student_id, o.subject_id, c.id AS class_id, o.term_id, c.grade_level_id, o.grade
               FROM grade_overrides o
               JOIN subjects sj ON sj.id = o.subject_id AND sj.is_graded = 1
               JOIN students s ON s.id = o.student_id AND s.deleted_at IS NULL
               JOIN class_enrollments e ON e.student_id = o.student_id
               JOIN classes c ON c.id = e.class_id AND c.academic_year_id = ?
               JOIN academic_terms tm ON tm.id = o.term_id AND tm.academic_year_id = ?
               JOIN teaching_assignments ta ON ta.class_id = c.id AND ta.subject_id = o.subject_id
              WHERE o.school_id = ? AND {$scope[0]} $oWhere",
            $oParams
        ) as $row) {
            $key = "{$row['student_id']}|{$row['subject_id']}|{$row['class_id']}|{$row['term_id']}";
            $overrides[$key] = (float) $row['grade'];
            $combined[$key] ??= [
                'student_id' => (int) $row['student_id'], 'subject_id' => (int) $row['subject_id'], 'class_id' => (int) $row['class_id'],
                'term_id' => (int) $row['term_id'], 'grade_level_id' => (int) $row['grade_level_id'], 'parts' => [],
            ];
        }

        $this->rows = [];
        foreach ($combined as $key => $row) {
            $avg = $overrides[$key] ?? self::combine($row['parts'], $componentWeights);
            if ($avg !== null) {
                unset($row['parts']);
                $this->rows[] = $row + ['avg' => $avg];
            }
        }
    }

    /**
     * One student's grade in one subject and term from their results by assessment type.
     *
     * @param array<int, array{0: float, 1: float}> $parts   type id => [Σ(score% × assessment weight), Σ(assessment weight)]
     * @param array<int, float>                     $weights component weights (componentWeights()); empty = no components
     * @return float|null null when nothing counts
     */
    public static function combine(array $parts, array $weights): ?float
    {
        $points = 0.0;
        $total = 0.0;
        foreach ($parts as $typeId => $part) {
            [$p, $w] = $part;
            if ($w <= 0) {
                continue;
            }
            if ($weights === []) {
                // No components: the plain weighted mean of all assessments.
                $points += $p;
                $total += $w;
            } elseif (($weights[$typeId] ?? 0.0) > 0) {
                $points += $p / $w * $weights[$typeId];
                $total += $weights[$typeId];
            }
        }
        return $total > 0 ? $points / $total : null;
    }

    /**
     * Adds one student's results of one type to their parts. A component grade typed in the grade
     * sheet (summary assessment) replaces the average of that type's other assessments.
     *
     * @param array<int, array{0: float, 1: float, 2?: bool}> $parts
     */
    public static function addPart(array &$parts, int $typeId, bool $summary, float $points, float $weight): void
    {
        if ($summary) {
            $parts[$typeId] = [$points, $weight, true];
        } elseif (!($parts[$typeId][2] ?? false)) {
            $parts[$typeId] = [$points, $weight];
        }
    }

    /** The final term grade as reported: always rounded up to a whole number. */
    public static function finalGrade(?float $average): ?int
    {
        return $average === null ? null : (int) ceil(round($average, 6));
    }

    /** @return array<int, float> assessment type id => weight in percent; empty when the school set no components */
    public static function componentWeights(Database $db, int $schoolId): array
    {
        $weights = [];
        foreach ($db->all('SELECT id, term_weight FROM assessment_types WHERE school_id = ? AND term_weight IS NOT NULL', [$schoolId]) as $row) {
            $weights[(int) $row['id']] = (float) $row['term_weight'];
        }
        return array_sum($weights) > 0 ? $weights : [];
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
