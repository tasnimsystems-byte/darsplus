<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\AttendanceStats;
use App\Services\TeacherWeek;
use Tests\TestCase;

/**
 * Study groups for shared lessons: the principal builds a group from one or more
 * classes and links it to lessons; the lesson page then lists only the group, and
 * a group over several classes is recorded once, each student in their own class.
 */
final class StudyGroupTest extends TestCase
{
    private int $school;
    private int $year;
    private int $classA;
    private int $classB;
    private int $math;
    private int $teacher;
    private int $teacherUser;
    private int $principal;
    private int $mathA;
    private int $mathB;
    /** @var array<string, int> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->year = $this->createYear($this->school);
        $level = $this->createLevel($this->school, 'ז', 7);
        $this->classA = $this->createClass($this->school, $this->year, $level, 'ז1');
        $this->classB = $this->createClass($this->school, $this->year, $level, 'ז2');
        $this->math = $this->createSubject($this->school, 'מתמטיקה');
        $this->teacherUser = $this->createUser('t.teacher', [['subject_teacher', $this->school]]);
        $this->teacher = App::db()->insert('teachers', ['school_id' => $this->school, 'first_name' => 'רות', 'last_name' => 'לוי', 'user_id' => $this->teacherUser]);
        $this->mathA = $this->assign($this->school, $this->year, $this->teacher, $this->classA, $this->math);
        $this->mathB = $this->assign($this->school, $this->year, $this->teacher, $this->classB, $this->math);
        foreach (['a1' => $this->classA, 'a2' => $this->classA, 'a3' => $this->classA, 'b1' => $this->classB, 'b2' => $this->classB] as $key => $class) {
            $this->s[$key] = $this->createStudent($this->school, "תלמיד-$key", $class);
        }
        $this->principal = $this->createUser('t.principal', [['principal', $this->school]]);
        $this->loginAs($this->principal);
    }

    /** Builds "עתודה": a1, a2 from ז1 and b1 from ז2, linked to the teacher's math lessons in both classes. */
    private function buildGroup(): int
    {
        $response = $this->post('/study-groups', ['name' => 'עתודה', 'subject_id' => (string) $this->math]);
        $group = (int) App::db()->value("SELECT id FROM study_groups WHERE name = 'עתודה'");
        $this->assertRedirect("/study-groups/$group", $response);
        $this->post("/study-groups/$group/members", ['class_id' => (string) $this->classA, 'student_ids' => [(string) $this->s['a1'], (string) $this->s['a2']]]);
        $this->post("/study-groups/$group/members", ['class_id' => (string) $this->classB, 'student_ids' => [(string) $this->s['b1']]]);
        $this->post("/study-groups/$group/lessons", ['assignment_ids' => [(string) $this->mathA, (string) $this->mathB]]);
        return $group;
    }

    public function testPrincipalBuildsAGroupFromSeveralClasses(): void
    {
        $group = $this->buildGroup();
        self::assertSame(3, (int) App::db()->value('SELECT COUNT(*) FROM study_group_students WHERE group_id = ?', [$group]));
        self::assertSame([$this->mathA, $this->mathB], array_map('intval', App::db()->column('SELECT id FROM teaching_assignments WHERE study_group_id = ? ORDER BY id', [$group])));

        $page = $this->get("/study-groups/$group", ['class_id' => (string) $this->classA]);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('תלמיד-b1', $page->body);
        self::assertMatchesRegularExpression('/value="' . $this->s['a1'] . '" id="m-\d+" checked/', $page->body);
        self::assertDoesNotMatchRegularExpression('/value="' . $this->s['a3'] . '" id="m-\d+" checked/', $page->body);
        self::assertStringContainsString('עתודה', $this->get('/study-groups')->body);

        // Saving one class leaves the other class's members alone; students of another class are ignored.
        $this->post("/study-groups/$group/members", ['class_id' => (string) $this->classA, 'student_ids' => [(string) $this->s['a3'], (string) $this->s['b2']]]);
        self::assertSame([$this->s['a3'], $this->s['b1']], array_map('intval', App::db()->column('SELECT student_id FROM study_group_students WHERE group_id = ? ORDER BY student_id', [$group])));

        // Unlinking one lesson.
        $this->post("/study-groups/$group/lessons", ['assignment_ids' => [(string) $this->mathB]]);
        self::assertNull(App::db()->value('SELECT study_group_id FROM teaching_assignments WHERE id = ?', [$this->mathA]));
        self::assertSame(1, $this->auditCount('create', 'study_group'));
        self::assertSame(5, $this->auditCount('update', 'study_group'), 'Every change of members or lessons is audited.');
    }

    public function testGroupIsBuiltByTeacherSubjectClassesAndStudents(): void
    {
        $other = $this->createSubject($this->school, 'אנגלית');
        $this->assign($this->school, $this->year, $this->teacher, $this->classA, $other);

        // Step 1: teachers; step 2: the teacher's subjects; step 3: classes of the shared lesson with their students.
        $page = $this->get('/study-groups/create')->body;
        self::assertStringContainsString('לוי רות', $page);
        self::assertStringNotContainsString('name="assignment_ids[]"', $page);
        $page = $this->get('/study-groups/create', ['teacher_id' => (string) $this->teacher])->body;
        self::assertStringContainsString('אנגלית', $page);
        $page = $this->get('/study-groups/create', ['teacher_id' => (string) $this->teacher, 'subject_id' => (string) $this->math])->body;
        self::assertStringContainsString('value="' . $this->mathA . '"', $page);
        self::assertStringContainsString('value="' . $this->mathB . '"', $page);
        self::assertStringContainsString('name="students[' . $this->mathB . '][]" value="' . $this->s['b2'] . '"', $page);
        self::assertStringContainsString('value="מתמטיקה – לוי רות"', $page, 'A name is suggested.');

        $form = ['teacher_id' => (string) $this->teacher, 'subject_id' => (string) $this->math, 'name' => 'מצוינות',
            'assignment_ids' => [(string) $this->mathA, (string) $this->mathB],
            // b2 is offered under ז1 although they are in ז2: ignored.
            'students' => [$this->mathA => [(string) $this->s['a1'], (string) $this->s['a3'], (string) $this->s['b2']], $this->mathB => [(string) $this->s['b1']]]];

        // Nothing chosen / no name: nothing is created.
        $this->post('/study-groups/create', ['students' => []] + $form);
        $this->post('/study-groups/create', ['name' => ' '] + $form);
        $this->post('/study-groups/create', ['assignment_ids' => [(string) $this->mathA]] + ['students' => [$this->mathA => []]] + $form);
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM study_groups'));

        $response = $this->post('/study-groups/create', $form);
        $group = (int) App::db()->value("SELECT id FROM study_groups WHERE name = 'מצוינות'");
        $this->assertRedirect("/study-groups/$group", $response);
        self::assertSame($this->math, (int) App::db()->value('SELECT subject_id FROM study_groups WHERE id = ?', [$group]));
        self::assertSame([$this->s['a1'], $this->s['a3'], $this->s['b1']], array_map('intval', App::db()->column('SELECT student_id FROM study_group_students WHERE group_id = ? ORDER BY student_id', [$group])));
        self::assertSame([$this->mathA, $this->mathB], array_map('intval', App::db()->column('SELECT id FROM teaching_assignments WHERE study_group_id = ? ORDER BY id', [$group])));
        self::assertSame(1, $this->auditCount('create', 'study_group'));

        // The same name again is refused; a class whose box is ticked without students is not linked.
        $this->post('/study-groups/create', $form);
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM study_groups'));
        $this->post('/study-groups/create', ['name' => 'ז1 בלבד', 'students' => [$this->mathA => [(string) $this->s['a2']]]] + $form);
        $second = (int) App::db()->value("SELECT id FROM study_groups WHERE name = 'ז1 בלבד'");
        self::assertSame([$this->mathA], array_map('intval', App::db()->column('SELECT id FROM teaching_assignments WHERE study_group_id = ?', [$second])));

        // Another teacher's lesson cannot be slipped in.
        $otherTeacher = App::db()->insert('teachers', ['school_id' => $this->school, 'first_name' => 'דן', 'last_name' => 'כהן']);
        $foreign = $this->assign($this->school, $this->year, $otherTeacher, $this->classB, $this->math);
        $this->post('/study-groups/create', ['name' => 'זר', 'assignment_ids' => [(string) $foreign], 'students' => [$foreign => [(string) $this->s['b2']]]] + $form);
        self::assertNull(App::db()->value("SELECT id FROM study_groups WHERE name = 'זר'"));
    }

    public function testLessonListsOnlyTheGroupAndRecordsEachStudentInTheirClass(): void
    {
        $this->buildGroup();
        $this->loginAs($this->teacherUser);
        $this->post('/attendance/sessions', ['teaching_assignment_id' => (string) $this->mathA, 'lesson_date' => '2026-09-06', 'period_number' => '3']);
        $session = (int) App::db()->value('SELECT id FROM lesson_sessions WHERE teaching_assignment_id = ?', [$this->mathA]);

        $page = $this->get("/attendance/sessions/$session")->body;
        self::assertStringContainsString('עתודה', $page);
        foreach (['a1', 'a2', 'b1'] as $in) {
            self::assertStringContainsString("תלמיד-$in", $page);
        }
        foreach (['a3', 'b2'] as $out) {
            self::assertStringNotContainsString("תלמיד-$out", $page, 'Only the group is listed.');
        }

        $this->post("/attendance/sessions/$session", [
            'topic' => 'משוואות',
            'students' => [$this->s['a1'] => ['status' => 'late'], $this->s['b1'] => ['status' => 'absent'], $this->s['a3'] => ['status' => 'absent']],
        ]);
        $sessionB = (int) App::db()->value('SELECT id FROM lesson_sessions WHERE teaching_assignment_id = ? AND lesson_date = ? AND period_number = 3', [$this->mathB, '2026-09-06']);
        self::assertGreaterThan(0, $sessionB, 'The lesson of the other class is opened with it.');
        self::assertSame('משוואות', App::db()->value('SELECT topic FROM lesson_sessions WHERE id = ?', [$sessionB]));
        self::assertSame('absent', App::db()->value('SELECT status FROM attendance_records WHERE lesson_session_id = ? AND student_id = ?', [$sessionB, $this->s['b1']]),
            'ז2 student recorded in the ז2 lesson.');
        self::assertSame('late', App::db()->value('SELECT status FROM attendance_records WHERE lesson_session_id = ? AND student_id = ?', [$session, $this->s['a1']]));
        self::assertNull(App::db()->value('SELECT id FROM attendance_records WHERE student_id = ?', [$this->s['a3']]), 'Non-members cannot be recorded.');

        // Opening the ז2 entry shows the same group lesson with the saved statuses.
        $pageB = $this->get("/attendance/sessions/$sessionB")->body;
        self::assertStringContainsString('name="students[' . $this->s['b1'] . '][status]" value="absent"', $pageB);
        self::assertStringContainsString('name="students[' . $this->s['a1'] . '][status]" value="late"', $pageB);

        // Rates: a3 is not in the group, so this lesson does not count for them.
        $rows = array_column(AttendanceStats::byStudent(App::db(), $this->classA, ['1 = 1', []]), null, 'id');
        self::assertSame(1, $rows[$this->s['a1']]['sessions']);
        self::assertSame(0, $rows[$this->s['a3']]['sessions']);
        self::assertNull($rows[$this->s['a3']]['rate']);
        // Expected attendances: 2 members in ז1 + 1 in ז2 = 3, one absence.
        self::assertSame(66.7, AttendanceStats::rate(App::db(), $this->school, $this->year, ['1 = 1', []]));

        // Cancelling cancels the lesson for both classes.
        $this->post("/attendance/sessions/$session/cancel");
        self::assertSame(['cancelled', 'cancelled'], App::db()->column('SELECT status FROM lesson_sessions ORDER BY id'));
    }

    public function testTeacherWeekShowsTheGroupLessonOnce(): void
    {
        $this->buildGroup();
        foreach ([$this->mathA, $this->mathB] as $assignment) {
            App::db()->insert('timetable_slots', ['school_id' => $this->school, 'academic_year_id' => $this->year,
                'teaching_assignment_id' => $assignment, 'weekday' => 0, 'period_number' => 3]);
        }
        $week = TeacherWeek::build(App::db(), $this->school, App::db()->one('SELECT * FROM academic_years WHERE id = ?', [$this->year]), $this->teacher, '2026-09-06', true);
        self::assertCount(1, $week['grid'][3][0]);
        self::assertSame(['ז1', 'ז2'], array_values($week['grid'][3][0])[0]['classes']);
        self::assertSame(1, $week['lessons']);
        $this->loginAs($this->teacherUser);
        self::assertStringContainsString('עתודה – ז1، ז2', $this->get('/', ['week' => '2026-09-06'])->body);
    }

    public function testOnlyStructureManagersAndOnlyTheirSchool(): void
    {
        $group = $this->buildGroup();
        $this->loginAs($this->teacherUser);
        self::assertSame(403, $this->get('/study-groups')->status);
        self::assertSame(403, $this->post("/study-groups/$group/members", ['class_id' => (string) $this->classA])->status);

        $other = $this->createSchool();
        $this->createYear($other);
        $this->loginAs($this->createUser('t.other', [['principal', $other]]));
        self::assertSame(404, $this->get("/study-groups/$group")->status);
        $this->post("/study-groups/$group/lessons", ['assignment_ids' => []]);
        self::assertSame(2, (int) App::db()->value('SELECT COUNT(*) FROM teaching_assignments WHERE study_group_id = ?', [$group]));

        // The year locked: no changes.
        $this->loginAs($this->principal);
        App::db()->update('academic_years', ['is_locked' => 1], ['id' => $this->year]);
        $this->loginAs($this->createUser('t.principal2', [['principal', $this->school]]));
        $this->post("/study-groups/$group/lessons", ['assignment_ids' => []]);
        self::assertSame(2, (int) App::db()->value('SELECT COUNT(*) FROM teaching_assignments WHERE study_group_id = ?', [$group]));
    }

    public function testSharedLessonsOfTheTimetableBecomeGroups(): void
    {
        $db = App::db();
        $slot = fn (int $assignment, int $day, int $period) => $db->insert('timetable_slots', ['school_id' => $this->school,
            'academic_year_id' => $this->year, 'teaching_assignment_id' => $assignment, 'weekday' => $day, 'period_number' => $period]);
        // Math in ז1 and ז2 together on Sunday 3rd period (and ז1 alone on Monday): one shared lesson.
        $slot($this->mathA, 0, 3);
        $slot($this->mathB, 0, 3);
        $slot($this->mathA, 1, 1);
        // Another teacher's lesson at the same time is not part of it.
        $other = $this->assign($this->school, $this->year, $this->createTeacher($this->school, 'כהן'), $this->classB, $this->createSubject($this->school, 'אנגלית'));
        $slot($other, 0, 3);

        $page = $this->get('/study-groups/shared')->body;
        self::assertStringContainsString('ז1، ז2', $page);
        self::assertStringContainsString(e(__('study_groups.shared.create', ['count' => 1])), $page);
        self::assertStringContainsString('href="/study-groups/shared"', $this->get('/study-groups')->body);

        $this->assertRedirect('/study-groups', $this->post('/study-groups/shared'));
        $group = (int) $db->value('SELECT id FROM study_groups WHERE school_id = ?', [$this->school]);
        self::assertSame('מתמטיקה – רות לוי (ז1، ז2)', $db->value('SELECT name FROM study_groups WHERE id = ?', [$group]));
        self::assertSame([$this->mathA, $this->mathB], array_map('intval', $db->column('SELECT id FROM teaching_assignments WHERE study_group_id = ? ORDER BY id', [$group])));
        self::assertNull($db->value('SELECT study_group_id FROM teaching_assignments WHERE id = ?', [$other]));
        self::assertSame(5, (int) $db->value('SELECT COUNT(*) FROM study_group_students WHERE group_id = ?', [$group]), 'All students of both classes, to be trimmed.');

        // Nothing left to detect; the teacher's week shows the lesson once.
        self::assertStringContainsString(e(__('study_groups.shared.none')), $this->get('/study-groups/shared')->body);
        $week = TeacherWeek::build($db, $this->school, $db->one('SELECT * FROM academic_years WHERE id = ?', [$this->year]), $this->teacher, '2026-09-06', true);
        self::assertCount(1, $week['grid'][3][0]);
    }

    public function testSplitClassesGiveAGroupPerTeacher(): void
    {
        $db = App::db();
        $pe = $this->createSubject($this->school, 'חינוך גופני');
        $teacher2 = $this->createTeacher($this->school, 'כהן');
        $peA1 = $this->assign($this->school, $this->year, $this->teacher, $this->classA, $pe);
        $peA2 = $this->assign($this->school, $this->year, $teacher2, $this->classA, $pe);
        $peB = $this->assign($this->school, $this->year, $teacher2, $this->classB, $pe);
        $slot = fn (int $assignment, int $day, int $period) => $db->insert('timetable_slots', ['school_id' => $this->school,
            'academic_year_id' => $this->year, 'teaching_assignment_id' => $assignment, 'weekday' => $day, 'period_number' => $period]);
        // ז1 has PE with two teachers at once; ז2 has PE with one teacher, at another time.
        $slot($peA1, 2, 4);
        $slot($peA2, 2, 4);
        $slot($peB, 3, 1);

        self::assertStringContainsString(e(__('study_groups.shared.kinds.split')), $this->get('/study-groups/shared')->body);
        $this->post('/study-groups/shared');
        $groups = $db->all('SELECT g.name, ta.id AS assignment, (SELECT COUNT(*) FROM study_group_students m WHERE m.group_id = g.id) AS members
                              FROM study_groups g JOIN teaching_assignments ta ON ta.study_group_id = g.id ORDER BY ta.id');
        self::assertSame([
            ['name' => 'חינוך גופני – רות לוי (ז1)', 'assignment' => $peA1, 'members' => 3],
            ['name' => 'חינוך גופני – ' . $db->value("SELECT CONCAT(first_name, ' ', last_name) FROM teachers WHERE id = ?", [$teacher2]) . ' (ז1)', 'assignment' => $peA2, 'members' => 3],
        ], array_map(static fn ($g) => ['name' => $g['name'], 'assignment' => (int) $g['assignment'], 'members' => (int) $g['members']], $groups));
        self::assertNull($db->value('SELECT study_group_id FROM teaching_assignments WHERE id = ?', [$peB]), 'A class with one teacher is not split.');
    }

    public function testMembersAreChosenOnTheEditPage(): void
    {
        $group = $this->buildGroup();   // a1, a2 (ז1) and b1 (ז2); lessons in ז1 and ז2
        $db = App::db();
        $page = $this->get("/study-groups/$group/edit")->body;
        self::assertStringContainsString('name="member_classes[]" value="' . $this->classA . '"', $page);
        self::assertStringContainsString('name="member_classes[]" value="' . $this->classB . '"', $page);
        self::assertMatchesRegularExpression('/value="' . $this->s['a1'] . '" id="mp-' . $this->classA . '-\d+" checked/', $page);
        self::assertDoesNotMatchRegularExpression('/value="' . $this->s['a3'] . '" id="mp-' . $this->classA . '-\d+" checked/', $page);
        self::assertStringContainsString('data-check-all="mp-class-' . $this->classA . '"', $page);

        // Rename and choose a3 + b2 instead; both classes are on the form, so both are replaced.
        $response = $this->post("/study-groups/$group", ['name' => 'עתודה חדשה', 'subject_id' => (string) $this->math,
            'member_classes' => [(string) $this->classA, (string) $this->classB], 'student_ids' => [(string) $this->s['a3'], (string) $this->s['b2']]]);
        $this->assertRedirect("/study-groups/$group", $response);
        self::assertSame('עתודה חדשה', $db->value('SELECT name FROM study_groups WHERE id = ?', [$group]));
        self::assertSame([$this->s['a3'], $this->s['b2']], array_map('intval', $db->column('SELECT student_id FROM study_group_students WHERE group_id = ? ORDER BY student_id', [$group])));

        // A failed validation changes nothing; a form without classes leaves the members alone.
        $this->post("/study-groups/$group", ['name' => '', 'member_classes' => [(string) $this->classA], 'student_ids' => []]);
        self::assertSame(2, (int) $db->value('SELECT COUNT(*) FROM study_group_students WHERE group_id = ?', [$group]));
        $this->post("/study-groups/$group", ['name' => 'עתודה', 'subject_id' => (string) $this->math]);
        self::assertSame(2, (int) $db->value('SELECT COUNT(*) FROM study_group_students WHERE group_id = ?', [$group]));
    }

    public function testListFilters(): void
    {
        $db = App::db();
        $this->buildGroup();   // עתודה: manual, lessons + members
        $db->insert('study_groups', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'name' => 'ריקה', 'origin' => 'split']);
        $list = fn (array $q) => $this->get('/study-groups', $q)->body;

        $page = $list([]);
        self::assertStringContainsString('class="filter-bar', $page);
        self::assertStringContainsString('<label class="ff-label" for="filter-status">' . e(__('study_groups.filters.status')) . '</label>', $page);
        foreach ([['status' => 'active'], ['teacher_id' => (string) $this->teacher], ['class_id' => (string) $this->classB], ['subject_id' => (string) $this->math], ['origin' => 'manual']] as $q) {
            $body = $list($q);
            self::assertStringContainsString('עתודה', $body, json_encode($q));
            self::assertStringNotContainsString('ריקה', $body, json_encode($q));
        }
        $body = $list(['status' => 'no_lessons', 'origin' => 'split']);
        self::assertStringContainsString('ריקה', $body);
        self::assertStringNotContainsString('>עתודה<', $body);
    }

    public function testPagesRenderInBothLanguages(): void
    {
        $group = $this->buildGroup();
        foreach (['he', 'ar'] as $locale) {
            App::db()->update('users', ['locale' => $locale], ['id' => $this->principal]);
            $this->loginAs($this->principal);
            foreach (['/study-groups', '/study-groups/create', "/study-groups/$group", "/study-groups/$group/edit"] as $path) {
                $response = $this->get($path);
                self::assertSame(200, $response->status, $path);
                self::assertStringContainsString('dir="rtl"', $response->body);
                self::assertDoesNotMatchRegularExpression('/study_groups\.[a-z_]+/', strip_tags($response->body), "Raw key on $path");
            }
        }
    }
}
