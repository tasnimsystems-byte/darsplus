<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Passwords;

/**
 * Portal accounts of one class (users.create – the principal): create the missing student accounts and
 * parent accounts in one step, or a new starting password for one of them. Starting passwords are shown
 * once, on a printable sheet, and must be changed at the first sign-in; they are never stored readable.
 *
 * A parent of several children gets one account: guardian rows with the same phone number share it.
 */
final class PortalAccountController extends Controller
{
    private const CREDENTIALS = 'portal_credentials';

    private function year(): array
    {
        return $this->auth()->year() ?? throw new RedirectException('/years', 'app.errors.no_year');
    }

    private function classes(): array
    {
        return array_column($this->db()->all(
            'SELECT c.id, c.name FROM classes c JOIN grade_levels gl ON gl.id = c.grade_level_id
              WHERE c.school_id = ? AND c.academic_year_id = ? ORDER BY gl.level_number, c.name',
            [$this->schoolId(), $this->year()['id']]
        ), 'name', 'id');
    }

    private function students(int $classId): array
    {
        return $this->db()->all(
            'SELECT s.id, s.first_name, s.last_name, s.student_code, s.user_id, u.username, u.last_login_at, u.is_active
               FROM students s JOIN class_enrollments e ON e.student_id = s.id
               LEFT JOIN users u ON u.id = s.user_id AND u.deleted_at IS NULL
              WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL ORDER BY s.last_name, s.first_name',
            [$classId]
        );
    }

    private function guardians(int $classId): array
    {
        return $this->db()->all(
            'SELECT g.id, g.student_id, g.full_name, g.relation, g.phone, g.user_id, u.username, u.last_login_at
               FROM guardians g JOIN class_enrollments e ON e.student_id = g.student_id
               LEFT JOIN users u ON u.id = g.user_id AND u.deleted_at IS NULL
              WHERE e.class_id = ? AND e.left_on IS NULL AND g.school_id = ? ORDER BY g.student_id, g.is_primary DESC, g.id',
            [$classId, $this->schoolId()]
        );
    }

    private function classId(Request $request): int
    {
        $classId = $request->int('class_id');
        if (!isset($this->classes()[$classId])) {
            throw new HttpException(404);
        }
        return $classId;
    }

    public function index(Request $request): Response
    {
        $classes = $this->classes();
        $classId = $request->int('class_id');
        $classId = isset($classes[$classId]) ? $classId : 0;
        $guardians = [];
        foreach ($classId === 0 ? [] : $this->guardians($classId) as $g) {
            $guardians[(int) $g['student_id']][] = $g;
        }
        return $this->view('portal_accounts/index', [
            'classes' => $classes, 'classId' => $classId, 'students' => $classId === 0 ? [] : $this->students($classId),
            'guardians' => $guardians, 'locked' => (bool) $this->year()['is_locked'],
        ]);
    }

    private function roleId(string $code): int
    {
        return (int) ($this->db()->value('SELECT id FROM roles WHERE school_id IS NULL AND code = ?', [$code])
            ?? throw new HttpException(500));
    }

    private function username(string $wanted): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9._-]+/', '', strtolower($wanted)), '.-_') ?: 'user';
        $name = $base;
        for ($n = 2; $this->db()->value('SELECT 1 FROM users WHERE username = ?', [$name]) !== null; $n++) {
            $name = "$base$n";
        }
        return $name;
    }

    /** @return array{int, string} user id and its starting password */
    private function createUser(string $username, string $fullName, string $role): array
    {
        $password = Passwords::generate(10);
        $userId = $this->db()->insert('users', [
            'username' => $this->username($username), 'full_name' => mb_substr($fullName, 0, 150),
            'password_hash' => Passwords::hash($password), 'must_change_password' => 1,
            'locale' => (string) ($this->auth()->school()['default_locale'] ?? 'he'), 'created_by' => $this->auth()->id(),
        ]);
        $this->db()->insert('user_roles', ['user_id' => $userId, 'role_id' => $this->roleId($role), 'school_id' => $this->schoolId(), 'created_by' => $this->auth()->id()]);
        return [$userId, $password];
    }

    private function showCredentials(array $rows, string $message): Response
    {
        App::session()->flash(self::CREDENTIALS, $rows);
        return $this->redirect('/portal-accounts/credentials', $message);
    }

    public function createStudents(Request $request): Response
    {
        $classId = $this->classId($request);
        $class = $this->classes()[$classId];
        $created = [];
        $this->db()->transaction(function () use ($classId, $class, &$created): void {
            foreach ($this->students($classId) as $student) {
                if ($student['user_id'] !== null) {
                    continue;
                }
                $name = $student['first_name'] . ' ' . $student['last_name'];
                // The student code itself when it starts with a letter (S00005 → s00005), otherwise s + code.
                $code = strtolower((string) $student['student_code']);
                [$userId, $password] = $this->createUser(preg_match('/^[a-z]/', $code) ? $code : "s$code", $name, 'student');
                $this->db()->update('students', ['user_id' => $userId], ['id' => $student['id']]);
                $created[] = ['name' => $name, 'class' => $class, 'role' => 'student',
                    'username' => $this->db()->value('SELECT username FROM users WHERE id = ?', [$userId]), 'password' => $password];
            }
        });
        $this->audit('create', 'portal_accounts', $classId, null, ['students' => count($created)]);
        return $this->showCredentials($created, __('portal.accounts.created', ['count' => count($created)]));
    }

    public function createParents(Request $request): Response
    {
        $classId = $this->classId($request);
        $class = $this->classes()[$classId];
        $phoneKey = static fn (?string $phone): string => ltrim((string) preg_replace('/\D+/', '', (string) $phone), '0');
        $created = [];
        $linked = 0;
        $this->db()->transaction(function () use ($classId, $class, $phoneKey, &$created, &$linked): void {
            foreach ($this->guardians($classId) as $g) {
                if ($g['user_id'] !== null) {
                    continue;
                }
                $key = $phoneKey($g['phone']);
                // The same parent (same phone) of another child already has an account: link this child too.
                $existing = $key === '' ? null : $this->db()->value(
                    "SELECT g2.user_id FROM guardians g2 WHERE g2.school_id = ? AND g2.user_id IS NOT NULL
                       AND TRIM(LEADING '0' FROM REGEXP_REPLACE(COALESCE(g2.phone, ''), '[^0-9]', '')) = ? LIMIT 1",
                    [$this->schoolId(), $key]
                );
                if ($existing !== null) {
                    $this->db()->update('guardians', ['user_id' => (int) $existing], ['id' => $g['id']]);
                    $linked++;
                    continue;
                }
                $student = $this->db()->one('SELECT student_code, first_name FROM students WHERE id = ?', [$g['student_id']]);
                [$userId, $password] = $this->createUser($key !== '' ? "p$key" : 'p' . $student['student_code'] . '-' . $g['id'], $g['full_name'], 'parent');
                $this->db()->update('guardians', ['user_id' => $userId], ['id' => $g['id']]);
                $created[] = ['name' => $g['full_name'] . ' (' . $student['first_name'] . ')', 'class' => $class, 'role' => 'parent',
                    'username' => $this->db()->value('SELECT username FROM users WHERE id = ?', [$userId]), 'password' => $password];
            }
        });
        $this->audit('create', 'portal_accounts', $classId, null, ['parents' => count($created), 'linked_to_existing' => $linked]);
        return $this->showCredentials($created, __('portal.accounts.parents_created', ['count' => count($created), 'linked' => $linked]));
    }

    /** A new starting password for one student or parent account of this school. */
    public function resetPassword(Request $request): Response
    {
        $userId = $request->int('user_id');
        $owner = $this->db()->one(
            "SELECT u.id, u.username, u.full_name, 'student' AS role FROM users u JOIN students s ON s.user_id = u.id WHERE u.id = ? AND s.school_id = ?
             UNION SELECT u.id, u.username, u.full_name, 'parent' AS role FROM users u JOIN guardians g ON g.user_id = u.id WHERE u.id = ? AND g.school_id = ?
             LIMIT 1",
            [$userId, $this->schoolId(), $userId, $this->schoolId()]
        );
        if ($owner === null) {
            throw new HttpException(404);
        }
        $password = Passwords::generate(10);
        $this->db()->update('users', ['password_hash' => Passwords::hash($password), 'must_change_password' => 1, 'is_active' => 1], ['id' => $userId]);
        $this->audit('reset_password', 'user', $userId);
        return $this->showCredentials([['name' => $owner['full_name'], 'class' => '', 'role' => $owner['role'], 'username' => $owner['username'], 'password' => $password]],
            __('portal.accounts.reset'));
    }

    /** The sheet of new usernames and starting passwords – shown once. */
    public function credentials(Request $request): Response
    {
        $rows = App::session()->pullFlash(self::CREDENTIALS);
        if (!is_array($rows)) {
            return $this->redirect('/portal-accounts', null, 'portal.accounts.gone');
        }
        return $this->view('portal_accounts/credentials', ['rows' => $rows]);
    }
}
