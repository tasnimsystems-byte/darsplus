<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    private int $school;
    private int $other;
    private int $principal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->other = $this->createSchool();
        $this->principal = $this->createUser('t.principal', [['principal', $this->school]]);
        $this->loginAs($this->principal);
    }

    public function testPrincipalCreatesUserWithTemporaryPassword(): void
    {
        $this->assertRedirect('/users', $this->post('/users', [
            'username' => 'New.Teacher', 'full_name' => 'מורה חדשה', 'email' => 'new@example.test',
            'locale' => 'ar', 'roles' => [$this->roleId('subject_teacher')],
        ]));

        $user = App::db()->one("SELECT * FROM users WHERE username = 'new.teacher'");
        self::assertNotNull($user, 'Username is normalised to lower case.');
        self::assertSame('מורה חדשה', $user['full_name']);
        self::assertSame(1, (int) $user['must_change_password']);
        self::assertSame($this->principal, (int) $user['created_by']);
        self::assertSame(
            [$this->school],
            array_map('intval', App::db()->column('SELECT school_id FROM user_roles WHERE user_id = ?', [$user['id']]))
        );
        self::assertSame(1, $this->auditCount('create', 'user'));

        // The temporary password is flashed once, is not in the audit log, and works.
        $password = $this->session->pullFlash('temp_password');
        self::assertIsString($password);
        self::assertStringNotContainsString($password, (string) App::db()->value("SELECT new_values FROM audit_logs WHERE action = 'create'"));
        $this->post('/logout');
        $this->post('/login', ['username' => 'new.teacher', 'password' => $password]);
        self::assertSame((int) $user['id'], App::auth()->id());
        $this->assertRedirect('/password', $this->get('/'));
    }

    public function testValidationErrorsAreReportedAndNothingIsSaved(): void
    {
        $this->createUser('taken.name', [['viewer', $this->school]], ['email' => 'taken@example.test']);
        $before = (int) App::db()->value('SELECT COUNT(*) FROM users');

        $this->assertRedirect('/users/create', $this->post('/users', [
            'username' => 'taken.name', 'full_name' => '', 'email' => 'taken@example.test', 'locale' => 'fr',
        ]));
        $errors = $this->session->pullFlash('errors');
        self::assertSame(['full_name', 'locale', 'username', 'email', 'roles'], array_keys($errors));
        self::assertSame($before, (int) App::db()->value('SELECT COUNT(*) FROM users'));

        $this->post('/users', ['username' => 'Bad Name!', 'full_name' => 'X', 'roles' => [$this->roleId('viewer')]]);
        self::assertArrayHasKey('username', $this->session->pullFlash('errors'));
    }

    public function testFormRedisplaysSubmittedValuesAndMessages(): void
    {
        $this->post('/users', ['username' => 'ab', 'full_name' => 'שם כלשהו']);
        $body = $this->get('/users/create')->body;
        self::assertStringContainsString('value="שם כלשהו"', $body);
        self::assertStringContainsString('aria-invalid="true"', $body);
        self::assertStringContainsString('invalid-feedback', $body);
    }

    public function testSchoolAdministratorCannotGrantNetworkRoles(): void
    {
        $response = $this->post('/users', [
            'username' => 'escalate', 'full_name' => 'Escalate', 'roles' => [$this->roleId('system_admin')],
        ]);
        self::assertSame(403, $response->status);
        self::assertNull(App::db()->value("SELECT id FROM users WHERE username = 'escalate'"));
        self::assertStringNotContainsString(__('permissions.roles.system_admin'), $this->get('/users/create')->body);
    }

    public function testNobodyCanGrantMoreThanTheyHold(): void
    {
        $limited = $this->createRole($this->school, 'user_clerk', ['dashboard.view', 'users.view', 'users.create', 'users.update']);
        $clerk = $this->createUser('t.clerk');
        App::db()->insert('user_roles', ['user_id' => $clerk, 'role_id' => $limited, 'school_id' => $this->school]);
        $this->loginAs($clerk);

        $response = $this->post('/users', [
            'username' => 'boss', 'full_name' => 'Boss', 'roles' => [$this->roleId('principal')],
        ]);
        self::assertSame(403, $response->status);

        // …but a role within their own permissions is fine.
        $viewerLike = $this->createRole($this->school, 'dash_only', ['dashboard.view']);
        $this->assertRedirect('/users', $this->post('/users', [
            'username' => 'modest', 'full_name' => 'Modest', 'roles' => [$viewerLike],
        ]));
    }

    public function testEditingKeepsRolesTheActorMayNotAssign(): void
    {
        $limited = $this->createRole($this->school, 'user_clerk', ['dashboard.view', 'users.view', 'users.update']);
        $clerk = $this->createUser('t.clerk');
        App::db()->insert('user_roles', ['user_id' => $clerk, 'role_id' => $limited, 'school_id' => $this->school]);
        $target = $this->createUser('t.vice', [['vice_principal', $this->school]]);
        $dashOnly = $this->createRole($this->school, 'dash_only', ['dashboard.view']);

        $this->loginAs($clerk);
        $this->assertRedirect('/users', $this->post("/users/$target", ['full_name' => 'Renamed', 'roles' => [$dashOnly]]));

        $roles = array_map('intval', App::db()->column('SELECT role_id FROM user_roles WHERE user_id = ? ORDER BY role_id', [$target]));
        self::assertContains($this->roleId('vice_principal'), $roles, 'A role the actor cannot assign must not be removed by them.');
        self::assertContains($dashOnly, $roles);
    }

    public function testUpdateChangesDetailsAndRolesAndIsAudited(): void
    {
        $target = $this->createUser('t.target', [['subject_teacher', $this->school]]);
        $this->assertRedirect('/users', $this->post("/users/$target", [
            'full_name' => 'שם מעודכן', 'email' => 'x@example.test', 'locale' => 'he',
            'roles' => [$this->roleId('counselor')], 'is_active' => '1',
        ]));
        self::assertSame('שם מעודכן', App::db()->value('SELECT full_name FROM users WHERE id = ?', [$target]));
        self::assertSame(
            [$this->roleId('counselor')],
            array_map('intval', App::db()->column('SELECT role_id FROM user_roles WHERE user_id = ?', [$target]))
        );
        $log = App::db()->one("SELECT old_values, new_values FROM audit_logs WHERE action = 'update' AND entity_type = 'user'");
        self::assertStringContainsString('User t.target', $log['old_values']);
        self::assertStringNotContainsString('password', $log['old_values'] . $log['new_values']);
    }

    public function testUsersCannotChangeTheirOwnRolesOrDeactivateThemselves(): void
    {
        $this->post("/users/{$this->principal}", ['full_name' => 'Me', 'roles' => [$this->roleId('viewer')]]);
        self::assertSame(
            [$this->roleId('principal')],
            array_map('intval', App::db()->column('SELECT role_id FROM user_roles WHERE user_id = ?', [$this->principal]))
        );
        self::assertSame(1, (int) App::db()->value('SELECT is_active FROM users WHERE id = ?', [$this->principal]));
        self::assertSame(403, $this->post("/users/{$this->principal}/delete")->status);
    }

    public function testRemovingASharedUserOnlyDetachesThisSchool(): void
    {
        $shared = $this->createUser('t.shared', [['subject_teacher', $this->school], ['subject_teacher', $this->other]]);
        $local = $this->createUser('t.local', [['subject_teacher', $this->school]]);

        $this->assertRedirect('/users', $this->post("/users/$shared/delete"));
        $this->assertRedirect('/users', $this->post("/users/$local/delete"));

        self::assertNull(App::db()->value('SELECT deleted_at FROM users WHERE id = ?', [$shared]), 'Still works in the other school.');
        self::assertSame(
            [$this->other],
            array_map('intval', App::db()->column('SELECT school_id FROM user_roles WHERE user_id = ?', [$shared]))
        );
        self::assertNotNull(App::db()->value('SELECT deleted_at FROM users WHERE id = ?', [$local]), 'No role left anywhere → soft deleted.');
        self::assertSame(1, $this->auditCount('remove_from_school', 'user'));
        self::assertSame(1, $this->auditCount('delete', 'user'));
    }

    public function testSchoolAdministratorCannotDeactivateAUserSharedWithAnotherSchool(): void
    {
        $shared = $this->createUser('t.shared', [['subject_teacher', $this->school], ['subject_teacher', $this->other]]);
        $this->post("/users/$shared", ['full_name' => 'Shared', 'roles' => [$this->roleId('subject_teacher')]]); // is_active omitted
        self::assertSame(1, (int) App::db()->value('SELECT is_active FROM users WHERE id = ?', [$shared]));
    }

    public function testPasswordResetForcesChangeAndInvalidatesOldPassword(): void
    {
        $target = $this->createUser('t.reset', [['viewer', $this->school]]);
        $this->assertRedirect('/users', $this->post("/users/$target/reset-password"));
        $temp = $this->session->pullFlash('temp_password');

        $row = App::db()->one('SELECT password_hash, must_change_password FROM users WHERE id = ?', [$target]);
        self::assertSame(1, (int) $row['must_change_password']);
        self::assertFalse(password_verify(self::PASSWORD, $row['password_hash']));
        self::assertTrue(password_verify($temp, $row['password_hash']));
        self::assertSame(1, $this->auditCount('reset_password', 'user'));
    }

    public function testOutputIsEscaped(): void
    {
        $this->createUser('t.xss', [['viewer', $this->school]], ['full_name' => '<script>alert(1)</script>']);
        $body = $this->get('/users')->body;
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function testSearchTreatsWildcardsAsText(): void
    {
        $this->createUser('t.alpha', [['viewer', $this->school]], ['full_name' => 'Alpha Person']);
        self::assertStringContainsString('Alpha Person', $this->get('/users', ['q' => 'alpha'])->body);
        self::assertStringNotContainsString('Alpha Person', $this->get('/users', ['q' => '%'])->body);
        self::assertSame(200, $this->get('/users', ['q' => "' OR 1=1 --"])->status);
    }
}
