<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->assertRedirect('/login', $this->get('/'));
        $this->assertRedirect('/login', $this->get('/users'));
    }

    public function testLoginPageRendersWithCsrfToken(): void
    {
        $response = $this->get('/login');
        self::assertSame(200, $response->status);
        self::assertStringContainsString('name="_csrf" value="' . App::csrf()->token() . '"', $response->body);
        self::assertStringContainsString('dir="rtl"', $response->body);
    }

    public function testSuccessfulLoginReachesTheDashboard(): void
    {
        $id = $this->createUser('t.principal', [['principal', $this->school]]);

        $this->assertRedirect('/', $this->post('/login', ['username' => 't.principal', 'password' => self::PASSWORD]));
        self::assertSame($id, App::auth()->id());
        self::assertSame($this->school, App::auth()->schoolId(), 'A single school is entered automatically.');
        self::assertSame(200, $this->get('/')->status);
        self::assertSame(1, $this->auditCount('login', 'user'));
        self::assertNotNull(App::db()->value('SELECT last_login_at FROM users WHERE id = ?', [$id]));
    }

    public function testUsernameIsCaseInsensitive(): void
    {
        $this->createUser('t.case', [['viewer', $this->school]]);
        $this->assertRedirect('/', $this->post('/login', ['username' => 'T.Case', 'password' => self::PASSWORD]));
    }

    public function testWrongPasswordAndUnknownUserGiveTheSameAnswer(): void
    {
        $this->createUser('t.user', [['viewer', $this->school]]);

        $this->assertRedirect('/login', $this->post('/login', ['username' => 't.user', 'password' => 'wrong-password-1']));
        $wrongPassword = $this->session->pullFlash('errors');
        $this->assertRedirect('/login', $this->post('/login', ['username' => 'no.such.user', 'password' => 'wrong-password-1']));
        $unknownUser = $this->session->pullFlash('errors');

        self::assertSame($wrongPassword, $unknownUser);
        self::assertNull(App::auth()->id());
        self::assertSame(2, $this->auditCount('login_failed', 'user'));
    }

    public function testAccountLocksAfterRepeatedFailures(): void
    {
        $this->createUser('t.lock', [['viewer', $this->school]]);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 't.lock', 'password' => 'wrong-password-1']);
        }
        // Even the correct password is refused while locked.
        $this->assertRedirect('/login', $this->post('/login', ['username' => 't.lock', 'password' => self::PASSWORD]));
        self::assertNull(App::auth()->id());
        self::assertSame(__('auth.locked'), $this->session->pullFlash('errors')['login'][0]);
    }

    public function testIpIsLockedAfterManyFailuresAcrossUsernames(): void
    {
        for ($i = 0; $i < 20; $i++) {
            App::db()->insert('login_attempts', ['username' => "ghost$i", 'ip_address' => '127.0.0.1', 'success' => 0]);
        }
        $this->createUser('t.ip', [['viewer', $this->school]]);
        $this->post('/login', ['username' => 't.ip', 'password' => self::PASSWORD]);
        self::assertNull(App::auth()->id());
    }

    public function testInactiveAndDeletedUsersCannotLogIn(): void
    {
        $this->createUser('t.inactive', [['viewer', $this->school]], ['is_active' => 0]);
        $this->createUser('t.deleted', [['viewer', $this->school]], ['deleted_at' => '2026-01-01 00:00:00']);
        foreach (['t.inactive', 't.deleted'] as $username) {
            $this->post('/login', ['username' => $username, 'password' => self::PASSWORD]);
            self::assertNull(App::auth()->id());
        }
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $this->createUser('t.csrf', [['viewer', $this->school]]);
        $response = $this->post('/login', ['username' => 't.csrf', 'password' => self::PASSWORD], withCsrf: false);
        self::assertSame(419, $response->status);
        self::assertNull(App::auth()->id());

        $response = $this->post('/login', ['username' => 't.csrf', 'password' => self::PASSWORD, '_csrf' => 'forged'], withCsrf: false);
        self::assertSame(419, $response->status);
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->loginAs($this->createUser('t.out', [['viewer', $this->school]]));
        $this->assertRedirect('/login', $this->post('/logout'));
        $this->assertRedirect('/login', $this->get('/'));
        self::assertNull($this->session->get('user_id'));
    }

    public function testLogoutRequiresPost(): void
    {
        $this->loginAs($this->createUser('t.out2', [['viewer', $this->school]]));
        self::assertSame(405, $this->get('/logout')->status);
        self::assertNotNull(App::auth()->id());
    }

    public function testIdleSessionExpires(): void
    {
        $this->loginAs($this->createUser('t.idle', [['viewer', $this->school]]));
        $this->session->set('last_activity', time() - 31 * 60);
        $this->assertRedirect('/login', $this->get('/'));
        self::assertNull($this->session->get('user_id'));
    }

    public function testDeactivatedUserLosesAnExistingSession(): void
    {
        $id = $this->createUser('t.kicked', [['viewer', $this->school]]);
        $this->loginAs($id);
        App::db()->update('users', ['is_active' => 0], ['id' => $id]);
        $this->assertRedirect('/login', $this->get('/'));
    }

    public function testForcedPasswordChangeBlocksEverythingElse(): void
    {
        $id = $this->createUser('t.force', [['viewer', $this->school]], ['must_change_password' => 1]);
        $this->loginAs($id);

        $this->assertRedirect('/password', $this->get('/'));
        self::assertSame(200, $this->get('/password')->status);

        // Weak password, wrong current password and mismatch are all refused.
        $this->post('/password', ['current_password' => self::PASSWORD, 'new_password' => 'short', 'new_password_confirmation' => 'short']);
        self::assertArrayHasKey('new_password', $this->session->pullFlash('errors'));
        $this->post('/password', ['current_password' => 'nope', 'new_password' => 'Brand-new-pass-1', 'new_password_confirmation' => 'Brand-new-pass-1']);
        self::assertArrayHasKey('current_password', $this->session->pullFlash('errors'));
        $this->post('/password', ['current_password' => self::PASSWORD, 'new_password' => 'Brand-new-pass-1', 'new_password_confirmation' => 'different-pass-1']);
        self::assertArrayHasKey('new_password_confirmation', $this->session->pullFlash('errors'));
        self::assertSame(1, (int) App::db()->value('SELECT must_change_password FROM users WHERE id = ?', [$id]));

        $this->assertRedirect('/', $this->post('/password', [
            'current_password' => self::PASSWORD, 'new_password' => 'Brand-new-pass-1', 'new_password_confirmation' => 'Brand-new-pass-1',
        ]));
        self::assertSame(0, (int) App::db()->value('SELECT must_change_password FROM users WHERE id = ?', [$id]));
        self::assertSame(200, $this->get('/')->status);
        self::assertTrue(password_verify('Brand-new-pass-1', App::db()->value('SELECT password_hash FROM users WHERE id = ?', [$id])));
    }

    public function testUserOfSeveralSchoolsMustChooseAndCannotEnterOthers(): void
    {
        $second = $this->createSchool();
        $foreign = $this->createSchool();
        $this->loginAs($this->createUser('t.multi', [['subject_teacher', $this->school], ['viewer', $second]]));

        $this->assertRedirect('/select-school', $this->get('/'));
        $page = $this->get('/select-school');
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('value="' . $foreign . '"', $page->body);

        self::assertSame(403, $this->post('/select-school', ['school_id' => $foreign])->status);
        self::assertNull(App::auth()->schoolId());

        $this->assertRedirect('/', $this->post('/select-school', ['school_id' => $second]));
        self::assertSame($second, App::auth()->schoolId());
    }

    public function testPermissionsFollowTheActiveSchool(): void
    {
        $second = $this->createSchool();
        $id = $this->createUser('t.two', [['principal', $this->school], ['viewer', $second]]);

        $this->loginAs($id, $this->school);
        self::assertSame(200, $this->get('/users')->status);

        $this->post('/select-school', ['school_id' => $second]);
        self::assertSame(403, $this->get('/users')->status, 'Principal rights in one school must not carry over to another.');
    }

    public function testInactiveSchoolCannotBeEntered(): void
    {
        $closed = $this->createSchool();
        App::db()->update('schools', ['is_active' => 0], ['id' => $closed]);
        $this->loginAs($this->createUser('t.closed', [['principal', $closed]]));
        $this->assertRedirect('/select-school', $this->get('/'));
    }

    public function testLanguageSwitchIsRememberedAndRedirectsLocallyOnly(): void
    {
        $id = $this->createUser('t.lang', [['viewer', $this->school]]);
        $this->loginAs($id);

        $this->assertRedirect('/', $this->post('/locale', ['locale' => 'ar', 'back' => 'https://evil.example/']));
        self::assertSame('ar', App::db()->value('SELECT locale FROM users WHERE id = ?', [$id]));
        $body = $this->get('/')->body;
        self::assertStringContainsString('<html lang="ar" dir="rtl">', $body);
        self::assertStringContainsString('لوحة المتابعة', $body);

        $this->post('/locale', ['locale' => 'xx']);
        self::assertSame('ar', $this->session->get('locale'), 'Unsupported locales are ignored.');
    }

    public function testErrorPagesDoNotLeakDetails(): void
    {
        $response = $this->get('/no-such-page');
        self::assertSame(404, $response->status);
        self::assertStringNotContainsString('Exception', $response->body);
        self::assertArrayHasKey('Content-Security-Policy', $response->headers);
    }
}
