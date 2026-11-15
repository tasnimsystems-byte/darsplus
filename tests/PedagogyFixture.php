<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;

/**
 * A small school used by the stage D tests:
 *
 *   classes  ז1 (students s1, s2, s3)   ז2 (student s4)
 *   subjects math, hebrew
 *   math in ז1   → teacher "mathUser"      hebrew in ז1 → teacher "hebrewUser"
 *   math in ז2   → teacher "otherMathUser" homeroom of ז1 → "homeroomUser"
 *   principal sees everything
 */
trait PedagogyFixture
{
    protected int $school;
    protected int $year;
    protected int $term1;
    protected int $term2;
    protected int $level;
    protected int $classA;
    protected int $classB;
    protected int $math;
    protected int $hebrew;
    protected int $principal;
    protected int $mathUser;
    protected int $hebrewUser;
    protected int $otherMathUser;
    protected int $homeroomUser;
    protected int $mathA;
    protected int $hebrewA;
    protected int $mathB;
    /** @var int[] */
    protected array $s = [];
    protected int $numericType;
    protected int $verbalType;

    protected function buildSchool(): void
    {
        $db = App::db();
        $this->school = $this->createSchool();
        $this->year = $this->createYear($this->school, '2026-27', true, '2026-09-01', date('Y') >= 2027 ? '2099-06-30' : '2027-06-30');
        // The working year always contains "today", whatever the date the tests run on.
        $db->update('academic_years', ['start_date' => date('Y-m-d', strtotime('-200 days')), 'end_date' => date('Y-m-d', strtotime('+160 days'))], ['id' => $this->year]);
        $term = fn (string $name, int $order, string $from, string $to): int => $db->insert('academic_terms', [
            'school_id' => $this->school, 'academic_year_id' => $this->year, 'name' => $name, 'sort_order' => $order,
            'start_date' => date('Y-m-d', strtotime($from)), 'end_date' => date('Y-m-d', strtotime($to)),
        ]);
        $this->term1 = $term('Term 1', 1, '-200 days', '-21 days');
        $this->term2 = $term('Term 2', 2, '-20 days', '+160 days');

        $this->level = $this->createLevel($this->school, 'ז', 7);
        $this->math = $this->createSubject($this->school, 'Mathematics');
        $this->hebrew = $this->createSubject($this->school, 'Hebrew');

        $this->principal = $this->createUser('p.principal', [['principal', $this->school]]);
        $this->mathUser = $this->createUser('p.math', [['subject_teacher', $this->school]]);
        $this->hebrewUser = $this->createUser('p.hebrew', [['subject_teacher', $this->school]]);
        $this->otherMathUser = $this->createUser('p.othermath', [['subject_teacher', $this->school]]);
        $this->homeroomUser = $this->createUser('p.homeroom', [['homeroom_teacher', $this->school]]);

        $mathTeacher = $this->createTeacher($this->school, 'MathTeacher', $this->mathUser);
        $hebrewTeacher = $this->createTeacher($this->school, 'HebrewTeacher', $this->hebrewUser);
        $otherMathTeacher = $this->createTeacher($this->school, 'OtherMathTeacher', $this->otherMathUser);
        $homeroomTeacher = $this->createTeacher($this->school, 'HomeroomTeacher', $this->homeroomUser);

        $this->classA = $this->createClass($this->school, $this->year, $this->level, 'ז1', $homeroomTeacher);
        $this->classB = $this->createClass($this->school, $this->year, $this->level, 'ז2');
        $this->mathA = $this->assign($this->school, $this->year, $mathTeacher, $this->classA, $this->math);
        $this->hebrewA = $this->assign($this->school, $this->year, $hebrewTeacher, $this->classA, $this->hebrew);
        $this->mathB = $this->assign($this->school, $this->year, $otherMathTeacher, $this->classB, $this->math);

        foreach ([1 => $this->classA, 2 => $this->classA, 3 => $this->classA, 4 => $this->classB] as $n => $classId) {
            $this->s[$n] = $this->createStudent($this->school, "Student$n", $classId);
        }
        $this->numericType = $db->insert('assessment_types', ['school_id' => $this->school, 'name' => 'Exam', 'category' => 'exam', 'is_numeric' => 1]);
        $this->verbalType = $db->insert('assessment_types', ['school_id' => $this->school, 'name' => 'Verbal', 'category' => 'alternative', 'is_numeric' => 0]);
    }

    /** @param array<int, float|string|null> $scores student number => score, or 'absent' / 'exempt' */
    protected function assessment(int $assignment, int $term, array $scores, float $max = 100, float $weight = 1, string $status = 'published', ?int $type = null): int
    {
        $db = App::db();
        $id = $db->insert('assessments', [
            'school_id' => $this->school, 'academic_year_id' => $this->year, 'teaching_assignment_id' => $assignment, 'term_id' => $term,
            'type_id' => $type ?? $this->numericType, 'title' => 'Assessment ' . bin2hex(random_bytes(3)),
            'assessment_date' => date('Y-m-d', strtotime($term === $this->term1 ? '-60 days' : '-5 days')),
            'max_score' => $max, 'weight' => $weight, 'status' => $status,
        ]);
        foreach ($scores as $n => $score) {
            $db->insert('assessment_results', [
                'school_id' => $this->school, 'assessment_id' => $id, 'student_id' => $this->s[$n],
                'score' => is_string($score) ? null : $score, 'result_status' => is_string($score) ? $score : 'graded',
            ]);
        }
        return $id;
    }

    protected function lesson(int $assignment, string $date, int $period, array $exceptions = [], string $status = 'held'): int
    {
        $db = App::db();
        $id = $db->insert('lesson_sessions', [
            'school_id' => $this->school, 'teaching_assignment_id' => $assignment, 'lesson_date' => date('Y-m-d', strtotime($date)),
            'period_number' => $period, 'status' => $status,
        ]);
        foreach ($exceptions as $n => $state) {
            $db->insert('attendance_records', ['school_id' => $this->school, 'lesson_session_id' => $id, 'student_id' => $this->s[$n], 'status' => $state]);
        }
        return $id;
    }
}
