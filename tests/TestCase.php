<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Services\AuditLogger;
use App\Services\Passwords;

/**
 * Feature tests drive the real Kernel (routing, CSRF, authentication, authorisation,
 * controllers, views) against the test database. Each test runs inside a transaction
 * that is rolled back afterwards.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const PASSWORD = 'Test-pass-12345';

    protected Session $session;
    protected Router $router;
    private Kernel $kernel;
    private static ?string $passwordHash = null;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->session = Session::fake();
        App::useSession($this->session);
        App::lang()->setLocale('he');
        AuditLogger::setIp('127.0.0.1');
        App::db()->beginOuter();

        $router = new Router();
        require App::root('routes/web.php');
        $this->router = $router;
        $this->kernel = new Kernel($router);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob(App::root('storage/cache/import_*')) ?: []);
        foreach (App::db()->column('SELECT stored_name FROM documents') as $name) {
            @unlink(App::root('storage/uploads/' . $name));
        }
        App::db()->rollBackOuter();
    }

    protected function get(string $path, array $query = []): Response
    {
        return $this->kernel->handle(new Request('GET', $path, $query, [], [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    protected function post(string $path, array $data = [], bool $withCsrf = true, array $files = []): Response
    {
        if ($withCsrf) {
            $data['_csrf'] = App::csrf()->token();
        }
        return $this->kernel->handle(new Request('POST', $path, [], $data, $files, ['REMOTE_ADDR' => '127.0.0.1']));
    }

    protected function createSchool(?string $code = null, string $locale = 'he'): int
    {
        $code = strtoupper($code ?? 'T' . (++$this->sequence) . '-' . bin2hex(random_bytes(3)));
        return App::db()->insert('schools', ['name' => "School $code", 'code' => $code, 'default_locale' => $locale]);
    }

    /** @param array<int, array{0: string, 1: ?int}> $roles [role code, school id or null for network level] */
    protected function createUser(string $username, array $roles = [], array $attributes = []): int
    {
        self::$passwordHash ??= Passwords::hash(self::PASSWORD);
        $userId = App::db()->insert('users', $attributes + [
            'username' => $username,
            'full_name' => "User $username",
            'password_hash' => self::$passwordHash,
        ]);
        foreach ($roles as [$code, $schoolId]) {
            App::db()->insert('user_roles', ['user_id' => $userId, 'role_id' => $this->roleId($code), 'school_id' => $schoolId]);
        }
        return $userId;
    }

    protected function roleId(string $code): int
    {
        return (int) App::db()->value('SELECT id FROM roles WHERE code = ? ORDER BY is_system DESC LIMIT 1', [$code]);
    }

    /** A custom role of one school holding exactly the given permissions. */
    protected function createRole(int $schoolId, string $code, array $permissionCodes): int
    {
        $roleId = App::db()->insert('roles', [
            'school_id' => $schoolId, 'code' => $code, 'name' => "Role $code", 'level' => 'school', 'is_system' => 0,
        ]);
        foreach ($permissionCodes as $permission) {
            App::db()->insert('role_permissions', [
                'role_id' => $roleId,
                'permission_id' => (int) App::db()->value('SELECT id FROM permissions WHERE code = ?', [$permission]),
            ]);
        }
        return $roleId;
    }

    protected function createYear(int $schoolId, string $name = '2026-27', bool $current = true, string $start = '2026-09-01', string $end = '2027-06-30'): int
    {
        return App::db()->insert('academic_years', [
            'school_id' => $schoolId, 'name' => $name, 'start_date' => $start, 'end_date' => $end, 'is_current' => $current ? 1 : 0,
        ]);
    }

    protected function createLevel(int $schoolId, string $name, int $number): int
    {
        return App::db()->insert('grade_levels', ['school_id' => $schoolId, 'name' => $name, 'level_number' => $number]);
    }

    protected function createSubject(int $schoolId, string $name): int
    {
        return App::db()->insert('subjects', ['school_id' => $schoolId, 'name' => $name]);
    }

    protected function createClass(int $schoolId, int $yearId, int $levelId, string $name, ?int $homeroomTeacherId = null): int
    {
        return App::db()->insert('classes', [
            'school_id' => $schoolId, 'academic_year_id' => $yearId, 'grade_level_id' => $levelId,
            'name' => $name, 'homeroom_teacher_id' => $homeroomTeacherId,
        ]);
    }

    protected function createTeacher(int $schoolId, string $lastName, ?int $userId = null): int
    {
        return App::db()->insert('teachers', [
            'school_id' => $schoolId, 'first_name' => 'T', 'last_name' => $lastName, 'user_id' => $userId,
        ]);
    }

    protected function createStudent(int $schoolId, string $lastName, ?int $classId = null, array $attributes = []): int
    {
        $id = App::db()->insert('students', $attributes + [
            'school_id' => $schoolId, 'student_code' => 'X' . (++$this->sequence) . bin2hex(random_bytes(2)),
            'first_name' => 'S', 'last_name' => $lastName,
        ]);
        if ($classId !== null) {
            App::db()->insert('class_enrollments', [
                'school_id' => $schoolId, 'student_id' => $id, 'class_id' => $classId, 'enrolled_on' => '2026-09-01',
            ]);
        }
        return $id;
    }

    protected function assign(int $schoolId, int $yearId, int $teacherId, int $classId, int $subjectId): int
    {
        return App::db()->insert('teaching_assignments', [
            'school_id' => $schoolId, 'academic_year_id' => $yearId, 'teacher_id' => $teacherId,
            'class_id' => $classId, 'subject_id' => $subjectId,
        ]);
    }

    protected function loginAs(int $userId, ?int $schoolId = null): void
    {
        App::resetAuth()->login($userId);
        if ($schoolId !== null) {
            self::assertTrue(App::auth()->selectSchool($schoolId), 'Test user could not enter the school.');
        }
    }

    protected function assertRedirect(string $path, Response $response): void
    {
        self::assertTrue($response->isRedirect(), "Expected a redirect, got status $response->status.");
        self::assertSame($path, $response->location());
    }

    protected function auditCount(string $action, string $entityType): int
    {
        return (int) App::db()->value(
            'SELECT COUNT(*) FROM audit_logs WHERE action = ? AND entity_type = ?',
            [$action, $entityType]
        );
    }
}
