<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use Tests\TestCase;

/** Nothing belonging to one school may be read or changed from inside another. */
final class SchoolIsolationTest extends TestCase
{
    private int $schoolA;
    private int $schoolB;
    private int $principalA;
    private int $teacherB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schoolA = $this->createSchool();
        $this->schoolB = $this->createSchool();
        $this->principalA = $this->createUser('a.principal', [['principal', $this->schoolA]]);
        $this->teacherB = $this->createUser('b.teacher', [['subject_teacher', $this->schoolB]], ['full_name' => 'Teacher Of School B']);
        $this->loginAs($this->principalA);
    }

    public function testUserListShowsOnlyTheActiveSchool(): void
    {
        $this->createUser('a.teacher', [['subject_teacher', $this->schoolA]], ['full_name' => 'Teacher Of School A']);
        $this->createUser('net.admin', [['system_admin', null]], ['full_name' => 'Network Admin Person']);

        $body = $this->get('/users')->body;
        self::assertStringContainsString('Teacher Of School A', $body);
        self::assertStringNotContainsString('Teacher Of School B', $body);
        self::assertStringNotContainsString('Network Admin Person', $body, 'School staff do not see network-level accounts.');
        self::assertStringNotContainsString('Teacher Of School B', $this->get('/users', ['q' => 'School B'])->body);
    }

    public function testUsersOfAnotherSchoolCannotBeOpenedOrChanged(): void
    {
        $id = $this->teacherB;
        self::assertSame(404, $this->get("/users/$id/edit")->status);
        self::assertSame(404, $this->post("/users/$id", ['full_name' => 'Hijacked', 'roles' => [$this->roleId('viewer')]])->status);
        self::assertSame(404, $this->post("/users/$id/delete")->status);
        self::assertSame(404, $this->post("/users/$id/reset-password")->status);

        $row = App::db()->one('SELECT full_name, must_change_password, deleted_at FROM users WHERE id = ?', [$id]);
        self::assertSame(['full_name' => 'Teacher Of School B', 'must_change_password' => 0, 'deleted_at' => null], $row);
        self::assertSame(
            [$this->schoolB],
            array_map('intval', App::db()->column('SELECT school_id FROM user_roles WHERE user_id = ?', [$id]))
        );
    }

    public function testNetworkAccountsAreOutOfReachForSchoolAdministrators(): void
    {
        $admin = $this->createUser('net.admin', [['system_admin', null]]);
        self::assertSame(404, $this->get("/users/$admin/edit")->status);
        self::assertSame(404, $this->post("/users/$admin/reset-password")->status);
        self::assertSame(404, $this->post("/users/$admin/delete")->status);
    }

    public function testCustomRolesOfAnotherSchoolAreInvisibleAndUnassignable(): void
    {
        $foreignRole = $this->createRole($this->schoolB, 'b_special', ['dashboard.view']);

        self::assertStringNotContainsString('Role b_special', $this->get('/roles')->body);
        self::assertStringNotContainsString('Role b_special', $this->get('/users/create')->body);
        self::assertSame(403, $this->post('/users', [
            'username' => 'cross', 'full_name' => 'Cross', 'roles' => [$foreignRole],
        ])->status);
    }

    public function testAuditLogIsScopedToTheSchool(): void
    {
        App::db()->insert('audit_logs', [
            'school_id' => $this->schoolB, 'user_id' => $this->teacherB, 'action' => 'update', 'entity_type' => 'secret_b',
        ]);
        App::db()->insert('audit_logs', [
            'school_id' => $this->schoolA, 'user_id' => $this->principalA, 'action' => 'update', 'entity_type' => 'visible_a',
        ]);
        $body = $this->get('/audit')->body;
        self::assertStringContainsString('visible_a', $body);
        self::assertStringNotContainsString('secret_b', $body);
        self::assertStringNotContainsString('Teacher Of School B', $body);
    }

    public function testSchoolListShowsOnlyOwnSchoolAndCannotBeEdited(): void
    {
        $body = $this->get('/schools')->body;
        self::assertStringContainsString('School ' . App::db()->value('SELECT code FROM schools WHERE id = ?', [$this->schoolA]), $body);
        self::assertStringNotContainsString((string) App::db()->value('SELECT code FROM schools WHERE id = ?', [$this->schoolB]), $body);

        // Even a custom role granting schools.update does not let a school-level user edit schools.
        $role = $this->createRole($this->schoolA, 'school_editor', ['dashboard.view', 'schools.view', 'schools.update', 'schools.create']);
        $user = $this->createUser('a.editor');
        App::db()->insert('user_roles', ['user_id' => $user, 'role_id' => $role, 'school_id' => $this->schoolA]);
        $this->loginAs($user);
        self::assertSame(403, $this->get("/schools/{$this->schoolB}/edit")->status);
        self::assertSame(403, $this->post("/schools/{$this->schoolB}", ['name' => 'Hacked', 'code' => 'HACK', 'default_locale' => 'he'])->status);
        self::assertSame(403, $this->post('/schools', ['name' => 'New', 'code' => 'NEW', 'default_locale' => 'he'])->status);
    }

    public function testNotificationsOfOthersCannotBeMarkedRead(): void
    {
        $id = App::db()->insert('notifications', [
            'user_id' => $this->teacherB, 'school_id' => $this->schoolB, 'type' => 'test', 'title' => 'Private notice B',
        ]);
        $this->post("/notifications/$id/read");
        self::assertNull(App::db()->value('SELECT read_at FROM notifications WHERE id = ?', [$id]));
        self::assertStringNotContainsString('Private notice B', $this->get('/notifications')->body);
    }

    public function testDashboardCountsOnlyTheActiveSchool(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createUser("b.extra$i", [['viewer', $this->schoolB]]);
        }
        $count = (int) App::db()->value(
            'SELECT COUNT(DISTINCT user_id) FROM user_roles WHERE school_id = ?',
            [$this->schoolA]
        );
        self::assertSame(1, $count);
        self::assertMatchesRegularExpression('/kpi-value">\s*1\s*</', $this->get('/')->body);
    }

    public function testNetworkAdministratorWorksInOneSchoolAtATime(): void
    {
        $this->createUser('a.teacher', [['subject_teacher', $this->schoolA]], ['full_name' => 'Teacher Of School A']);
        $admin = $this->createUser('net.admin', [['system_admin', null]]);
        $this->loginAs($admin, $this->schoolB);

        $body = $this->get('/users')->body;
        self::assertStringContainsString('Teacher Of School B', $body);
        self::assertStringNotContainsString('Teacher Of School A', $body);
        self::assertSame(200, $this->get("/users/{$this->teacherB}/edit")->status);
    }
}
