<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\View;

/** Escape for HTML output. Every dynamic value in a view goes through this. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return match (strtolower((string) $value)) {
        'true' => true,
        'false' => false,
        default => $value,
    };
}

/** Translate an interface string: __('users.title'). */
function __(string $key, array $replace = []): string
{
    return App::lang()->get($key, $replace);
}

function url(string $path = ''): string
{
    return rtrim((string) App::config('app.base_path', ''), '/') . '/' . ltrim($path, '/');
}

/** URL of a public asset; the file's modification time is appended so browsers fetch a new version after an update. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = App::root('public/assets/' . $path);
    return url('assets/' . $path) . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(App::csrf()->token()) . '">';
}

function can(string $permission): bool
{
    return App::auth()->can($permission);
}

/** Previously submitted value (after a failed validation) or the default. */
function old(string $key, mixed $default = ''): mixed
{
    return View::shared('old')[$key] ?? $default;
}

function error_for(string $key): ?string
{
    return View::shared('errors')[$key][0] ?? null;
}

/** Bootstrap class + aria attributes for a form control that may carry an error. */
function field_attrs(string $key): string
{
    return error_for($key) === null
        ? ''
        : ' aria-invalid="true" aria-describedby="err-' . e($key) . '"';
}

function field_error(string $key): string
{
    $msg = error_for($key);
    return $msg === null ? '' : '<div class="invalid-feedback d-block" id="err-' . e($key) . '">' . e($msg) . '</div>';
}

function format_datetime(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date('d/m/Y H:i', $ts);
}

/** Display name of a role: translated for system roles, as entered for custom ones. */
function role_label(array $role): string
{
    return !empty($role['is_system']) ? __('permissions.roles.' . $role['code']) : (string) $role['name'];
}

/** Translation when the key exists, otherwise the given fallback (used for open-ended codes such as audit actions). */
function trans_or(string $key, string $fallback): string
{
    $lang = App::lang();
    return $lang->has($lang->locale(), $key) || $lang->has('he', $key) ? $lang->get($key) : $fallback;
}

/** 4.0 → "4", 4.5 → "4.5", null → "—". */
function format_hours(mixed $hours): string
{
    return $hours === null || $hours === '' ? '—' : rtrim(rtrim(number_format((float) $hours, 1, '.', ''), '0'), '.');
}

/** The user's colour mode: 'auto' (follow the device), 'light' or 'dark'. Guests follow the device. */
function ui_theme(): string
{
    $theme = (string) (\App\Core\App::auth()->user()['theme'] ?? 'auto');
    return in_array($theme, ['auto', 'light', 'dark'], true) ? $theme : 'auto';
}

/**
 * Which design the user gets: 'staff' (teachers and management), 'teen' (students of grades 7–12)
 * or 'junior' (students of grades 1–6). Every account today is a staff account; the student themes
 * are ready in tokens.css for a student portal.
 */
function ui_audience(): string
{
    $auth = \App\Core\App::auth();
    if (!$auth->check() || $auth->schoolId() === null || $auth->can('dashboard.view') || !$auth->can('portal.view')) {
        return 'staff';
    }
    $db = \App\Core\App::db();
    if (\App\Services\Portal::isParent($db, $auth)) {
        return 'teen';
    }
    $student = \App\Services\Portal::current($db, $auth);
    return $student !== null && $student['level_number'] !== null && (int) $student['level_number'] <= 6 ? 'junior' : 'teen';
}

/** Attributes of <html>: language, direction, design and colour mode (the server's best guess; theme.js refines "auto"). */
function html_attributes(): string
{
    $lang = \App\Core\App::lang();
    $theme = ui_theme();
    return 'lang="' . e($lang->locale()) . '" dir="' . e($lang->direction()) . '" data-audience="' . e(ui_audience())
        . '" data-theme-pref="' . e($theme) . '" data-bs-theme="' . ($theme === 'dark' ? 'dark' : 'light') . '"';
}

/**
 * A "regular" teacher: sees only what they teach – not the whole school, not homeroom teacher of a
 * class this year, no coordinator position and no extra scope given by hand. Their menu leaves out the
 * school-wide lists (students, classes); the pages themselves stay reachable from their lessons.
 */
function is_regular_teacher(): bool
{
    $auth = \App\Core\App::auth();
    $userId = (int) $auth->id();
    $schoolId = (int) $auth->schoolId();
    $yearId = (int) ($auth->year()['id'] ?? 0);
    $db = \App\Core\App::db();
    if ($userId === 0 || (new \App\Services\AccessScope($db, $auth))->isUnrestricted()) {
        return false;
    }
    $mine = 'SELECT t.id FROM teachers t WHERE t.user_id = ? AND t.school_id = ? AND t.deleted_at IS NULL';
    $wider = $db->value(
        "SELECT 1 FROM classes c WHERE c.school_id = ? AND c.academic_year_id = ? AND c.homeroom_teacher_id IN ($mine)
         UNION SELECT 1 FROM staff_positions sp WHERE sp.school_id = ? AND sp.academic_year_id = ? AND sp.teacher_id IN ($mine)
         UNION SELECT 1 FROM user_scopes us WHERE us.user_id = ? AND us.school_id = ?
         LIMIT 1",
        [$schoolId, $yearId, $userId, $schoolId, $schoolId, $yearId, $userId, $schoolId, $userId, $schoolId]
    );
    return $wider === null;
}
