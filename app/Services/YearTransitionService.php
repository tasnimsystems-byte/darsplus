<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Opens a new academic year from an existing one:
 * the year and its terms are created (terms shifted by one year), each class is
 * re-created in the level chosen for it, and its current students are enrolled
 * in the new class. Classes marked "graduate" produce no class; their students
 * are marked as graduated. Nothing in the source year is changed except that it
 * stops being current when asked — its classes, enrolments and (later) grades
 * remain as history. Teaching assignments are not copied: they are decided anew.
 */
final class YearTransitionService
{
    public const GRADUATE = 'graduate';
    public const SKIP = 'skip';

    public function __construct(private Database $db)
    {
    }

    /** Source classes with student counts and a suggested target level and name. */
    public function proposal(int $schoolId, array $year): array
    {
        $levels = $this->db->all('SELECT id, name, level_number FROM grade_levels WHERE school_id = ? ORDER BY level_number', [$schoolId]);
        $next = [];
        foreach ($levels as $i => $level) {
            $next[(int) $level['id']] = $levels[$i + 1] ?? null;
        }
        $classes = $this->db->all(
            'SELECT c.id, c.name, c.grade_level_id, gl.name AS level_name,
                    (SELECT COUNT(*) FROM class_enrollments e JOIN students s ON s.id = e.student_id
                      WHERE e.class_id = c.id AND e.left_on IS NULL AND s.deleted_at IS NULL AND s.status = \'active\') AS student_count
               FROM classes c JOIN grade_levels gl ON gl.id = c.grade_level_id
              WHERE c.school_id = ? AND c.academic_year_id = ?
              ORDER BY gl.level_number, c.name',
            [$schoolId, $year['id']]
        );
        foreach ($classes as &$class) {
            $target = $next[(int) $class['grade_level_id']];
            $class['target'] = $target === null ? self::GRADUATE : (int) $target['id'];
            // "ז1" → "ח1" when the class name starts with its level's name; otherwise keep the name.
            $class['new_name'] = $target !== null && str_starts_with($class['name'], $class['level_name'])
                ? $target['name'] . mb_substr($class['name'], mb_strlen($class['level_name']))
                : $class['name'];
        }
        return $classes;
    }

    public function suggestedDates(array $year): array
    {
        return [
            'start_date' => date('Y-m-d', strtotime($year['start_date'] . ' +1 year')),
            'end_date' => date('Y-m-d', strtotime($year['end_date'] . ' +1 year')),
        ];
    }

    /**
     * Validates the submitted class mapping against the real classes and levels of the school.
     *
     * @return array<int, array{target: int|string, name: string}>|null null when anything is inconsistent
     */
    public function readMapping(int $schoolId, array $year, mixed $input): ?array
    {
        if (!is_array($input)) {
            return null;
        }
        $levelIds = array_map('intval', $this->db->column('SELECT id FROM grade_levels WHERE school_id = ?', [$schoolId]));
        $classIds = array_map('intval', $this->db->column(
            'SELECT id FROM classes WHERE school_id = ? AND academic_year_id = ?',
            [$schoolId, $year['id']]
        ));
        $mapping = [];
        $names = [];
        foreach ($classIds as $classId) {
            $row = $input[$classId] ?? null;
            if (!is_array($row) || !is_scalar($row['target'] ?? null)) {
                return null;
            }
            $target = (string) $row['target'];
            if ($target === self::GRADUATE || $target === self::SKIP) {
                $mapping[$classId] = ['target' => $target, 'name' => ''];
                continue;
            }
            $name = is_scalar($row['name'] ?? null) ? trim((string) $row['name']) : '';
            if (!ctype_digit($target) || !in_array((int) $target, $levelIds, true)
                || $name === '' || mb_strlen($name) > 40 || isset($names[$name])) {
                return null;
            }
            $names[$name] = true;
            $mapping[$classId] = ['target' => (int) $target, 'name' => $name];
        }
        return $mapping;
    }

    /** @return array{year_id: int, classes: int, promoted: int, graduated: int} */
    public function execute(int $schoolId, array $source, array $newYear, array $mapping, bool $makeCurrent): array
    {
        return $this->db->transaction(function () use ($schoolId, $source, $newYear, $mapping, $makeCurrent): array {
            if ($makeCurrent) {
                $this->db->run('UPDATE academic_years SET is_current = 0 WHERE school_id = ?', [$schoolId]);
            }
            $yearId = $this->db->insert('academic_years', [
                'school_id' => $schoolId, 'name' => $newYear['name'], 'start_date' => $newYear['start_date'],
                'end_date' => $newYear['end_date'], 'is_current' => $makeCurrent ? 1 : 0,
            ]);

            foreach ($this->db->all('SELECT * FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order', [$source['id']]) as $term) {
                $this->db->insert('academic_terms', [
                    'school_id' => $schoolId, 'academic_year_id' => $yearId, 'name' => $term['name'],
                    'sort_order' => $term['sort_order'],
                    'start_date' => max($newYear['start_date'], date('Y-m-d', strtotime($term['start_date'] . ' +1 year'))),
                    'end_date' => min($newYear['end_date'], date('Y-m-d', strtotime($term['end_date'] . ' +1 year'))),
                ]);
            }

            $result = ['year_id' => $yearId, 'classes' => 0, 'promoted' => 0, 'graduated' => 0];
            foreach ($mapping as $classId => $plan) {
                if ($plan['target'] === self::SKIP) {
                    continue;
                }
                $studentIds = array_map('intval', $this->db->column(
                    "SELECT e.student_id FROM class_enrollments e JOIN students s ON s.id = e.student_id
                      WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL AND s.status = 'active'",
                    [$classId]
                ));
                // The old enrolment ends with the old year; the row stays as history.
                $this->db->run(
                    'UPDATE class_enrollments SET left_on = ? WHERE class_id = ? AND left_on IS NULL',
                    [$source['end_date'], $classId]
                );
                if ($plan['target'] === self::GRADUATE) {
                    foreach ($studentIds as $studentId) {
                        $this->db->update('students', ['status' => 'graduated'], ['id' => $studentId]);
                    }
                    $result['graduated'] += count($studentIds);
                    continue;
                }
                $newClassId = $this->db->insert('classes', [
                    'school_id' => $schoolId, 'academic_year_id' => $yearId,
                    'grade_level_id' => $plan['target'], 'name' => $plan['name'],
                ]);
                foreach ($studentIds as $studentId) {
                    $this->db->insert('class_enrollments', [
                        'school_id' => $schoolId, 'student_id' => $studentId, 'class_id' => $newClassId,
                        'enrolled_on' => $newYear['start_date'],
                    ]);
                }
                $result['classes']++;
                $result['promoted'] += count($studentIds);
            }
            return $result;
        });
    }
}
