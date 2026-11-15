<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;

/**
 * Per-session ceilings for signed-in users: state-changing requests and file
 * exports per minute. It stops a runaway script or a stuck browser from
 * hammering the database and bulk-downloading reports.
 *
 * It is not the defence against password guessing — that is the per-username
 * and per-IP lock in AuthService, which lives in the database and cannot be
 * escaped by discarding a cookie.
 */
final class RateLimit
{
    public static function handle(Request $request, Session $session): void
    {
        $export = in_array($request->query['format'] ?? '', ['xlsx', 'pdf'], true) || str_ends_with($request->path, '/download');
        if ($request->method === 'GET' && !$export) {
            return;
        }
        $bucket = $export ? 'exports' : 'writes';
        if (!self::allow($session, $bucket, (int) App::config("app.rate_limit.{$bucket}_per_minute", 60))) {
            throw new HttpException(429);
        }
    }

    /** Fixed one-minute window counter kept in the session. */
    public static function allow(Session $session, string $bucket, int $limit, ?int $now = null): bool
    {
        $now ??= time();
        $state = $session->get('_rate', []);
        $window = intdiv($now, 60);
        if (($state[$bucket]['window'] ?? null) !== $window) {
            $state[$bucket] = ['window' => $window, 'count' => 0];
        }
        $state[$bucket]['count']++;
        $session->set('_rate', $state);
        return $state[$bucket]['count'] <= $limit;
    }
}
