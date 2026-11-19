<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The authenticated context of one request: who the user is, which school is
 * active, and which permissions apply there.
 *
 * Permissions are the union of the user's roles at the active school and of
 * their network-level roles (user_roles.school_id IS NULL).
 */
final class Auth
{
    private bool $loaded = false;
    private ?array $user = null;
    private ?array $school = null;
    private bool $network = false;
    /** @var int[] */
    private array $schoolIds = [];
    /** @var array<string,true> */
    private array $permissions = [];

    public function __construct(private Database $db, private Session $session)
    {
    }

    public function load(): void
    {
        $this->loaded = true;
        $this->user = $this->school = null;
        $this->year = false;
        $this->network = false;
        $this->schoolIds = $this->permissions = [];

        $userId = $this->session->get('user_id');
        if (!is_int($userId)) {
            return;
        }

        $idle = (int) App::config('app.session.idle_minutes', 30) * 60;
        if (time() - (int) $this->session->get('last_activity', 0) > $idle) {
            $this->session->clear();
            $this->session->flash('error', 'auth.session_expired');
            return;
        }

        $user = $this->db->one(
            'SELECT id, username, full_name, email, locale, theme, must_change_password, password_changed_at
               FROM users WHERE id = ? AND is_active = 1 AND deleted_at IS NULL',
            [$userId]
        );
        if ($user === null) {
            $this->session->clear();
            return;
        }
        // A session authenticated before the last password change or reset is no longer valid.
        if ($user['password_changed_at'] !== null && strtotime($user['password_changed_at']) > (int) $this->session->get('auth_time', 0)) {
            $this->session->clear();
            $this->session->flash('error', 'auth.session_expired');
            return;
        }
        unset($user['password_changed_at']);
        $this->user = $user;
        $this->session->set('last_activity', time());

        foreach ($this->db->column('SELECT DISTINCT school_id FROM user_roles WHERE user_id = ?', [$userId]) as $id) {
            if ($id === null) {
                $this->network = true;
            } else {
                $this->schoolIds[] = (int) $id;
            }
        }

        $schoolId = $this->session->get('school_id');
        if (is_int($schoolId) && ($this->network || in_array($schoolId, $this->schoolIds, true))) {
            $this->school = $this->db->one(
                'SELECT id, name, code, default_locale FROM schools WHERE id = ? AND is_active = 1 AND deleted_at IS NULL',
                [$schoolId]
            );
        }
        if ($this->school === null) {
            $this->session->forget('school_id');
        }

        $codes = $this->db->column(
            'SELECT DISTINCT p.code
               FROM user_roles ur
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE ur.user_id = ? AND (ur.school_id IS NULL OR ur.school_id = ?)',
            [$userId, $this->school['id'] ?? 0]
        );
        $this->permissions = array_fill_keys($codes, true);
    }

    private function ensureLoaded(): void
    {
        if (!$this->loaded) {
            $this->load();
        }
    }

    public function check(): bool
    {
        $this->ensureLoaded();
        return $this->user !== null;
    }

    public function user(): ?array
    {
        $this->ensureLoaded();
        return $this->user;
    }

    public function id(): ?int
    {
        return $this->user()['id'] ?? null;
    }

    public function school(): ?array
    {
        $this->ensureLoaded();
        return $this->school;
    }

    public function schoolId(): ?int
    {
        return $this->school()['id'] ?? null;
    }

    /** True when the user holds a network-level role (not tied to one school). */
    public function isNetwork(): bool
    {
        $this->ensureLoaded();
        return $this->network;
    }

    public function can(string $permission): bool
    {
        $this->ensureLoaded();
        return isset($this->permissions[$permission]);
    }

    /** @return string[] */
    public function permissions(): array
    {
        $this->ensureLoaded();
        return array_keys($this->permissions);
    }

    /** Schools this user may enter. */
    public function availableSchools(): array
    {
        $this->ensureLoaded();
        if ($this->user === null) {
            return [];
        }
        if ($this->network) {
            return $this->db->all('SELECT id, name, code FROM schools WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name');
        }
        if ($this->schoolIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($this->schoolIds), '?'));
        return $this->db->all(
            "SELECT id, name, code FROM schools WHERE id IN ($in) AND is_active = 1 AND deleted_at IS NULL ORDER BY name",
            $this->schoolIds
        );
    }

    private array|false|null $year = false;

    /** Academic years of the active school, newest first. */
    public function years(): array
    {
        $schoolId = $this->schoolId();
        return $schoolId === null ? [] : $this->db->all(
            'SELECT id, name, start_date, end_date, is_current, is_locked FROM academic_years
              WHERE school_id = ? ORDER BY start_date DESC, id DESC',
            [$schoolId]
        );
    }

    /**
     * The academic year being worked in: the one chosen in this session, otherwise
     * the school's current year, otherwise the newest. Null when none is defined.
     */
    public function year(): ?array
    {
        if ($this->year !== false) {
            return $this->year;
        }
        $years = $this->years();
        $chosen = $this->session->get('year_id');
        $current = null;
        foreach ($years as $year) {
            if ((int) $year['id'] === $chosen) {
                return $this->year = $year;
            }
            if ($year['is_current'] && $current === null) {
                $current = $year;
            }
        }
        return $this->year = $current ?? $years[0] ?? null;
    }

    public function selectYear(int $yearId): bool
    {
        foreach ($this->years() as $year) {
            if ((int) $year['id'] === $yearId) {
                $this->session->set('year_id', $yearId);
                $this->year = false;
                return true;
            }
        }
        return false;
    }

    public function login(int $userId): void
    {
        $locale = $this->session->get('locale');
        $this->session->clear(); // also rotates the session id (fixation defence)
        if ($locale !== null) {
            $this->session->set('locale', $locale);
        }
        $this->session->set('user_id', $userId);
        $this->session->set('auth_time', time());
        $this->session->set('last_activity', time());
        $this->load();

        $schools = $this->availableSchools();
        if (count($schools) === 1) {
            $this->selectSchool((int) $schools[0]['id']);
        }
    }

    public function selectSchool(int $schoolId): bool
    {
        foreach ($this->availableSchools() as $school) {
            if ((int) $school['id'] === $schoolId) {
                $this->session->regenerate();
                $this->session->set('school_id', $schoolId);
                $this->session->forget('year_id');
                $this->load();
                return true;
            }
        }
        return false;
    }

    public function logout(): void
    {
        $this->session->clear();
        $this->load();
    }
}
