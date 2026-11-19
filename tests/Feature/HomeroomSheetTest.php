<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\GradeStats;
use Tests\TestCase;

/**
 * A subject without grades (the homeroom lesson): the grade sheet records absences, late arrivals and the
 * homeroom teacher's remark (ready-made or free text); the certificate prints them and leaves the subject out.
 */
final class HomeroomSheetTest extends TestCase
{
    private int $school;
    private int $year;
    private int $term;
    private int $class;
    private int $teacherUser;
    private int $homeroomLesson;
    private int $math;
    /** @var array<string, int> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = App::db();
        $this->school = $this->createSchool(null, 'ar');
        $this->year = $this->createYear($this->school);
        $this->term = $db->insert('academic_terms', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'name' => 'الفصل الأول', 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $level = $this->createLevel($this->school, 'الثامن', 8);
        $this->teacherUser = $this->createUser('t.homeroom', [['homeroom_teacher', $this->school]]);
        $teacher = $this->createTeacher($this->school, 'سعيد', $this->teacherUser);
        $this->class = $this->createClass($this->school, $this->year, $level, 'الثامن 2', $teacher);
        foreach (['a', 'b'] as $key) {
            $this->s[$key] = $this->createStudent($this->school, "طالب-$key", $this->class);
        }
        $tarbiya = $this->createSubject($this->school, 'التربية');
        $db->update('subjects', ['is_graded' => 0], ['id' => $tarbiya]);
        $this->homeroomLesson = $this->assign($this->school, $this->year, $teacher, $this->class, $tarbiya);
        $mathLesson = $this->assign($this->school, $this->year, $teacher, $this->class, $this->math = $this->createSubject($this->school, 'الرياضيات'));

        $exam = $db->insert('assessment_types', ['school_id' => $this->school, 'name' => 'امتحان', 'category' => 'exam']);
        foreach ([$this->homeroomLesson => 40, $mathLesson => 90] as $lesson => $score) {
            $a = $db->insert('assessments', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'teaching_assignment_id' => $lesson,
                'term_id' => $this->term, 'type_id' => $exam, 'title' => 'x', 'assessment_date' => '2026-10-01', 'status' => 'published']);
            $db->insert('assessment_results', ['school_id' => $this->school, 'assessment_id' => $a, 'student_id' => $this->s['a'], 'score' => $score]);
        }
        foreach (['2026-10-02' => 'absent', '2026-10-03' => 'absent', '2026-10-04' => 'late'] as $date => $status) {
            $session = $db->insert('lesson_sessions', ['school_id' => $this->school, 'teaching_assignment_id' => $mathLesson, 'lesson_date' => $date, 'period_number' => 1, 'status' => 'held']);
            $db->insert('attendance_records', ['school_id' => $this->school, 'lesson_session_id' => $session, 'student_id' => $this->s['a'], 'status' => $status]);
        }
    }

    public function testHomeroomSheetFeedsTheCertificate(): void
    {
        $this->loginAs($this->teacherUser);
        $query = ['term_id' => (string) $this->term, 'lesson' => 'a' . $this->homeroomLesson];
        $page = $this->get('/assessments/gradebook', $query)->body;
        self::assertStringContainsString('homeroom-table', $page);
        self::assertStringNotContainsString('gradebook-table', $page, 'No grades for the homeroom lesson.');
        self::assertMatchesRegularExpression('/name="homeroom\[' . $this->s['a'] . '\]\[absences\]"\s+value=""\s+placeholder="2"/', $page, 'Counted from the lessons.');
        self::assertStringContainsString(e(__('gradebook.presets.positive.1')), $page);

        // a: 5 absences typed, lates left to the lessons, a ready-made remark; b: free text; a bad count is refused.
        $this->post('/assessments/gradebook', $query + ['homeroom' => [
            $this->s['a'] => ['absences' => '5', 'lates' => '', 'preset' => 'positive.1', 'remark' => ''],
            $this->s['b'] => ['absences' => 'x', 'lates' => '0', 'preset' => '', 'remark' => 'ملاحظة خاصة'],
        ]]);
        $rows = array_column(App::db()->all('SELECT student_id, remark, absences, lates FROM report_card_remarks'), null, 'student_id');
        self::assertSame(__('gradebook.presets.positive.1'), $rows[$this->s['a']]['remark']);
        self::assertSame(5, (int) $rows[$this->s['a']]['absences']);
        self::assertNull($rows[$this->s['a']]['lates']);
        self::assertSame('ملاحظة خاصة', $rows[$this->s['b']]['remark']);
        self::assertSame(0, (int) $rows[$this->s['b']]['lates']);
        self::assertNull($rows[$this->s['b']]['absences'], 'The invalid count was not saved.');
        self::assertStringContainsString(e(__('gradebook.invalid_counts', ['count' => 1])), $this->get('/assessments/gradebook', $query)->body);

        // The certificate: counts and remark from the sheet; no "التربية" row; it does not change the average.
        $this->loginAs($this->createUser('t.principal', [['principal', $this->school]]));
        $cert = $this->get('/certificates/print', ['lang' => 'ar', 'term_id' => (string) $this->term, 'class_id' => (string) $this->class, 'student_id' => (string) $this->s['a']])->body;
        self::assertStringContainsString('الغيابات: 5', $cert);
        self::assertStringContainsString('التأخرات: 1', $cert);
        self::assertStringContainsString(__('gradebook.presets.positive.1'), $cert);
        self::assertStringNotContainsString('>التربية</th>', $cert);
        self::assertMatchesRegularExpression('/المعدل العام<\/th>\s*<td class="cert-num">90<\/td>/u', $cert);
        self::assertSame([$this->math], array_values(array_unique(array_column((new GradeStats(App::db(), $this->school, $this->year, ['1 = 1', []]))->rows(), 'subject_id'))));

        // Clearing everything removes the record; the principal's remarks page keeps the counts.
        $this->post('/certificates/remarks', ['lang' => 'ar', 'term_id' => (string) $this->term, 'class_id' => (string) $this->class, 'remarks' => [$this->s['a'] => '']]);
        self::assertSame(5, (int) App::db()->value('SELECT absences FROM report_card_remarks WHERE student_id = ?', [$this->s['a']]));
        self::assertNull(App::db()->value('SELECT remark FROM report_card_remarks WHERE student_id = ?', [$this->s['a']]));
    }

    public function testSubjectsCanBeMarkedWithoutGrades(): void
    {
        $this->loginAs($this->createUser('t.principal', [['principal', $this->school]]));
        self::assertStringContainsString('name="is_graded"', $this->get('/subjects/create')->body);
        $this->post('/subjects/' . $this->math, ['name' => 'الرياضيات', 'is_active' => '1']);
        self::assertSame(0, (int) App::db()->value('SELECT is_graded FROM subjects WHERE id = ?', [$this->math]), 'Unticked = no grades.');
    }
}
