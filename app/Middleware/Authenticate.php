<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Response;

final class Authenticate
{
    /** Returns a redirect when the request may not proceed yet, or null to continue. */
    public static function handle(Auth $auth, array $options): ?Response
    {
        if (!$auth->check()) {
            return Response::redirect('/login');
        }
        if ($auth->user()['must_change_password'] && !$options['password']) {
            return Response::redirect('/password');
        }
        if ($options['school'] && $auth->schoolId() === null) {
            return Response::redirect('/select-school');
        }
        return null;
    }
}
