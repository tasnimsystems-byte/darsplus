<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use Tests\PedagogyFixture;
use Tests\TestCase;

/** Stage E: interventions, parent contacts, observations, staff development, meetings and tasks. */
final class WorkflowTest extends TestCase
{
    use PedagogyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function foreignPrincipal(): int
    {
        $other = $this->createSchool();
        $this->createYear($other);
        return $this->createUser('x.principal' . bin2hex(random_bytes(2)), [['principal', $other]]);
    }

    private function notifications(int $userId): array
    {
        return App::db()->column('SELECT type FROM notifications WHERE user_id = ? ORDER BY id', [$userId]);
    }

    // ---- Interventions ----------------------------------------------------------------

    private function intervention(array $overrides = []): int
    {
        $this->post('/interventions', $overrides + [
            'student_id' => $this->s[1], 'difficulty_area' => 'הבנת הנקרא', 'owner_user_id' => $this->homeroomUser,
            'start_date' => date('Y-m-d', strtotime('-10 days')), 'review_date' => date('Y-m-d', strtotime('+20 days')), 'status' => 'active',
        ]);
        return (int) App::db()->value('SELECT id FROM student_interventions ORDER BY id DESC LIMIT 1');
    }

    public function testInterventionIsOpenedByAPersonForAStudentTheySeeInFull(): void
    {
        $this->loginAs($this->homeroomUser);
        // A student of another class is not offered and not accepted.
        $this->post('/interventions', ['student_id' => $this->s[4], 'difficulty_area' => 'x', 'owner_user_id' => $this->homeroomUser, 'start_date' => date('Y-m-d'), 'status' => 'active']);
        self::assertArrayHasKey('student_id', $this->session->pullFlash('errors'));
        $this->post('/interventions', ['student_id' => $this->s[1], 'difficulty_area' => 'x', 'owner_user_id' => $this->homeroomUser, 'start_date' => date('Y-m-d'), 'review_date' => '2020-01-01', 'status' => 'active']);
        self::assertArrayHasKey('review_date', $this->session->pullFlash('errors'));

        $id = $this->intervention();
        self::assertGreaterThan(0, $id);
        $this->post("/interventions/$id/actions", ['description' => 'קריאה מודרכת פעמיים בשבוע', 'owner_user_id' => $this->mathUser, 'due_date' => date('Y-m-d', strtotime('+7 days'))]);
        $action = (int) App::db()->value('SELECT id FROM intervention_actions');
        self::assertSame(['intervention'], $this->notifications($this->mathUser), 'The person given an action is notified.');

        // Review: not in the future; saving moves the next review date.
        $this->post("/interventions/$id/reviews", ['review_date' => date('Y-m-d', strtotime('+1 day')), 'findings' => 'x', 'progress_rating' => 'improved']);
        self::assertArrayHasKey('review_date', $this->session->pullFlash('errors'));
        $next = date('Y-m-d', strtotime('+30 days'));
        $this->post("/interventions/$id/reviews", ['review_date' => date('Y-m-d'), 'findings' => 'שיפור בשטף הקריאה', 'progress_rating' => 'improved', 'next_review_date' => $next]);
        self::assertSame($next, App::db()->value('SELECT review_date FROM student_interventions WHERE id = ?', [$id]));

        // The student of an existing plan cannot be swapped.
        $this->post("/interventions/$id", ['student_id' => $this->s[2], 'difficulty_area' => 'x', 'owner_user_id' => $this->homeroomUser, 'start_date' => date('Y-m-d'), 'status' => 'active']);
        self::assertArrayHasKey('student_id', $this->session->pullFlash('errors'));

        // The subject teacher sees the plan only because an action is theirs: may complete it, not change the plan.
        $this->loginAs($this->mathUser);
        self::assertSame(200, $this->get("/interventions/$id")->status);
        self::assertSame(403, $this->post("/interventions/$id/actions", ['description' => 'x'])->status);
        self::assertSame(403, $this->get("/interventions/$id/edit")->status);
        $this->assertRedirect("/interventions/$id", $this->post("/interventions/$id/actions/$action/toggle"));
        self::assertSame('done', App::db()->value('SELECT status FROM intervention_actions WHERE id = ?', [$action]));

        $this->loginAs($this->homeroomUser);
        $body = $this->get("/interventions/$id")->body;
        self::assertStringContainsString('שיפור בשטף הקריאה', $body);
        self::assertStringContainsString(__('interventions.events.action'), $body, 'The completed action is on the timeline.');
        self::assertGreaterThanOrEqual(2, $this->auditCount('view', 'intervention'));

        // Others: a teacher with no part in it, and another school.
        $this->loginAs($this->hebrewUser);
        self::assertStringNotContainsString('הבנת הנקרא', $this->get('/interventions')->body);
        self::assertSame(404, $this->get("/interventions/$id")->status);
        $this->loginAs($this->foreignPrincipal());
        self::assertSame(404, $this->get("/interventions/$id")->status);
        self::assertSame(404, $this->post("/interventions/$id/reviews", ['review_date' => date('Y-m-d'), 'findings' => 'x', 'progress_rating' => 'same'])->status);
    }

    public function testOpeningFromAnIndicationRecordsThePersonsDecision(): void
    {
        $criterion = App::db()->insert('risk_criteria', ['school_id' => $this->school, 'name' => 'Low', 'criterion_type' => 'low_average', 'threshold' => 60]);
        $forS1 = App::db()->insert('risk_indications', ['school_id' => $this->school, 'student_id' => $this->s[1], 'criterion_id' => $criterion, 'term_id' => $this->term1, 'evidence' => '{}']);
        $forS2 = App::db()->insert('risk_indications', ['school_id' => $this->school, 'student_id' => $this->s[2], 'criterion_id' => $criterion, 'term_id' => $this->term1, 'evidence' => '{}']);
        $this->loginAs($this->principal);

        $form = $this->get('/interventions/create', ['indication' => $forS1])->body;
        self::assertStringContainsString('name="risk_indication_id" value="' . $forS1 . '"', $form);
        // Nothing has happened yet — the indication changes only when the person saves the plan.
        self::assertSame('new', App::db()->value('SELECT review_status FROM risk_indications WHERE id = ?', [$forS1]));

        $id = $this->intervention(['risk_indication_id' => $forS1]);
        self::assertSame($forS1, (int) App::db()->value('SELECT risk_indication_id FROM student_interventions WHERE id = ?', [$id]));
        self::assertSame(['intervention', $this->principal], array_values(array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, App::db()->one('SELECT review_status, reviewed_by FROM risk_indications WHERE id = ?', [$forS1]))));

        // An indication about a different student is ignored.
        $second = $this->intervention(['risk_indication_id' => $forS2]);
        self::assertNull(App::db()->value('SELECT risk_indication_id FROM student_interventions WHERE id = ?', [$second]));
        self::assertSame('new', App::db()->value('SELECT review_status FROM risk_indications WHERE id = ?', [$forS2]));
    }

    // ---- Parents ------------------------------------------------------------------------

    public function testParentContactKeepsInternalNotesApartFromTheSharedSummary(): void
    {
        $this->loginAs($this->homeroomUser);
        $card = '/students/' . $this->s[1];
        $this->post("$card/guardians", ['full_name' => 'רות כהן', 'relation' => 'אם', 'phone' => '050-1234567', 'is_primary' => '1']);
        $this->post("$card/guardians", ['full_name' => 'x', 'phone' => 'call me', 'email' => 'nope']);
        self::assertSame(['phone', 'email'], array_keys($this->session->pullFlash('errors')));
        $guardian = (int) App::db()->value('SELECT id FROM guardians');
        $otherGuardian = App::db()->insert('guardians', ['school_id' => $this->school, 'student_id' => $this->s[2], 'full_name' => 'הורה אחר']);
        self::assertStringContainsString('רות כהן', $this->get($card)->body);

        $form = ['student_id' => $this->s[1], 'contact_type' => 'call', 'contacted_on' => date('Y-m-d'),
            'internal_notes' => 'INTERNAL-ONLY-TEXT', 'shared_summary' => 'סוכם על קריאה יומית בבית', 'decisions' => 'מעקב בעוד חודש', 'follow_up_status' => 'none'];
        $this->post('/parent-contacts', ['guardian_id' => $otherGuardian] + $form);
        self::assertArrayHasKey('guardian_id', $this->session->pullFlash('errors'), 'The guardian must belong to this student.');
        $this->post('/parent-contacts', ['follow_up_status' => 'open'] + $form);
        self::assertArrayHasKey('follow_up_date', $this->session->pullFlash('errors'));
        $this->post('/parent-contacts', ['contacted_on' => date('Y-m-d', strtotime('+2 days'))] + $form);
        self::assertArrayHasKey('contacted_on', $this->session->pullFlash('errors'));

        $this->post('/parent-contacts', ['guardian_id' => $guardian] + $form);
        $row = App::db()->one('SELECT * FROM parent_contacts');
        $id = (int) $row['id'];
        self::assertSame($this->homeroomUser, (int) $row['staff_user_id']);

        self::assertStringContainsString('INTERNAL-ONLY-TEXT', $this->get("/parent-contacts/$id")->body);
        $summary = $this->get("/parent-contacts/$id/summary")->body;
        self::assertStringContainsString('סוכם על קריאה יומית בבית', $summary);
        self::assertStringNotContainsString('INTERNAL-ONLY-TEXT', $summary, 'Internal notes never reach the page for the family.');
        self::assertSame(1, $this->auditCount('export', 'parent_contact'));
        // A guardian referenced by a contact cannot be deleted.
        $this->post("$card/guardians/$guardian/delete");
        self::assertSame('app.errors.in_use', $this->session->pullFlash('error'));

        // A subject teacher sees neither the contact nor the family's details.
        $this->loginAs($this->mathUser);
        self::assertSame(404, $this->get("/parent-contacts/$id")->status);
        self::assertSame(404, $this->get("/parent-contacts/$id/summary")->status);
        self::assertStringNotContainsString('סוכם', $this->get('/parent-contacts')->body);
        $body = $this->get($card)->body;
        self::assertStringNotContainsString('רות כהן', $body);
        self::assertStringNotContainsString('050-1234567', $body);
        $this->post('/parent-contacts', $form);
        self::assertArrayHasKey('student_id', $this->session->pullFlash('errors'), 'No student is selectable outside classes seen in full.');

        $this->loginAs($this->foreignPrincipal());
        self::assertSame(404, $this->get("/parent-contacts/$id")->status);
        self::assertSame(404, $this->post("$card/guardians", ['full_name' => 'x'])->status);
    }

    // ---- Observations and staff development ------------------------------------------------

    public function testObservationIsConfidentialUntilShared(): void
    {
        $teacher = (int) App::db()->value('SELECT id FROM teachers WHERE user_id = ?', [$this->mathUser]);
        $coordinator = $this->createUser('p.coord', [['subject_coordinator', $this->school]]);
        $coordinatorTeacher = $this->createTeacher($this->school, 'Coordinator', $coordinator);
        $form = ['teacher_id' => $teacher, 'record_type' => 'lesson_visit', 'observed_on' => date('Y-m-d'),
            'strengths' => 'STRENGTH-TEXT', 'areas_for_growth' => 'GROWTH-TEXT', 'feedback' => 'FEEDBACK-TEXT'];

        $this->loginAs($coordinator);
        $this->post('/observations', ['teacher_id' => $coordinatorTeacher] + $form);
        self::assertArrayHasKey('teacher_id', $this->session->pullFlash('errors'), 'No observation about oneself.');
        $this->post('/observations', $form);
        $id = (int) App::db()->value('SELECT id FROM observations');
        self::assertSame($coordinator, (int) App::db()->value('SELECT observer_user_id FROM observations'));
        $this->post("/observations/$id/recommendations", ['recommendation' => 'שילוב עבודה בזוגות', 'follow_up_date' => date('Y-m-d', strtotime('+14 days'))]);
        $recommendation = (int) App::db()->value('SELECT id FROM observation_recommendations');
        $this->post("/observations/$id/recommendations/$recommendation", ['follow_up_note' => 'יושם בשיעור הבא']);
        self::assertSame(['done', 'יושם בשיעור הבא'], array_values(App::db()->one('SELECT status, follow_up_note FROM observation_recommendations')));

        // The observed teacher: nothing before sharing.
        $this->loginAs($this->mathUser);
        self::assertStringNotContainsString('FEEDBACK-TEXT', $this->get('/my-development')->body);
        self::assertSame(404, $this->get("/observations/$id")->status);
        self::assertStringNotContainsString("/observations/$id\"", $this->get('/observations')->body);
        self::assertSame([], $this->notifications($this->mathUser));

        // Management reads (and each reading is logged) but does not rewrite someone else's record.
        $this->loginAs($this->principal);
        self::assertSame(200, $this->get("/observations/$id")->status);
        self::assertSame(1, $this->auditCount('view', 'observation'));
        self::assertSame(403, $this->get("/observations/$id/edit")->status);
        self::assertSame(403, $this->post("/observations/$id", ['shared_with_teacher' => '1'] + $form)->status);

        // The author shares it: the teacher is told (without content) and can now read it.
        $this->loginAs($coordinator);
        $this->post("/observations/$id", ['shared_with_teacher' => '1'] + $form);
        self::assertSame(['observation'], $this->notifications($this->mathUser));
        self::assertNull(App::db()->value('SELECT body FROM notifications WHERE user_id = ?', [$this->mathUser]));
        $this->loginAs($this->mathUser);
        self::assertStringContainsString('FEEDBACK-TEXT', $this->get('/my-development')->body);
        self::assertSame(404, $this->get("/observations/$id")->status, 'Still not through the management screen.');

        // Another teacher, another coordinator, another school: nothing.
        $this->loginAs($this->hebrewUser);
        self::assertStringNotContainsString('FEEDBACK-TEXT', $this->get('/my-development')->body);
        $second = $this->createUser('p.coord2', [['subject_coordinator', $this->school]]);
        $this->loginAs($second);
        self::assertSame(404, $this->get("/observations/$id")->status);
        $this->loginAs($this->foreignPrincipal());
        self::assertSame(404, $this->get("/observations/$id")->status);
    }

    public function testStaffDevelopmentIsManagedByManagementAndReadByTheTeacher(): void
    {
        $teacher = (int) App::db()->value('SELECT id FROM teachers WHERE user_id = ?', [$this->mathUser]);
        $this->loginAs($this->principal);
        $this->post("/staff-development/$teacher/goals", ['title' => 'שילוב הערכה מעצבת', 'due_date' => date('Y-m-d', strtotime('+60 days'))]);
        $this->post("/staff-development/$teacher/trainings", ['title' => 'הוראה דיפרנציאלית', 'provider' => 'פסג"ה', 'hours' => '30', 'status' => 'completed']);
        $this->post("/staff-development/$teacher/trainings", ['title' => 'x', 'hours' => 'many', 'status' => 'finished']);
        self::assertSame(['hours', 'status'], array_keys($this->session->pullFlash('errors')));
        $goal = (int) App::db()->value('SELECT id FROM teacher_goals');
        $this->post("/staff-development/$teacher/goals/$goal", ['status' => 'achieved']);
        self::assertSame('achieved', App::db()->value('SELECT status FROM teacher_goals'));
        self::assertSame(404, $this->post("/staff-development/$teacher/goals/$goal", ['status' => 'legendary'])->status);
        self::assertStringContainsString('שילוב הערכה מעצבת', $this->get("/staff-development/$teacher")->body);

        $this->loginAs($this->mathUser);
        $body = $this->get('/my-development')->body;
        self::assertStringContainsString('שילוב הערכה מעצבת', $body);
        self::assertStringContainsString('הוראה דיפרנציאלית', $body);
        self::assertSame(403, $this->post("/staff-development/$teacher/goals", ['title' => 'x'])->status);
        // A colleague cannot open someone else's page; a user with no teacher record gets an explanation.
        $this->loginAs($this->hebrewUser);
        self::assertSame(404, $this->get("/staff-development/$teacher")->status);
        $this->loginAs($this->principal);
        self::assertStringContainsString(__('staffdev.no_teacher_record'), $this->get('/my-development')->body);
    }

    // ---- Meetings and tasks -------------------------------------------------------------------

    private function meeting(array $overrides = []): int
    {
        $this->post('/meetings', $overrides + [
            'title' => 'ישיבה פדגוגית – מחצית א', 'meeting_type' => 'pedagogical', 'meeting_date' => date('Y-m-d'), 'start_time' => '14:30',
            'agenda' => "ניתוח הישגים\nתלמידים במעקב", 'status' => 'planned', 'participant_ids' => [$this->mathUser, $this->homeroomUser],
        ]);
        return (int) App::db()->value('SELECT id FROM meetings ORDER BY id DESC LIMIT 1');
    }

    public function testMeetingFromInvitationToDecisionsAndTasks(): void
    {
        $this->loginAs($this->principal);
        $this->post('/meetings', ['title' => 'x', 'meeting_type' => 'party', 'meeting_date' => '2020-01-01', 'start_time' => '25:00', 'status' => 'planned']);
        self::assertSame(['meeting_type', 'start_time'], array_keys($this->session->pullFlash('errors')));
        $this->post('/meetings', ['title' => 'x', 'meeting_type' => 'individual', 'meeting_date' => date('Y-m-d'), 'status' => 'planned']);
        self::assertArrayHasKey('participant_ids', $this->session->pullFlash('errors'));

        $id = $this->meeting();
        self::assertSame($this->principal, (int) App::db()->value('SELECT organizer_user_id FROM meetings WHERE id = ?', [$id]));
        self::assertSame(['meeting'], $this->notifications($this->mathUser));
        self::assertSame(['meeting'], $this->notifications($this->homeroomUser));

        // Minutes; the meeting is marked held and attendance is recorded from the ticks.
        $this->post("/meetings/$id/minutes", ['minutes' => 'נדונו תוצאות המיפוי.', 'mark_held' => '1', 'attended' => [$this->mathUser]]);
        self::assertSame(['held', 'נדונו תוצאות המיפוי.'], array_values(App::db()->one('SELECT status, minutes FROM meetings WHERE id = ?', [$id])));
        self::assertSame(
            [$this->mathUser => 'attended', $this->homeroomUser => 'absent'],
            array_column(App::db()->all('SELECT user_id, attendance FROM meeting_participants WHERE meeting_id = ?', [$id]), 'attendance', 'user_id')
        );

        // A decision with a responsible person becomes a task on that person's list.
        $this->post("/meetings/$id/decisions", ['decision' => 'להכין רשימת תלמידים לתגבור', 'owner_user_id' => $this->mathUser, 'due_date' => date('Y-m-d', strtotime('+7 days')), 'create_task' => '1']);
        $this->post("/meetings/$id/decisions", ['decision' => 'לעדכן את ההורים', 'owner_user_id' => $this->homeroomUser]);
        [$withTask, $plain] = array_map('intval', App::db()->column('SELECT id FROM meeting_decisions ORDER BY id'));
        $task = App::db()->one('SELECT * FROM tasks');
        self::assertSame(['meeting_decision', $withTask, $this->mathUser, $this->principal], [$task['source_type'], (int) $task['source_id'], (int) $task['assignee_user_id'], (int) $task['assigned_by']]);
        self::assertSame(['meeting', 'task'], $this->notifications($this->mathUser));
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM tasks'));

        // The assignee completes the task → the decision is closed with it.
        $this->loginAs($this->mathUser);
        self::assertSame(200, $this->get("/meetings/$id")->status);
        $this->assertRedirect('/tasks/' . $task['id'], $this->post('/tasks/' . $task['id'] . '/status', ['status' => 'done']));
        self::assertSame('done', App::db()->value('SELECT status FROM meeting_decisions WHERE id = ?', [$withTask]));
        // A participant may not close someone else's decision, write minutes or add decisions.
        self::assertSame(403, $this->post("/meetings/$id/decisions/$plain/toggle")->status);
        self::assertSame(403, $this->post("/meetings/$id/minutes", ['minutes' => 'hacked'])->status);
        self::assertSame(403, $this->post("/meetings/$id/decisions", ['decision' => 'x'])->status);
        self::assertSame(403, $this->get('/meetings/create')->status);

        // The owner of a decision closes it themselves.
        $this->loginAs($this->homeroomUser);
        $this->assertRedirect("/meetings/$id", $this->post("/meetings/$id/decisions/$plain/toggle"));
        self::assertSame('done', App::db()->value('SELECT status FROM meeting_decisions WHERE id = ?', [$plain]));

        // Not invited → invisible.
        $this->loginAs($this->hebrewUser);
        self::assertStringNotContainsString('ישיבה פדגוגית – מחצית א', $this->get('/meetings')->body);
        self::assertSame(404, $this->get("/meetings/$id")->status);
        $this->loginAs($this->foreignPrincipal());
        self::assertSame(404, $this->get("/meetings/$id")->status);
    }

    public function testOpenDecisionsCarryOverToTheNextMeetingOfTheSameType(): void
    {
        $this->loginAs($this->principal);
        $first = $this->meeting(['meeting_date' => date('Y-m-d', strtotime('-14 days')), 'title' => 'ישיבה ראשונה']);
        $this->post("/meetings/$first/decisions", ['decision' => 'CARRIED-OVER-DECISION', 'owner_user_id' => $this->mathUser]);
        $this->post("/meetings/$first/decisions", ['decision' => 'ALREADY-DONE-DECISION']);
        $done = (int) App::db()->value("SELECT id FROM meeting_decisions WHERE decision = 'ALREADY-DONE-DECISION'");
        $this->post("/meetings/$first/decisions/$done/toggle");
        $other = $this->meeting(['meeting_type' => 'management', 'meeting_date' => date('Y-m-d', strtotime('-7 days')), 'title' => 'הנהלה']);
        $this->post("/meetings/$other/decisions", ['decision' => 'OTHER-TYPE-DECISION']);

        $second = $this->meeting(['title' => 'ישיבה שנייה']);
        $body = $this->get("/meetings/$second")->body;
        self::assertStringContainsString('CARRIED-OVER-DECISION', $body);
        self::assertStringNotContainsString('ALREADY-DONE-DECISION', $body);
        self::assertStringNotContainsString('OTHER-TYPE-DECISION', $body);
    }

    public function testTasksBetweenColleagues(): void
    {
        $this->loginAs($this->mathUser);
        $this->post('/tasks', ['title' => '', 'assignee_user_id' => 999999, 'priority' => 'extreme', 'status' => 'open']);
        self::assertSame(['title', 'assignee_user_id', 'priority'], array_keys($this->session->pullFlash('errors')));
        $this->post('/tasks', ['title' => 'להעביר חומרי תרגול', 'assignee_user_id' => $this->hebrewUser, 'priority' => 'high', 'status' => 'open', 'due_date' => date('Y-m-d', strtotime('-2 days'))]);
        $id = (int) App::db()->value('SELECT id FROM tasks');
        self::assertSame(['task'], $this->notifications($this->hebrewUser));

        // The assignee sees it, updates its status and comments, but does not rewrite it.
        $this->loginAs($this->hebrewUser);
        $list = $this->get('/tasks', ['mine' => '1'])->body;
        self::assertStringContainsString('להעביר חומרי תרגול', $list);
        self::assertStringContainsString(__('tasks.overdue'), $list);
        self::assertSame(403, $this->get("/tasks/$id/edit")->status);
        self::assertSame(403, $this->post("/tasks/$id/delete")->status);
        $this->post("/tasks/$id/comments", ['body' => 'אעביר עד יום חמישי']);
        $this->post("/tasks/$id/status", ['status' => 'in_progress']);
        $this->post("/tasks/$id/status", ['status' => 'flying']);
        self::assertSame('in_progress', App::db()->value('SELECT status FROM tasks WHERE id = ?', [$id]));
        self::assertMatchesRegularExpression('/kpi-value">\s*1\s*</', $this->get('/')->body);

        // The creator sees the comment; "only mine" hides a task they merely assigned.
        $this->loginAs($this->mathUser);
        self::assertStringContainsString('אעביר עד יום חמישי', $this->get("/tasks/$id")->body);
        self::assertStringNotContainsString('להעביר חומרי תרגול', $this->get('/tasks', ['mine' => '1'])->body);
        self::assertStringContainsString('להעביר חומרי תרגול', $this->get('/tasks')->body);

        // A third colleague sees nothing; management sees it on the board; another school never.
        $this->loginAs($this->otherMathUser);
        self::assertStringNotContainsString('להעביר חומרי תרגול', $this->get('/tasks')->body);
        self::assertSame(404, $this->get("/tasks/$id")->status);
        self::assertSame(404, $this->post("/tasks/$id/status", ['status' => 'done'])->status);
        self::assertSame(404, $this->post("/tasks/$id/comments", ['body' => 'x'])->status);
        $this->loginAs($this->principal);
        self::assertStringContainsString('להעביר חומרי תרגול', $this->get('/tasks/board')->body);
        $this->loginAs($this->foreignPrincipal());
        self::assertSame(404, $this->get("/tasks/$id")->status);
        self::assertStringNotContainsString('להעביר חומרי תרגול', $this->get('/tasks/board')->body);
    }

    public function testAllStageEPagesRenderInBothLanguages(): void
    {
        $this->loginAs($this->principal);
        $intervention = $this->intervention();
        $this->post("/interventions/$intervention/actions", ['description' => 'פעולה']);
        $this->post('/parent-contacts', ['student_id' => $this->s[1], 'contact_type' => 'meeting', 'contacted_on' => date('Y-m-d'), 'follow_up_status' => 'none', 'shared_summary' => 'סיכום']);
        $contact = (int) App::db()->value('SELECT id FROM parent_contacts');
        $teacher = (int) App::db()->value('SELECT id FROM teachers WHERE user_id = ?', [$this->mathUser]);
        $this->post('/observations', ['teacher_id' => $teacher, 'record_type' => 'observation', 'observed_on' => date('Y-m-d')]);
        $observation = (int) App::db()->value('SELECT id FROM observations');
        $meeting = $this->meeting();
        $this->post("/meetings/$meeting/decisions", ['decision' => 'החלטה', 'owner_user_id' => $this->mathUser, 'create_task' => '1']);
        $task = (int) App::db()->value('SELECT id FROM tasks');
        $pages = ['/', '/interventions', '/interventions/create', "/interventions/$intervention", "/interventions/$intervention/edit",
            '/parent-contacts', '/parent-contacts/create', "/parent-contacts/$contact", "/parent-contacts/$contact/summary", "/parent-contacts/$contact/edit",
            '/observations', '/observations/create', "/observations/$observation", "/observations/$observation/edit",
            "/staff-development/$teacher", '/my-development', '/meetings', '/meetings/create', "/meetings/$meeting", "/meetings/$meeting/edit",
            '/tasks', '/tasks/create', '/tasks/board', "/tasks/$task", "/tasks/$task/edit", '/students/' . $this->s[1]];

        foreach (['he', 'ar'] as $locale) {
            $this->post('/locale', ['locale' => $locale]);
            foreach ($pages as $page) {
                $response = $this->get($page);
                self::assertSame(200, $response->status, "$locale $page");
                self::assertDoesNotMatchRegularExpression(
                    '/[>"]\s*(app|nav|interventions|parent_contacts|observations|staffdev|meetings|tasks|students|dashboard|risk)\.[a-z_.]+\s*[<"]/',
                    $response->body,
                    "$locale $page shows a raw translation key"
                );
            }
        }
    }
}
