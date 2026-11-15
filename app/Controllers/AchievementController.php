<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AccessScope;
use App\Services\GradeStats;

/**
 * Analysis of achievements for the working year, filtered by term, grade level,
 * class and subject, and always limited to what the user's scope covers.
 */
final class AchievementController extends Controller
{
    public function index(Request $request): Response
    {
        $db = $this->db();
        $schoolId = $this->schoolId();
        $year = $this->auth()->year() ?? throw new RedirectException('/years', 'app.errors.no_year');
        $scope = new AccessScope($db, $this->auth());
        $pairs = static fn (array $rows): array => array_column($rows, 'label', 'id');

        [$classCondition, $classParams] = $scope->classCondition('c.id');
        $options = [
            'term_id' => $pairs($db->all('SELECT id, name AS label FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order', [$year['id']])),
            'grade_level_id' => $pairs($db->all('SELECT id, name AS label FROM grade_levels WHERE school_id = ? ORDER BY level_number', [$schoolId])),
            'class_id' => $pairs($db->all(
                "SELECT c.id, c.name AS label FROM classes c WHERE c.school_id = ? AND c.academic_year_id = ? AND $classCondition ORDER BY c.name",
                [$schoolId, $year['id'], ...$classParams]
            )),
            'subject_id' => $pairs($db->all('SELECT id, name AS label FROM subjects WHERE school_id = ? ORDER BY name', [$schoolId])),
        ];
        // A filter value is used only if it is one of the offered options.
        $filters = [];
        foreach ($options as $key => $choices) {
            $value = $request->int($key);
            if (isset($choices[$value])) {
                $filters[$key] = $value;
            }
        }
        $termId = $filters['term_id'] ?? null;
        $structural = array_diff_key($filters, ['term_id' => 1]);

        // All terms are loaded so that terms can be compared; the chosen term narrows the other figures.
        $stats = new GradeStats($db, $schoolId, (int) $year['id'], $scope->assignmentCondition('ta'), $structural);
        $termIds = array_keys($options['term_id']);
        $current = $termId ?? null;
        $previous = null;
        if ($current !== null && ($position = array_search($current, $termIds, true)) > 0) {
            $previous = $termIds[$position - 1];
        } elseif ($current === null && count($termIds) >= 2) {
            [$previous, $current] = array_slice($termIds, -2);
            $current = isset($stats->by('term_id')[$current]) ? $current : null;
        }

        $students = [];
        if (isset($filters['class_id'])) {
            $averages = $stats->studentAverages($termId);
            $trend = $current !== null && $previous !== null ? $stats->trend($previous, $current) : [];
            $subjects = $termId !== null ? $stats->studentSubjects($termId) : [];
            foreach ($db->all(
                'SELECT s.id, s.first_name, s.last_name FROM students s JOIN class_enrollments e ON e.student_id = s.id
                  WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL ORDER BY s.last_name, s.first_name',
                [$filters['class_id']]
            ) as $student) {
                $id = (int) $student['id'];
                $students[] = $student + [
                    'average' => isset($averages[$id]) ? round($averages[$id], 1) : null,
                    'trend' => $trend[$id] ?? null,
                    'subjects' => $subjects[$id] ?? [],
                ];
            }
        }

        $declining = [];
        if ($current !== null && $previous !== null) {
            $drops = array_filter($stats->trend($previous, $current), static fn (array $t): bool => $t['change'] <= -5);
            uasort($drops, static fn (array $a, array $b): int => $a['change'] <=> $b['change']);
            $drops = array_slice($drops, 0, 20, true);
            if ($drops !== []) {
                // Names are shown only for students whose class the user sees in full.
                [$fullCondition, $fullParams] = $scope->fullClassCondition('e.class_id');
                $in = implode(',', array_fill(0, count($drops), '?'));
                foreach ($db->all(
                    "SELECT s.id, s.first_name, s.last_name, c.name AS class_name
                       FROM students s JOIN class_enrollments e ON e.student_id = s.id AND e.left_on IS NULL JOIN classes c ON c.id = e.class_id
                      WHERE s.id IN ($in) AND c.academic_year_id = ? AND $fullCondition",
                    [...array_keys($drops), $year['id'], ...$fullParams]
                ) as $student) {
                    $declining[] = $student + $drops[(int) $student['id']];
                }
                usort($declining, static fn (array $a, array $b): int => $a['change'] <=> $b['change']);
            }
        }

        return $this->view('achievements/index', [
            'year' => $year, 'options' => $options, 'filters' => $filters, 'empty' => $stats->isEmpty(),
            'overall' => $stats->overall($termId),
            'bySubject' => $stats->by('subject_id', $termId), 'byClass' => $stats->by('class_id', $termId),
            'byLevel' => $stats->by('grade_level_id', $termId), 'byTerm' => $stats->by('term_id'),
            'subjectByTerm' => $stats->subjectByTerm(), 'distribution' => $stats->distribution($termId),
            'students' => $students, 'declining' => $declining,
            'comparison' => $current !== null && $previous !== null ? [$previous, $current] : null,
            'classNames' => $pairs($db->all('SELECT id, name AS label FROM classes WHERE school_id = ? AND academic_year_id = ?', [$schoolId, $year['id']])),
        ]);
    }
}
