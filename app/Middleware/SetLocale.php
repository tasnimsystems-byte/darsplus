<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Auth;

final class SetLocale
{
    /** Explicit choice in this session → user's saved preference → school default → application default. */
    public static function handle(Auth $auth): void
    {
        App::lang()->setLocale(
            App::session()->get('locale')
            ?? $auth->user()['locale']
            ?? $auth->school()['default_locale']
            ?? App::config('app.locale')
        );
    }
}
