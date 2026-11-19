<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\GradeStats;
use Tests\TestCase;

/**
 * Grade sheet: term + class → each student's average per component and the final grade,
 * always rounded up to a whole number.
 */
final class GradebookTest extends TestCase
{
    private int $school;
    private int $year;
    private int $term;
    private int $assignment;
    private int $teacherUser;
    private int $exam;
    private int $homework;
    /** @var array<string, int> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = App::db();
        $this->school = $this->createSchool();
        $this->year = $this->createYear($this->school);
        $this->term = $db->insert('academic_terms', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'name' => "מחצית א'", 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $level = $this->createLevel($this->school, 'ז', 7);
        $class = $this->createClass($this->school, $this->year, $level, 'ז1');
        $this->teacherUser = $this->createUser('t.teacher', [['subject_teacher', $this->school]]);
        $this->assignment = $this->assign($this->school, $this->year, $this->createTeacher($this->school, 'לוי', $this->teacherUser), $class, $this->createSubject($this->school, 'מתמטיקה'));
        foreach (['a', 'b', 'c'] as $key) {
            $this->s[$key] = $this->createStudent($this->school, "תלמיד-$key", $class);
        }
        $this->exam = $db->insert('assessment_types', ['school_id' => $this->school, 'name' => 'מבחן', 'category' => 'exam', 'term_weight' => 60, 'sort_order' => 1]);
        $this->homework = $db->insert('assessment_types', ['school_id' => $this->school, 'name' => 'שיעורי בית', 'category' => 'assignment', 'term_weight' => 40, 'sort_order' => 2]);

        $this->assessment($this->exam, 100, ['a' => 80, 'b' => 70]);
        $this->assessment($this->exam, 100, ['a' => 91]);
        $this->assessment($this->homework, 20, ['a' => 15]);
        $this->assessment($this->homework, 100, ['a' => 0, 'b' => 0], 'draft');
    }

    private function assessment(int $type, int $max, array $scores, string $status = 'published'): void
    {
        $db = App::db();
        $id = $db->insert('assessments', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'teaching_assignment_id' => $this->assignment,
            'term_id' => $this->term, 'type_id' => $type, 'title' => 'x', 'assessment_date' => '2026-10-01', 'max_score' => $max, 'status' => $status]);
        foreach ($scores as $key => $score) {
            $db->insert('assessment_results', ['school_id' => $this->school, 'assessment_id' => $id, 'student_id' => $this->s[$key], 'score' => $score]);
        }
    }

    public function testComponentsAndFinalGradeRoundedUp(): void
    {
        $this->loginAs($this->teacherUser);
        $page = $this->get('/assessments/gradebook');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('selected>ז1', $page->body, 'A teacher with one class gets it chosen.');

        $body = $this->get('/assessments/gradebook', ['term_id' => (string) $this->term, 'lesson' => 'a' . $this->assignment])->body;
        // a: exams (80 + 91) / 2 = 85.5, homework 15/20 = 75 → 85.5·0.6 + 75·0.4 = 81.3 → 82.
        self::assertMatchesRegularExpression('/תלמיד-a.*?placeholder="85\.5".*?placeholder="75".*?placeholder="82"/s', $body);
        // b: only the exam → 70; the draft homework does not count.
        self::assertMatchesRegularExpression('/תלמיד-b.*?placeholder="70".*?\[final\]"\s+value=""\s+placeholder="70"/s', $body);
        self::assertStringContainsString('תלמיד-c', $body);
        self::assertStringContainsString(e(__('gradebook.drafts', ['count' => 1])), $body);
        self::assertStringContainsString('60%', $body);

        // The same figure as every report.
        $rows = array_column((new GradeStats(App::db(), $this->school, $this->year, ['1 = 1', []]))->rows(), 'avg', 'student_id');
        self::assertEqualsWithDelta(81.3, $rows[$this->s['a']], 0.001);
    }

    public function testComponentGradesAreTypedInTheSheet(): void
    {
        $this->loginAs($this->teacherUser);
        $query = ['term_id' => (string) $this->term, 'lesson' => 'a' . $this->assignment];
        $body = $this->get('/assessments/gradebook', $query)->body;
        self::assertMatchesRegularExpression('/name="grades\[' . $this->s['a'] . '\]\[' . $this->homework . '\]"\s+value="" placeholder="75"/', $body);

        $save = fn (array $grades) => $this->post('/assessments/gradebook', $query + ['grades' => $grades]);
        $response = $save([
            $this->s['a'] => [$this->homework => '90', $this->exam => ''],
            $this->s['b'] => [$this->exam => 'abc', $this->homework => '50,5'],
            $this->s['c'] => [$this->exam => '100', $this->homework => '101'],
        ]);
        $this->assertRedirect('/assessments/gradebook?term_id=' . $this->term . '&lesson=a' . $this->assignment, $response);
        $finals = fn () => array_map(fn ($avg) => GradeStats::finalGrade($avg),
            array_column((new GradeStats(App::db(), $this->school, $this->year, ['1 = 1', []]))->rows(), 'avg', 'student_id'));
        // a: the typed 90 replaces the homework average 75 → 85.5·0.6 + 90·0.4 = 87.3 → 88.
        // b: exam 70, typed homework 50.5 → 42 + 20.2 = 62.2 → 63 ("abc" refused). c: typed exam only → 100 (101 refused).
        self::assertSame([$this->s['a'] => 88, $this->s['b'] => 63, $this->s['c'] => 100], $finals());
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM assessments WHERE is_summary = 1 AND type_id = ?', [$this->exam]), 'One summary per component.');
        self::assertStringContainsString(e(__('gradebook.invalid', ['count' => 2])), $this->get('/assessments/gradebook', $query)->body);

        $body = $this->get('/assessments/gradebook', $query)->body;
        self::assertMatchesRegularExpression('/grade-typed"[^>]*name="grades\[' . $this->s['a'] . '\]\[' . $this->homework . '\]"\s+value="90"/', $body);
        self::assertMatchesRegularExpression('/name="grades\[' . $this->s['a'] . '\]\[final\]"\s+value=""\s+placeholder="88"/', $body);

        // Clearing the cell brings back the computed average.
        $save([$this->s['a'] => [$this->homework => '']]);
        self::assertSame(82, $finals()[$this->s['a']]);

        // Another teacher, or a locked year: refused.
        $this->loginAs($this->createUser('t.other', [['subject_teacher', $this->school]]));
        self::assertSame(403, $save([$this->s['a'] => [$this->homework => '10']])->status);
        App::db()->update('academic_years', ['is_locked' => 1], ['id' => $this->year]);
        $this->loginAs($this->teacherUser);
        self::assertSame(403, $save([$this->s['a'] => [$this->homework => '10']])->status);
        self::assertStringNotContainsString('name="grades[', $this->get('/assessments/gradebook', $query)->body);
        self::assertSame(82, $finals()[$this->s['a']]);
    }

    public function testOnlyOwnLessonsAndAStudyGroupOnce(): void
    {
        $db = App::db();
        $level = (int) $db->value('SELECT grade_level_id FROM classes WHERE name = ?', ['ז1']);
        $class1 = (int) $db->value('SELECT id FROM classes WHERE name = ?', ['ז1']);
        $class2 = $this->createClass($this->school, $this->year, $level, 'ז2');
        $class3 = $this->createClass($this->school, $this->year, $level, 'ז3');
        $teacher = (int) $db->value('SELECT teacher_id FROM teaching_assignments WHERE id = ?', [$this->assignment]);
        $english = $this->createSubject($this->school, 'אנגלית');
        // The teacher is homeroom teacher of ז3 (taught by someone else) and teaches a study group in ז1 + ז2.
        $db->update('classes', ['homeroom_teacher_id' => $teacher], ['id' => $class3]);
        $other = $this->assign($this->school, $this->year, $this->createTeacher($this->school, 'כהן'), $class3, $english);
        $g1 = $this->assign($this->school, $this->year, $teacher, $class1, $english);
        $g2 = $this->assign($this->school, $this->year, $teacher, $class2, $english);
        $group = $db->insert('study_groups', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'name' => 'מצוינות']);
        $db->run('UPDATE teaching_assignments SET study_group_id = ? WHERE id IN (?, ?)', [$group, $g1, $g2]);
        $inB = $this->createStudent($this->school, 'תלמיד-ז2', $class2);
        $this->createStudent($this->school, 'לא-בקבוצה', $class2);
        foreach ([$this->s['a'], $inB] as $member) {
            $db->insert('study_group_students', ['school_id' => $this->school, 'group_id' => $group, 'student_id' => $member]);
        }

        $this->loginAs($this->teacherUser);
        // Four lists: term, class, teacher, subject. The teacher sees only their own classes (and the group once).
        $body = $this->get('/assessments/gradebook', ['term_id' => (string) $this->term])->body;
        self::assertStringContainsString('<option value="c' . $class1 . '">ז1</option>', $body);
        self::assertStringContainsString('<option value="g' . $group . '">מצוינות (ז1، ז2)</option>', $body);
        self::assertStringNotContainsString('value="c' . $class2 . '"', $body, 'ז2 is taught only inside the group.');
        self::assertStringNotContainsString('value="c' . $class3 . '"', $body, 'The homeroom class taught by another teacher is not listed.');
        self::assertMatchesRegularExpression('/<option value="' . $teacher . '" selected>/', $body, 'The only teacher is chosen by itself.');
        self::assertStringContainsString('value="c' . $class3 . '"', $this->get('/assessments/gradebook', ['term_id' => (string) $this->term, 'all' => '1'])->body);

        // In ז1 this teacher has one plain lesson (English there is taught only as the group): its subject fills in by itself.
        $body = $this->get('/assessments/gradebook', ['term_id' => (string) $this->term, 'class' => "c$class1"])->body;
        self::assertStringContainsString('gradebook-table', $body);
        self::assertStringContainsString('name="lesson" value="a' . $this->assignment . '"', $body);
        self::assertStringNotContainsString('>אנגלית</option>', $body);
        // Choosing the group fills in its only subject by itself.
        $body = $this->get('/assessments/gradebook', ['term_id' => (string) $this->term, 'class' => "g$group"])->body;
        self::assertStringContainsString('name="lesson" value="g' . $group . 't' . $teacher . '"', $body);

        // The group sheet: only members, from both classes; each grade is saved in the student's own class.
        $query = ['term_id' => (string) $this->term, 'lesson' => "g{$group}t$teacher"];
        $body = $this->get('/assessments/gradebook', $query)->body;
        self::assertStringContainsString('תלמיד-a', $body);
        self::assertStringContainsString('תלמיד-ז2', $body);
        self::assertStringNotContainsString('לא-בקבוצה', $body);
        self::assertStringNotContainsString('תלמיד-b', $body);
        $this->post('/assessments/gradebook', $query + ['grades' => [$this->s['a'] => [$this->exam => '80'], $inB => [$this->exam => '90'], $this->s['b'] => [$this->exam => '50']]]);
        $saved = $db->all('SELECT a.teaching_assignment_id, r.student_id, r.score FROM assessment_results r JOIN assessments a ON a.id = r.assessment_id WHERE a.is_summary = 1 ORDER BY a.teaching_assignment_id');
        self::assertSame([[$g1, $this->s['a'], 80.0], [$g2, $inB, 90.0]], array_map(static fn ($r) => [(int) $r['teaching_assignment_id'], (int) $r['student_id'], (float) $r['score']], $saved));

        // Someone else's lesson cannot be saved through the sheet.
        self::assertSame(403, $this->post('/assessments/gradebook', ['term_id' => (string) $this->term, 'lesson' => "a$other", 'grades' => []])->status);
    }

    public function testFinalGradeCanBeTypedDirectly(): void
    {
        $this->loginAs($this->teacherUser);
        $query = ['term_id' => (string) $this->term, 'lesson' => 'a' . $this->assignment];
        $body = $this->get('/assessments/gradebook', $query)->body;
        self::assertMatchesRegularExpression('/name="grades\[' . $this->s['a'] . '\]\[final\]"\s+value=""\s+placeholder="82"/', $body, 'The computed final is shown faintly.');

        $finals = fn () => array_map(static fn ($avg) => GradeStats::finalGrade($avg),
            array_column((new GradeStats(App::db(), $this->school, $this->year, ['1 = 1', []]))->rows(), 'avg', 'student_id'));

        // a: 95 instead of the computed 82; c has no assessment at all and gets 70; b: invalid values are refused.
        $this->post('/assessments/gradebook', $query + ['grades' => [
            $this->s['a'] => ['final' => '95'], $this->s['c'] => ['final' => '70'], $this->s['b'] => ['final' => '80.5'],
        ]]);
        self::assertSame([$this->s['a'] => 95, $this->s['b'] => 70, $this->s['c'] => 70], $finals());
        self::assertStringContainsString(e(__('gradebook.invalid', ['count' => 1])), $this->get('/assessments/gradebook', $query)->body);
        $this->post('/assessments/gradebook', $query + ['grades' => [$this->s['b'] => ['final' => '101']]]);
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM grade_overrides WHERE student_id = ?', [$this->s['b']]));

        $body = $this->get('/assessments/gradebook', $query)->body;
        self::assertMatchesRegularExpression('/grade-typed[^"]*"[^>]*name="grades\[' . $this->s['a'] . '\]\[final\]"\s+value="95"\s+placeholder="82"/', $body);

        // The certificate uses the typed grade too.
        $this->loginAs($this->createUser('t.principal', [['principal', $this->school]]));
        $class = (int) App::db()->value('SELECT class_id FROM teaching_assignments WHERE id = ?', [$this->assignment]);
        $cert = $this->get('/certificates/print', ['lang' => 'he', 'term_id' => (string) $this->term, 'class_id' => (string) $class, 'student_id' => (string) $this->s['a']])->body;
        self::assertMatchesRegularExpression('/<td class="cert-num">95<\/td>\s*<td class="cert-verbal">מצוין<\/td>/u', $cert);

        // Clearing the cell brings the computed grade back.
        $this->loginAs($this->teacherUser);
        $this->post('/assessments/gradebook', $query + ['grades' => [$this->s['a'] => ['final' => '']]]);
        self::assertSame(82, $finals()[$this->s['a']]);
    }

    public function testRoundingAndAccess(): void
    {
        self::assertSame(73, GradeStats::finalGrade(72.01));
        self::assertSame(72, GradeStats::finalGrade(72.0));
        self::assertSame(72, GradeStats::finalGrade(71.99999999999));
        self::assertNull(GradeStats::finalGrade(null));

        // Another teacher cannot open this class.
        $this->loginAs($this->createUser('t.other', [['subject_teacher', $this->school]]));
        $body = $this->get('/assessments/gradebook', ['term_id' => (string) $this->term, 'lesson' => 'a' . $this->assignment])->body;
        self::assertStringNotContainsString('תלמיד-a', $body);

        foreach (['he', 'ar'] as $locale) {
            $principal = $this->createUser("t.principal.$locale", [['principal', $this->school]], ['locale' => $locale]);
            $this->loginAs($principal);
            $response = $this->get('/assessments/gradebook', ['term_id' => (string) $this->term, 'lesson' => 'a' . $this->assignment]);
            self::assertSame(200, $response->status);
            self::assertStringContainsString('תלמיד-a', $response->body, 'The principal sees every class.');
            self::assertDoesNotMatchRegularExpression('/gradebook\.[a-z_]+/', strip_tags($response->body));
        }
    }
}
