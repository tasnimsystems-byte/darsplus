<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Which classes of the working year a user may see.
 *
 * Whole school: any of the user's roles (at this school or network-level) has
 * scope_mode = 'all', or a manual scope of type 'all' exists.
 *
 * Otherwise the union of:
 *   - classes the user is homeroom teacher of
 *   - classes the user teaches (teaching_assignments)
 *   - classes covered by the user's staff positions (a grade level, or a subject taught there)
 *   - manual scopes granted in the users screen (grade level / class / subject)
 *
 * Results are applied inside SQL (WHERE class_id IN …), never by filtering rows afterwards.
 */
final class AccessScope
{
    private bool $resolved = false;
    /** @var int[]|null null = every class of the school */
    private ?array $classIds = null;

    public function __construct(private Database $db, private Auth $auth)
    {
    }

    public function isUnrestricted(): bool
    {
        return $this->classIds() === null;
    }

    /** @return int[]|null class ids in the working year, or null for no restriction */
    public function classIds(): ?array
    {
        if ($this->resolved) {
            return $this->classIds;
        }
        $this->resolved = true;
        $userId = (int) $this->auth->id();
        $schoolId = (int) $this->auth->schoolId();
        $yearId = (int) ($this->auth->year()['id'] ?? 0);

        $all = $this->db->value(
            "SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = ? AND (ur.school_id = ? OR ur.school_id IS NULL) AND r.scope_mode = 'all' LIMIT 1",
            [$userId, $schoolId]
        ) ?? $this->db->value(
            "SELECT 1 FROM user_scopes WHERE user_id = ? AND school_id = ? AND scope_type = 'all'
                AND (academic_year_id IS NULL OR academic_year_id = ?) LIMIT 1",
            [$userId, $schoolId, $yearId]
        );
        if ($all !== null) {
            return $this->classIds = null;
        }

        $base = 'SELECT c.id FROM classes c WHERE c.school_id = ? AND c.academic_year_id = ? AND ';
        $ids = $this->db->column(
            // Homeroom teacher
            $base . 'c.homeroom_teacher_id IN (SELECT t.id FROM teachers t WHERE t.user_id = ? AND t.school_id = c.school_id AND t.deleted_at IS NULL)
            UNION
            ' . // Teaches the class
            $base . 'c.id IN (SELECT ta.class_id FROM teaching_assignments ta JOIN teachers t ON t.id = ta.teacher_id
                               WHERE t.user_id = ? AND t.deleted_at IS NULL)
            UNION
            ' . // Position over a grade level
            $base . 'c.grade_level_id IN (SELECT sp.grade_level_id FROM staff_positions sp JOIN teachers t ON t.id = sp.teacher_id
                               WHERE t.user_id = ? AND t.deleted_at IS NULL AND sp.academic_year_id = c.academic_year_id)
            UNION
            ' . // Position over a subject: classes where that subject is taught
            $base . 'c.id IN (SELECT ta.class_id FROM teaching_assignments ta
                               JOIN staff_positions sp ON sp.subject_id = ta.subject_id AND sp.academic_year_id = ta.academic_year_id
                               JOIN teachers t ON t.id = sp.teacher_id
                               WHERE t.user_id = ? AND t.deleted_at IS NULL)
            UNION
            ' . // Manual scopes
            $base . "(c.id IN (SELECT us.scope_id FROM user_scopes us WHERE us.user_id = ? AND us.school_id = c.school_id
                                 AND us.scope_type = 'class' AND (us.academic_year_id IS NULL OR us.academic_year_id = c.academic_year_id))
                   OR c.grade_level_id IN (SELECT us.scope_id FROM user_scopes us WHERE us.user_id = ? AND us.school_id = c.school_id
                                 AND us.scope_type = 'grade_level' AND (us.academic_year_id IS NULL OR us.academic_year_id = c.academic_year_id))
                   OR c.id IN (SELECT ta.class_id FROM teaching_assignments ta JOIN user_scopes us ON us.scope_id = ta.subject_id
                                WHERE us.user_id = ? AND us.school_id = c.school_id AND us.scope_type = 'subject'
                                  AND (us.academic_year_id IS NULL OR us.academic_year_id = c.academic_year_id)))",
            [
                $schoolId, $yearId, $userId,
                $schoolId, $yearId, $userId,
                $schoolId, $yearId, $userId,
                $schoolId, $yearId, $userId,
                $schoolId, $yearId, $userId, $userId, $userId,
            ]
        );
        return $this->classIds = array_map('intval', $ids);
    }

    public function canSeeClass(int $classId): bool
    {
        $ids = $this->classIds();
        return $ids === null || in_array($classId, $ids, true);
    }

    /**
     * SQL condition restricting a class-id column to the visible classes.
     *
     * @return array{0: string, 1: int[]} e.g. ['c.id IN (?,?)', [3, 7]]; ['1 = 1', []] when unrestricted
     */
    public function classCondition(string $column): array
    {
        $ids = $this->classIds();
        if ($ids === null) {
            return ['1 = 1', []];
        }
        if ($ids === []) {
            return ['1 = 0', []];
        }
        return ["$column IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /** A student is visible when currently enrolled in a visible class of the working year. */
    public function canSeeStudent(int $studentId): bool
    {
        if ($this->classIds() === null) {
            return true;
        }
        [$condition, $params] = $this->classCondition('e.class_id');
        return $this->db->value(
            "SELECT 1 FROM class_enrollments e WHERE e.student_id = ? AND e.left_on IS NULL AND $condition LIMIT 1",
            [$studentId, ...$params]
        ) !== null;
    }

    // ---- Subject-level visibility (grades, attendance, curriculum progress) -------

    private ?array $detail = null;

    /**
     * What a restricted user may see below class level:
     *   own      – teaching assignments that are theirs (they may also edit these)
     *   classes  – classes seen in full: homeroom, grade-level position, manual class/grade scope
     *   subjects – subjects seen across all classes: subject position, manual subject scope
     * A subject teacher therefore sees their own subject only, while a homeroom
     * teacher sees every subject of their class.
     *
     * @return array{own: int[], classes: int[], subjects: int[]}
     */
    private function detail(): array
    {
        if ($this->detail !== null) {
            return $this->detail;
        }
        $userId = (int) $this->auth->id();
        $schoolId = (int) $this->auth->schoolId();
        $yearId = (int) ($this->auth->year()['id'] ?? 0);
        $ints = fn (string $sql, array $params): array => array_map('intval', $this->db->column($sql, $params));
        $mine = 'SELECT t.id FROM teachers t WHERE t.user_id = ? AND t.school_id = ? AND t.deleted_at IS NULL';

        return $this->detail = [
            'own' => $ints(
                "SELECT ta.id FROM teaching_assignments ta WHERE ta.school_id = ? AND ta.academic_year_id = ? AND ta.teacher_id IN ($mine)",
                [$schoolId, $yearId, $userId, $schoolId]
            ),
            'classes' => $ints(
                "SELECT c.id FROM classes c WHERE c.school_id = ? AND c.academic_year_id = ? AND (
                    c.homeroom_teacher_id IN ($mine)
                    OR c.grade_level_id IN (SELECT sp.grade_level_id FROM staff_positions sp
                         WHERE sp.academic_year_id = c.academic_year_id AND sp.teacher_id IN ($mine))
                    OR c.id IN (SELECT us.scope_id FROM user_scopes us WHERE us.user_id = ? AND us.school_id = c.school_id
                         AND us.scope_type = 'class' AND (us.academic_year_id IS NULL OR us.academic_year_id = c.academic_year_id))
                    OR c.grade_level_id IN (SELECT us.scope_id FROM user_scopes us WHERE us.user_id = ? AND us.school_id = c.school_id
                         AND us.scope_type = 'grade_level' AND (us.academic_year_id IS NULL OR us.academic_year_id = c.academic_year_id)))",
                [$schoolId, $yearId, $userId, $schoolId, $userId, $schoolId, $userId, $userId]
            ),
            'subjects' => $ints(
                "SELECT sp.subject_id FROM staff_positions sp
                  WHERE sp.school_id = ? AND sp.academic_year_id = ? AND sp.subject_id IS NOT NULL AND sp.teacher_id IN ($mine)
                 UNION
                 SELECT us.scope_id FROM user_scopes us WHERE us.user_id = ? AND us.school_id = ? AND us.scope_type = 'subject'
                    AND (us.academic_year_id IS NULL OR us.academic_year_id = ?)",
                [$schoolId, $yearId, $userId, $schoolId, $userId, $schoolId, $yearId]
            ),
        ];
    }

    /**
     * Condition on a teaching_assignments alias limiting it to what the user may see.
     *
     * @return array{0: string, 1: int[]}
     */
    public function assignmentCondition(string $alias): array
    {
        if ($this->isUnrestricted()) {
            return ['1 = 1', []];
        }
        $parts = [];
        $params = [];
        foreach (['own' => 'id', 'classes' => 'class_id', 'subjects' => 'subject_id'] as $key => $column) {
            $ids = $this->detail()[$key];
            if ($ids !== []) {
                $parts[] = "$alias.$column IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($params, ...$ids);
            }
        }
        return $parts === [] ? ['1 = 0', []] : ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /** Recording grades, attendance and progress is for the assignment's own teacher, or someone who sees the whole school. */
    public function canEditAssignment(int $assignmentId): bool
    {
        return $this->isUnrestricted() || in_array($assignmentId, $this->detail()['own'], true);
    }

    /** Classes the user sees in full (all subjects). Null = every class. */
    public function fullClassIds(): ?array
    {
        return $this->isUnrestricted() ? null : $this->detail()['classes'];
    }

    /** @return array{0: string, 1: int[]} */
    public function fullClassCondition(string $column): array
    {
        $ids = $this->fullClassIds();
        if ($ids === null) {
            return ['1 = 1', []];
        }
        return $ids === [] ? ['1 = 0', []] : ["$column IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /** True when the student is currently in a class the user sees in full. */
    public function canSeeStudentFully(int $studentId): bool
    {
        [$condition, $params] = $this->fullClassCondition('e.class_id');
        $yearId = (int) ($this->auth->year()['id'] ?? 0);
        return $this->db->value(
            "SELECT 1 FROM class_enrollments e JOIN classes c ON c.id = e.class_id
              WHERE e.student_id = ? AND e.left_on IS NULL AND c.academic_year_id = ? AND $condition LIMIT 1",
            [$studentId, $yearId, ...$params]
        ) !== null;
    }
}
