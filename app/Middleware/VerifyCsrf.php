<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;

final class VerifyCsrf
{
    /** Every state-changing request must carry the session's token — no exceptions, login included. */
    public static function handle(Request $request): void
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $token = $request->post['_csrf'] ?? $request->header('X-CSRF-Token');
        if (!App::csrf()->verify(is_string($token) ? $token : null)) {
            throw new HttpException(419);
        }
    }
}
