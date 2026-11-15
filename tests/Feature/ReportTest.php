<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Reports\Reports;
use App\Services\AccessScope;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\PedagogyFixture;
use Tests\TestCase;

/** Stage F: reports, exports and dashboard figures — checked against hand-calculated numbers and against the access scope. */
final class ReportTest extends TestCase
{
    use PedagogyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        // The worked example of AssessmentTest:
        //   math ז1, term 1: s1 87.5, s2 55, s3 90      hebrew ז1: s1 70, s2 95      math ז2: s4 40
        $this->assessment($this->mathA, $this->term1, [1 => 90, 2 => 60, 3 => 'absent'], 100, 3);
        $this->assessment($this->mathA, $this->term1, [1 => 40, 2 => 20, 3 => 45], 50, 1);
        $this->assessment($this->hebrewA, $this->term1, [1 => 70, 2 => 95]);
        $this->assessment($this->mathB, $this->term1, [4 => 40]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob(App::root('storage/exports/report_*')) ?: []);
        parent::tearDown();
    }

    private function build(string $key, array $filters = []): array
    {
        $reports = new Reports(App::db(), App::auth(), new AccessScope(App::db(), App::auth()));
        return $reports->build($key, $reports->cleanFilters($key, $filters));
    }

    private function rowsByFirstColumn(array $table): array
    {
        return array_column(array_map(static fn (array $row): array => ['k' => $row[0], 'v' => array_slice($row, 1)], $table['rows']), 'v', 'k');
    }

    public function testAchievementReportsMatchHandCalculation(): void
    {
        $this->loginAs($this->principal);

        $school = $this->rowsByFirstColumn($this->build('achievements_school', ['term_id' => $this->term1]));
        self::assertSame([70.9, 4], $school[__('reports.rows.school')]);                                  // (78.75 + 75 + 90 + 40) ÷ 4
        self::assertSame([68.1, 4], $school[__('reports.rows.subject', ['name' => 'Mathematics'])]);      // (87.5 + 55 + 90 + 40) ÷ 4
        self::assertSame([82.5, 2], $school[__('reports.rows.subject', ['name' => 'Hebrew'])]);

        // Class report: students × subjects (subjects in name order: Hebrew, Mathematics) and each student's average.
        $class = $this->build('achievements_class', ['class_id' => $this->classA, 'term_id' => $this->term1]);
        self::assertSame([__('assessments.student'), 'Hebrew', 'Mathematics', __('reports.columns.average')], $class['columns']);
        $byStudent = $this->rowsByFirstColumn($class);
        self::assertSame([70.0, 87.5, 78.8], $byStudent['Student1 S']);
        self::assertSame([95.0, 55.0, 75.0], $byStudent['Student2 S']);
        self::assertSame([null, 90.0, 90.0], $byStudent['Student3 S'], 'No hebrew grade → empty cell, not zero.');

        $level = $this->rowsByFirstColumn($this->build('achievements_level', ['grade_level_id' => $this->level, 'term_id' => $this->term1]));
        self::assertSame([82.5, 77.5], $level['ז1']);
        self::assertSame([null, 40.0], $level['ז2']);

        $subject = $this->rowsByFirstColumn($this->build('achievements_subject', ['subject_id' => $this->math]));
        self::assertSame([77.5, 3], $subject['ז1']);
        self::assertSame([68.1, 4], $subject[__('reports.rows.total')]);

        $this->assessment($this->mathA, $this->term2, [1 => 67.5]);
        $progress = $this->rowsByFirstColumn($this->build('student_progress', ['student_id' => $this->s[1]]));
        self::assertSame([87.5, 67.5], $progress['Mathematics']);
        self::assertSame([70.0, null], $progress['Hebrew']);
        $comparison = $this->rowsByFirstColumn($this->build('term_comparison'));
        self::assertSame([68.1, 67.5, -0.6], $comparison['Mathematics']);
    }

    public function testRequiredFiltersAndForeignValues(): void
    {
        $this->loginAs($this->principal);
        self::assertSame([], $this->build('achievements_class')['columns'], 'No class chosen → no report.');
        $other = $this->createSchool();
        $foreignClass = $this->createClass($other, $this->createYear($other), $this->createLevel($other, 'ז', 7), 'x');
        self::assertSame([], $this->build('achievements_class', ['class_id' => $foreignClass])['columns'], 'A class of another school is not a valid choice.');
        $page = $this->get('/reports/achievements_class', ['class_id' => $foreignClass]);
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('Student', $page->body);
    }

    public function testReportsFollowTheReadersScopeAndPermissions(): void
    {
        // A subject teacher has no reports permission at all.
        $this->loginAs($this->mathUser);
        self::assertSame(403, $this->get('/reports')->status);
        self::assertSame(403, $this->get('/reports/achievements_school')->status);

        // The homeroom teacher: only ז1, viewing only.
        $this->loginAs($this->homeroomUser);
        self::assertSame(200, $this->get('/reports/achievements_class', ['class_id' => $this->classA])->status);
        self::assertSame([], $this->build('achievements_class', ['class_id' => $this->classB])['columns']);
        $school = $this->rowsByFirstColumn($this->build('achievements_school', ['term_id' => $this->term1]));
        self::assertSame([81.3, 3], $school[__('reports.rows.school')], 'Built from ז1 alone: (78.75 + 75 + 90) ÷ 3.');
        self::assertSame(403, $this->get('/reports/achievements_class', ['class_id' => $this->classA, 'format' => 'xlsx'])->status);
        self::assertSame(403, $this->get('/reports/work_plan', ['format' => 'pdf'])->status);
        self::assertSame(0, $this->auditCount('export', 'report'));

        // The read-only role gets the work-plan report and nothing about students.
        $this->loginAs($this->createUser('p.viewer', [['viewer', $this->school]]));
        $index = $this->get('/reports')->body;
        self::assertStringContainsString('/reports/work_plan', $index);
        self::assertStringNotContainsString('/reports/achievements_school', $index);
        self::assertSame(403, $this->get('/reports/achievements_school')->status);
        self::assertSame(403, $this->get('/reports/support_needed')->status);

        // A coordinator with a restricted scope sees only their own tasks in the tasks report.
        $coordinator = $this->createUser('p.coord', [['subject_coordinator', $this->school]]);
        App::db()->insert('tasks', ['school_id' => $this->school, 'title' => 'TASK-FOR-COORD', 'assignee_user_id' => $coordinator, 'assigned_by' => $this->principal]);
        App::db()->insert('tasks', ['school_id' => $this->school, 'title' => 'TASK-FOR-OTHER', 'assignee_user_id' => $this->mathUser, 'assigned_by' => $this->principal]);
        $this->loginAs($coordinator);
        $body = $this->get('/reports/tasks')->body;
        self::assertStringContainsString('TASK-FOR-COORD', $body);
        self::assertStringNotContainsString('TASK-FOR-OTHER', $body);
    }

    public function testOtherReportsAreBuiltFromRealRecords(): void
    {
        $this->lesson($this->mathA, '-3 days', 1, [1 => 'absent', 2 => 'late']);
        $this->lesson($this->mathA, '-2 days', 1, [1 => 'justified']);
        $criterion = App::db()->insert('risk_criteria', ['school_id' => $this->school, 'name' => 'Low average', 'criterion_type' => 'low_average', 'threshold' => 60]);
        App::db()->insert('risk_indications', ['school_id' => $this->school, 'student_id' => $this->s[2], 'criterion_id' => $criterion, 'term_id' => $this->term1, 'evidence' => '{}']);
        App::db()->insert('risk_indications', ['school_id' => $this->school, 'student_id' => $this->s[1], 'criterion_id' => $criterion, 'term_id' => $this->term1, 'evidence' => '{}', 'review_status' => 'dismissed']);
        $this->loginAs($this->principal);
        $this->post('/work-plan/objectives', ['title' => 'Objective']);
        $objective = (int) App::db()->value('SELECT id FROM plan_objectives');
        $this->post('/goals', ['title' => 'Goal one', 'plan_objective_id' => $objective, 'status' => 'in_progress', 'start_date' => date('Y-m-d', strtotime('-30 days')), 'end_date' => date('Y-m-d', strtotime('-1 day'))]);

        $attendance = $this->rowsByFirstColumn($this->build('attendance', ['class_id' => $this->classA]));
        self::assertSame([2, 1, 1, 0, 0.0], $attendance['Student1 S']);     // two lessons, missed both (one justified)
        self::assertSame([2, 0, 0, 1, 100.0], $attendance['Student2 S']);   // late counts as present

        $support = $this->build('support_needed');
        self::assertCount(1, $support['rows'], 'Dismissed indications are not listed.');
        self::assertSame(['Student2 S', 'ז1', 'Low average'], array_slice($support['rows'][0], 0, 3));

        $plan = $this->build('work_plan');
        self::assertSame(['Objective', 'Goal one'], array_slice($plan['rows'][0], 0, 2));
        self::assertSame(__('goals.statuses.delayed'), $plan['rows'][0][4], 'Past its end date and not completed.');
    }

    public function testExcelExportIsAFileOfPlainValues(): void
    {
        // A name that looks like a formula must arrive in the sheet as text.
        App::db()->update('students', ['last_name' => '=HYPERLINK("http://evil.example","x")'], ['id' => $this->s[3]]);
        $this->loginAs($this->principal);
        $response = $this->get('/reports/achievements_class', ['class_id' => $this->classA, 'term_id' => $this->term1, 'format' => 'xlsx']);

        self::assertSame(200, $response->status);
        self::assertStringStartsWith('attachment;', $response->headers['Content-Disposition']);
        self::assertStringContainsString('achievements_class_', $response->headers['Content-Disposition']);
        self::assertSame('PK', substr((string) file_get_contents($response->filePath), 0, 2), 'A real xlsx (zip) file.');
        self::assertSame(1, $this->auditCount('export', 'report'));
        $log = json_decode((string) App::db()->value("SELECT new_values FROM audit_logs WHERE action = 'export' AND entity_type = 'report'"), true);
        self::assertSame(['achievements_class', 'xlsx', 3], [$log['report'], $log['format'], $log['rows']]);

        if (!extension_loaded('zip')) {
            self::markTestIncomplete('zip extension not loaded: the file was produced but its cells were not re-read.');
        }
        $sheet = IOFactory::load($response->filePath)->getActiveSheet();
        self::assertTrue($sheet->getRightToLeft());
        $cells = $sheet->toArray(null, false, false, false);
        $flat = array_merge(...$cells);
        self::assertContains(__('reports.names.achievements_class'), $flat);
        self::assertContains(87.5, $flat, 'Numbers are numeric cells.');
        $formulaLike = array_values(array_filter($flat, static fn ($v) => is_string($v) && str_starts_with($v, '=HYPERLINK')));
        self::assertCount(1, $formulaLike, 'Stored as the literal text…');
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                self::assertNotSame('f', $cell->getDataType(), '…never as a formula cell.');
            }
        }
    }

    public function testPdfAndPrintOutputs(): void
    {
        $this->loginAs($this->principal);
        $pdf = $this->get('/reports/achievements_school', ['term_id' => $this->term1, 'format' => 'pdf']);
        self::assertSame(200, $pdf->status);
        self::assertSame('application/pdf', $pdf->headers['Content-Type']);
        self::assertSame('%PDF', substr((string) file_get_contents($pdf->filePath), 0, 4));
        self::assertGreaterThan(5000, filesize($pdf->filePath));

        $print = $this->get('/reports/achievements_school', ['term_id' => $this->term1, 'format' => 'print']);
        self::assertSame(200, $print->status);
        self::assertStringContainsString('70.9', $print->body);
        self::assertStringNotContainsString('app-sidebar', $print->body, 'The print layout has no navigation.');

        // Nothing to export without the required filter.
        $this->assertRedirect('/reports/achievements_class', $this->get('/reports/achievements_class', ['format' => 'xlsx']));
    }

    public function testDashboardFiguresAndChartsFollowFiltersAndScope(): void
    {
        $this->loginAs($this->principal);
        $body = $this->get('/')->body;
        self::assertStringContainsString('70.9', $body);
        self::assertStringContainsString('class="js-chart"', $body);
        self::assertStringContainsString('chart.umd.js', $body);
        self::assertMatchesRegularExpression('/&quot;labels&quot;:\[&quot;Hebrew&quot;,&quot;Mathematics&quot;\],&quot;values&quot;:\[82\.5,68\.1\]/', $body);
        // The same numbers are available as a table next to each chart.
        self::assertStringContainsString('<details', $body);

        $filtered = $this->get('/', ['class_id' => $this->classB])->body;
        self::assertMatchesRegularExpression('/kpi-value">40</', $filtered);
        self::assertStringNotContainsString('70.9', $filtered);
        // No names of students anywhere on the dashboard.
        self::assertStringNotContainsString('Student1', $body);

        $this->loginAs($this->homeroomUser);
        $body = $this->get('/')->body;
        self::assertStringContainsString('81.3', $body);
        self::assertStringNotContainsString('70.9', $body);
        // Other pages do not load the chart library.
        self::assertStringNotContainsString('chart.umd.js', $this->get('/students')->body);
    }

    public function testEveryReportRendersInBothLanguages(): void
    {
        $this->loginAs($this->principal);
        $filters = ['term_id' => $this->term1, 'grade_level_id' => $this->level, 'class_id' => $this->classA, 'subject_id' => $this->math, 'student_id' => $this->s[1]];
        foreach (['he', 'ar'] as $locale) {
            $this->post('/locale', ['locale' => $locale]);
            $index = $this->get('/reports');
            self::assertSame(200, $index->status);
            foreach (array_keys(Reports::ALL) as $key) {
                self::assertStringContainsString("/reports/$key", $index->body);
                foreach ([[], $filters, $filters + ['format' => 'print']] as $query) {
                    $response = $this->get("/reports/$key", $query);
                    self::assertSame(200, $response->status, "$locale $key");
                    self::assertDoesNotMatchRegularExpression(
                        '/[>"]\s*(app|nav|reports|dashboard|achievements|attendance|risk|goals|tasks|meetings|curriculum)\.[a-z_.]+\s*[<"]/',
                        $response->body,
                        "$locale $key shows a raw translation key"
                    );
                }
            }
        }
    }
}
