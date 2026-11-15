<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Applies the school's criteria to one term and records, for each student who
 * meets one, an indication for review together with the values that triggered
 * it. That is all it does: it opens no intervention, changes no status and
 * sends nothing. What happens next is decided by a member of staff.
 *
 * Re-running is safe: an indication exists once per student, criterion and term;
 * one that was already reviewed is left untouched, one still "new" gets fresh
 * evidence, and one that no longer applies and was never reviewed is removed.
 */
final class RiskIndicatorService
{
    public const TYPES = ['low_average', 'decline', 'multi_subject', 'absences'];

    public function __construct(private Database $db)
    {
    }

    /** @return array{created: int, updated: int, removed: int} */
    public function run(int $schoolId, array $year, array $term): array
    {
        $everything = ['1 = 1', []];
        $stats = new GradeStats($this->db, $schoolId, (int) $year['id'], $everything);
        $termId = (int) $term['id'];
        $previousTermId = $this->db->value(
            'SELECT id FROM academic_terms WHERE academic_year_id = ? AND sort_order < ? ORDER BY sort_order DESC LIMIT 1',
            [$year['id'], $term['sort_order']]
        );
        $subjectNames = array_column($this->db->all('SELECT id, name FROM subjects WHERE school_id = ?', [$schoolId]), 'name', 'id');
        $active = array_flip(array_map('intval', $this->db->column(
            "SELECT s.id FROM students s WHERE s.school_id = ? AND s.deleted_at IS NULL AND s.status = 'active'",
            [$schoolId]
        )));

        $result = ['created' => 0, 'updated' => 0, 'removed' => 0];
        $this->db->transaction(function () use ($schoolId, $term, $termId, $previousTermId, $stats, $subjectNames, $active, &$result): void {
            foreach ($this->db->all('SELECT * FROM risk_criteria WHERE school_id = ? AND is_active = 1', [$schoolId]) as $criterion) {
                $threshold = (float) $criterion['threshold'];
                $minCount = (int) $criterion['min_count'];
                $found = [];

                switch ($criterion['criterion_type']) {
                    case 'low_average':
                        foreach ($stats->studentAverages($termId) as $studentId => $average) {
                            if ($average < $threshold) {
                                $found[$studentId] = ['average' => round($average, 1), 'threshold' => $threshold];
                            }
                        }
                        break;
                    case 'decline':
                        if ($previousTermId !== null) {
                            foreach ($stats->trend((int) $previousTermId, $termId) as $studentId => $trend) {
                                if (-$trend['change'] >= $threshold) {
                                    $found[$studentId] = $trend + ['threshold' => $threshold];
                                }
                            }
                        }
                        break;
                    case 'multi_subject':
                        foreach ($stats->studentSubjects($termId) as $studentId => $subjects) {
                            $low = array_filter($subjects, static fn (float $average): bool => $average < $threshold);
                            if (count($low) >= max(1, $minCount)) {
                                $list = [];
                                foreach ($low as $subjectId => $average) {
                                    $list[] = ['subject' => $subjectNames[$subjectId] ?? '?', 'average' => $average];
                                }
                                $found[$studentId] = ['subjects' => $list, 'threshold' => $threshold, 'min_count' => $minCount];
                            }
                        }
                        break;
                    case 'absences':
                        foreach (AttendanceStats::unjustifiedAbsences($this->db, $schoolId, $term['start_date'], $term['end_date']) as $studentId => $count) {
                            if ($count >= max(1, $minCount)) {
                                $found[$studentId] = ['absences' => $count, 'min_count' => $minCount];
                            }
                        }
                        break;
                }
                $found = array_intersect_key($found, $active);

                $existing = [];
                foreach ($this->db->all(
                    'SELECT id, student_id, review_status FROM risk_indications WHERE criterion_id = ? AND term_id = ?',
                    [$criterion['id'], $termId]
                ) as $row) {
                    $existing[(int) $row['student_id']] = $row;
                }
                foreach ($found as $studentId => $evidence) {
                    $json = json_encode($evidence, JSON_UNESCAPED_UNICODE);
                    if (!isset($existing[$studentId])) {
                        $this->db->insert('risk_indications', [
                            'school_id' => $schoolId, 'student_id' => $studentId, 'criterion_id' => (int) $criterion['id'],
                            'term_id' => $termId, 'evidence' => $json,
                        ]);
                        $result['created']++;
                    } elseif ($existing[$studentId]['review_status'] === 'new') {
                        $this->db->update('risk_indications', ['evidence' => $json], ['id' => $existing[$studentId]['id']]);
                        $result['updated']++;
                    }
                }
                foreach (array_diff_key($existing, $found) as $row) {
                    if ($row['review_status'] === 'new') {
                        $this->db->run('DELETE FROM risk_indications WHERE id = ?', [$row['id']]);
                        $result['removed']++;
                    }
                }
            }
        });
        return $result;
    }
}
