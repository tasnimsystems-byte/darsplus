<?php

declare(strict_types=1);

$env = (string) env('APP_ENV', 'production');

return [
    'name' => 'DarsPlus',

    // The maker of DarsPlus, shown at the foot of every page.
    'vendor' => [
        'name' => 'أنظمة تسنيم للحاسوب',
        'email' => 'tasnimsystems@gmail.com',
        'phone' => '0547297817',
    ],
    'env' => $env,
    'debug' => (bool) env('APP_DEBUG', false),
    // Set when the application is served from a sub-directory, e.g. "/spms".
    'base_path' => (string) env('APP_BASE_PATH', ''),
    'timezone' => (string) env('APP_TIMEZONE', 'Asia/Jerusalem'),
    'locale' => (string) env('APP_LOCALE', 'he'),

    'password_min_length' => 10,

    'session' => [
        'name' => 'spms_session',
        // Must be true in production (HTTPS). Defaults to true unless running locally.
        'secure' => (bool) env('SESSION_SECURE', !in_array($env, ['local', 'testing'], true)),
        'idle_minutes' => (int) env('SESSION_IDLE_MINUTES', 30),
    ],

    'login' => [
        'max_attempts_per_user' => 5,
        'max_attempts_per_ip' => 20,
        'window_minutes' => 15,
    ],

    // Per signed-in session, per minute (see App\Middleware\RateLimit).
    'rate_limit' => [
        'writes_per_minute' => (int) env('RATE_LIMIT_WRITES', 120),
        'exports_per_minute' => (int) env('RATE_LIMIT_EXPORTS', 20),
    ],

    'per_page' => 20,
];
