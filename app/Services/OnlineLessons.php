<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Who may enter a lesson's online meeting, and as what:
 *  - host:    whoever records the lesson (its own teacher, or someone who sees the whole school) – moderates;
 *  - staff:   another staff member who may view the lesson (a coordinator) – joins without moderating;
 *  - student: a student of the lesson – the class, or for a study group only its members.
 * Parents do not enter: the meeting is the student's lesson. A meeting is live from the moment the
 * teacher opens it until they end it, and at most until the end of that day.
 */
final class OnlineLessons
{
    public const ROLES = ['host', 'staff', 'student'];

    /** Condition on an online_meetings alias: open and opened today. */
    public static function liveCondition(string $alias): string
    {
        return "$alias.ended_at IS NULL AND $alias.started_at >= '" . date('Y-m-d') . " 00:00:00'";
    }

    /** The meeting with its lesson, or null. */
    public static function meeting(Database $db, int $schoolId, int $meetingId): ?array
    {
        return $db->one(
            "SELECT om.*, ls.lesson_date, ls.period_number, ls.teaching_assignment_id, ls.status AS lesson_status,
                    ta.class_id, ta.subject_id, ta.teacher_id, ta.academic_year_id, ta.study_group_id, ta.group_name,
                    c.name AS class_name, s.name AS subject_name, sg.name AS study_group_name,
                    CONCAT(t.first_name, ' ', t.last_name) AS teacher_name,
                    (" . self::liveCondition('om') . ") AS is_live
               FROM online_meetings om
               JOIN lesson_sessions ls ON ls.id = om.lesson_session_id
               JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
               JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id JOIN teachers t ON t.id = ta.teacher_id
               LEFT JOIN study_groups sg ON sg.id = ta.study_group_id
              WHERE om.id = ? AND om.school_id = ?",
            [$meetingId, $schoolId]
        );
    }

    /**
     * The live meeting of a lesson, if one is open (a study group's lesson: of any of its classes' sessions).
     *
     * @param int[] $lessonSessionIds
     */
    public static function liveFor(Database $db, array $lessonSessionIds): ?array
    {
        $in = implode(',', array_map('intval', $lessonSessionIds ?: [0]));
        return $db->one(
            "SELECT * FROM online_meetings om WHERE om.lesson_session_id IN ($in) AND " . self::liveCondition('om') . ' ORDER BY om.id DESC LIMIT 1'
        );
    }

    /**
     * The assignments taught together with this lesson: for a study group, the group's assignments of
     * the same teacher (one per class); otherwise only this one.
     *
     * @return int[]
     */
    public static function lessonAssignments(Database $db, array $meeting): array
    {
        if ($meeting['study_group_id'] === null) {
            return [(int) $meeting['teaching_assignment_id']];
        }
        return array_map('intval', $db->column(
            'SELECT id FROM teaching_assignments WHERE school_id = ? AND study_group_id = ? AND teacher_id = ? AND academic_year_id = ?',
            [$meeting['school_id'], $meeting['study_group_id'], $meeting['teacher_id'], $meeting['academic_year_id']]
        ));
    }

    /** The student record of the signed-in account, when it is a student's own account. */
    public static function ownStudent(Database $db, Auth $auth): ?array
    {
        $id = $db->value('SELECT id FROM students WHERE user_id = ? AND school_id = ? AND deleted_at IS NULL', [$auth->id(), $auth->schoolId()]);
        if ($id === null) {
            return null;
        }
        return Portal::students($db, $auth)[(int) $id] ?? null;
    }

    /**
     * @return array{role: string, student: ?array}|null null when the account may not enter
     */
    public static function access(Database $db, Auth $auth, array $meeting): ?array
    {
        $student = self::ownStudent($db, $auth);
        if ($student !== null) {
            $mine = array_intersect(Portal::assignments($db, $student), self::lessonAssignments($db, $meeting));
            return $mine === [] ? null : ['role' => 'student', 'student' => $student];
        }
        if (!$auth->can('attendance.view')) {
            return null;
        }
        $scope = new AccessScope($db, $auth);
        $assignment = (int) $meeting['teaching_assignment_id'];
        if ($scope->canEditAssignment($assignment) && $auth->can('attendance.update')) {
            return ['role' => 'host', 'student' => null];
        }
        [$condition, $params] = $scope->assignmentCondition('ta');
        $visible = $db->value("SELECT 1 FROM teaching_assignments ta WHERE ta.id = ? AND $condition", [$assignment, ...$params]);
        return $visible === null ? null : ['role' => 'staff', 'student' => null];
    }

    /** The video grant of a role (what the person may publish). */
    public static function grant(string $room, string $role, bool $micsLocked, bool $screen = false): array
    {
        $grant = ['room' => $room, 'roomJoin' => true, 'canSubscribe' => true, 'canPublish' => true, 'canPublishData' => true];
        if ($role === 'host') {
            return $grant + ['roomAdmin' => true];
        }
        $grant['canPublishSources'] = self::sources($role, $micsLocked, $screen, false);
        return $grant;
    }

    /**
     * What a non-host may publish: the camera, the microphone unless the teacher locked them, and the
     * screen only when the teacher allowed this person.
     *
     * @return string[] lower case for a token, upper case (enum names) for the room service
     */
    public static function sources(string $role, bool $micsLocked, bool $screen, bool $upper): array
    {
        $sources = ['camera'];
        if (!$micsLocked || $role !== 'student') {
            $sources[] = 'microphone';
        }
        if ($screen) {
            array_push($sources, 'screen_share', 'screen_share_audio');
        }
        return $upper ? array_map('strtoupper', $sources) : $sources;
    }

    public static function identity(int $userId): string
    {
        return 'u' . $userId;
    }

    /** Notes who entered (first time, latest time, how many times). */
    public static function recordJoin(Database $db, array $meeting, int $userId, string $role, ?int $studentId): void
    {
        $db->run(
            'INSERT INTO online_meeting_participants (school_id, meeting_id, user_id, student_id, role) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE joins = joins + 1, last_joined_at = CURRENT_TIMESTAMP',
            [$meeting['school_id'], $meeting['id'], $userId, $studentId, $role]
        );
    }

    /**
     * The live meetings a student may enter now.
     *
     * @return array<int, array> with subject, teacher, class and period
     */
    public static function liveForStudent(Database $db, array $student): array
    {
        $assignments = Portal::assignments($db, $student);
        if ($assignments === []) {
            return [];
        }
        $in = implode(',', $assignments);
        // A study group lesson is opened on one class's assignment of the group; its members of other classes enter it too.
        return $db->all(
            "SELECT om.id, om.started_at, ls.period_number, s.name AS subject_name, c.name AS class_name, sg.name AS study_group_name,
                    CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
               FROM online_meetings om
               JOIN lesson_sessions ls ON ls.id = om.lesson_session_id
               JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
               JOIN subjects s ON s.id = ta.subject_id JOIN classes c ON c.id = ta.class_id JOIN teachers t ON t.id = ta.teacher_id
               LEFT JOIN study_groups sg ON sg.id = ta.study_group_id
              WHERE " . self::liveCondition('om') . " AND (ta.id IN ($in) OR (ta.study_group_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM teaching_assignments mine WHERE mine.id IN ($in) AND mine.study_group_id = ta.study_group_id
                       AND mine.teacher_id = ta.teacher_id AND mine.academic_year_id = ta.academic_year_id)))
              ORDER BY om.started_at DESC"
        );
    }

    /**
     * @param int[] $lessonSessionIds
     * @return array<int, array> student id => joined row (first time, count) for every meeting of a lesson
     */
    public static function joinedStudents(Database $db, array $lessonSessionIds): array
    {
        $in = implode(',', array_map('intval', $lessonSessionIds ?: [0]));
        $out = [];
        foreach ($db->all(
            "SELECT p.student_id, MIN(p.first_joined_at) AS first_joined_at, SUM(p.joins) AS joins
               FROM online_meeting_participants p JOIN online_meetings om ON om.id = p.meeting_id
              WHERE om.lesson_session_id IN ($in) AND p.role = 'student' AND p.student_id IS NOT NULL
              GROUP BY p.student_id"
        ) as $row) {
            $out[(int) $row['student_id']] = $row;
        }
        return $out;
    }
}
