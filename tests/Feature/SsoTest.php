<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\OidcClient;
use Tests\TestCase;

/**
 * Sign-in through the Ministry of Education (OpenID Connect), against a fake identity provider:
 * the user links their account once, then signs in with the provider; signatures, nonce, state and
 * expiry are checked, and only a fingerprint of the provider's identifier is stored.
 */
final class SsoTest extends TestCase
{
    private const ISSUER = 'https://idp.example.test';
    /** @var \OpenSSLAsymmetricKey */
    private $key;
    private array $jwk;
    private int $school;
    private int $user;
    /** Claims the fake provider puts in the next ID token (null = sign nothing useful). */
    private array $claims = [];
    private ?\OpenSSLAsymmetricKey $signWith = null;
    private array $requests = [];
    /** The nonce the provider received in the sign-in address (it signs it back into the token). */
    private string $nonce = '';

    protected function setUp(): void
    {
        parent::setUp();
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $config = 'C:/xampp/php/extras/ssl/openssl.cnf';
        if (PHP_OS_FAMILY === 'Windows' && is_file($config)) {
            $options['config'] = $config;
        }
        $this->key = openssl_pkey_new($options);
        $details = openssl_pkey_get_details($this->key);
        $this->jwk = ['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256',
            'n' => OidcClient::base64url($details['rsa']['n']), 'e' => OidcClient::base64url($details['rsa']['e'])];
        $this->enable();

        $this->school = $this->createSchool();
        $this->createYear($this->school);
        $this->user = $this->createUser('t.teacher', [['subject_teacher', $this->school]]);
    }

    protected function tearDown(): void
    {
        OidcClient::override(null);
        parent::tearDown();
    }

    private function enable(bool $enabled = true): void
    {
        OidcClient::override([
            'enabled' => $enabled, 'issuer' => self::ISSUER, 'client_id' => 'spms-test', 'client_secret' => 'secret',
            'redirect_uri' => 'https://spms.example.test/auth/sso/callback', 'scopes' => 'openid', 'id_claim' => 'sub',
            'hash_key' => str_repeat('k', 32), 'label_he' => 'כניסה דרך משרד החינוך', 'label_ar' => 'الدخول عبر وزارة التربية',
        ], function (string $method, string $url, array $headers, ?string $body): array {
            $this->requests[] = [$method, $url, $body];
            return match (true) {
                $url === self::ISSUER . '/.well-known/openid-configuration' => [200, json_encode([
                    'issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER . '/authorize',
                    'token_endpoint' => self::ISSUER . '/token', 'jwks_uri' => self::ISSUER . '/jwks',
                ])],
                $url === self::ISSUER . '/jwks' => [200, json_encode(['keys' => [$this->jwk]])],
                $url === self::ISSUER . '/token' && $method === 'POST' => [200, json_encode(['id_token' => $this->idToken()])],
                default => [404, ''],
            };
        });
    }

    private function idToken(): string
    {
        $claims = $this->claims + ['iss' => self::ISSUER, 'aud' => 'spms-test', 'exp' => time() + 300, 'iat' => time(),
            'nonce' => $this->nonce, 'sub' => '012345678'];
        $head = OidcClient::base64url(json_encode(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT']));
        $body = OidcClient::base64url(json_encode($claims));
        openssl_sign("$head.$body", $signature, $this->signWith ?? $this->key, OPENSSL_ALGO_SHA256);
        return "$head.$body." . OidcClient::base64url($signature);
    }

    /** Goes to the provider and comes back with a code, as the browser would. */
    private function roundTrip(bool $link = false, ?string $state = null): \App\Core\Response
    {
        $go = $this->get('/auth/sso', $link ? ['link' => '1'] : []);
        self::assertSame(302, $go->status);
        parse_str((string) parse_url((string) $go->location(), PHP_URL_QUERY), $query);
        $this->nonce = $query['nonce'];
        return $this->get('/auth/sso/callback', ['state' => $state ?? $query['state'], 'code' => 'abc']);
    }

    public function testLinkThenSignInWithTheProvider(): void
    {
        self::assertStringContainsString('href="/auth/sso"', $this->get('/login')->body, 'The Ministry button.');

        // Linking, while signed in with the system's password.
        $this->loginAs($this->user);
        $go = $this->get('/auth/sso', ['link' => '1']);
        self::assertStringStartsWith(self::ISSUER . '/authorize?', (string) $go->location());
        self::assertStringContainsString('code_challenge_method=S256', (string) $go->location());
        self::assertStringContainsString('client_id=spms-test', (string) $go->location());
        parse_str((string) parse_url((string) $go->location(), PHP_URL_QUERY), $query);
        $this->nonce = $query['nonce'];
        $this->assertRedirect('/password', $this->get('/auth/sso/callback', ['state' => $query['state'], 'code' => 'abc']));
        $stored = (string) App::db()->value('SELECT sso_subject_hash FROM users WHERE id = ?', [$this->user]);
        self::assertSame(OidcClient::fingerprint('012345678'), $stored);
        self::assertStringNotContainsString('012345678', $stored, 'Only a fingerprint is kept.');
        self::assertStringContainsString('code_verifier=', (string) array_values(array_filter($this->requests, static fn ($r) => $r[0] === 'POST'))[0][2], 'PKCE.');

        // Signing in.
        $this->post('/logout');
        App::resetAuth();
        $this->assertRedirect('/', $this->roundTrip());
        self::assertSame($this->user, App::auth()->id());
        self::assertSame(1, (int) App::db()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'login' AND new_values LIKE '%sso%'"));

        // Unlinking.
        $this->assertRedirect('/password', $this->post('/auth/sso/unlink'));
        self::assertNull(App::db()->value('SELECT sso_subject_hash FROM users WHERE id = ?', [$this->user]));
    }

    public function testEverythingThatMustBeRefused(): void
    {
        $this->loginAs($this->user);
        $this->roundTrip(true);
        $this->post('/logout');
        App::resetAuth();

        $refused = function (callable $prepare, string $why, ?string $state = null): void {
            $prepare();
            $response = $this->roundTrip(false, $state);
            self::assertSame('/login', parse_url((string) $response->location(), PHP_URL_PATH), $why);
            self::assertNull(App::auth()->id(), $why);
            $this->claims = [];
            $this->signWith = null;
        };
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + (is_file('C:/xampp/php/extras/ssl/openssl.cnf') ? ['config' => 'C:/xampp/php/extras/ssl/openssl.cnf'] : []));
        $refused(fn () => $this->signWith = $other, 'Signed with another key.');
        $refused(fn () => $this->claims = ['nonce' => 'not-this-one'], 'Wrong nonce.');
        $refused(fn () => $this->claims = ['exp' => time() - 3600], 'Expired.');
        $refused(fn () => $this->claims = ['aud' => 'another-app'], 'Another audience.');
        $refused(fn () => $this->claims = ['iss' => 'https://evil.example'], 'Another issuer.');
        $refused(fn () => $this->claims = ['sub' => '999999999'], 'Not linked to any account.');
        $refused(fn () => null, 'Forged state.', 'forged');

        // The provider's identity of one user cannot be linked to a second account.
        $second = $this->createUser('t.second', [['subject_teacher', $this->school]]);
        $this->loginAs($second);
        $this->assertRedirect('/password', $this->roundTrip(true));
        self::assertNull(App::db()->value('SELECT sso_subject_hash FROM users WHERE id = ?', [$second]));

        // Turned off: no button, no route.
        $this->enable(false);
        self::assertSame(404, $this->get('/auth/sso')->status);
        $this->post('/logout');
        App::resetAuth();
        self::assertStringNotContainsString('href="/auth/sso"', $this->get('/login')->body);
    }
}
