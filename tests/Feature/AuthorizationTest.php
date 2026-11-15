<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use Tests\TestCase;

final class AuthorizationTest extends TestCase
{
    /** Routes that are intentionally open to every authenticated user (personal pages) or to guests. */
    private const NO_PERMISSION_NEEDED = [
        'GET /login', 'POST /login', 'POST /locale', 'POST /logout',
        'GET /password', 'POST /password', 'GET /select-school', 'POST /select-school',
        'GET /notifications', 'POST /notifications/read-all', 'POST /notifications/{id}/read',
        'POST /year', // choosing among the school's own years
        // Deleting is decided per document (uploader or documents.delete) inside the controller.
        'POST /documents/{id}/delete',
    ];

    public function testEveryRouteDeclaresAPermissionUnlessExplicitlyListed(): void
    {
        $unprotected = [];
        foreach ($this->router->routes() as $route) {
            $name = $route['method'] . ' ' . $route['path'];
            if ($route['options']['permission'] === null && !in_array($name, self::NO_PERMISSION_NEEDED, true)
                && $name !== 'POST /documents/{id}/delete') {
                $unprotected[] = $name;
            }
        }
        self::assertSame([], $unprotected, 'Routes without a server-side permission');
    }

    public function testOnlyGuestRoutesAreReachableWithoutLogin(): void
    {
        foreach ($this->router->routes() as $route) {
            $path = preg_replace('/\{\w+\}/', '1', $route['path']);
            $response = $route['method'] === 'GET' ? $this->get($path) : $this->post($path);
            if (in_array($route['path'], ['/login', '/locale'], true)) {
                continue;
            }
            $this->assertRedirect('/login', $response);
        }
    }

    public function testAllPermissionCodesUsedByRoutesExist(): void
    {
        $known = App::db()->column('SELECT code FROM permissions');
        foreach ($this->router->routes() as $route) {
            $permission = $route['options']['permission'];
            if ($permission !== null) {
                self::assertContains($permission, $known, "Unknown permission on {$route['path']}");
            }
        }
    }

    public function testRolesSeeOnlyWhatTheyAreGranted(): void
    {
        $school = $this->createSchool();
        $expectations = [
            'subject_teacher' => ['/' => 200, '/documents' => 200, '/users' => 403, '/roles' => 403, '/audit' => 403, '/schools' => 403],
            'viewer' => ['/' => 200, '/documents' => 403, '/users' => 403, '/audit' => 403],
            'vice_principal' => ['/users' => 200, '/users/create' => 403, '/audit' => 403, '/schools' => 200],
            'principal' => ['/users' => 200, '/users/create' => 200, '/roles' => 200, '/roles/create' => 403, '/audit' => 200, '/schools/create' => 403],
        ];
        foreach ($expectations as $role => $pages) {
            $this->loginAs($this->createUser("t.$role", [[$role, $school]]));
            foreach ($pages as $path => $status) {
                self::assertSame($status, $this->get($path)->status, "$role → GET $path");
            }
        }
    }

    public function testForbiddenWritesChangeNothing(): void
    {
        $school = $this->createSchool();
        $this->loginAs($this->createUser('t.teacher', [['subject_teacher', $school]]));
        $before = (int) App::db()->value('SELECT COUNT(*) FROM users');

        $response = $this->post('/users', [
            'username' => 'sneaky', 'full_name' => 'Sneaky', 'roles' => [$this->roleId('principal')],
        ]);
        self::assertSame(403, $response->status);
        self::assertSame($before, (int) App::db()->value('SELECT COUNT(*) FROM users'));
    }

    public function testSidebarShowsOnlyPermittedModules(): void
    {
        $school = $this->createSchool();
        $this->loginAs($this->createUser('t.nav', [['subject_teacher', $school]]));
        $body = $this->get('/')->body;
        self::assertStringContainsString('href="/documents"', $body);
        self::assertStringNotContainsString('href="/users"', $body);
        self::assertStringNotContainsString('href="/audit"', $body);
    }

    public function testSystemAdministratorHasNoAccessToPedagogicalModules(): void
    {
        $codes = App::db()->column(
            'SELECT p.module FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = ?',
            [$this->roleId('system_admin')]
        );
        foreach (['students', 'assessments', 'attendance', 'risk', 'interventions', 'observations', 'parent_contacts', 'documents'] as $module) {
            self::assertNotContains($module, $codes, "system_admin must not hold $module permissions by default");
        }
    }

    public function testNetworkSupervisorCannotManageUsers(): void
    {
        $school = $this->createSchool();
        $this->loginAs($this->createUser('t.super', [['network_supervisor', null]]), $school);
        self::assertSame(200, $this->get('/')->status);
        self::assertSame(403, $this->get('/users')->status);
        self::assertSame(403, $this->get('/documents')->status);
    }
}
