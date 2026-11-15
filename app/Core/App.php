<?php

declare(strict_types=1);

namespace App\Core;

use Dotenv\Dotenv;

/**
 * Application registry: configuration and the handful of shared services.
 * Deliberately small — no reflection-based container.
 */
final class App
{
    private static string $root = '';
    private static array $config = [];
    private static ?Database $db = null;
    private static ?Session $session = null;
    private static ?Lang $lang = null;
    private static ?Auth $auth = null;
    private static ?Csrf $csrf = null;

    public static function boot(string $root): void
    {
        self::$root = rtrim($root, '/\\');
        Dotenv::createImmutable(self::$root)->safeLoad();

        self::$config = [];
        foreach (glob(self::$root . '/config/*.php') ?: [] as $file) {
            self::$config[basename($file, '.php')] = require $file;
        }

        date_default_timezone_set((string) self::config('app.timezone', 'Asia/Jerusalem'));
        mb_internal_encoding('UTF-8');

        self::$db = null;
        self::$session = null;
        self::$auth = null;
        self::$csrf = null;
        self::$lang = new Lang(self::$root . '/lang', (string) self::config('app.locale', 'he'));
    }

    public static function root(string $path = ''): string
    {
        return self::$root . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    /** Dot notation: App::config('app.locale'). */
    public static function config(string $key, mixed $default = null): mixed
    {
        $value = self::$config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function isDebug(): bool
    {
        return (bool) self::config('app.debug', false);
    }

    public static function db(): Database
    {
        return self::$db ??= new Database(self::config('database'));
    }

    public static function session(): Session
    {
        return self::$session ??= Session::start(self::config('app.session'));
    }

    public static function useSession(Session $session): void
    {
        self::$session = $session;
        self::$auth = null;
        self::$csrf = null;
    }

    public static function lang(): Lang
    {
        return self::$lang;
    }

    public static function csrf(): Csrf
    {
        return self::$csrf ??= new Csrf(self::session());
    }

    public static function auth(): Auth
    {
        return self::$auth ??= new Auth(self::db(), self::session());
    }

    /** A fresh Auth context for every request (matters for tests, which run many requests per process). */
    public static function resetAuth(): Auth
    {
        self::$auth = null;
        return self::auth();
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] %s: %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );
        @file_put_contents(self::root('storage/logs/app-' . date('Y-m-d') . '.log'), $line, FILE_APPEND | LOCK_EX);
    }
}
