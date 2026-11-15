<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\AuditLogger;

abstract class Controller
{
    protected function view(string $template, array $data = [], ?string $layout = 'layouts/app'): Response
    {
        $session = App::session();
        View::share('errors', $session->pullFlash('errors', []));
        View::share('old', $session->pullFlash('old', []));
        View::share('input_ids', []);
        return new Response(View::render($template, $data, $layout));
    }

    protected function redirect(string $path, ?string $success = null, ?string $error = null): Response
    {
        if ($success !== null) {
            App::session()->flash('success', $success);
        }
        if ($error !== null) {
            App::session()->flash('error', $error);
        }
        return Response::redirect($path);
    }

    /** Back to the form with messages and the submitted values (never passwords or tokens). */
    protected function invalid(string $path, array $errors, array $input): Response
    {
        $old = array_filter(
            $input,
            static fn ($key) => !str_contains((string) $key, 'password') && $key !== '_csrf',
            ARRAY_FILTER_USE_KEY
        );
        App::session()->flash('errors', $errors);
        App::session()->flash('old', $old);
        return Response::redirect($path);
    }

    protected function audit(string $action, string $entityType, ?int $entityId = null, ?array $old = null, ?array $new = null): void
    {
        AuditLogger::log($action, $entityType, $entityId, $old, $new);
    }

    protected function db(): Database
    {
        return App::db();
    }

    protected function auth(): Auth
    {
        return App::auth();
    }

    /** Active school id; routes reaching here were already required to have one. */
    protected function schoolId(): int
    {
        return $this->auth()->schoolId() ?? throw new HttpException(403);
    }

    protected function page(Request $request): int
    {
        return max(1, $request->int('page', 1));
    }
}
