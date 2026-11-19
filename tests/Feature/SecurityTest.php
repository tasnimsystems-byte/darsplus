<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Core\Session;
use App\Middleware\RateLimit;
use Tests\PedagogyFixture;
use Tests\TestCase;

/**
 * Stage G: checks that sweep the whole application rather than one module —
 * every route, every form control, every place a name is printed.
 */
final class SecurityTest extends TestCase
{
    use PedagogyFixture;

    private const XSS = '"><img src=x onerror=alert(1)><script>alert(2)</script>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    /** A populated school with one record of everything, and the pages that show it. */
    private function populate(): array
    {
        $this->loginAs($this->principal);
        $name = self::XSS;
        $db = App::db();
        $db->update('students', ['last_name' => $name, 'first_name' => $name], ['id' => $this->s[1]]);
        $teacher = (int) $db->value('SELECT id FROM teachers WHERE user_id = ?', [$this->mathUser]);
        $db->update('teachers', ['last_name' => $name], ['id' => $teacher]);
        $db->update('subjects', ['name' => $name], ['id' => $this->math]);
        $db->update('classes', ['name' => mb_substr($name, 0, 40)], ['id' => $this->classA]);
        $db->update('users', ['full_name' => $name], ['id' => $this->mathUser]);
        $db->update('schools', ['name' => $name], ['id' => $this->school]);

        $this->post('/work-plan', ['vision' => $name]);
        $this->post('/work-plan/objectives', ['title' => $name, 'description' => $name]);
        $objective = (int) $db->value('SELECT id FROM plan_objectives');
        $this->post('/goals', ['title' => $name, 'plan_objective_id' => $objective, 'status' => 'in_progress', 'description' => $name,
            'start_date' => date('Y-m-d', strtotime('-9 days')), 'end_date' => date('Y-m-d', strtotime('+9 days')), 'owner_user_id' => $this->mathUser]);
        $goal = (int) $db->value('SELECT id FROM annual_goals');
        $this->post("/goals/$goal/actions", ['title' => $name, 'resources' => $name]);
        $this->post("/goals/$goal/indicators", ['name' => $name, 'unit' => 'x']);
        $this->post("/goals/$goal/updates", ['progress_percent' => 10, 'status' => 'in_progress', 'note' => $name]);

        $this->post('/curriculum', ['title' => $name, 'subject_id' => $this->math, 'grade_level_id' => $this->level]);
        $plan = (int) $db->value('SELECT id FROM curriculum_plans');
        $this->post("/curriculum/$plan/units", ['title' => $name]);
        $unit = (int) $db->value('SELECT id FROM curriculum_units');
        $this->post("/curriculum/$plan/topics", ['title' => $name, 'unit_id' => $unit, 'skills' => $name, 'planned_week_start' => date('Y-m-d')]);

        $assessment = $this->assessment($this->mathA, $this->term1, [1 => 40, 2 => 90]);
        $db->update('assessments', ['title' => $name], ['id' => $assessment]);
        $db->run('UPDATE assessment_results SET comment = ? WHERE assessment_id = ?', [$name, $assessment]);
        $lesson = $this->lesson($this->mathA, '-1 days', 1, [1 => 'absent']);
        $db->run('UPDATE attendance_records SET note = ?', [$name]);
        $criterion = $db->insert('risk_criteria', ['school_id' => $this->school, 'name' => $name, 'criterion_type' => 'low_average', 'threshold' => 60]);
        $this->post('/risk/run', ['term_id' => $this->term1]);
        $indication = (int) $db->value('SELECT id FROM risk_indications');

        $this->post('/interventions', ['student_id' => $this->s[1], 'difficulty_area' => $name, 'situation_description' => $name, 'owner_user_id' => $this->principal, 'start_date' => date('Y-m-d'), 'status' => 'active']);
        $intervention = (int) $db->value('SELECT id FROM student_interventions');
        $this->post("/interventions/$intervention/actions", ['description' => $name]);
        $this->post("/interventions/$intervention/reviews", ['review_date' => date('Y-m-d'), 'findings' => $name, 'progress_rating' => 'same', 'next_steps' => $name]);
        $this->post('/students/' . $this->s[1] . '/guardians', ['full_name' => $name, 'relation' => 'x']);
        $this->post('/parent-contacts', ['student_id' => $this->s[1], 'contact_type' => 'call', 'contacted_on' => date('Y-m-d'), 'internal_notes' => $name, 'shared_summary' => $name, 'decisions' => $name, 'follow_up_status' => 'none']);
        $contact = (int) $db->value('SELECT id FROM parent_contacts');
        $this->post('/observations', ['teacher_id' => $teacher, 'record_type' => 'observation', 'observed_on' => date('Y-m-d'), 'strengths' => $name, 'feedback' => $name]);
        $observation = (int) $db->value('SELECT id FROM observations');
        $this->post("/observations/$observation/recommendations", ['recommendation' => $name]);
        $this->post("/staff-development/$teacher/goals", ['title' => $name, 'success_measure' => $name]);
        $this->post("/staff-development/$teacher/trainings", ['title' => $name, 'provider' => $name, 'status' => 'planned']);
        $this->post('/meetings', ['title' => $name, 'meeting_type' => 'pedagogical', 'meeting_date' => date('Y-m-d'), 'agenda' => $name, 'location' => $name, 'status' => 'planned', 'participant_ids' => [$this->mathUser]]);
        $meeting = (int) $db->value('SELECT id FROM meetings');
        $this->post("/meetings/$meeting/minutes", ['minutes' => $name]);
        $this->post("/meetings/$meeting/decisions", ['decision' => $name, 'owner_user_id' => $this->mathUser, 'create_task' => '1']);
        $task = (int) $db->value('SELECT id FROM tasks');
        $this->post("/tasks/$task/comments", ['body' => $name]);
        $db->insert('notifications', ['user_id' => $this->principal, 'school_id' => $this->school, 'type' => 't', 'title' => $name, 'body' => $name, 'link' => 'javascript:alert(1)']);

        $db->insert('timetable_periods', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'period_number' => 1, 'name' => mb_substr($name, 0, 60), 'start_time' => '08:00', 'end_time' => '08:45']);
        $db->insert('timetable_slots', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'teaching_assignment_id' => $this->mathA, 'weekday' => (int) date('w'), 'period_number' => 1]);
        $db->insert('timetable_imports', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'source_name' => $name, 'summary' => json_encode(['project' => $name])]);

        $s = $this->s[1];
        return ['/timetable', '/timetable/import', '/', '/notifications', '/students', "/students/$s", "/students/$s/edit", '/teachers', "/teachers/$teacher", '/classes', "/classes/{$this->classA}",
            '/subjects', '/users', "/users/{$this->mathUser}/edit", "/users/{$this->mathUser}/scopes", '/schools', '/audit',
            '/work-plan', '/goals', "/goals/$goal", "/goals/$goal/edit", '/curriculum', "/curriculum/$plan", "/curriculum/$plan?view=week",
            "/curriculum/$plan/progress/{$this->mathA}", '/assessments', "/assessments/$assessment", '/achievements', "/achievements?class_id={$this->classA}",
            '/attendance', "/attendance/sessions/$lesson", "/attendance/report?class_id={$this->classA}", '/risk', "/risk/$indication", '/risk/criteria',
            "/risk/criteria/$criterion/edit", '/interventions', "/interventions/$intervention", "/interventions/$intervention/edit",
            '/parent-contacts', "/parent-contacts/$contact", "/parent-contacts/$contact/summary", '/observations', "/observations/$observation",
            "/staff-development/$teacher", '/meetings', "/meetings/$meeting", "/meetings/$meeting/edit", '/tasks', '/tasks/board', "/tasks/$task",
            '/reports', "/reports/achievements_class?class_id={$this->classA}", "/reports/achievements_class?class_id={$this->classA}&format=print",
            '/reports/tasks', '/reports/meetings', '/reports/work_plan', '/reports/support_needed', '/reports/curriculum',
            "/reports/student_progress?student_id=$s", '/years', "/years/{$this->year}", "/years/{$this->year}/transition", '/calendar', '/documents'];
    }

    private function fetch(string $page): \App\Core\Response
    {
        [$path, $query] = array_pad(explode('?', $page, 2), 2, '');
        parse_str($query, $params);
        return $this->get($path, $params);
    }

    public function testNoPagePrintsStoredTextAsMarkup(): void
    {
        foreach ($this->populate() as $page) {
            $response = $this->fetch($page);
            self::assertSame(200, $response->status, $page);
            self::assertStringNotContainsString('<img src=x', $response->body, "$page prints stored text unescaped");
            self::assertStringNotContainsString('<script>alert(2)', $response->body, "$page prints stored text unescaped");
            self::assertStringNotContainsString('href="javascript:', $response->body, "$page links to a script URL");
        }
        // The payload did reach the pages — escaped.
        self::assertStringContainsString('&lt;script&gt;alert(2)', $this->get('/students')->body);
    }

    public function testEveryFormControlTableAndPageIsAccessiblyLabelled(): void
    {
        $problems = [];
        foreach ($this->populate() as $page) {
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $this->fetch($page)->body);
            $xpath = new \DOMXPath($dom);
            $has = static fn (string $query): bool => $xpath->query($query)->length > 0;

            if ($xpath->query('//html[@lang and @dir]')->length !== 1) {
                $problems[] = "$page: <html> lacks lang/dir";
            }
            if ($xpath->query('//h1')->length !== 1) {
                $problems[] = "$page: expected exactly one <h1>, found " . $xpath->query('//h1')->length;
            }
            foreach ($xpath->query('//input[not(@type="hidden") and not(@type="submit")] | //select | //textarea') as $control) {
                $id = $control->getAttribute('id');
                $labelled = $control->hasAttribute('aria-label') || $control->hasAttribute('aria-labelledby')
                    || ($id !== '' && $has('//label[@for="' . $id . '"]'))
                    || $xpath->query('ancestor::label', $control)->length > 0;
                if (!$labelled) {
                    $problems[] = "$page: unlabelled <{$control->nodeName} name=\"{$control->getAttribute('name')}\">";
                }
            }
            foreach ($xpath->query('//button | //a[@href]') as $element) {
                $text = trim($element->textContent);
                if ($text === '' && !$element->hasAttribute('aria-label')) {
                    $problems[] = "$page: <{$element->nodeName}> with no accessible name ({$element->getAttribute('href')}{$element->getAttribute('class')})";
                }
            }
            foreach ($xpath->query('//table[not(caption)]') as $table) {
                $problems[] = "$page: table without <caption>";
            }
            foreach ($xpath->query('//i[contains(@class,"bi ") and not(@aria-hidden)]') as $icon) {
                $problems[] = "$page: decorative icon not hidden from assistive technology";
            }
            $ids = [];
            foreach ($xpath->query('//*[@id]') as $element) {
                $ids[] = $element->getAttribute('id');
            }
            $duplicates = array_keys(array_filter(array_count_values($ids), static fn (int $n): bool => $n > 1));
            if ($duplicates !== []) {
                $problems[] = "$page: duplicate ids " . implode(', ', array_slice($duplicates, 0, 5));
            }
        }
        self::assertSame([], array_values(array_unique($problems)));
    }

    public function testEveryStateChangingRouteRejectsARequestWithoutCsrfToken(): void
    {
        $this->loginAs($this->principal);
        $checked = 0;
        foreach ($this->router->routes() as $route) {
            if ($route['method'] !== 'POST') {
                continue;
            }
            $response = $this->post(preg_replace('/\{\w+\}/', '1', $route['path']), ['title' => 'x'], withCsrf: false);
            self::assertSame(419, $response->status, 'POST ' . $route['path']);
            $checked++;
        }
        self::assertGreaterThan(90, $checked);
        self::assertNotNull(App::auth()->id(), 'Still signed in: nothing ran.');
    }

    public function testHostileInputNeverCausesAServerError(): void
    {
        $this->loginAs($this->principal);
        $junk = ['q' => "' OR 1=1 -- <script>", 'page' => '-7', 'status' => ['a' => 'b'], 'term_id' => "1; DROP TABLE users", 'class_id' => '99999999999999999999',
            'student_id' => "' UNION SELECT password_hash FROM users --", 'view' => '../../etc/passwd', 'format' => 'exe', 'date' => '9999-99-99',
            'from' => "'", 'to' => ['x'], 'mine' => '1 OR 1=1', 'indication' => '-1', 'subject_id' => 'NaN', 'grade_level_id' => '0x1F'];
        foreach ($this->router->routes() as $route) {
            $path = preg_replace('/\{\w+\}/', '987654321', $route['path']);
            if (in_array($route['path'], ['/login', '/logout'], true)) {
                continue;
            }
            $response = $route['method'] === 'GET' ? $this->get($path, $junk) : $this->post($path, $junk + ['name' => ['nested' => ['array']], 'title' => str_repeat('א', 5000)]);
            self::assertLessThan(500, $response->status, "{$route['method']} {$route['path']} answered {$response->status}");
            self::assertStringNotContainsString('SQLSTATE', $response->body);
            self::assertStringNotContainsString('Stack trace', $response->body);
        }
        self::assertSame(5, (int) App::db()->value('SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id WHERE ur.school_id = ?', [$this->school]), 'The users table is intact.');
        self::assertSame([], glob(App::root('storage/logs/php-error.log')) ?: [], 'No PHP error was logged while fuzzing.');
    }

    public function testPasswordChangeEndsOtherSessions(): void
    {
        // The same user signed in on two devices.
        $deviceA = $this->session;
        $this->loginAs($this->mathUser);
        $deviceB = Session::fake();
        App::useSession($deviceB);
        App::resetAuth()->login($this->mathUser);

        // Device B changes the password: it stays signed in…
        $this->assertRedirect('/', $this->post('/password', ['current_password' => self::PASSWORD, 'new_password' => 'Another-pass-99', 'new_password_confirmation' => 'Another-pass-99']));
        App::db()->run('UPDATE users SET password_changed_at = password_changed_at + INTERVAL 2 SECOND WHERE id = ?', [$this->mathUser]);
        $deviceB->set('auth_time', time() + 5);
        self::assertSame(200, $this->get('/')->status);
        // …device A is signed out at its next request.
        App::useSession($deviceA);
        $this->assertRedirect('/login', $this->get('/'));
        self::assertNull($deviceA->get('user_id'));
    }

    public function testAdministratorResetEndsTheUsersSession(): void
    {
        $userSession = $this->session;
        $this->loginAs($this->hebrewUser);
        self::assertSame(200, $this->get('/')->status);

        $adminSession = Session::fake();
        App::useSession($adminSession);
        App::resetAuth()->login($this->principal);
        $this->post("/users/{$this->hebrewUser}/reset-password");
        App::db()->run('UPDATE users SET password_changed_at = password_changed_at + INTERVAL 2 SECOND WHERE id = ?', [$this->hebrewUser]);

        App::useSession($userSession);
        $this->assertRedirect('/login', $this->get('/'));
    }

    public function testRateLimiterCountsPerMinuteWindow(): void
    {
        $session = Session::fake();
        $now = 1_800_000_000;
        self::assertTrue(RateLimit::allow($session, 'writes', 3, $now));
        self::assertTrue(RateLimit::allow($session, 'writes', 3, $now + 1));
        self::assertTrue(RateLimit::allow($session, 'writes', 3, $now + 2));
        self::assertFalse(RateLimit::allow($session, 'writes', 3, $now + 3), 'The fourth request in the same minute is refused.');
        self::assertTrue(RateLimit::allow($session, 'exports', 3, $now + 3), 'Buckets are counted separately.');
        self::assertTrue(RateLimit::allow($session, 'writes', 3, $now + 61), 'A new minute starts a new window.');
    }

    public function testResponsesCarrySecurityHeadersAndNothingSensitiveIsUnderTheWebRoot(): void
    {
        foreach ([$this->get('/login'), $this->get('/no-such-page'), $this->post('/login', [], withCsrf: false)] as $response) {
            foreach (['Content-Security-Policy', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Cross-Origin-Opener-Policy', 'Cache-Control'] as $header) {
                self::assertArrayHasKey($header, $response->headers);
            }
            self::assertStringNotContainsString('unsafe', $response->headers['Content-Security-Policy']);
        }
        // Views contain no inline scripts, inline handlers or inline styles (the CSP would block them anyway).
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(App::root('app/Views'))) as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'pdf.php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (preg_match('/<script(?![^>]*\bsrc=)|\son[a-z]+\s*=\s*"|\sstyle\s*=\s*"|<style/i', $source)) {
                $offenders[] = $file->getFilename();
            }
        }
        self::assertSame([], $offenders);

        $public = array_map('basename', glob(App::root('public') . '/{,.}[!.]*', GLOB_BRACE));
        sort($public);
        self::assertSame(['.htaccess', 'assets', 'index.php'], $public, 'Only the front controller and static assets are web-accessible.');
        foreach (['.env', 'composer.phar', 'vendor'] as $ignored) {
            self::assertMatchesRegularExpression('#^/' . preg_quote($ignored, '#') . '/?$#m', (string) file_get_contents(App::root('.gitignore')));
        }
        self::assertStringNotContainsString('DB_PASSWORD=' . (string) env('DB_PASSWORD', 'x-not-set'), (string) file_get_contents(App::root('.env.example')) . 'x');
    }

    public function testAuditLogNeverContainsCredentials(): void
    {
        $this->loginAs($this->principal);
        $this->post('/users', ['username' => 'audit.check', 'full_name' => 'Audit Check', 'roles' => [$this->roleId('viewer')]]);
        $temporary = (string) $this->session->pullFlash('temp_password');
        $created = (int) App::db()->value("SELECT id FROM users WHERE username = 'audit.check'");
        $this->post("/users/$created/reset-password");
        $second = (string) $this->session->pullFlash('temp_password');
        $this->post('/password', ['current_password' => self::PASSWORD, 'new_password' => 'Changed-pass-77', 'new_password_confirmation' => 'Changed-pass-77']);

        $log = implode("\n", array_map(static fn (array $row): string => implode('|', array_map('strval', $row)), App::db()->all('SELECT action, entity_type, old_values, new_values FROM audit_logs')));
        foreach ([$temporary, $second, 'Changed-pass-77', self::PASSWORD, '$argon2', '$2y$', 'password_hash'] as $secret) {
            self::assertNotSame('', $secret);
            self::assertStringNotContainsString($secret, $log);
        }
    }
}
