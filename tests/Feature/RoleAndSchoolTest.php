<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use Tests\TestCase;

final class RoleAndSchoolTest extends TestCase
{
    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
    }

    private function permissionId(string $code): int
    {
        return (int) App::db()->value('SELECT id FROM permissions WHERE code = ?', [$code]);
    }

    private function loginWithPermissions(array $permissions): int
    {
        $role = $this->createRole($this->school, 'actor_' . bin2hex(random_bytes(2)), $permissions);
        $user = $this->createUser('t.actor' . bin2hex(random_bytes(2)));
        App::db()->insert('user_roles', ['user_id' => $user, 'role_id' => $role, 'school_id' => $this->school]);
        $this->loginAs($user);
        return $user;
    }

    public function testSeededRolesMatchTheConfiguredMatrix(): void
    {
        $config = App::config('permissions');
        self::assertSame(
            count($config['modules']) * count($config['actions']),
            (int) App::db()->value('SELECT COUNT(*) FROM permissions')
        );
        foreach ($config['roles'] as $code => $definition) {
            $expected = [];
            foreach ($definition['grants'] as $module => $letters) {
                foreach (str_split($letters) as $letter) {
                    $expected[] = $module . '.' . $config['actions'][$letter];
                }
            }
            $actual = App::db()->column(
                'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
                [$this->roleId($code)]
            );
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, "Role $code");
            self::assertSame($definition['level'], App::db()->value('SELECT level FROM roles WHERE id = ?', [$this->roleId($code)]));
        }
    }

    public function testNetworkAdministratorEditsSystemRoleAndChangeTakesEffect(): void
    {
        $this->loginAs($this->createUser('net.admin', [['system_admin', null]]), $this->school);
        $viewer = $this->roleId('viewer');
        $ids = [$this->permissionId('dashboard.view'), $this->permissionId('documents.view')];

        self::assertSame(200, $this->get("/roles/$viewer/edit")->status);
        $this->assertRedirect('/roles', $this->post("/roles/$viewer", ['permissions' => $ids]));
        self::assertSame(1, $this->auditCount('update', 'role'));

        $this->loginAs($this->createUser('t.viewer', [['viewer', $this->school]]));
        self::assertSame(200, $this->get('/documents')->status, 'The new permission applies immediately.');
    }

    public function testSchoolLevelUserCannotEditSystemRoles(): void
    {
        $this->loginWithPermissions(['dashboard.view', 'roles.view', 'roles.update', 'roles.create', 'roles.delete']);
        $viewer = $this->roleId('viewer');

        self::assertSame(403, $this->get("/roles/$viewer/edit")->status);
        self::assertSame(403, $this->post("/roles/$viewer", ['permissions' => [$this->permissionId('roles.view')]])->status);
        self::assertSame(403, $this->post("/roles/$viewer/delete")->status);
        self::assertSame(
            ['dashboard.view', 'reports.view', 'work_plan.view'],
            App::db()->column(
                'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.code',
                [$viewer]
            )
        );
    }

    public function testCustomRoleLifecycle(): void
    {
        $this->loginWithPermissions(['dashboard.view', 'roles.view', 'roles.update', 'roles.create', 'roles.delete', 'tasks.view']);

        $this->assertRedirect('/roles', $this->post('/roles', [
            'name' => 'רכז חברתי', 'permissions' => [$this->permissionId('tasks.view'), $this->permissionId('dashboard.view')],
        ]));
        $role = App::db()->one("SELECT * FROM roles WHERE name = 'רכז חברתי'");
        self::assertSame($this->school, (int) $role['school_id']);
        self::assertSame(0, (int) $role['is_system']);
        self::assertSame('school', $role['level']);
        self::assertStringContainsString('רכז חברתי', $this->get('/roles')->body);

        $id = (int) $role['id'];
        $this->assertRedirect('/roles', $this->post("/roles/$id", ['name' => 'רכז חברתי וקהילתי', 'permissions' => [$this->permissionId('dashboard.view')]]));
        self::assertSame('רכז חברתי וקהילתי', App::db()->value('SELECT name FROM roles WHERE id = ?', [$id]));
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM role_permissions WHERE role_id = ?', [$id]));

        // In use → cannot be deleted.
        $holder = $this->createUser('t.holder');
        App::db()->insert('user_roles', ['user_id' => $holder, 'role_id' => $id, 'school_id' => $this->school]);
        $this->assertRedirect('/roles', $this->post("/roles/$id/delete"));
        self::assertNotNull(App::db()->value('SELECT id FROM roles WHERE id = ?', [$id]));

        App::db()->run('DELETE FROM user_roles WHERE role_id = ?', [$id]);
        $this->assertRedirect('/roles', $this->post("/roles/$id/delete"));
        self::assertNull(App::db()->value('SELECT id FROM roles WHERE id = ?', [$id]));
    }

    public function testCustomRoleCannotCarryPermissionsItsAuthorLacks(): void
    {
        $this->loginWithPermissions(['dashboard.view', 'roles.view', 'roles.create']);
        $before = (int) App::db()->value('SELECT COUNT(*) FROM roles');

        self::assertSame(403, $this->post('/roles', [
            'name' => 'Power grab', 'permissions' => [$this->permissionId('users.delete')],
        ])->status);
        self::assertSame(403, $this->post('/roles', ['name' => 'Forged', 'permissions' => [999999]])->status);
        self::assertSame($before, (int) App::db()->value('SELECT COUNT(*) FROM roles'));
    }

    public function testCustomRoleOfAnotherSchoolCannotBeEdited(): void
    {
        $foreign = $this->createRole($this->createSchool(), 'foreign', ['dashboard.view']);
        $this->loginWithPermissions(['dashboard.view', 'roles.view', 'roles.update', 'roles.delete']);
        self::assertSame(404, $this->get("/roles/$foreign/edit")->status);
        self::assertSame(404, $this->post("/roles/$foreign", ['name' => 'x'])->status);
        self::assertSame(404, $this->post("/roles/$foreign/delete")->status);
    }

    public function testNetworkAdministratorManagesSchools(): void
    {
        $this->loginAs($this->createUser('net.admin', [['system_admin', null]]), $this->school);

        $this->assertRedirect('/schools', $this->post('/schools', [
            'name' => 'בית ספר חדש', 'code' => 'new-01', 'default_locale' => 'ar',
        ]));
        $school = App::db()->one("SELECT * FROM schools WHERE code = 'NEW-01'");
        self::assertSame('ar', $school['default_locale']);
        self::assertSame(1, $this->auditCount('create', 'school'));

        // Duplicate code and bad locale are rejected.
        $this->post('/schools', ['name' => 'Dup', 'code' => 'NEW-01', 'default_locale' => 'xx']);
        self::assertSame(['default_locale', 'code'], array_keys($this->session->pullFlash('errors')));

        $id = (int) $school['id'];
        $this->assertRedirect('/schools', $this->post("/schools/$id", ['name' => 'שם מעודכן', 'code' => 'NEW-01', 'default_locale' => 'he']));
        $updated = App::db()->one('SELECT name, is_active FROM schools WHERE id = ?', [$id]);
        self::assertSame(['name' => 'שם מעודכן', 'is_active' => 0], $updated, 'Unchecked switch deactivates the school.');

        // The school currently worked in stays active whatever the form says.
        $code = App::db()->value('SELECT code FROM schools WHERE id = ?', [$this->school]);
        $this->post("/schools/{$this->school}", ['name' => 'Current', 'code' => $code, 'default_locale' => 'he']);
        self::assertSame(1, (int) App::db()->value('SELECT is_active FROM schools WHERE id = ?', [$this->school]));
    }

    public function testAllAdministrationPagesRenderInBothLanguages(): void
    {
        $this->loginAs($this->createUser('net.admin', [['system_admin', null]]), $this->school);
        $viewer = $this->roleId('viewer');
        $pages = ['/', '/users', '/users/create', '/roles', '/roles/create', "/roles/$viewer/edit", '/schools',
            '/schools/create', "/schools/{$this->school}/edit", '/audit', '/notifications', '/password'];

        foreach (['he', 'ar'] as $locale) {
            $this->post('/locale', ['locale' => $locale]);
            foreach ($pages as $page) {
                $response = $this->get($page);
                self::assertSame(200, $response->status, "$locale $page");
                self::assertStringContainsString('<html lang="' . $locale . '" dir="rtl">', $response->body);
                // An untranslated key would show up as "file.key" text between tags.
                self::assertDoesNotMatchRegularExpression('/>\s*(app|nav|auth|users|roles|schools|audit|documents|dashboard|errors|notifications|validation|permissions)\.[a-z_.]+\s*</', $response->body, "$locale $page shows a raw key");
            }
        }
    }
}
