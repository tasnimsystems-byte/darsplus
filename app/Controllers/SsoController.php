<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditLogger;
use App\Services\OidcClient;

/**
 * Sign-in through the Ministry of Education (OpenID Connect).
 *
 *   GET  /auth/sso            → the provider's sign-in page (state, nonce and PKCE kept in the session)
 *   GET  /auth/sso?link=1     → the same, for a signed-in user who links their account
 *   GET  /auth/sso/callback   → back from the provider: verify, then sign in or link
 *   POST /auth/sso/unlink     → remove the link from one's own account
 *
 * Accounts are never created here: a provider identity signs in only to the account it was linked to,
 * by its own user, while signed in with the system's password.
 */
final class SsoController extends Controller
{
    private const SESSION_KEY = 'sso_attempt';
    private const MAX_AGE = 600;

    private function guardEnabled(): void
    {
        if (!OidcClient::enabled()) {
            throw new HttpException(404);
        }
    }

    public function start(Request $request): Response
    {
        $this->guardEnabled();
        $link = $request->str('link') === '1';
        if ($link && !$this->auth()->check()) {
            return Response::redirect('/login');
        }
        $attempt = [
            'state' => bin2hex(random_bytes(16)), 'nonce' => bin2hex(random_bytes(16)),
            'verifier' => OidcClient::base64url(random_bytes(48)), 'mode' => $link ? 'link' : 'login',
            'user_id' => $link ? $this->auth()->id() : null, 'at' => time(),
        ];
        try {
            $url = (new OidcClient())->authorizationUrl($attempt['state'], $attempt['nonce'], $attempt['verifier']);
        } catch (\DomainException $e) {
            return $this->fail($link, $e->getMessage());
        }
        App::session()->set(self::SESSION_KEY, $attempt);
        // An absolute URL of the provider: not through Response::redirect(), which prefixes local paths.
        return new Response('', 302, ['Location' => $url]);
    }

    public function callback(Request $request): Response
    {
        $this->guardEnabled();
        $attempt = App::session()->pull(self::SESSION_KEY);
        $link = is_array($attempt) && ($attempt['mode'] ?? '') === 'link';
        if (!is_array($attempt) || !hash_equals((string) ($attempt['state'] ?? ''), $request->str('state'))
            || time() - (int) ($attempt['at'] ?? 0) > self::MAX_AGE) {
            return $this->fail($link, 'auth.sso.errors.state');
        }
        if ($request->str('error') !== '' || $request->str('code') === '') {
            return $this->fail($link, 'auth.sso.errors.cancelled');
        }

        try {
            $claims = (new OidcClient())->claims($request->str('code'), (string) $attempt['verifier'], (string) $attempt['nonce']);
            $fingerprint = OidcClient::fingerprint(OidcClient::subject($claims));
        } catch (\DomainException $e) {
            AuditLogger::log('login_failed', 'user', null, null, ['method' => 'sso', 'reason' => $e->getMessage()]);
            return $this->fail($link, $e->getMessage());
        }

        if ($link) {
            $userId = (int) $attempt['user_id'];
            if (!$this->auth()->check() || $this->auth()->id() !== $userId) {
                return $this->fail(false, 'auth.sso.errors.state');
            }
            $owner = $this->db()->value('SELECT id FROM users WHERE sso_subject_hash = ?', [$fingerprint]);
            if ($owner !== null && (int) $owner !== $userId) {
                return $this->fail(true, 'auth.sso.errors.taken');
            }
            $this->db()->update('users', ['sso_subject_hash' => $fingerprint, 'sso_linked_at' => date('Y-m-d H:i:s')], ['id' => $userId]);
            $this->audit('sso_link', 'user', $userId);
            return $this->redirect('/password', 'auth.sso.linked');
        }

        $user = $this->db()->one(
            'SELECT id FROM users WHERE sso_subject_hash = ? AND is_active = 1 AND deleted_at IS NULL',
            [$fingerprint]
        );
        if ($user === null) {
            AuditLogger::log('login_failed', 'user', null, null, ['method' => 'sso', 'reason' => 'not_linked']);
            return $this->fail(false, 'auth.sso.errors.not_linked');
        }
        $userId = (int) $user['id'];
        $this->auth()->login($userId);
        $this->db()->update('users', ['last_login_at' => date('Y-m-d H:i:s')], ['id' => $userId]);
        AuditLogger::log('login', 'user', $userId, null, ['method' => 'sso'], $userId);
        return Response::redirect('/');
    }

    public function unlink(Request $request): Response
    {
        $userId = (int) $this->auth()->id();
        $this->db()->update('users', ['sso_subject_hash' => null, 'sso_linked_at' => null], ['id' => $userId]);
        $this->audit('sso_unlink', 'user', $userId);
        return $this->redirect('/password', 'auth.sso.unlinked');
    }

    private function fail(bool $link, string $key): Response
    {
        if ($link && $this->auth()->check()) {
            return $this->redirect('/password', null, $key);
        }
        return $this->invalid('/login', ['login' => [__($key)]], []);
    }
}
