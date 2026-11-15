<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Validator;
use App\Middleware\SecurityHeaders;
use App\Services\Passwords;
use PHPUnit\Framework\TestCase;

final class CoreTest extends TestCase
{
    public function testEscapeHelperNeutralisesHtml(): void
    {
        self::assertSame('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', e("<script>alert('x')</script>"));
        self::assertSame('', e(null));
    }

    public function testCsrfTokenIsStablePerSessionAndVerifiedInConstantTime(): void
    {
        $csrf = new Csrf(Session::fake());
        $token = $csrf->token();
        self::assertSame(64, strlen($token));
        self::assertSame($token, $csrf->token());
        self::assertTrue($csrf->verify($token));
        self::assertFalse($csrf->verify($token . 'x'));
        self::assertFalse($csrf->verify(null));
        self::assertFalse((new Csrf(Session::fake()))->verify(''), 'An empty token must never verify.');
    }

    public function testRouterMatchesNumericParametersOnly(): void
    {
        $router = new Router();
        $router->get('/users/{id}/edit', ['C', 'edit']);
        $router->post('/users/{id}', ['C', 'update']);

        [$route, $params] = $router->match('GET', '/users/42/edit');
        self::assertSame(['C', 'edit'], $route['handler']);
        self::assertSame(['id' => 42], $params);
        self::assertNull($router->match('GET', '/users/abc/edit'));
        self::assertNull($router->match('GET', '/users/42'), 'GET must not match a POST-only route.');
        self::assertTrue($router->pathExists('/users/42'));
    }

    public function testRouteOptionsDefaultToAuthenticatedWithSchool(): void
    {
        $router = new Router();
        $router->get('/x', ['C', 'm']);
        self::assertSame(
            ['auth' => true, 'school' => true, 'permission' => null, 'password' => false],
            $router->routes()[0]['options']
        );
    }

    public function testValidatorRules(): void
    {
        $v = Validator::make(
            ['name' => '  Dana  ', 'email' => 'not-an-email', 'age' => '12', 'kind' => 'z', 'empty' => ''],
            ['name' => 'required|max:10', 'email' => 'email', 'age' => 'int|min:18', 'kind' => 'in:a,b',
             'empty' => 'max:5', 'missing' => 'required'],
            'users.fields'
        );
        self::assertTrue($v->fails());
        self::assertSame(['email', 'age', 'kind', 'missing'], array_keys($v->errors()));
        self::assertSame('Dana', $v->validated()['name'], 'Values are trimmed.');
        self::assertNull($v->validated()['empty'], 'Empty optional values become null.');
    }

    public function testValidatorRejectsArraysWhereScalarsAreExpected(): void
    {
        $v = Validator::make(['name' => ['a', 'b']], ['name' => 'required|string'], 'users.fields');
        self::assertTrue($v->fails());
    }

    public function testPasswordPolicy(): void
    {
        self::assertFalse(Validator::passwordIsStrong('short1a'));
        self::assertFalse(Validator::passwordIsStrong('onlylettershere'));
        self::assertFalse(Validator::passwordIsStrong('12345678901234'));
        self::assertTrue(Validator::passwordIsStrong('longenough123'));
        self::assertTrue(Validator::passwordIsStrong('סיסמהארוכה2026'), 'Non-Latin letters count as letters.');
    }

    public function testGeneratedPasswordsSatisfyThePolicyAndDiffer(): void
    {
        $a = Passwords::generate();
        $b = Passwords::generate();
        self::assertTrue(Validator::passwordIsStrong($a));
        self::assertNotSame($a, $b);
    }

    public function testPasswordsAreHashedNotStored(): void
    {
        $hash = Passwords::hash('secret-value-123');
        self::assertStringNotContainsString('secret-value-123', $hash);
        self::assertTrue(password_verify('secret-value-123', $hash));
        self::assertFalse(Passwords::needsRehash($hash));
    }

    public function testSecurityHeadersAreApplied(): void
    {
        $headers = SecurityHeaders::apply(new Response('x'))->headers;
        self::assertStringContainsString("script-src 'self'", $headers['Content-Security-Policy']);
        self::assertStringNotContainsString('unsafe-inline', $headers['Content-Security-Policy']);
        self::assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    public function testDownloadResponseNeverRendersInline(): void
    {
        $response = Response::download(__FILE__, 'דוח "שנתי".pdf', 'application/pdf');
        self::assertStringStartsWith('attachment;', $response->headers['Content-Disposition']);
        self::assertStringNotContainsString('"שנתי"', $response->headers['Content-Disposition']);
    }

    public function testDatabaseUsesNativePreparedStatements(): void
    {
        $pdo = App::db()->pdo();
        self::assertFalse((bool) $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES));
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
        // A classic injection string is stored and compared as data.
        self::assertNull(App::db()->value('SELECT id FROM users WHERE username = ?', ["' OR '1'='1"]));
    }
}
