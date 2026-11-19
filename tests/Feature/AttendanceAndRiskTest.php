<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\AttendanceStats;
use App\Services\RiskIndicatorService;
use Tests\PedagogyFixture;
use Tests\TestCase;

final class AttendanceAndRiskTest extends TestCase
{
    use PedagogyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function everything(): array
    {
        return ['1 = 1', []];
    }

    // ---- Attendance ---------------------------------------------------------------

    public function testTeacherOpensLessonForOwnAssignmentOnly(): void
    {
        $this->loginAs($this->mathUser);
        $today = date('Y-m-d');
        $form = ['lesson_date' => $today, 'period_number' => 2];

        $this->post('/attendance/sessions', $form + ['teaching_assignment_id' => $this->hebrewA]);
        self::assertArrayHasKey('teaching_assignment_id', $this->session->pullFlash('errors'));
        $this->post('/attendance/sessions', ['lesson_date' => date('Y-m-d', strtotime('+1 day'))] + $form + ['teaching_assignment_id' => $this->mathA]);
        self::assertArrayHasKey('lesson_date', $this->session->pullFlash('errors'), 'A lesson cannot be reported in advance.');
        $this->post('/attendance/sessions', ['period_number' => 99] + $form + ['teaching_assignment_id' => $this->mathA]);
        self::assertArrayHasKey('period_number', $this->session->pullFlash('errors'));

        $first = $this->post('/attendance/sessions', $form + ['teaching_assignment_id' => $this->mathA]);
        $id = (int) App::db()->value('SELECT id FROM lesson_sessions');
        $this->assertRedirect("/attendance/sessions/$id", $first);
        // Opening the same lesson again leads to the existing one.
        $this->assertRedirect("/attendance/sessions/$id", $this->post('/attendance/sessions', $form + ['teaching_assignment_id' => $this->mathA]));
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM lesson_sessions'));
        self::assertStringContainsString("/attendance/sessions/$id", $this->get('/attendance', ['date' => $today])->body);
    }

    public function testOnlyExceptionsAreStored(): void
    {
        $id = $this->lesson($this->mathA, 'today', 1);
        $this->loginAs($this->mathUser);
        $page = $this->get("/attendance/sessions/$id");
        self::assertStringContainsString('Student3', $page->body);
        self::assertStringNotContainsString('Student4', $page->body);

        $this->assertRedirect("/attendance/sessions/$id", $this->post("/attendance/sessions/$id", ['topic' => 'שבר פשוט', 'students' => [
            $this->s[1] => ['status' => 'absent', 'note' => 'ללא הודעה'],
            $this->s[2] => ['status' => 'late'],
            $this->s[3] => ['status' => 'present'],
            $this->s[4] => ['status' => 'absent'],   // not in this class → ignored
        ]]));
        self::assertSame(
            [[$this->s[1], 'absent', 'ללא הודעה'], [$this->s[2], 'late', null]],
            array_map('array_values', App::db()->all('SELECT student_id, status, note FROM attendance_records ORDER BY student_id'))
        );
        // Marking the student present again removes the row.
        $this->post("/attendance/sessions/$id", ['topic' => 'שבר פשוט', 'students' => [$this->s[1] => ['status' => 'present'], $this->s[2] => ['status' => 'justified']]]);
        self::assertSame([[$this->s[2], 'justified']], array_map('array_values', App::db()->all('SELECT student_id, status FROM attendance_records')));
    }

    public function testAttendanceFiguresMatchHandCalculation(): void
    {
        // Two held math lessons and one cancelled; hebrew: one held lesson.
        $this->lesson($this->mathA, '-3 days', 1, [1 => 'absent', 2 => 'late']);
        $this->lesson($this->mathA, '-2 days', 1, [1 => 'justified']);
        $this->lesson($this->mathA, '-1 days', 1, [1 => 'absent', 2 => 'absent', 3 => 'absent'], 'cancelled');
        $this->lesson($this->hebrewA, '-2 days', 2, [3 => 'absent']);

        $rows = array_column(AttendanceStats::byStudent(App::db(), $this->classA, $this->everything()), null, 'last_name');
        // Three held lessons for the class. s1 missed two (one justified) → 1/3 present = 33.3%.
        self::assertSame([3, 1, 1, 0, 33.3], [$rows['Student1']['sessions'], $rows['Student1']['absent'], $rows['Student1']['justified'], $rows['Student1']['late'], $rows['Student1']['rate']]);
        self::assertSame([0, 1, 100.0], [$rows['Student2']['absent'], $rows['Student2']['late'], $rows['Student2']['rate']], 'Late counts as present.');
        self::assertSame(66.7, $rows['Student3']['rate']);
        // Whole class: 3 lessons × 3 students = 9 expected, 3 missed → 66.7%.
        self::assertSame(66.7, AttendanceStats::rate(App::db(), $this->school, $this->year, $this->everything()));
        self::assertNull(AttendanceStats::rate(App::db(), $this->school, $this->year, ['ta.class_id = ?', [$this->classB]]), 'No lessons → no rate, not 100%.');
        self::assertSame([$this->s[1] => 1, $this->s[3] => 1], AttendanceStats::unjustifiedAbsences(App::db(), $this->school, date('Y-m-d', strtotime('-10 days')), date('Y-m-d')));
    }

    public function testAttendanceVisibilityFollowsScope(): void
    {
        $math = $this->lesson($this->mathA, '-1 days', 1, [1 => 'absent']);
        $this->lesson($this->hebrewA, '-1 days', 2, [2 => 'absent']);

        $this->loginAs($this->hebrewUser);
        self::assertSame(404, $this->get("/attendance/sessions/$math")->status);
        $report = $this->get('/attendance/report', ['class_id' => $this->classA])->body;
        self::assertSame(200, $this->get('/attendance/report')->status);
        self::assertMatchesRegularExpression('/Student1.*?<td>1<\/td>\s*<td>0<\/td>/s', $report, 'Only the hebrew lesson is counted: one lesson, no absence for s1.');

        $this->loginAs($this->homeroomUser);
        self::assertSame(200, $this->get("/attendance/sessions/$math")->status);
        self::assertSame(403, $this->post("/attendance/sessions/$math", ['students' => [$this->s[1] => ['status' => 'present']]])->status, 'Viewing is not recording.');
        self::assertSame(403, $this->post("/attendance/sessions/$math/cancel")->status);
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM attendance_records WHERE lesson_session_id = ?', [$math]));

        $other = $this->createSchool();
        $this->createYear($other);
        $this->loginAs($this->createUser('x.principal', [['principal', $other]]));
        self::assertSame(404, $this->get("/attendance/sessions/$math")->status);
    }

    // ---- Early identification -------------------------------------------------------

    private function criterion(string $type, ?float $threshold, ?int $count = null): int
    {
        return App::db()->insert('risk_criteria', [
            'school_id' => $this->school, 'name' => "Criterion $type", 'criterion_type' => $type, 'threshold' => $threshold, 'min_count' => $count,
        ]);
    }

    private function detect(int $term): array
    {
        $year = App::db()->one('SELECT * FROM academic_years WHERE id = ?', [$this->year]);
        return (new RiskIndicatorService(App::db()))->run($this->school, $year, App::db()->one('SELECT * FROM academic_terms WHERE id = ?', [$term]));
    }

    private function indications(): array
    {
        $rows = [];
        foreach (App::db()->all('SELECT ri.*, rc.criterion_type FROM risk_indications ri JOIN risk_criteria rc ON rc.id = ri.criterion_id ORDER BY ri.id') as $row) {
            $rows[$row['criterion_type'] . ':' . array_search((int) $row['student_id'], $this->s, true)] = json_decode($row['evidence'], true) + ['_status' => $row['review_status'], '_id' => (int) $row['id']];
        }
        return $rows;
    }

    public function testCriteriaProduceIndicationsWithTheirEvidence(): void
    {
        $this->criterion('low_average', 60);
        $this->criterion('decline', 10);
        $this->criterion('multi_subject', 60, 2);
        $this->criterion('absences', null, 2);
        // Term 1: math s1 90, s2 50, s3 58 · hebrew s1 85, s2 55, s3 70.   Term 2: math s1 75, s2 52.
        $this->assessment($this->mathA, $this->term1, [1 => 90, 2 => 50, 3 => 58]);
        $this->assessment($this->hebrewA, $this->term1, [1 => 85, 2 => 55, 3 => 70]);
        $this->assessment($this->mathA, $this->term2, [1 => 75, 2 => 52]);
        $this->lesson($this->mathA, '-40 days', 1, [3 => 'absent']);
        $this->lesson($this->mathA, '-39 days', 1, [3 => 'absent', 1 => 'justified']);

        self::assertSame(['created' => 3, 'updated' => 0, 'removed' => 0], $this->detect($this->term1));
        $found = $this->indications();
        self::assertSame(['low_average:2', 'multi_subject:2', 'absences:3'], array_keys($found));
        self::assertSame(52.5, $found['low_average:2']['average'], 's2: (50 + 55) ÷ 2');
        self::assertSame(60.0, (float) $found['low_average:2']['threshold']);
        self::assertSame(['Mathematics', 'Hebrew'], array_column($found['multi_subject:2']['subjects'], 'subject'));
        self::assertSame(2, $found['absences:3']['absences'], 'Justified absences do not count.');

        // Term 2 against term 1: s1 fell from 87.5 to 75 (−12.5); s2 has 52 < 60.
        $this->detect($this->term2);
        $found = $this->indications();
        self::assertEquals(['previous' => 87.5, 'current' => 75.0, 'change' => -12.5], array_slice($found['decline:1'], 0, 3, true));
        self::assertArrayHasKey('low_average:2', $found);
    }

    public function testRerunIsIdempotentAndNeverTouchesHumanDecisions(): void
    {
        $this->criterion('low_average', 60);
        $low = $this->assessment($this->mathA, $this->term1, [1 => 40, 2 => 50]);
        $this->detect($this->term1);
        self::assertSame(['created' => 0, 'updated' => 2, 'removed' => 0], $this->detect($this->term1));

        // s1 is reviewed by a person; s2 stays new.
        $found = $this->indications();
        App::db()->update('risk_indications', ['review_status' => 'monitoring', 'reviewed_by' => $this->principal, 'review_note' => 'בשיחה עם המחנך'], ['id' => $found['low_average:1']['_id']]);
        // New grades lift both students above the threshold.
        App::db()->run('UPDATE assessment_results SET score = 90 WHERE assessment_id = ?', [$low]);

        self::assertSame(['created' => 0, 'updated' => 0, 'removed' => 1], $this->detect($this->term1));
        $found = $this->indications();
        self::assertSame(['low_average:1'], array_keys($found), 'The unreviewed one is removed; the reviewed one is kept as it was.');
        self::assertSame(40.0, (float) $found['low_average:1']['average'], 'Evidence of a reviewed indication is frozen.');
        self::assertSame('monitoring', $found['low_average:1']['_status']);
    }

    public function testInactiveCriteriaAndInactiveStudentsAreSkipped(): void
    {
        $inactive = $this->criterion('low_average', 99);
        App::db()->update('risk_criteria', ['is_active' => 0], ['id' => $inactive]);
        $this->criterion('multi_subject', 99, 1);
        $this->assessment($this->mathA, $this->term1, [1 => 40, 2 => 50]);
        App::db()->update('students', ['status' => 'left'], ['id' => $this->s[2]]);
        $this->detect($this->term1);
        self::assertSame(['multi_subject:1'], array_keys($this->indications()));
    }

    public function testIndicationsAreShownOnlyToThoseWhoSeeTheWholeClass(): void
    {
        $this->criterion('low_average', 60);
        $this->assessment($this->mathA, $this->term1, [2 => 50]);
        $this->assessment($this->mathB, $this->term1, [4 => 30]);
        $this->detect($this->term1);
        $found = $this->indications();
        $inA = $found['low_average:2']['_id'];
        $inB = $found['low_average:4']['_id'];

        // risk.view is held by subject teachers too, but the evidence spans subjects.
        $this->loginAs($this->mathUser);
        self::assertStringNotContainsString('Student2', $this->get('/risk')->body);
        self::assertSame(404, $this->get("/risk/$inA")->status);

        $this->loginAs($this->homeroomUser);
        $list = $this->get('/risk')->body;
        self::assertStringContainsString('Student2', $list);
        self::assertStringNotContainsString('Student4', $list);
        self::assertSame(200, $this->get("/risk/$inA")->status);
        self::assertSame(404, $this->get("/risk/$inB")->status);
        self::assertSame(404, $this->post("/risk/$inB", ['decision' => 'dismissed', 'review_note' => 'x'])->status);
        self::assertSame(403, $this->post('/risk/run', ['term_id' => $this->term1])->status, 'Running the criteria needs risk.create.');
        self::assertMatchesRegularExpression('/kpi-value">\s*1\s*</', $this->get('/')->body);

        $other = $this->createSchool();
        $this->createYear($other);
        $this->loginAs($this->createUser('x.principal', [['principal', $other]]));
        self::assertSame(404, $this->get("/risk/$inA")->status);
    }

    public function testDecisionIsRecordedByAPersonAndDismissalNeedsAReason(): void
    {
        $this->criterion('low_average', 60);
        $this->assessment($this->mathA, $this->term1, [2 => 50]);
        $this->loginAs($this->principal);
        $this->assertRedirect('/risk?term_id=' . $this->term1, $this->post('/risk/run', ['term_id' => $this->term1]));
        $id = (int) App::db()->value('SELECT id FROM risk_indications');
        // Running creates indications and nothing else.
        self::assertSame(['new', null], array_values(App::db()->one('SELECT review_status, reviewed_by FROM risk_indications WHERE id = ?', [$id])));

        $page = $this->get("/risk/$id")->body;
        self::assertStringContainsString('50', $page);
        self::assertStringContainsString('60', $page);
        self::assertSame(1, $this->auditCount('view', 'risk_indication'));

        $this->post("/risk/$id", ['decision' => 'dismissed']);
        self::assertArrayHasKey('review_note', $this->session->pullFlash('errors'));
        $this->post("/risk/$id", ['decision' => 'expel']);
        self::assertArrayHasKey('decision', $this->session->pullFlash('errors'));
        self::assertSame('new', App::db()->value('SELECT review_status FROM risk_indications WHERE id = ?', [$id]));

        $this->assertRedirect('/risk', $this->post("/risk/$id", ['decision' => 'intervention', 'review_note' => 'נקבעה פגישה עם המחנכת']));
        $row = App::db()->one('SELECT review_status, reviewed_by, review_note, reviewed_at FROM risk_indications WHERE id = ?', [$id]);
        self::assertSame(['intervention', $this->principal, 'נקבעה פגישה עם המחנכת'], [$row['review_status'], (int) $row['reviewed_by'], $row['review_note']]);
        self::assertNotNull($row['reviewed_at']);
        self::assertSame(1, $this->auditCount('review', 'risk_indication'));
    }

    public function testCriteriaScreenValidatesByType(): void
    {
        $this->loginAs($this->principal);
        $this->post('/risk/criteria', ['name' => 'a', 'criterion_type' => 'low_average']);
        self::assertArrayHasKey('threshold', $this->session->pullFlash('errors'));
        $this->post('/risk/criteria', ['name' => 'a', 'criterion_type' => 'absences']);
        self::assertArrayHasKey('min_count', $this->session->pullFlash('errors'));
        $this->post('/risk/criteria', ['name' => 'a', 'criterion_type' => 'low_average', 'threshold' => '140']);
        self::assertArrayHasKey('threshold', $this->session->pullFlash('errors'));
        $this->assertRedirect('/risk/criteria', $this->post('/risk/criteria', ['name' => 'ממוצע מתחת ל-65', 'criterion_type' => 'low_average', 'threshold' => '64,5', 'is_active' => '1']));
        self::assertSame('64.5', App::db()->value("SELECT threshold FROM risk_criteria WHERE name = 'ממוצע מתחת ל-65'"));

        $this->loginAs($this->homeroomUser);
        self::assertSame(403, $this->get('/risk/criteria/create')->status);
    }
}
