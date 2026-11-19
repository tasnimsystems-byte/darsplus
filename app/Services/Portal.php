<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Auth;
use App\Core\Database;

/**
 * Who a portal user may see: a student – themselves; a parent – the children whose guardian rows are
 * linked to their account. Every portal page goes through here, and no page takes a student id from
 * the address: a parent switches child through the session, among their own children only.
 */
final class Portal
{
    private const CHILD_KEY = 'portal_child';

    /** @return array<int, array> student id => student with their class of the working year */
    public static function students(Database $db, Auth $auth): array
    {
        $userId = (int) $auth->id();
        $schoolId = (int) $auth->schoolId();
        $yearId = (int) ($auth->year()['id'] ?? 0);
        $rows = $db->all(
            "SELECT DISTINCT s.id, s.first_name, s.last_name, s.student_code, c.id AS class_id, c.name AS class_name, gl.level_number
               FROM students s
               LEFT JOIN class_enrollments e ON e.student_id = s.id AND e.left_on IS NULL
                    AND e.class_id IN (SELECT id FROM classes WHERE academic_year_id = ?)
               LEFT JOIN classes c ON c.id = e.class_id
               LEFT JOIN grade_levels gl ON gl.id = c.grade_level_id
              WHERE s.school_id = ? AND s.deleted_at IS NULL AND (
                    s.user_id = ? OR s.id IN (SELECT g.student_id FROM guardians g WHERE g.user_id = ? AND g.school_id = ?))
              ORDER BY s.first_name, s.id",
            [$yearId, $schoolId, $userId, $userId, $schoolId]
        );
        $students = [];
        foreach ($rows as $row) {
            $students[(int) $row['id']] ??= $row;
        }
        return $students;
    }

    /** The student shown now: the only one, or the child the parent chose. */
    public static function current(Database $db, Auth $auth): ?array
    {
        $students = self::students($db, $auth);
        $chosen = (int) App::session()->get(self::CHILD_KEY, 0);
        return $students[$chosen] ?? ($students === [] ? null : reset($students));
    }

    public static function choose(Database $db, Auth $auth, int $studentId): bool
    {
        if (!isset(self::students($db, $auth)[$studentId])) {
            return false;
        }
        App::session()->set(self::CHILD_KEY, $studentId);
        return true;
    }

    /** True for a parent account (linked through guardian rows rather than as the student). */
    public static function isParent(Database $db, Auth $auth): bool
    {
        return $db->value('SELECT 1 FROM guardians WHERE user_id = ? AND school_id = ? LIMIT 1', [$auth->id(), $auth->schoolId()]) !== null;
    }

    /**
     * The student's teaching assignments this year: those of their class, and of a study group only
     * when they are a member of it.
     *
     * @return int[]
     */
    public static function assignments(Database $db, array $student): array
    {
        if (empty($student['class_id'])) {
            return [];
        }
        return array_map('intval', $db->column(
            'SELECT ta.id FROM teaching_assignments ta
              WHERE ta.class_id = ? AND (ta.study_group_id IS NULL
                    OR ta.study_group_id IN (SELECT m.group_id FROM study_group_students m WHERE m.student_id = ?))',
            [$student['class_id'], $student['id']]
        ));
    }
}
