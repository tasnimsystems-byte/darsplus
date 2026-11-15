<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Response;

final class SecurityHeaders
{
    public static function apply(Response $response): Response
    {
        $response->headers += [
            // No inline scripts or styles anywhere in the views.
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
                . "font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cache-Control' => 'no-store, private',
        ];
        if (App::config('app.session.secure')) {
            $response->headers += ['Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'];
        }
        return $response;
    }
}
