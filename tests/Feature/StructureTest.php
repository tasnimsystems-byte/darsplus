<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use Tests\TestCase;

/** Years, terms, grade levels, subjects, rooms, calendar, classes, teachers and assignments. */
final class StructureTest extends TestCase
{
    private int $school;
    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->year = $this->createYear($this->school);
        $this->loginAs($this->createUser('t.principal', [['principal', $this->school]]));
    }

    public function testSimpleLookupCrudWithUniquenessAndAudit(): void
    {
        $this->assertRedirect('/subjects', $this->post('/subjects', ['name' => 'מתמטיקה', 'code' => 'MATH', 'is_active' => '1']));
        $id = (int) App::db()->value("SELECT id FROM subjects WHERE name = 'מתמטיקה'");
        self::assertSame($this->school, (int) App::db()->value('SELECT school_id FROM subjects WHERE id = ?', [$id]));
        self::assertStringContainsString('מתמטיקה', $this->get('/subjects')->body);

        // Same name again → refused with a message on the field.
        $this->assertRedirect('/subjects/create', $this->post('/subjects', ['name' => 'מתמטיקה']));
        self::assertArrayHasKey('name', $this->session->pullFlash('errors'));

        // Another school may use the same name.
        $other = $this->createSchool();
        App::db()->insert('subjects', ['school_id' => $other, 'name' => 'מתמטיקה']);

        $this->assertRedirect('/subjects', $this->post("/subjects/$id", ['name' => 'מתמטיקה מוגברת', 'code' => 'M5']));
        $row = App::db()->one('SELECT name, code, is_active FROM subjects WHERE id = ?', [$id]);
        self::assertSame(['name' => 'מתמטיקה מוגברת', 'code' => 'M5', 'is_active' => 0], $row, 'Unchecked switch is saved as off.');

        $this->assertRedirect('/subjects', $this->post("/subjects/$id/delete"));
        self::assertNull(App::db()->value('SELECT id FROM subjects WHERE id = ?', [$id]));
        foreach (['create', 'update', 'delete'] as $action) {
            self::assertSame(1, $this->auditCount($action, 'subject'), $action);
        }
    }

    public function testValidationOfLookups(): void
    {
        $this->post('/grade-levels', ['name' => '', 'level_number' => '99']);
        self::assertSame(['name', 'level_number'], array_keys($this->session->pullFlash('errors')));
        $this->post('/rooms', ['name' => 'מעבדה', 'capacity' => 'many']);
        self::assertArrayHasKey('capacity', $this->session->pullFlash('errors'));
        $this->post('/calendar', ['title' => 'חופשה', 'event_type' => 'party', 'start_date' => '2026-10-10', 'end_date' => '2026-10-01']);
        self::assertArrayHasKey('event_type', $this->session->pullFlash('errors'));
        $this->post('/calendar', ['title' => 'חופשה', 'event_type' => 'holiday', 'start_date' => '2026-10-10', 'end_date' => '2026-10-01']);
        self::assertArrayHasKey('end_date', $this->session->pullFlash('errors'));
        $this->post('/calendar', ['title' => 'חופשה', 'event_type' => 'holiday', 'start_date' => '2026-13-45', 'end_date' => 'tomorrow']);
        self::assertSame(['start_date', 'end_date'], array_keys($this->session->pullFlash('errors')));
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM calendar_events'));
    }

    public function testRecordsOfAnotherSchoolDoNotExistHere(): void
    {
        $other = $this->createSchool();
        $foreignSubject = $this->createSubject($other, 'Foreign subject');
        $foreignLevel = $this->createLevel($other, 'Foreign level', 7);
        $foreignYear = $this->createYear($other);
        $foreignClass = $this->createClass($other, $foreignYear, $foreignLevel, 'Foreign class');
        $foreignTeacher = $this->createTeacher($other, 'Foreigner');
        $foreignStudent = $this->createStudent($other, 'Foreign student', $foreignClass);

        foreach (['/subjects', '/grade-levels', '/classes', '/teachers', '/students', '/years'] as $list) {
            self::assertStringNotContainsString('Foreign', $this->get($list)->body, $list);
        }
        $paths = [
            "/subjects/$foreignSubject/edit", "/grade-levels/$foreignLevel/edit", "/classes/$foreignClass",
            "/classes/$foreignClass/edit", "/teachers/$foreignTeacher", "/students/$foreignStudent",
            "/students/$foreignStudent/edit", "/years/$foreignYear", "/years/$foreignYear/transition",
        ];
        foreach ($paths as $path) {
            self::assertSame(404, $this->get($path)->status, $path);
        }
        self::assertSame(404, $this->post("/subjects/$foreignSubject", ['name' => 'Hijacked'])->status);
        self::assertSame(404, $this->post("/subjects/$foreignSubject/delete")->status);
        self::assertSame(404, $this->post("/students/$foreignStudent/delete")->status);
        self::assertSame(404, $this->post("/years/$foreignYear/current")->status);
        self::assertSame('Foreign subject', App::db()->value('SELECT name FROM subjects WHERE id = ?', [$foreignSubject]));
    }

    public function testForeignIdsInSelectsAreRejected(): void
    {
        $other = $this->createSchool();
        $foreignLevel = $this->createLevel($other, 'ח', 8);
        $foreignTeacher = $this->createTeacher($other, 'Foreigner');
        $level = $this->createLevel($this->school, 'ז', 7);

        $this->post('/classes', ['name' => 'ז1', 'grade_level_id' => $foreignLevel]);
        self::assertArrayHasKey('grade_level_id', $this->session->pullFlash('errors'));
        $this->post('/classes', ['name' => 'ז1', 'grade_level_id' => $level, 'homeroom_teacher_id' => $foreignTeacher]);
        self::assertArrayHasKey('homeroom_teacher_id', $this->session->pullFlash('errors'));
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM classes'));
    }

    public function testDatabaseItselfRefusesCrossSchoolLinks(): void
    {
        $other = $this->createSchool();
        $foreignLevel = $this->createLevel($other, 'ח', 8);
        $level = $this->createLevel($this->school, 'ז', 7);
        $class = $this->createClass($this->school, $this->year, $level, 'ז1');
        $foreignStudent = $this->createStudent($other, 'Foreign');

        $attempts = [
            fn () => App::db()->insert('classes', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'grade_level_id' => $foreignLevel, 'name' => 'bad']),
            fn () => App::db()->insert('class_enrollments', ['school_id' => $this->school, 'student_id' => $foreignStudent, 'class_id' => $class, 'enrolled_on' => '2026-09-01']),
            fn () => App::db()->insert('class_enrollments', ['school_id' => $other, 'student_id' => $foreignStudent, 'class_id' => $class, 'enrolled_on' => '2026-09-01']),
        ];
        foreach ($attempts as $i => $attempt) {
            try {
                $attempt();
                self::fail("Cross-school insert #$i was accepted by the database.");
            } catch (\PDOException $e) {
                self::assertSame('23000', $e->getCode());
            }
        }
    }

    public function testRecordInUseCannotBeDeleted(): void
    {
        $level = $this->createLevel($this->school, 'ז', 7);
        $this->createClass($this->school, $this->year, $level, 'ז1');
        $this->assertRedirect('/grade-levels', $this->post("/grade-levels/$level/delete"));
        self::assertSame('app.errors.in_use', $this->session->pullFlash('error'));
        self::assertNotNull(App::db()->value('SELECT id FROM grade_levels WHERE id = ?', [$level]));
    }

    public function testYearLifecycleTermsCurrentAndLock(): void
    {
        $this->post('/years', ['name' => '2027-28', 'start_date' => '2027-09-01', 'end_date' => '2027-08-01']);
        self::assertArrayHasKey('end_date', $this->session->pullFlash('errors'));

        $this->assertRedirect('/years', $this->post('/years', ['name' => '2027-28', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'is_current' => '1', 'is_locked' => '1']));
        $new = App::db()->one("SELECT * FROM academic_years WHERE name = '2027-28'");
        self::assertSame([0, 0], [(int) $new['is_current'], (int) $new['is_locked']], 'Flags cannot be set through the form.');
        $newId = (int) $new['id'];

        // Terms must lie inside the year and be unique by name.
        $this->post("/years/$newId/terms", ['name' => 'מחצית א', 'start_date' => '2027-08-01', 'end_date' => '2028-01-31']);
        self::assertArrayHasKey('start_date', $this->session->pullFlash('errors'));
        $this->assertRedirect("/years/$newId", $this->post("/years/$newId/terms", ['name' => 'מחצית א', 'start_date' => '2027-09-01', 'end_date' => '2028-01-31']));
        $this->post("/years/$newId/terms", ['name' => 'מחצית א', 'start_date' => '2028-02-01', 'end_date' => '2028-06-30']);
        self::assertArrayHasKey('name', $this->session->pullFlash('errors'));
        self::assertStringContainsString('מחצית א', $this->get("/years/$newId")->body);

        // The current year cannot be locked; another one can, and then becomes read-only.
        $this->post("/years/{$this->year}/lock");
        self::assertSame(0, (int) App::db()->value('SELECT is_locked FROM academic_years WHERE id = ?', [$this->year]));
        $this->post("/years/$newId/current");
        self::assertSame(
            [$newId],
            array_map('intval', App::db()->column('SELECT id FROM academic_years WHERE school_id = ? AND is_current = 1', [$this->school]))
        );
        $this->post("/years/{$this->year}/lock");
        self::assertSame(1, (int) App::db()->value('SELECT is_locked FROM academic_years WHERE id = ?', [$this->year]));

        $this->post('/year', ['year_id' => $this->year, 'back' => '/classes/5']);
        self::assertSame($this->year, (int) App::auth()->year()['id']);
        $level = $this->createLevel($this->school, 'ז', 7);
        $this->assertRedirect('/classes', $this->post('/classes', ['name' => 'ז1', 'grade_level_id' => $level]));
        self::assertSame('app.errors.year_locked', $this->session->pullFlash('error'));
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM classes'));
        $this->assertRedirect("/years/{$this->year}", $this->post("/years/{$this->year}/terms", ['name' => 'x', 'start_date' => '2026-09-01', 'end_date' => '2026-12-01']));
        self::assertSame(1, $this->auditCount('lock', 'academic_year'));
    }

    public function testYearCannotBeChosenFromAnotherSchool(): void
    {
        $foreignYear = $this->createYear($this->createSchool(), 'Foreign year');
        $this->post('/year', ['year_id' => $foreignYear]);
        self::assertSame($this->year, (int) App::auth()->year()['id']);
    }

    public function testClassesBelongToTheWorkingYear(): void
    {
        $level = $this->createLevel($this->school, 'ז', 7);
        $this->assertRedirect('/classes', $this->post('/classes', ['name' => 'ז1', 'grade_level_id' => $level]));
        $next = $this->createYear($this->school, '2027-28', false, '2027-09-01', '2028-06-30');
        $this->createClass($this->school, $next, $level, 'כיתת שנה הבאה');

        self::assertStringNotContainsString('כיתת שנה הבאה', $this->get('/classes')->body);
        $this->post('/year', ['year_id' => $next]);
        $body = $this->get('/classes')->body;
        self::assertStringContainsString('כיתת שנה הבאה', $body);
        self::assertStringNotContainsString('>ז1<', $body);
        // The same class name is allowed in a different year.
        $this->assertRedirect('/classes', $this->post('/classes', ['name' => 'ז1', 'grade_level_id' => $level]));
        self::assertSame(2, (int) App::db()->value("SELECT COUNT(*) FROM classes WHERE name = 'ז1'"));
    }

    public function testTeacherProfileSubjectsAndPositions(): void
    {
        $math = $this->createSubject($this->school, 'מתמטיקה');
        $level = $this->createLevel($this->school, 'ז', 7);
        $account = $this->createUser('t.account', [['subject_teacher', $this->school]]);
        $outsider = $this->createUser('t.outsider', [['subject_teacher', $this->createSchool()]]);

        // A user of another school cannot be linked.
        $this->post('/teachers', ['first_name' => 'נועה', 'last_name' => 'שגיא', 'user_id' => $outsider]);
        self::assertArrayHasKey('user_id', $this->session->pullFlash('errors'));

        $response = $this->post('/teachers', [
            'first_name' => 'נועה', 'last_name' => 'שגיא', 'staff_code' => 'T-17', 'phone' => '050-0000000',
            'user_id' => $account, 'subject_ids' => [$math, 999999], 'is_active' => '1',
        ]);
        $id = (int) App::db()->value("SELECT id FROM teachers WHERE staff_code = 'T-17'");
        $this->assertRedirect("/teachers/$id", $response);
        self::assertSame([$math], array_map('intval', App::db()->column('SELECT subject_id FROM teacher_subjects WHERE teacher_id = ?', [$id])));

        // One account ↔ one teacher.
        $this->post('/teachers', ['first_name' => 'א', 'last_name' => 'ב', 'user_id' => $account]);
        self::assertArrayHasKey('user_id', $this->session->pullFlash('errors'));

        $this->assertRedirect("/teachers/$id", $this->post("/teachers/$id/positions", ['position_type' => 'grade_coordinator', 'grade_level_id' => $level, 'responsibilities' => 'ריכוז שכבה ז']));
        $this->post("/teachers/$id/positions", ['position_type' => 'king']);
        self::assertArrayHasKey('position_type', $this->session->pullFlash('errors'));
        $body = $this->get("/teachers/$id")->body;
        self::assertStringContainsString('ריכוז שכבה ז', $body);
        self::assertStringContainsString('050-0000000', $body);

        // Contact details are for those who may edit teachers.
        $this->loginAs($this->createUser('t.viewer2', [['subject_coordinator', $this->school]]));
        $body = $this->get("/teachers/$id")->body;
        self::assertStringContainsString('שגיא', $body);
        self::assertStringNotContainsString('050-0000000', $body);
    }

    public function testAssignmentsAndTeacherDeletionRule(): void
    {
        $math = $this->createSubject($this->school, 'מתמטיקה');
        $level = $this->createLevel($this->school, 'ז', 7);
        $class = $this->createClass($this->school, $this->year, $level, 'ז1');
        $teacher = $this->createTeacher($this->school, 'כהן');
        $other = $this->createSchool();
        $foreignTeacher = $this->createTeacher($other, 'Foreign');
        $foreignClass = $this->createClass($other, $this->createYear($other), $this->createLevel($other, 'ז', 7), 'x');

        $this->assertRedirect("/classes/$class", $this->post('/assignments', ['class_id' => $class, 'teacher_id' => $teacher, 'subject_id' => $math, 'weekly_hours' => '4,5']));
        $row = App::db()->one('SELECT * FROM teaching_assignments');
        self::assertSame('4.5', $row['weekly_hours']);
        self::assertSame($this->year, (int) $row['academic_year_id']);

        $this->post('/assignments', ['class_id' => $class, 'teacher_id' => $teacher, 'subject_id' => $math]);
        self::assertArrayHasKey('teacher_id', $this->session->pullFlash('errors'), 'Duplicate assignment');
        $this->post('/assignments', ['class_id' => $class, 'teacher_id' => $foreignTeacher, 'subject_id' => $math]);
        self::assertArrayHasKey('teacher_id', $this->session->pullFlash('errors'));
        self::assertSame(404, $this->post('/assignments', ['class_id' => $foreignClass, 'teacher_id' => $teacher, 'subject_id' => $math])->status);
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM teaching_assignments'));

        $body = $this->get("/classes/$class")->body;
        self::assertStringContainsString('כהן', $body);
        self::assertStringContainsString('מתמטיקה', $body);

        // A teacher with assignments in an open year cannot be deleted.
        $this->assertRedirect('/teachers', $this->post("/teachers/$teacher/delete"));
        self::assertNull(App::db()->value('SELECT deleted_at FROM teachers WHERE id = ?', [$teacher]));
        $this->assertRedirect("/classes/$class", $this->post('/assignments/' . (int) $row['id'] . '/delete'));
        $this->post("/teachers/$teacher/delete");
        self::assertNotNull(App::db()->value('SELECT deleted_at FROM teachers WHERE id = ?', [$teacher]));
        self::assertStringNotContainsString('כהן', $this->get('/teachers')->body);
    }

    public function testStructureScreensRequireTheirPermissions(): void
    {
        $level = $this->createLevel($this->school, 'ז', 7);
        $this->loginAs($this->createUser('t.teacher', [['subject_teacher', $this->school]]));
        self::assertSame(200, $this->get('/classes')->status);
        self::assertSame(403, $this->get('/classes/create')->status);
        self::assertSame(403, $this->post('/subjects', ['name' => 'x'])->status);
        self::assertSame(403, $this->post("/grade-levels/$level/delete")->status);
        self::assertSame(403, $this->get('/teachers')->status);
        self::assertSame(403, $this->get('/students/import')->status);
        self::assertSame(403, $this->get("/years/{$this->year}/transition")->status);
    }

    public function testAllStructurePagesRenderInBothLanguages(): void
    {
        $level = $this->createLevel($this->school, 'ז', 7);
        $teacher = $this->createTeacher($this->school, 'כהן');
        $class = $this->createClass($this->school, $this->year, $level, 'ז1', $teacher);
        $student = $this->createStudent($this->school, 'לוי', $class);
        $user = $this->createUser('t.someone', [['subject_teacher', $this->school]]);
        $pages = ['/', '/years', '/years/create', "/years/{$this->year}", "/years/{$this->year}/transition", '/grade-levels',
            '/grade-levels/create', '/subjects', '/rooms/create', '/calendar', '/calendar/create', '/classes', '/classes/create',
            "/classes/$class", "/classes/$class/edit", '/teachers', '/teachers/create', "/teachers/$teacher", "/teachers/$teacher/edit",
            '/students', '/students/create', "/students/$student", "/students/$student/edit", '/students/import',
            "/users/$user/scopes", '/roles'];

        foreach (['he', 'ar'] as $locale) {
            $this->post('/locale', ['locale' => $locale]);
            foreach ($pages as $page) {
                $response = $this->get($page);
                self::assertSame(200, $response->status, "$locale $page");
                self::assertDoesNotMatchRegularExpression(
                    '/[>"]\s*(app|nav|years|grade_levels|subjects|rooms|calendar|classes|assignments|teachers|students|scopes|roles|dashboard)\.[a-z_.]+\s*[<"]/',
                    $response->body,
                    "$locale $page shows a raw translation key"
                );
            }
        }
    }
}
