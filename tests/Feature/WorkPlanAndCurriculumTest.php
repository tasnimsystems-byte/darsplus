<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\GoalService;
use Tests\PedagogyFixture;
use Tests\TestCase;

final class WorkPlanAndCurriculumTest extends TestCase
{
    use PedagogyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->loginAs($this->principal);
    }

    private function objective(): int
    {
        $this->post('/work-plan/objectives', ['title' => 'שיפור ההישגים במתמטיקה']);
        return (int) App::db()->value('SELECT id FROM plan_objectives ORDER BY id DESC LIMIT 1');
    }

    private function goal(array $overrides = []): int
    {
        $objective = (int) (App::db()->value('SELECT id FROM plan_objectives LIMIT 1') ?? $this->objective());
        $this->post('/goals', $overrides + [
            'title' => 'העלאת הממוצע ב-5 נקודות', 'plan_objective_id' => $objective, 'status' => 'not_started',
            'start_date' => date('Y-m-d', strtotime('-30 days')), 'end_date' => date('Y-m-d', strtotime('+90 days')),
            'baseline_description' => 'ממוצע 68', 'target_description' => 'ממוצע 73',
        ]);
        return (int) App::db()->value('SELECT id FROM annual_goals ORDER BY id DESC LIMIT 1');
    }

    // ---- Work plan ------------------------------------------------------------------

    public function testVisionObjectivesAndApproval(): void
    {
        self::assertSame(200, $this->get('/work-plan')->status);
        $this->assertRedirect('/work-plan', $this->post('/work-plan', ['vision' => 'בית ספר שבו כל תלמיד מתקדם']));
        $plan = App::db()->one('SELECT * FROM work_plans');
        self::assertSame([$this->year, 'בית ספר שבו כל תלמיד מתקדם', 'draft'], [(int) $plan['academic_year_id'], $plan['vision'], $plan['status']]);

        $objective = $this->objective();
        $goal = $this->goal();
        $body = $this->get('/work-plan')->body;
        self::assertStringContainsString('שיפור ההישגים במתמטיקה', $body);
        self::assertStringContainsString('העלאת הממוצע ב-5 נקודות', $body);

        // An objective that has goals cannot be removed.
        $this->post("/work-plan/objectives/$objective/delete");
        self::assertSame('app.errors.in_use', $this->session->pullFlash('error'));

        $this->post('/work-plan/approve');
        self::assertSame(['approved', $this->principal], array_values(App::db()->one('SELECT status, approved_by FROM work_plans')));
        // Approval is a separate permission: the vice principal may edit but not approve.
        $this->loginAs($this->createUser('p.vice', [['vice_principal', $this->school]]));
        self::assertSame(403, $this->post('/work-plan/approve')->status);
        self::assertSame(200, $this->get("/goals/$goal")->status);
    }

    public function testProgressFollowsActionsAndIsLogged(): void
    {
        $goal = $this->goal();
        $this->post("/goals/$goal/actions", ['title' => 'מיפוי פערים', 'owner_user_id' => $this->mathUser, 'due_date' => date('Y-m-d', strtotime('+10 days')), 'budget' => '1500,50']);
        $this->post("/goals/$goal/actions", ['title' => 'קבוצות תגבור', 'due_date' => date('Y-m-d', strtotime('-5 days')), 'start_date' => date('Y-m-d')]);
        self::assertArrayHasKey('due_date', $this->session->pullFlash('errors'), 'Due date before start date.');
        $this->post("/goals/$goal/actions", ['title' => 'קבוצות תגבור']);
        [$first, $second] = array_map('intval', App::db()->column('SELECT id FROM goal_actions ORDER BY id'));
        self::assertSame('1500.50', App::db()->value('SELECT budget FROM goal_actions WHERE id = ?', [$first]));

        $this->post("/goals/$goal/actions/$first/toggle");
        self::assertSame([50, 'in_progress'], array_values(array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, App::db()->one('SELECT progress_percent, status FROM annual_goals WHERE id = ?', [$goal]))));
        $this->post("/goals/$goal/actions/$second/toggle");
        self::assertSame(100, (int) App::db()->value('SELECT progress_percent FROM annual_goals WHERE id = ?', [$goal]));
        $this->post("/goals/$goal/actions/$second/toggle");   // reopened
        self::assertSame(50, (int) App::db()->value('SELECT progress_percent FROM annual_goals WHERE id = ?', [$goal]));

        // A progress report: with actions, the percentage stays derived from them.
        $this->post("/goals/$goal/updates", ['progress_percent' => 90, 'status' => 'needs_intervention', 'note' => 'חסרות שעות תגבור']);
        $update = App::db()->one('SELECT * FROM goal_progress_updates');
        self::assertSame([50, 'needs_intervention', 'חסרות שעות תגבור', $this->principal], [(int) $update['progress_percent'], $update['status'], $update['note'], (int) $update['created_by']]);
        $this->post("/goals/$goal/updates", ['progress_percent' => 150, 'status' => 'done']);
        self::assertSame(['progress_percent', 'status'], array_keys($this->session->pullFlash('errors')));
        $this->post("/goals/$goal/updates", ['progress_percent' => 10, 'status' => 'completed']);
        self::assertSame(100, (int) App::db()->value('SELECT progress_percent FROM annual_goals WHERE id = ?', [$goal]));
        self::assertSame(2, (int) App::db()->value('SELECT COUNT(*) FROM goal_progress_updates'));
    }

    public function testIndicatorsAndDelayedStatus(): void
    {
        $goal = $this->goal(['end_date' => date('Y-m-d', strtotime('-1 day')), 'start_date' => date('Y-m-d', strtotime('-60 days'))]);
        $this->post("/goals/$goal/indicators", ['name' => 'ממוצע שכבתי', 'unit' => 'נק\'', 'baseline_value' => '68', 'target_value' => '73', 'current_value' => 'abc']);
        self::assertArrayHasKey('current_value', $this->session->pullFlash('errors'));
        $this->post("/goals/$goal/indicators", ['name' => 'ממוצע שכבתי', 'baseline_value' => '68', 'target_value' => '73']);
        $indicator = (int) App::db()->value('SELECT id FROM goal_indicators');
        $this->post("/goals/$goal/indicators/$indicator", ['current_value' => '70,5']);
        self::assertSame('70.50', App::db()->value('SELECT current_value FROM goal_indicators'));

        // Past its end date and not completed → shown as delayed, though "delayed" is never stored.
        self::assertSame('not_started', App::db()->value('SELECT status FROM annual_goals WHERE id = ?', [$goal]));
        self::assertStringContainsString(__('goals.statuses.delayed'), $this->get("/goals/$goal")->body);
        self::assertSame('delayed', GoalService::displayStatus(['status' => 'in_progress', 'end_date' => '2026-01-01'], '2026-01-02'));
        self::assertSame('completed', GoalService::displayStatus(['status' => 'completed', 'end_date' => '2026-01-01'], '2026-01-02'));
        self::assertSame('in_progress', GoalService::displayStatus(['status' => 'in_progress', 'end_date' => '2026-01-02'], '2026-01-02'));
        self::assertSame(
            ['total' => 2, 'completed' => 1, 'delayed' => 1, 'needs_intervention' => 0, 'average_progress' => 60],
            GoalService::summary([
                ['status' => 'completed', 'end_date' => '2026-01-01', 'progress_percent' => 100],
                ['status' => 'in_progress', 'end_date' => '2026-01-01', 'progress_percent' => 20],
            ], '2026-06-01')
        );
        $this->post("/goals/$goal/indicators/$indicator/delete");
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM goal_indicators'));
    }

    public function testRestrictedUserEditsOnlyOwnGoalsAndOwnActions(): void
    {
        $coordinator = $this->createUser('p.coord', [['subject_coordinator', $this->school]]);   // work_plan: view + update, restricted scope
        $mine = $this->goal(['title' => 'Owned goal', 'owner_user_id' => $coordinator]);
        $other = $this->goal(['title' => 'Someone else goal']);
        $this->post("/goals/$other/actions", ['title' => 'Task for coordinator', 'owner_user_id' => $coordinator]);
        $this->post("/goals/$other/actions", ['title' => 'Task for nobody']);
        [$own, $foreign] = array_map('intval', App::db()->column('SELECT id FROM goal_actions ORDER BY id'));

        $this->loginAs($coordinator);
        self::assertSame(200, $this->get("/goals/$other")->status, 'The whole plan is visible.');
        self::assertSame(403, $this->get("/goals/$other/edit")->status);
        self::assertSame(403, $this->post("/goals/$other/updates", ['progress_percent' => 5, 'status' => 'in_progress'])->status);
        self::assertSame(403, $this->post("/goals/$other/actions", ['title' => 'x'])->status);
        self::assertSame(403, $this->post("/goals/$other/actions/$foreign/toggle")->status);
        $this->assertRedirect("/goals/$other", $this->post("/goals/$other/actions/$own/toggle"));
        self::assertSame('done', App::db()->value('SELECT status FROM goal_actions WHERE id = ?', [$own]));

        self::assertSame(200, $this->get("/goals/$mine/edit")->status);
        $this->assertRedirect("/goals/$mine", $this->post("/goals/$mine/updates", ['progress_percent' => 30, 'status' => 'in_progress']));
        self::assertSame(30, (int) App::db()->value('SELECT progress_percent FROM annual_goals WHERE id = ?', [$mine]));
        self::assertSame(403, $this->get('/goals/create')->status, 'Creating goals needs work_plan.create.');
    }

    public function testDocumentsCanBeAttachedToAGoal(): void
    {
        $goal = $this->goal();
        $tmp = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $file = ['file' => ['name' => 'plan.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]];
        $fields = ['title' => 'נספח ליעד', 'category' => 'work_plan', 'visibility' => 'restricted', 'entity_type' => 'goal', 'entity_id' => $goal, 'back' => "/goals/$goal"];

        $this->assertRedirect("/goals/$goal", $this->post('/documents', $fields, true, $file));
        $document = (int) App::db()->value('SELECT document_id FROM document_links WHERE entity_type = ? AND entity_id = ?', ['goal', $goal]);
        self::assertStringContainsString('נספח ליעד', $this->get("/goals/$goal")->body);

        // A restricted document becomes visible through the goal to anyone who may view the work plan…
        $this->loginAs($this->mathUser);
        self::assertSame(200, $this->get("/documents/$document/download")->status);
        // …an unknown record type or a goal of another school is refused, and "back" cannot leave the site.
        $this->loginAs($this->principal);
        self::assertSame(403, $this->post('/documents', ['entity_type' => 'planet'] + $fields, true, $file)->status);
        $other = $this->createSchool();
        $this->createYear($other);
        $this->loginAs($this->createUser('x.principal', [['principal', $other]]));
        self::assertSame(403, $this->post('/documents', $fields, true, $file)->status);
        self::assertSame(404, $this->get("/documents/$document/download")->status);
        self::assertSame(404, $this->get("/goals/$goal")->status);
        unlink($tmp);
    }

    // ---- Curriculum -----------------------------------------------------------------

    private function plan(int $subject, array $overrides = []): int
    {
        $this->post('/curriculum', $overrides + ['title' => 'Plan ' . bin2hex(random_bytes(2)), 'subject_id' => $subject, 'grade_level_id' => $this->level]);
        return (int) App::db()->value('SELECT id FROM curriculum_plans ORDER BY id DESC LIMIT 1');
    }

    /** @return int[] topic ids: two due in the past, one planned for the future */
    private function topics(int $plan): array
    {
        $this->post("/curriculum/$plan/units", ['title' => 'שברים', 'term_id' => $this->term1]);
        $unit = (int) App::db()->value('SELECT id FROM curriculum_units ORDER BY id DESC LIMIT 1');
        foreach ([['חיבור שברים', '-30 days', '4'], ['כפל שברים', '-10 days', '3,5'], ['חילוק שברים', '+20 days', '3']] as [$title, $when, $hours]) {
            $this->post("/curriculum/$plan/topics", ['title' => $title, 'unit_id' => $unit, 'planned_week_start' => date('Y-m-d', strtotime($when)), 'planned_hours' => $hours]);
        }
        return array_map('intval', App::db()->column('SELECT id FROM curriculum_topics WHERE unit_id = ? ORDER BY id', [$unit]));
    }

    public function testPlanStructureAndValidation(): void
    {
        $otherLevel = $this->createLevel($this->school, 'ח', 8);
        $this->post('/curriculum', ['title' => 'x', 'subject_id' => $this->math, 'grade_level_id' => $otherLevel, 'class_id' => $this->classA]);
        self::assertArrayHasKey('class_id', $this->session->pullFlash('errors'), 'The class must belong to the level.');

        $plan = $this->plan($this->math);
        [$a, $b, $c] = $this->topics($plan);
        $unit = (int) App::db()->value('SELECT unit_id FROM curriculum_topics WHERE id = ?', [$a]);
        self::assertSame('3.5', App::db()->value('SELECT planned_hours FROM curriculum_topics WHERE id = ?', [$b]));

        // One level of sub-topics, inside the same unit.
        $this->post("/curriculum/$plan/topics", ['title' => 'מכנה משותף', 'unit_id' => $unit, 'parent_topic_id' => $a]);
        $child = (int) App::db()->value('SELECT id FROM curriculum_topics ORDER BY id DESC LIMIT 1');
        $this->post("/curriculum/$plan/topics", ['title' => 'נכד', 'unit_id' => $unit, 'parent_topic_id' => $child]);
        self::assertArrayHasKey('parent_topic_id', $this->session->pullFlash('errors'));
        $otherPlan = $this->plan($this->hebrew);
        $this->post("/curriculum/$otherPlan/topics", ['title' => 'זר', 'unit_id' => $unit]);
        self::assertArrayHasKey('unit_id', $this->session->pullFlash('errors'), 'A unit of another plan is refused.');

        $this->assertRedirect("/curriculum/$plan", $this->post("/curriculum/$plan/topics/$c", ['title' => 'חילוק שברים ומספרים מעורבים', 'unit_id' => $unit, 'skills' => 'פתרון בעיות', 'planned_hours' => '3']));
        self::assertSame(404, $this->post("/curriculum/$otherPlan/topics/$c", ['title' => 'x', 'unit_id' => $unit])->status);
        foreach (['', '?view=month', '?view=week'] as $view) {
            $query = $view === '' ? [] : ['view' => substr($view, 6)];
            $body = $this->get("/curriculum/$plan", $query)->body;
            self::assertStringContainsString('חיבור שברים', $body, $view);
        }
        self::assertStringContainsString('10.5', $this->get("/curriculum/$plan")->body, 'Planned hours: 4 + 3.5 + 3.');

        $this->post("/curriculum/$plan/units/$unit/delete");
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM curriculum_topics WHERE unit_id = ?', [$unit]), 'Deleting a unit removes its topics.');
    }

    public function testPlannedVersusActualPerAssignment(): void
    {
        $plan = $this->plan($this->math);
        [$a, $b, $c] = $this->topics($plan);

        // The math teacher of ז1 reports one of the two topics that were due.
        $this->loginAs($this->mathUser);
        $this->assertRedirect("/curriculum/$plan/progress/{$this->mathA}", $this->post("/curriculum/$plan/progress/{$this->mathA}", ['topics' => [
            $a => ['status' => 'done', 'actual_date' => date('Y-m-d', strtotime('-28 days')), 'actual_hours' => '5', 'note' => 'נדרש שיעור נוסף'],
            $b => ['status' => 'in_progress'],
            $c => ['status' => 'flying'],
        ]]));
        self::assertSame('curriculum.errors.some_rows', $this->session->pullFlash('error'));
        self::assertSame(['done', 'in_progress'], App::db()->column('SELECT status FROM curriculum_progress ORDER BY topic_id'));
        // They cannot report for the parallel class, nor change the plan itself.
        self::assertSame(404, $this->post("/curriculum/$plan/progress/{$this->mathB}", ['topics' => [$a => ['status' => 'done']]])->status);
        self::assertSame(403, $this->post("/curriculum/$plan/units", ['title' => 'x'])->status);
        self::assertSame(403, $this->get("/curriculum/$plan/edit")->status);

        // The principal sees both classes side by side.
        $this->loginAs($this->principal);
        $body = $this->get("/curriculum/$plan")->body;
        self::assertStringContainsString('1/3', $body);     // ז1: one of three topics taught
        self::assertStringContainsString('0/3', $body);     // ז2: nothing reported
        self::assertStringContainsString(__('curriculum.compare.behind_count', ['count' => 1]), $body);   // ז1: one due topic not taught
        self::assertStringContainsString(__('curriculum.compare.behind_count', ['count' => 2]), $body);   // ז2: both due topics
        self::assertStringContainsString('5 / 10.5', $body);
    }

    public function testPlanVisibilityFollowsTeachingAssignments(): void
    {
        $math = $this->plan($this->math, ['title' => 'MathPlanTitle']);
        $hebrew = $this->plan($this->hebrew, ['title' => 'HebrewPlanTitle']);
        $classOnly = $this->plan($this->math, ['title' => 'MathClassBOnly', 'class_id' => $this->classB]);

        $this->loginAs($this->mathUser);
        $list = $this->get('/curriculum')->body;
        self::assertStringContainsString('MathPlanTitle', $list);
        self::assertStringNotContainsString('HebrewPlanTitle', $list);
        self::assertStringNotContainsString('MathClassBOnly', $list);
        self::assertSame(200, $this->get("/curriculum/$math")->status);
        self::assertSame(404, $this->get("/curriculum/$hebrew")->status);
        self::assertSame(404, $this->get("/curriculum/$classOnly")->status);

        $this->loginAs($this->homeroomUser);
        $list = $this->get('/curriculum')->body;
        self::assertStringContainsString('MathPlanTitle', $list);
        self::assertStringContainsString('HebrewPlanTitle', $list);
        self::assertStringNotContainsString('MathClassBOnly', $list);

        // The owner of a plan may edit its structure.
        $teacher = (int) App::db()->value('SELECT id FROM teachers WHERE user_id = ?', [$this->mathUser]);
        App::db()->update('curriculum_plans', ['owner_teacher_id' => $teacher], ['id' => $math]);
        $this->loginAs($this->mathUser);
        $this->assertRedirect("/curriculum/$math", $this->post("/curriculum/$math/units", ['title' => 'יחידה של בעל התוכנית']));
        // Approval is a separate permission.
        self::assertSame(403, $this->post("/curriculum/$math/approve")->status);
        $this->loginAs($this->principal);
        $this->post("/curriculum/$math/approve");
        self::assertSame('approved', App::db()->value('SELECT status FROM curriculum_plans WHERE id = ?', [$math]));
    }

    public function testAllStageDPagesRenderInBothLanguages(): void
    {
        $goal = $this->goal();
        $plan = $this->plan($this->math);
        [$topic] = $this->topics($plan);
        $assessment = $this->assessment($this->mathA, $this->term1, [1 => 90, 2 => 40]);
        $this->assessment($this->mathA, $this->term2, [1 => 70, 2 => 60]);
        $lesson = $this->lesson($this->mathA, '-1 days', 1, [1 => 'absent']);
        App::db()->insert('risk_criteria', ['school_id' => $this->school, 'name' => 'Low', 'criterion_type' => 'multi_subject', 'threshold' => 60, 'min_count' => 1]);
        $this->post('/risk/run', ['term_id' => $this->term1]);
        $indication = (int) App::db()->value('SELECT id FROM risk_indications');
        $criterion = (int) App::db()->value('SELECT id FROM risk_criteria');
        $pages = ['/', '/work-plan', '/goals', '/goals/create', "/goals/$goal", "/goals/$goal/edit", '/domains', '/curriculum', '/curriculum/create',
            "/curriculum/$plan", "/curriculum/$plan/edit", "/curriculum/$plan/topics/$topic/edit", "/curriculum/$plan/progress/{$this->mathA}",
            '/assessment-types', '/assessments', '/assessments/create', "/assessments/$assessment", "/assessments/$assessment/edit",
            '/achievements', "/achievements?class_id={$this->classA}&term_id={$this->term2}", '/attendance', "/attendance/sessions/$lesson",
            '/attendance/report', '/risk', "/risk/$indication", '/risk/criteria', "/risk/criteria/$criterion/edit", '/students/' . $this->s[1]];

        foreach (['he', 'ar'] as $locale) {
            $this->post('/locale', ['locale' => $locale]);
            foreach ($pages as $page) {
                [$path, $query] = array_pad(explode('?', $page, 2), 2, '');
                parse_str($query, $params);
                $response = $this->get($path, $params);
                self::assertSame(200, $response->status, "$locale $page");
                self::assertDoesNotMatchRegularExpression(
                    '/[>"]\s*(app|nav|workplan|goals|domains|curriculum|assessment_types|assessments|achievements|attendance|risk|risk_criteria|students|dashboard|documents)\.[a-z_.]+\s*[<"]/',
                    $response->body,
                    "$locale $page shows a raw translation key"
                );
            }
        }
    }
}
