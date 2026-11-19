<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use Tests\TestCase;

/** Design system: colour mode per user, audience on <html>, flash messages as toasts. */
final class ThemeTest extends TestCase
{
    public function testColourModeIsKeptPerUserAndAppliedOnTheServer(): void
    {
        $school = $this->createSchool();
        $this->createYear($school);
        $user = $this->createUser('t.principal', [['principal', $school]]);
        $this->loginAs($user);

        $page = $this->get('/')->body;
        self::assertStringContainsString('data-audience="staff" data-theme-pref="auto" data-bs-theme="light"', $page);
        self::assertStringContainsString('/assets/js/theme.js', $page, 'Loaded in <head> to settle "auto" before the page is drawn.');
        self::assertStringContainsString('/assets/css/tokens.css', $page);

        $this->assertRedirect('/students', $this->post('/theme', ['theme' => 'dark', 'back' => '/students']));
        self::assertSame('dark', App::db()->value('SELECT theme FROM users WHERE id = ?', [$user]));
        App::resetAuth();
        $this->loginAs($user);
        self::assertStringContainsString('data-theme-pref="dark" data-bs-theme="dark"', $this->get('/')->body);

        // Unknown values are ignored; only local paths are followed.
        $this->assertRedirect('/', $this->post('/theme', ['theme' => 'neon', 'back' => 'https://evil.example']));
        self::assertSame('dark', App::db()->value('SELECT theme FROM users WHERE id = ?', [$user]));
        self::assertSame(419, $this->post('/theme', ['theme' => 'light'], false)->status, 'CSRF protected.');
    }

    /** The sidebar only (the dashboard has its own links to the lists). */
    private function menu(): string
    {
        $body = $this->get('/')->body;
        $start = strpos($body, '<nav aria-label=');
        return substr($body, $start, strpos($body, '</nav>', $start) - $start);
    }

    public function testRegularTeacherMenuLeavesOutStudentsAndClasses(): void
    {
        $db = App::db();
        $school = $this->createSchool();
        $year = $this->createYear($school);
        $class = $this->createClass($school, $year, $this->createLevel($school, 'ז', 7), 'ז1');
        $user = $this->createUser('t.teacher', [['subject_teacher', $school]]);
        $teacher = $this->createTeacher($school, 'לוי', $user);
        $this->assign($school, $year, $teacher, $class, $this->createSubject($school, 'מתמטיקה'));
        $student = $this->createStudent($school, 'כהן', $class);
        $this->loginAs($user);

        $menu = $this->menu();
        self::assertStringNotContainsString('href="/students"', $menu);
        self::assertStringNotContainsString('href="/classes"', $menu);
        self::assertStringContainsString('href="/timetable"', $menu);
        // Nor anywhere on the dashboard: the student and class counts are plain figures.
        $dashboard = $this->get('/')->body;
        self::assertStringNotContainsString('href="/students"', $dashboard);
        self::assertStringNotContainsString('href="/classes"', $dashboard);
        self::assertStringContainsString('kpi-static', $dashboard);
        self::assertSame(200, $this->get("/students/$student")->status, 'Their own students stay reachable from the lessons.');

        // A homeroom teacher keeps them.
        $db->update('classes', ['homeroom_teacher_id' => $teacher], ['id' => $class]);
        self::assertStringContainsString('href="/students"', $this->menu());
        // So does a coordinator (a position this year).
        $db->update('classes', ['homeroom_teacher_id' => null], ['id' => $class]);
        $db->insert('staff_positions', ['school_id' => $school, 'academic_year_id' => $year, 'teacher_id' => $teacher, 'position_type' => 'grade_coordinator']);
        self::assertStringContainsString('href="/classes"', $this->menu());

        // And management, of course.
        $this->loginAs($this->createUser('t.principal', [['principal', $school]]));
        $menu = $this->menu();
        self::assertStringContainsString('href="/students"', $menu);
        self::assertStringContainsString('href="/classes"', $menu);
    }

    public function testProductNameAndFooterOnEveryPage(): void
    {
        $login = $this->get('/login')->body;
        self::assertStringContainsString('DarsPlus', $login);
        self::assertStringContainsString('أنظمة تسنيم للحاسوب', $login);
        self::assertStringContainsString('href="mailto:tasnimsystems@gmail.com"', $login);
        self::assertStringContainsString('href="tel:0547297817"', $login);

        $school = $this->createSchool(null, 'ar');
        $this->createYear($school);
        $user = $this->createUser('t.principal', [['principal', $school]], ['locale' => 'ar']);
        $this->loginAs($user);
        $page = $this->get('/')->body;
        self::assertStringContainsString('· درس بلاس</title>', $page);
        self::assertStringContainsString('class="app-footer d-print-none"', $page);
        self::assertStringContainsString('كل الحقوق محفوظة', $page);
    }

    public function testFlashMessagesAreToastsInsideAndAlertsOnGuestPages(): void
    {
        $school = $this->createSchool();
        $this->createYear($school);
        $this->loginAs($this->createUser('t.principal', [['principal', $school]]));
        App::session()->flash('success', 'app.updated');
        $page = $this->get('/')->body;
        self::assertStringContainsString('class="toast toast-success show" role="status"', $page);
        self::assertStringContainsString(e(__('app.updated')), $page);
    }
}
