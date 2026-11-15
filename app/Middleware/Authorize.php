<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;

final class Authorize
{
    public static function handle(Auth $auth, ?string $permission): void
    {
        if ($permission !== null && !$auth->can($permission)) {
            throw new HttpException(403);
        }
    }
}
