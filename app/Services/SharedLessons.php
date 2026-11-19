<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Lessons that are taught to a group rather than to a whole class, found in the weekly timetable:
 *  - shared lessons ("מקבץ"): the same teacher teaching the same subject to several classes at the
 *    same time — one study group over those classes;
 *  - split classes ("מפוצל"): one class learning one subject with two or more teachers at the same
 *    time (physical education, computers…) — each teacher's part of the class is a study group.
 * Such a lesson is then treated as a study group: one lesson page, one entry in the teacher's week
 * and one grade sheet, listing only the group's students.
 *
 * detect() finds them: for shared lessons, assignments of one teacher and one subject in different
 * classes that share at least one weekday and period, joined transitively; for split classes, the
 * assignments of one class and subject whose teachers meet it in the same period. Assignments already
 * linked to a study group are left out. create() makes a study group of each, with every
 * current student of those classes as members; the principal then removes the students who are not
 * in the group, in the group's page.
 */
final class SharedLessons
{
    /**
     * @return list<array{kind: string, teacher_id: int, subject_id: int, assignments: array<int, int>, classes: list<string>, subject: string, teacher: string, name: string}>
     *         assignments: class id => assignment id
     */
    public static function detect(Database $db, int $schoolId, int $yearId): array
    {
        $byTime = [];
        $byClassTime = [];
        $info = [];
        foreach ($db->all(
            "SELECT ts.weekday, ts.period_number, ta.id, ta.teacher_id, ta.subject_id, ta.class_id, c.name AS class_name,
                    s.name AS subject_name, CONCAT(t.first_name, ' ', t.last_name) AS teacher_name, gl.level_number
               FROM timetable_slots ts JOIN teaching_assignments ta ON ta.id = ts.teaching_assignment_id
               JOIN classes c ON c.id = ta.class_id JOIN grade_levels gl ON gl.id = c.grade_level_id
               JOIN subjects s ON s.id = ta.subject_id JOIN teachers t ON t.id = ta.teacher_id
              WHERE ts.school_id = ? AND ts.academic_year_id = ? AND ta.study_group_id IS NULL",
            [$schoolId, $yearId]
        ) as $row) {
            $byTime["{$row['teacher_id']}|{$row['subject_id']}|{$row['weekday']}|{$row['period_number']}"][(int) $row['id']] = (int) $row['class_id'];
            $byClassTime["{$row['class_id']}|{$row['subject_id']}|{$row['weekday']}|{$row['period_number']}"][(int) $row['id']] = (int) $row['teacher_id'];
            $info[(int) $row['id']] = $row;
        }

        // Union-find over assignments that meet together in some period.
        $parent = [];
        $find = static function (int $x) use (&$parent, &$find): int {
            $parent[$x] ??= $x;
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        foreach ($byTime as $together) {
            if (count(array_unique($together)) < 2) {
                continue;
            }
            $ids = array_keys($together);
            foreach ($ids as $id) {
                $parent[$find($id)] = $find($ids[0]);
            }
        }
        $clusters = [];
        foreach (array_keys($parent) as $id) {
            $clusters[$find($id)][] = $id;
        }

        $result = [];
        $taken = [];
        foreach ($clusters as $ids) {
            usort($ids, static fn ($a, $b) => [(int) $info[$a]['level_number'], $info[$a]['class_name']] <=> [(int) $info[$b]['level_number'], $info[$b]['class_name']]);
            $assignments = [];
            foreach ($ids as $id) {
                $assignments[(int) $info[$id]['class_id']] ??= $id;   // one assignment per class
            }
            if (count($assignments) < 2) {
                continue;
            }
            $first = $info[$ids[0]];
            $classes = array_values(array_unique(array_map(static fn ($id) => $info[$id]['class_name'], $ids)));
            $result[] = [
                'kind' => 'shared', 'teacher_id' => (int) $first['teacher_id'], 'subject_id' => (int) $first['subject_id'], 'assignments' => $assignments,
                'classes' => $classes, 'subject' => $first['subject_name'], 'teacher' => $first['teacher_name'],
                'name' => mb_substr($first['subject_name'] . ' – ' . $first['teacher_name'] . ' (' . implode('، ', $classes) . ')', 0, 80),
            ];
            foreach ($assignments as $id) {
                $taken[$id] = true;
            }
        }

        // Split classes: several teachers with one class in one subject at the same time; each teacher's part is a group.
        $split = [];
        foreach ($byClassTime as $together) {
            if (count(array_unique($together)) < 2) {
                continue;
            }
            foreach (array_keys($together) as $id) {
                if (!isset($taken[$id])) {
                    $split[$id] = true;
                }
            }
        }
        foreach (array_keys($split) as $id) {
            $row = $info[$id];
            $result[] = [
                'kind' => 'split', 'teacher_id' => (int) $row['teacher_id'], 'subject_id' => (int) $row['subject_id'],
                'assignments' => [(int) $row['class_id'] => $id], 'classes' => [$row['class_name']],
                'subject' => $row['subject_name'], 'teacher' => $row['teacher_name'],
                'name' => mb_substr($row['subject_name'] . ' – ' . $row['teacher_name'] . ' (' . $row['class_name'] . ')', 0, 80),
            ];
        }
        usort($result, static fn ($a, $b) => [$a['kind'], $a['subject'], $a['classes'][0], $a['teacher']] <=> [$b['kind'], $b['subject'], $b['classes'][0], $b['teacher']]);
        return $result;
    }

    /**
     * Creates a study group for each shared lesson: linked to its assignments, with the current students
     * of its classes as members. Returns the number of groups created.
     */
    public static function create(Database $db, int $schoolId, int $yearId, ?int $userId, array $shared, string $note): int
    {
        $created = 0;
        $db->transaction(function () use ($db, $schoolId, $yearId, $userId, $shared, $note, &$created): void {
            foreach ($shared as $lesson) {
                $name = $lesson['name'];
                for ($n = 2; $db->value('SELECT 1 FROM study_groups WHERE academic_year_id = ? AND name = ?', [$yearId, $name]) !== null; $n++) {
                    $name = mb_substr($lesson['name'], 0, 75) . " ($n)";
                }
                $group = $db->insert('study_groups', [
                    'school_id' => $schoolId, 'academic_year_id' => $yearId, 'name' => $name, 'subject_id' => $lesson['subject_id'], 'origin' => $lesson['kind'] ?? 'shared',
                    'notes' => $note, 'created_by' => $userId,
                ]);
                $ids = implode(',', array_map('intval', $lesson['assignments']));
                $db->run("UPDATE teaching_assignments SET study_group_id = ? WHERE school_id = ? AND study_group_id IS NULL AND id IN ($ids)", [$group, $schoolId]);
                $db->run(
                    'INSERT IGNORE INTO study_group_students (school_id, group_id, student_id)
                     SELECT ?, ?, e.student_id FROM class_enrollments e JOIN students s ON s.id = e.student_id
                      WHERE e.class_id IN (' . implode(',', array_map('intval', array_keys($lesson['assignments']))) . ') AND e.left_on IS NULL AND s.deleted_at IS NULL',
                    [$schoolId, $group]
                );
                $created++;
            }
        });
        return $created;
    }
}
