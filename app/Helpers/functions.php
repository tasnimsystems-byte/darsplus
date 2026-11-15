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

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
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
