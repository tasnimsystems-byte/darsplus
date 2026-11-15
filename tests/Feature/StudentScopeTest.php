<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use Tests\TestCase;

/** Students, enrolment, and who may see which student. */
final class StudentScopeTest extends TestCase
{
    private int $school;
    private int $year;
    private int $level7;
    private int $level8;
    private int $classA;
    private int $classB;
    private int $classC;
    private int $math;
    private int $teacherUser;
    private int $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->year = $this->createYear($this->school);
        $this->level7 = $this->createLevel($this->school, 'ז', 7);
        $this->level8 = $this->createLevel($this->school, 'ח', 8);
        $this->classA = $this->createClass($this->school, $this->year, $this->level7, 'ז1');
        $this->classB = $this->createClass($this->school, $this->year, $this->level7, 'ז2');
        $this->classC = $this->createClass($this->school, $this->year, $this->level8, 'ח1');
        $this->math = $this->createSubject($this->school, 'מתמטיקה');
        $this->teacherUser = $this->createUser('t.teacher', [['subject_teacher', $this->school]]);
        $this->teacher = $this->createTeacher($this->school, 'המורה', $this->teacherUser);

        $this->createStudent($this->school, 'Alpha-InA', $this->classA);
        $this->createStudent($this->school, 'Beta-InB', $this->classB);
        $this->createStudent($this->school, 'Gamma-InC', $this->classC);
        $this->createStudent($this->school, 'Delta-NoClass');
    }

    private function visibleNames(): array
    {
        preg_match_all('/(Alpha-InA|Beta-InB|Gamma-InC|Delta-NoClass)/', $this->get('/students')->body, $m);
        return array_values(array_unique($m[1]));
    }

    private function studentId(string $lastName): int
    {
        return (int) App::db()->value('SELECT id FROM students WHERE last_name = ?', [$lastName]);
    }

    public function testPrincipalSeesEveryStudentOfTheSchool(): void
    {
        $this->loginAs($this->createUser('t.principal', [['principal', $this->school]]));
        self::assertSame(['Alpha-InA', 'Beta-InB', 'Delta-NoClass', 'Gamma-InC'], $this->visibleNames());
    }

    public function testTeacherWithoutAssignmentsSeesNobody(): void
    {
        $this->loginAs($this->teacherUser);
        self::assertSame([], $this->visibleNames());
        self::assertSame(404, $this->get('/students/' . $this->studentId('Alpha-InA'))->status);
        self::assertMatchesRegularExpression('/kpi-value">\s*0\s*</', $this->get('/')->body);
    }

    public function testTeacherSeesOnlyStudentsOfClassesTheyTeach(): void
    {
        $this->assign($this->school, $this->year, $this->teacher, $this->classA, $this->math);
        $this->loginAs($this->teacherUser);

        self::assertSame(['Alpha-InA'], $this->visibleNames());
        self::assertSame(200, $this->get('/students/' . $this->studentId('Alpha-InA'))->status);
        foreach (['Beta-InB', 'Gamma-InC', 'Delta-NoClass'] as $hidden) {
            self::assertSame(404, $this->get('/students/' . $this->studentId($hidden))->status, $hidden);
        }
        // Filtering or searching cannot widen the scope.
        self::assertStringNotContainsString('Beta-InB', $this->get('/students', ['class_id' => $this->classB])->body);
        self::assertStringNotContainsString('Beta-InB', $this->get('/students', ['q' => 'Beta'])->body);
        // Classes follow the same scope.
        $classes = $this->get('/classes')->body;
        self::assertStringContainsString('>ז1<', $classes);
        self::assertStringNotContainsString('>ז2<', $classes);
        self::assertSame(200, $this->get("/classes/{$this->classA}")->status);
        self::assertSame(404, $this->get("/classes/{$this->classB}")->status);
        self::assertStringNotContainsString('Beta-InB', $this->get("/classes/{$this->classA}")->body);
    }

    public function testHomeroomPositionAndManualScopesWidenAccess(): void
    {
        // Homeroom teacher of ז2
        App::db()->update('classes', ['homeroom_teacher_id' => $this->teacher], ['id' => $this->classB]);
        $this->loginAs($this->teacherUser);
        self::assertSame(['Beta-InB'], $this->visibleNames());

        // Grade coordinator of level 7 → both classes of the level
        App::db()->insert('staff_positions', [
            'school_id' => $this->school, 'academic_year_id' => $this->year, 'teacher_id' => $this->teacher,
            'position_type' => 'grade_coordinator', 'grade_level_id' => $this->level7,
        ]);
        $this->loginAs($this->teacherUser);
        self::assertSame(['Alpha-InA', 'Beta-InB'], $this->visibleNames());

        // Manual scope on class ח1
        App::db()->insert('user_scopes', [
            'user_id' => $this->teacherUser, 'school_id' => $this->school, 'academic_year_id' => $this->year,
            'scope_type' => 'class', 'scope_id' => $this->classC,
        ]);
        $this->loginAs($this->teacherUser);
        self::assertSame(['Alpha-InA', 'Beta-InB', 'Gamma-InC'], $this->visibleNames());

        // Manual "all" → also students without a class
        App::db()->insert('user_scopes', [
            'user_id' => $this->teacherUser, 'school_id' => $this->school, 'academic_year_id' => $this->year, 'scope_type' => 'all',
        ]);
        $this->loginAs($this->teacherUser);
        self::assertCount(4, $this->visibleNames());
    }

    public function testSubjectCoordinatorSeesClassesWhereTheSubjectIsTaught(): void
    {
        $colleague = $this->createTeacher($this->school, 'עמית');
        $this->assign($this->school, $this->year, $colleague, $this->classC, $this->math);
        App::db()->insert('staff_positions', [
            'school_id' => $this->school, 'academic_year_id' => $this->year, 'teacher_id' => $this->teacher,
            'position_type' => 'subject_coordinator', 'subject_id' => $this->math,
        ]);
        $this->loginAs($this->teacherUser);
        self::assertSame(['Gamma-InC'], $this->visibleNames());
    }

    public function testScopeOfLastYearDoesNotCarryIntoThisYear(): void
    {
        $lastYear = $this->createYear($this->school, '2025-26', false, '2025-09-01', '2026-06-30');
        $oldClass = $this->createClass($this->school, $lastYear, $this->level7, 'ז1 אשתקד');
        $this->assign($this->school, $lastYear, $this->teacher, $oldClass, $this->math);
        $this->loginAs($this->teacherUser);
        self::assertSame([], $this->visibleNames(), 'Teaching a class last year gives no access to this year\'s students.');
    }

    public function testManualScopesAreManagedOnlyByThoseWhoSeeTheWholeSchool(): void
    {
        $this->loginAs($this->createUser('t.principal', [['principal', $this->school]]));
        $this->assertRedirect("/users/{$this->teacherUser}/scopes", $this->post("/users/{$this->teacherUser}/scopes", ['scope_type' => 'class', 'scope_id' => $this->classA]));
        $this->post("/users/{$this->teacherUser}/scopes", ['scope_type' => 'class', 'scope_id' => $this->classA]); // no duplicate
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM user_scopes'));
        self::assertStringContainsString('ז1', $this->get("/users/{$this->teacherUser}/scopes")->body);

        // A target from another school is refused.
        $other = $this->createSchool();
        $foreignClass = $this->createClass($other, $this->createYear($other), $this->createLevel($other, 'ז', 7), 'x');
        $this->post("/users/{$this->teacherUser}/scopes", ['scope_type' => 'class', 'scope_id' => $foreignClass]);
        self::assertSame('scopes.errors.target', $this->session->pullFlash('error'));
        self::assertSame(403, $this->post("/users/{$this->teacherUser}/scopes", ['scope_type' => 'planet', 'scope_id' => 1])->status);

        $scopeId = (int) App::db()->value('SELECT id FROM user_scopes');
        $this->loginAs($this->teacherUser);
        self::assertSame(['Alpha-InA'], $this->visibleNames());

        // A restricted user holding users.update still cannot hand out scopes.
        $clerkRole = $this->createRole($this->school, 'clerk', ['dashboard.view', 'users.view', 'users.update']);
        $clerk = $this->createUser('t.clerk');
        App::db()->insert('user_roles', ['user_id' => $clerk, 'role_id' => $clerkRole, 'school_id' => $this->school]);
        $this->loginAs($clerk);
        self::assertSame(403, $this->get("/users/{$this->teacherUser}/scopes")->status);
        self::assertSame(403, $this->post("/users/{$this->teacherUser}/scopes", ['scope_type' => 'all'])->status);
        self::assertSame(403, $this->post("/users/{$this->teacherUser}/scopes/$scopeId/delete")->status);

        $this->loginAs($this->createUser('t.principal2', [['principal', $this->school]]));
        $this->assertRedirect("/users/{$this->teacherUser}/scopes", $this->post("/users/{$this->teacherUser}/scopes/$scopeId/delete"));
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM user_scopes'));
    }

    public function testCustomRoleCannotBeGivenWholeSchoolScopeByARestrictedUser(): void
    {
        $role = $this->createRole($this->school, 'role_maker', ['dashboard.view', 'roles.view', 'roles.create', 'roles.update']);
        $maker = $this->createUser('t.maker');
        App::db()->insert('user_roles', ['user_id' => $maker, 'role_id' => $role, 'school_id' => $this->school]);
        $this->loginAs($maker);
        self::assertSame(403, $this->post('/roles', ['name' => 'Sees all', 'scope_mode' => 'all'])->status);
        $this->assertRedirect('/roles', $this->post('/roles', ['name' => 'Sees own', 'scope_mode' => 'assigned']));
        self::assertSame('assigned', App::db()->value("SELECT scope_mode FROM roles WHERE name = 'Sees own'"));

        $this->loginAs($this->createUser('t.admin', [['system_admin', null]]), $this->school);
        $this->assertRedirect('/roles', $this->post('/roles', ['name' => 'Sees all', 'scope_mode' => 'all']));
        self::assertSame('all', App::db()->value("SELECT scope_mode FROM roles WHERE name = 'Sees all'"));
    }

    public function testCreateStudentWithGeneratedCodeAndEnrolment(): void
    {
        $this->loginAs($this->createUser('t.principal', [['principal', $this->school]]));
        App::db()->insert('students', ['school_id' => $this->school, 'student_code' => 'S00041', 'first_name' => 'a', 'last_name' => 'b']);

        $response = $this->post('/students', [
            'first_name' => 'יעל', 'last_name' => 'בן-דוד', 'gender' => 'f', 'birth_date' => '2013-04-02',
            'class_id' => $this->classA, 'status' => 'active',
        ]);
        $student = App::db()->one("SELECT * FROM students WHERE last_name = 'בן-דוד'");
        $this->assertRedirect('/students/' . $student['id'], $response);
        self::assertSame('S00042', $student['student_code'], 'Next free internal code.');
        self::assertSame($this->classA, (int) App::db()->value('SELECT class_id FROM class_enrollments WHERE student_id = ? AND left_on IS NULL', [$student['id']]));

        // Validation
        $this->post('/students', ['first_name' => '', 'last_name' => 'x', 'gender' => 'q', 'birth_date' => '2999-01-01', 'status' => 'active', 'student_code' => 'bad code!']);
        self::assertSame(['first_name', 'student_code', 'gender'], array_keys($this->session->pullFlash('errors')));
        $this->post('/students', ['first_name' => 'a', 'last_name' => 'x', 'birth_date' => '2999-01-01', 'status' => 'active']);
        self::assertArrayHasKey('birth_date', $this->session->pullFlash('errors'));
        $this->post('/students', ['first_name' => 'a', 'last_name' => 'x', 'status' => 'active', 'student_code' => 'S00042']);
        self::assertArrayHasKey('student_code', $this->session->pullFlash('errors'));
    }

    public function testMovingClassKeepsHistoryAndViewIsAudited(): void
    {
        $this->loginAs($this->createUser('t.principal', [['principal', $this->school]]));
        $id = $this->studentId('Alpha-InA');
        $data = ['first_name' => 'S', 'last_name' => 'Alpha-InA', 'status' => 'active'];

        $this->assertRedirect("/students/$id", $this->post("/students/$id", $data + ['class_id' => $this->classB]));
        $rows = App::db()->all('SELECT class_id, left_on FROM class_enrollments WHERE student_id = ? ORDER BY id', [$id]);
        self::assertCount(2, $rows);
        self::assertSame($this->classA, (int) $rows[0]['class_id']);
        self::assertNotNull($rows[0]['left_on']);
        self::assertSame([$this->classB, null], [(int) $rows[1]['class_id'], $rows[1]['left_on']]);

        // Back to the first class: the old row is reopened rather than duplicated.
        $this->post("/students/$id", $data + ['class_id' => $this->classA]);
        self::assertSame(2, (int) App::db()->value('SELECT COUNT(*) FROM class_enrollments WHERE student_id = ?', [$id]));
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM class_enrollments WHERE student_id = ? AND left_on IS NULL', [$id]));

        // Removing from any class
        $this->post("/students/$id", $data + ['class_id' => '']);
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM class_enrollments WHERE student_id = ? AND left_on IS NULL', [$id]));

        $body = $this->get("/students/$id")->body;
        self::assertStringContainsString('ז2', $body);
        self::assertSame(1, $this->auditCount('view', 'student'));

        // Soft delete keeps the row and its code reserved.
        $this->assertRedirect('/students', $this->post("/students/$id/delete"));
        self::assertNotNull(App::db()->value('SELECT deleted_at FROM students WHERE id = ?', [$id]));
        self::assertSame(404, $this->get("/students/$id")->status);
    }

    public function testRestrictedUserMustPlaceNewStudentInOwnClass(): void
    {
        $role = $this->createRole($this->school, 'registrar', ['dashboard.view', 'students.view', 'students.create', 'students.update']);
        App::db()->insert('user_roles', ['user_id' => $this->teacherUser, 'role_id' => $role, 'school_id' => $this->school]);
        $this->assign($this->school, $this->year, $this->teacher, $this->classA, $this->math);
        $this->loginAs($this->teacherUser);

        $this->post('/students', ['first_name' => 'a', 'last_name' => 'NoClassGiven', 'status' => 'active']);
        self::assertArrayHasKey('class_id', $this->session->pullFlash('errors'));
        $this->post('/students', ['first_name' => 'a', 'last_name' => 'WrongClass', 'status' => 'active', 'class_id' => $this->classB]);
        self::assertArrayHasKey('class_id', $this->session->pullFlash('errors'));
        $this->post('/students', ['first_name' => 'a', 'last_name' => 'OwnClass', 'status' => 'active', 'class_id' => $this->classA]);
        self::assertNotNull(App::db()->value("SELECT id FROM students WHERE last_name = 'OwnClass'"));
        self::assertNull(App::db()->value("SELECT id FROM students WHERE last_name IN ('NoClassGiven', 'WrongClass')"));
        // …and cannot edit a student outside the scope.
        self::assertSame(404, $this->post('/students/' . $this->studentId('Beta-InB'), ['first_name' => 'x', 'last_name' => 'y', 'status' => 'left', 'class_id' => $this->classA])->status);
    }
}
