<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class EnrollmentService
{
    public function __construct(private Database $db)
    {
    }

    /** The student's open enrolment in the given year, if any. */
    public function current(int $studentId, int $yearId): ?array
    {
        return $this->db->one(
            'SELECT e.id, e.class_id FROM class_enrollments e JOIN classes c ON c.id = e.class_id
              WHERE e.student_id = ? AND c.academic_year_id = ? AND e.left_on IS NULL
              ORDER BY e.id DESC LIMIT 1',
            [$studentId, $yearId]
        );
    }

    /**
     * Puts the student in the class for that year (null = in no class).
     * A student has at most one open enrolment per year; history rows are kept.
     */
    public function setClass(int $schoolId, int $studentId, ?int $classId, array $year): void
    {
        $current = $this->current($studentId, (int) $year['id']);
        if ((int) ($current['class_id'] ?? 0) === (int) $classId) {
            return;
        }
        $this->db->transaction(function () use ($schoolId, $studentId, $classId, $year, $current): void {
            $today = date('Y-m-d');
            if ($current !== null) {
                $this->db->update('class_enrollments', ['left_on' => $today], ['id' => $current['id']]);
            }
            if ($classId === null) {
                return;
            }
            $existing = $this->db->value(
                'SELECT id FROM class_enrollments WHERE student_id = ? AND class_id = ?',
                [$studentId, $classId]
            );
            if ($existing !== null) {
                $this->db->update('class_enrollments', ['left_on' => null], ['id' => $existing]);
                return;
            }
            $this->db->insert('class_enrollments', [
                'school_id' => $schoolId, 'student_id' => $studentId, 'class_id' => $classId,
                'enrolled_on' => max($today, (string) $year['start_date']),
            ]);
        });
    }
}
