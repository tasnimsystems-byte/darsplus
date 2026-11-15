<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\AccessScope;
use App\Services\GradeStats;
use Tests\PedagogyFixture;
use Tests\TestCase;

final class AssessmentTest extends TestCase
{
    use PedagogyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function stats(array $filters = []): GradeStats
    {
        return new GradeStats(App::db(), $this->school, $this->year, ['1 = 1', []], $filters);
    }

    /** Worked example used by several tests; expected figures are computed by hand in the comments. */
    private function seedWorkedExample(): void
    {
        // Math, term 1: exam out of 100 with weight 3, quiz out of 50 with weight 1.
        $this->assessment($this->mathA, $this->term1, [1 => 90, 2 => 60, 3 => 'absent'], 100, 3);
        $this->assessment($this->mathA, $this->term1, [1 => 40, 2 => 20, 3 => 45], 50, 1);
        // s1: (90×3 + 80×1) ÷ 4 = 87.5     s2: (60×3 + 40×1) ÷ 4 = 55     s3: 90 (absent from the exam → only the quiz)
    }

    public function testWeightedAveragesMatchHandCalculation(): void
    {
        $this->seedWorkedExample();
        $stats = $this->stats();

        $averages = $stats->studentAverages($this->term1);
        self::assertEqualsWithDelta(87.5, $averages[$this->s[1]], 0.001);
        self::assertEqualsWithDelta(55.0, $averages[$this->s[2]], 0.001);
        self::assertEqualsWithDelta(90.0, $averages[$this->s[3]], 0.001, 'An absence is left out, not counted as zero.');

        // Class average = mean of the three student averages = (87.5 + 55 + 90) ÷ 3 = 77.5
        self::assertSame(['average' => 77.5, 'students' => 3], $stats->overall($this->term1));
        self::assertSame(77.5, $stats->by('class_id')[$this->classA]['average']);
        self::assertSame(77.5, $stats->by('subject_id')[$this->math]['average']);
        // Bands [0–55) [55–70) [70–85) [85–100]: 55 → second band; 87.5 and 90 → fourth.
        self::assertSame([0, 1, 0, 2], $stats->distribution($this->term1));
    }

    public function testDraftsVerbalDeletedAndOtherYearsAreExcluded(): void
    {
        $this->seedWorkedExample();
        $this->assessment($this->mathA, $this->term1, [1 => 0, 2 => 0, 3 => 0], 100, 5, 'draft');
        $this->assessment($this->mathA, $this->term1, [1 => 0], 100, 5, 'published', $this->verbalType);
        $deleted = $this->assessment($this->mathA, $this->term1, [1 => 0, 2 => 0], 100, 5);
        App::db()->update('assessments', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $deleted]);
        $this->assessment($this->mathA, $this->term1, [2 => 'exempt'], 100, 9);

        self::assertSame(77.5, $this->stats()->overall($this->term1)['average']);
    }

    public function testStudentAverageIsMeanOfSubjectsAndGroupsAreMeansOfStudents(): void
    {
        $this->seedWorkedExample();
        $this->assessment($this->hebrewA, $this->term1, [1 => 70, 2 => 95]);   // hebrew: s1 70, s2 95
        $this->assessment($this->mathB, $this->term1, [4 => 40]);              // class ז2: s4 40

        $stats = $this->stats();
        $averages = $stats->studentAverages($this->term1);
        self::assertEqualsWithDelta((87.5 + 70) / 2, $averages[$this->s[1]], 0.001);   // 78.75
        self::assertEqualsWithDelta((55 + 95) / 2, $averages[$this->s[2]], 0.001);     // 75
        // School: (78.75 + 75 + 90 + 40) ÷ 4 = 70.9375 → 70.9
        self::assertSame(70.9, $stats->overall($this->term1)['average']);
        // Math across both classes: (87.5 + 55 + 90 + 40) ÷ 4 = 68.125 → 68.1; hebrew: (70 + 95) ÷ 2 = 82.5
        self::assertSame(68.1, $stats->by('subject_id')[$this->math]['average']);
        self::assertSame(82.5, $stats->by('subject_id')[$this->hebrew]['average']);
        self::assertSame(40.0, $stats->by('class_id')[$this->classB]['average']);
        // Filters narrow the same calculation.
        self::assertSame(82.5, $this->stats(['subject_id' => $this->hebrew])->overall()['average']);
        self::assertSame(1, $this->stats(['class_id' => $this->classB])->overall()['students']);
    }

    public function testTrendBetweenTerms(): void
    {
        $this->assessment($this->mathA, $this->term1, [1 => 80, 2 => 70, 3 => 60]);
        $this->assessment($this->mathA, $this->term2, [1 => 65, 2 => 75]);
        $trend = $this->stats()->trend($this->term1, $this->term2);
        self::assertSame(['previous' => 80.0, 'current' => 65.0, 'change' => -15.0], $trend[$this->s[1]]);
        self::assertSame(5.0, $trend[$this->s[2]]['change']);
        self::assertArrayNotHasKey($this->s[3], $trend, 'No trend without a figure in both terms.');
        self::assertSame([$this->term1 => 70.0, $this->term2 => 70.0], $this->stats()->subjectByTerm()[$this->math]);
    }

    public function testTeacherCreatesAssessmentOnlyForOwnAssignment(): void
    {
        $this->loginAs($this->mathUser);
        $form = ['title' => 'מבחן שברים', 'term_id' => $this->term1, 'type_id' => $this->numericType,
            'assessment_date' => date('Y-m-d', strtotime('-30 days')), 'max_score' => '100', 'weight' => '2,5'];

        $this->post('/assessments', $form + ['teaching_assignment_id' => $this->hebrewA]);
        self::assertArrayHasKey('teaching_assignment_id', $this->session->pullFlash('errors'), 'Another teacher\'s assignment is not selectable.');
        $this->post('/assessments', $form + ['teaching_assignment_id' => $this->mathB]);
        self::assertArrayHasKey('teaching_assignment_id', $this->session->pullFlash('errors'));
        $this->post('/assessments', ['assessment_date' => '2020-01-01', 'max_score' => '0'] + $form + ['teaching_assignment_id' => $this->mathA]);
        self::assertSame(['assessment_date', 'max_score'], array_keys($this->session->pullFlash('errors')));

        $response = $this->post('/assessments', $form + ['teaching_assignment_id' => $this->mathA]);
        $row = App::db()->one("SELECT * FROM assessments WHERE title = 'מבחן שברים'");
        $this->assertRedirect('/assessments/' . $row['id'], $response);
        self::assertSame(['draft', '2.50', $this->year], [$row['status'], $row['weight'], (int) $row['academic_year_id']]);
    }

    public function testGradeSheetSavesValidatesAndLogsCorrections(): void
    {
        $id = $this->assessment($this->mathA, $this->term1, [], 50, 1, 'draft');
        $this->loginAs($this->mathUser);
        $sheet = $this->get("/assessments/$id");
        self::assertSame(200, $sheet->status);
        self::assertStringContainsString('Student1', $sheet->body);
        self::assertStringNotContainsString('Student4', $sheet->body, 'Only the class roster is listed.');
        self::assertStringContainsString('Mathematics', $sheet->body);
        self::assertStringContainsString('Term 1', $sheet->body);

        $this->assertRedirect("/assessments/$id", $this->post("/assessments/$id/results", ['results' => [
            $this->s[1] => ['score' => '45,5', 'status' => 'graded', 'comment' => 'יפה'],
            $this->s[2] => ['score' => '51', 'status' => 'graded'],          // above the maximum
            $this->s[3] => ['score' => '', 'status' => 'absent'],
            $this->s[4] => ['score' => '10', 'status' => 'graded'],          // not in this class
        ]]));
        self::assertNotNull($this->session->pullFlash('error'), 'The invalid score is reported.');
        $rows = App::db()->all('SELECT student_id, score, result_status, comment FROM assessment_results WHERE assessment_id = ? ORDER BY student_id', [$id]);
        self::assertSame([
            ['student_id' => $this->s[1], 'score' => '45.5', 'result_status' => 'graded', 'comment' => 'יפה'],
            ['student_id' => $this->s[3], 'score' => null, 'result_status' => 'absent', 'comment' => null],
        ], $rows);
        self::assertSame(1, $this->auditCount('update', 'assessment'));

        // Publishing, then changing a score, is recorded as a correction.
        $this->post("/assessments/$id/publish");
        self::assertSame('published', App::db()->value('SELECT status FROM assessments WHERE id = ?', [$id]));
        $this->post("/assessments/$id/results", ['results' => [$this->s[1] => ['score' => '40', 'status' => 'graded', 'comment' => 'יפה']]]);
        self::assertSame(1, $this->auditCount('correct_results', 'assessment'));
        // Saving the same values again changes nothing and logs nothing.
        $this->post("/assessments/$id/results", ['results' => [$this->s[1] => ['score' => '40', 'status' => 'graded', 'comment' => 'יפה']]]);
        self::assertSame(1, $this->auditCount('correct_results', 'assessment'));

        // The maximum cannot drop below an entered score.
        $this->post("/assessments/$id", ['title' => 'x', 'teaching_assignment_id' => $this->mathA, 'term_id' => $this->term1,
            'type_id' => $this->numericType, 'assessment_date' => date('Y-m-d', strtotime('-30 days')), 'max_score' => '30', 'weight' => '1']);
        self::assertArrayHasKey('max_score', $this->session->pullFlash('errors'));
    }

    public function testVerbalAssessmentStoresTextNotScore(): void
    {
        $id = $this->assessment($this->mathA, $this->term1, [], 100, 1, 'draft', $this->verbalType);
        $this->loginAs($this->mathUser);
        $this->post("/assessments/$id/results", ['results' => [$this->s[1] => ['verbal' => 'מתקדם יפה', 'score' => '99', 'status' => 'graded']]]);
        self::assertSame(['score' => null, 'verbal_evaluation' => 'מתקדם יפה'], App::db()->one('SELECT score, verbal_evaluation FROM assessment_results WHERE assessment_id = ?', [$id]));
    }

    public function testWhoSeesAnAssessment(): void
    {
        $draft = $this->assessment($this->mathA, $this->term1, [1 => 80], 100, 1, 'draft');
        $published = $this->assessment($this->mathA, $this->term1, [1 => 80]);
        $expect = [
            // user => [draft, published, may edit]
            'mathUser' => [200, 200, true],        // the teacher of the assignment
            'principal' => [200, 200, true],       // sees the whole school
            'homeroomUser' => [404, 200, false],   // sees all subjects of the class, once published
            'hebrewUser' => [404, 404, false],     // teaches the same class, another subject
            'otherMathUser' => [404, 404, false],  // teaches the same subject, another class
        ];
        foreach ($expect as $user => [$draftStatus, $publishedStatus, $mayEdit]) {
            $this->loginAs($this->$user);
            self::assertSame($draftStatus, $this->get("/assessments/$draft")->status, "$user draft");
            self::assertSame($publishedStatus, $this->get("/assessments/$published")->status, "$user published");
            $save = $this->post("/assessments/$published/results", ['results' => [$this->s[1] => ['score' => '10', 'status' => 'graded']]]);
            self::assertSame($mayEdit, $save->isRedirect(), "$user save");
            $list = $this->get('/assessments')->body;
            self::assertSame($publishedStatus === 200, str_contains($list, "/assessments/$published\""), "$user list");
            self::assertSame($draftStatus === 200, str_contains($list, "/assessments/$draft\""), "$user list draft");
        }
        // The two users allowed to edit both wrote 10; nobody else changed anything.
        self::assertSame('10.0', App::db()->value('SELECT score FROM assessment_results WHERE assessment_id = ?', [$published]));
    }

    public function testAssessmentOfAnotherSchoolIsUnreachable(): void
    {
        $id = $this->assessment($this->mathA, $this->term1, [1 => 80]);
        $other = $this->createSchool();
        $this->createYear($other);
        $this->loginAs($this->createUser('x.principal', [['principal', $other]]));
        self::assertSame(404, $this->get("/assessments/$id")->status);
        self::assertSame(404, $this->post("/assessments/$id/publish")->status);
        self::assertSame(404, $this->post("/assessments/$id/delete")->status);
    }

    public function testAnalysisPageRespectsScope(): void
    {
        $this->seedWorkedExample();
        $this->assessment($this->hebrewA, $this->term1, [1 => 70, 2 => 95]);
        $this->assessment($this->mathB, $this->term1, [4 => 40]);

        $this->loginAs($this->principal);
        $body = $this->get('/achievements', ['term_id' => $this->term1])->body;
        self::assertStringContainsString('70.9', $body);
        self::assertStringContainsString('Hebrew', $body);

        // The hebrew teacher's analysis is built from hebrew alone.
        $this->loginAs($this->hebrewUser);
        $body = $this->get('/achievements', ['term_id' => $this->term1])->body;
        self::assertStringContainsString('82.5', $body);
        self::assertStringNotContainsString('70.9', $body);
        self::assertStringNotContainsString('68.1', $body);
        $scope = new AccessScope(App::db(), App::auth());
        $own = new GradeStats(App::db(), $this->school, $this->year, $scope->assignmentCondition('ta'));
        self::assertSame([$this->hebrew], array_keys($own->by('subject_id')));

        // The homeroom teacher sees both subjects of ז1, and nothing of ז2.
        $this->loginAs($this->homeroomUser);
        $scope = new AccessScope(App::db(), App::auth());
        $own = new GradeStats(App::db(), $this->school, $this->year, $scope->assignmentCondition('ta'));
        self::assertSame([$this->classA], array_keys($own->by('class_id')));
        self::assertCount(2, $own->by('subject_id'));
        $body = $this->get('/achievements', ['class_id' => $this->classA, 'term_id' => $this->term1])->body;
        self::assertStringContainsString('Student1', $body);
        // A class outside the scope is not an accepted filter value.
        self::assertStringNotContainsString('Student4', $this->get('/achievements', ['class_id' => $this->classB])->body);
    }

    public function testStudentCardShowsOnlyResultsInsideTheViewersScope(): void
    {
        $math = $this->assessment($this->mathA, $this->term1, [1 => 91]);
        App::db()->update('assessments', ['title' => 'MathOnlyExam'], ['id' => $math]);
        $hebrew = $this->assessment($this->hebrewA, $this->term1, [1 => 72]);
        App::db()->update('assessments', ['title' => 'HebrewOnlyExam'], ['id' => $hebrew]);
        $card = '/students/' . $this->s[1];

        $this->loginAs($this->hebrewUser);
        $body = $this->get($card)->body;
        self::assertStringContainsString('HebrewOnlyExam', $body);
        self::assertStringNotContainsString('MathOnlyExam', $body);

        $this->loginAs($this->homeroomUser);
        $body = $this->get($card)->body;
        self::assertStringContainsString('HebrewOnlyExam', $body);
        self::assertStringContainsString('MathOnlyExam', $body);
        // Each result is shown with its subject, term and type.
        self::assertStringContainsString('Mathematics', $body);
        self::assertStringContainsString('Term 1', $body);
        self::assertStringContainsString('Exam', $body);
    }

    public function testLockedYearIsReadOnly(): void
    {
        $id = $this->assessment($this->mathA, $this->term1, [1 => 80], 100, 1, 'draft');
        App::db()->update('academic_years', ['is_locked' => 1], ['id' => $this->year]);
        $this->loginAs($this->principal);
        $this->post("/assessments/$id/results", ['results' => [$this->s[1] => ['score' => '10', 'status' => 'graded']]]);
        $this->post("/assessments/$id/publish");
        self::assertSame(['80.0', 'draft'], [
            App::db()->value('SELECT score FROM assessment_results WHERE assessment_id = ?', [$id]),
            App::db()->value('SELECT status FROM assessments WHERE id = ?', [$id]),
        ]);
    }
}
