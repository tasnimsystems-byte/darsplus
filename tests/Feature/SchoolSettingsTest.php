<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\GradeStats;
use Tests\TestCase;

/**
 * School settings: the number of terms of the working year and the components of the
 * term grade (assessment types with weights totalling 100), applied in every grade figure.
 */
final class SchoolSettingsTest extends TestCase
{
    private int $school;
    private int $year;
    private int $principal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool(null, 'ar');
        $this->year = $this->createYear($this->school);
        $this->principal = $this->createUser('t.principal', [['principal', $this->school]]);
        $this->loginAs($this->principal);
    }

    private function terms(): array
    {
        return App::db()->all('SELECT id, name, start_date, end_date FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order', [$this->year]);
    }

    private function type(string $name, ?float $weight = null, int $numeric = 1): int
    {
        return App::db()->insert('assessment_types', ['school_id' => $this->school, 'name' => $name, 'category' => 'exam', 'is_numeric' => $numeric, 'term_weight' => $weight]);
    }

    public function testTermsAreSplitEvenlyAndATermInUseIsKept(): void
    {
        $page = $this->get('/school-settings');
        self::assertSame(200, $page->status);
        self::assertStringContainsString(__('school_settings.components_title'), $page->body);
        self::assertStringContainsString('href="/school-settings"', $this->get('/')->body, 'The new tab is in the menu.');

        $this->post('/school-settings/terms', ['term_count' => '3']);
        $terms = $this->terms();
        self::assertSame(['الفصل الأول', 'الفصل الثاني', 'الفصل الثالث'], array_column($terms, 'name'));
        self::assertSame('2026-09-01', $terms[0]['start_date']);
        self::assertSame('2027-06-30', $terms[2]['end_date']);
        self::assertSame(date('Y-m-d', strtotime($terms[0]['end_date'] . ' +1 day')), $terms[1]['start_date'], 'Terms follow each other.');

        // Two terms: the first two keep their ids, the third is removed.
        $ids = array_column($terms, 'id');
        $this->post('/school-settings/terms', ['term_count' => '2']);
        self::assertSame(array_slice($ids, 0, 2), array_column($this->terms(), 'id'));
        self::assertSame(['2026-09-01', '2027-01-29'], [$this->terms()[0]['start_date'], $this->terms()[0]['end_date']]);

        // A term with assessments is not removed.
        $level = $this->createLevel($this->school, 'السابع', 7);
        $class = $this->createClass($this->school, $this->year, $level, 'السابع 1');
        $assignment = $this->assign($this->school, $this->year, $this->createTeacher($this->school, 'لوي'), $class, $this->createSubject($this->school, 'رياضيات'));
        App::db()->insert('assessments', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'teaching_assignment_id' => $assignment,
            'term_id' => $ids[1], 'type_id' => $this->type('امتحان'), 'title' => 'x', 'assessment_date' => '2027-03-01']);
        $this->post('/school-settings/terms', ['term_count' => '1']);
        self::assertCount(2, $this->terms());
        self::assertStringContainsString(e(__('school_settings.errors.term_in_use', ['name' => 'الفصل الثاني'])), $this->get('/school-settings')->body);
        $this->post('/school-settings/terms', ['term_count' => '9']);
        self::assertCount(2, $this->terms());
    }

    public function testComponentsMustTotalOneHundred(): void
    {
        $exam = $this->type('امتحان شهري');
        $verbal = $this->type('تقييم كلامي', null, 0);

        $this->post('/school-settings/components', [
            'components' => [$exam => ['name' => 'امتحان شهري', 'weight' => '30'], $verbal => ['name' => 'تقييم كلامي', 'weight' => '50']],
            'new' => [['name' => 'مهام محوسبة', 'category' => 'assignment', 'weight' => '20']],
        ]);
        self::assertSame(2, (int) App::db()->value('SELECT COUNT(*) FROM assessment_types WHERE school_id = ?', [$this->school]), 'A total of 50 is refused.');
        self::assertStringContainsString(e(__('school_settings.errors.total', ['total' => '50'])), $this->get('/school-settings')->body);

        $this->post('/school-settings/components', [
            'components' => [$exam => ['name' => 'امتحان شهري', 'weight' => '30']],
            'new' => [['name' => 'مهام محوسبة', 'category' => 'assignment', 'weight' => '20'], ['name' => 'امتحان فصلي', 'category' => 'periodic', 'weight' => '40'],
                ['name' => 'اشتراك', 'category' => 'alternative', 'weight' => '10']],
        ]);
        $weights = array_map('floatval', array_column(App::db()->all('SELECT name, term_weight FROM assessment_types WHERE school_id = ? AND term_weight IS NOT NULL', [$this->school]), 'term_weight', 'name'));
        self::assertSame(['امتحان شهري' => 30.0, 'مهام محوسبة' => 20.0, 'امتحان فصلي' => 40.0, 'اشتراك' => 10.0], $weights);
        self::assertNull(App::db()->value('SELECT term_weight FROM assessment_types WHERE id = ?', [$verbal]), 'A verbal type gets no weight.');

        // Duplicate names are refused; clearing every weight goes back to the plain mean.
        $this->post('/school-settings/components', ['components' => [$exam => ['name' => 'اشتراك', 'weight' => '100']]]);
        self::assertSame('امتحان شهري', App::db()->value('SELECT name FROM assessment_types WHERE id = ?', [$exam]));
        $ids = App::db()->column('SELECT id FROM assessment_types WHERE school_id = ?', [$this->school]);
        $this->post('/school-settings/components', ['components' => array_fill_keys($ids, ['weight' => ''])]);
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM assessment_types WHERE term_weight IS NOT NULL'));
    }

    public function testTemplateAndWeightedTermGrade(): void
    {
        $old = $this->type('امتحان قصير', 50);
        $this->post('/school-settings/components/template');
        self::assertSame(100.0, (float) App::db()->value('SELECT SUM(term_weight) FROM assessment_types WHERE school_id = ?', [$this->school]));
        self::assertNull(App::db()->value('SELECT term_weight FROM assessment_types WHERE id = ?', [$old]));
        self::assertSame(35.0, (float) App::db()->value("SELECT term_weight FROM assessment_types WHERE name = 'امتحان فصلي'"));

        // Grade: term exam 80 (35%) and homework 50 (10%) → (80·35 + 50·10) / 45 = 73.3; the unweighted quiz does not count.
        $db = App::db();
        $level = $this->createLevel($this->school, 'السابع', 7);
        $class = $this->createClass($this->school, $this->year, $level, 'السابع 1');
        $assignment = $this->assign($this->school, $this->year, $this->createTeacher($this->school, 'لوي'), $class, $this->createSubject($this->school, 'رياضيات'));
        $student = $this->createStudent($this->school, 'طالب', $class);
        $term = $db->insert('academic_terms', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'name' => 'ف1', 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        foreach ([['امتحان فصلي', 80], ['وظائف بيتية', 50], ['امتحان قصير', 10]] as [$type, $score]) {
            $assessment = $db->insert('assessments', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'teaching_assignment_id' => $assignment,
                'term_id' => $term, 'type_id' => (int) $db->value('SELECT id FROM assessment_types WHERE name = ? AND school_id = ?', [$type, $this->school]),
                'title' => $type, 'assessment_date' => '2026-10-01', 'status' => 'published']);
            $db->insert('assessment_results', ['school_id' => $this->school, 'assessment_id' => $assessment, 'student_id' => $student, 'score' => $score]);
        }
        $stats = new GradeStats($db, $this->school, $this->year, ['1 = 1', []]);
        self::assertEqualsWithDelta(73.33, $stats->rows()[0]['avg'], 0.01);

        // Without components: the plain mean of all three.
        $db->run('UPDATE assessment_types SET term_weight = NULL');
        $stats = new GradeStats($db, $this->school, $this->year, ['1 = 1', []]);
        self::assertEqualsWithDelta(46.67, $stats->rows()[0]['avg'], 0.01);
    }

    public function testOnlyPrincipalsAndBothLanguages(): void
    {
        foreach (['he', 'ar'] as $locale) {
            App::db()->update('users', ['locale' => $locale], ['id' => $this->principal]);
            $this->loginAs($this->principal);
            $body = $this->get('/school-settings')->body;
            self::assertDoesNotMatchRegularExpression('/school_settings\.[a-z_]+/', strip_tags($body));
        }
        $this->loginAs($this->createUser('t.teacher', [['subject_teacher', $this->school]]));
        self::assertSame(403, $this->get('/school-settings')->status);
        self::assertSame(403, $this->post('/school-settings/terms', ['term_count' => '2'])->status);
    }
}
