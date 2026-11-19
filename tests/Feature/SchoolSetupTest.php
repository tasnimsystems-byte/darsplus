<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\SchoolSetup;
use Tests\TestCase;

/** A new school ready to work: year and terms, grade levels and classes, the principal's account – in one step. */
final class SchoolSetupTest extends TestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $home = $this->createSchool();
        $this->createYear($home);
        $this->admin = $this->createUser('t.admin', [['system_admin', null], ['principal', $home]]);
        $this->loginAs($this->admin, $home);
    }

    public function testFullSetup(): void
    {
        self::assertStringContainsString('name="principal_username"', $this->get('/schools/create')->body);
        $response = $this->post('/schools', [
            'name' => 'المدرسة الجديدة', 'code' => 'new-1', 'default_locale' => 'ar',
            'year_name' => '', 'year_start' => '2026-09-01', 'year_end' => '2027-06-30', 'term_count' => '3',
            'levels_from' => '7', 'levels_to' => '9', 'classes_per_level' => '2',
            'principal_name' => 'علي موسى', 'principal_username' => 'p.newschool',
        ]);
        $this->assertRedirect('/schools', $response);
        $db = App::db();
        $school = (int) $db->value("SELECT id FROM schools WHERE code = 'NEW-1'");
        self::assertGreaterThan(0, $school);

        $year = $db->one('SELECT * FROM academic_years WHERE school_id = ?', [$school]);
        self::assertSame('2026-2027', $year['name']);
        self::assertSame(1, (int) $year['is_current']);
        self::assertSame(['الفصل الأول', 'الفصل الثاني', 'الفصل الثالث'], $db->column('SELECT name FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order', [$year['id']]));
        self::assertSame('2027-06-30', $db->value('SELECT MAX(end_date) FROM academic_terms WHERE academic_year_id = ?', [$year['id']]));
        self::assertSame(['السابع', 'الثامن', 'التاسع'], $db->column('SELECT name FROM grade_levels WHERE school_id = ? ORDER BY level_number', [$school]));
        self::assertSame(['السابع 1', 'السابع 2', 'الثامن 1', 'الثامن 2', 'التاسع 1', 'التاسع 2'], $db->column('SELECT c.name FROM classes c JOIN grade_levels gl ON gl.id = c.grade_level_id WHERE c.school_id = ? ORDER BY gl.level_number, c.name', [$school]));
        self::assertGreaterThan(0, (int) $db->value('SELECT COUNT(*) FROM assessment_types WHERE school_id = ?', [$school]), 'Defaults are there.');

        $principal = $db->one("SELECT id, must_change_password, full_name FROM users WHERE username = 'p.newschool'");
        self::assertSame(1, (int) $principal['must_change_password']);
        self::assertSame('علي موسى', $principal['full_name']);
        self::assertSame('principal', $db->value('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND ur.school_id = ?', [$principal['id'], $school]));
        $page = $this->get('/schools')->body;
        self::assertStringContainsString('class="temp-password"', $page, 'The starting password is shown once.');
        self::assertStringContainsString('المدرسة الجديدة', $page);
    }

    public function testOnlyTheBasicsAndRefusals(): void
    {
        $this->post('/schools', ['name' => 'בית ספר', 'code' => 'BASIC', 'default_locale' => 'he']);
        $basic = (int) App::db()->value("SELECT id FROM schools WHERE code = 'BASIC'");
        self::assertGreaterThan(0, $basic);
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM academic_years WHERE school_id = ?', [$basic]));

        // A refused part creates nothing at all.
        foreach ([['levels_from' => '9', 'levels_to' => '7'], ['principal_username' => 't.admin'], ['principal_username' => 'Bad Name!'],
                  ['year_start' => '2026-09-01', 'year_end' => '2026-01-01'], ['year_start' => '2026-09-01']] as $bad) {
            $this->post('/schools', $bad + ['name' => 'x', 'code' => 'BAD', 'default_locale' => 'he']);
            self::assertNull(App::db()->value("SELECT id FROM schools WHERE code = 'BAD'"), json_encode($bad));
        }

        // Only the network administrator creates schools.
        $other = $this->createSchool();
        $this->createYear($other);
        $this->loginAs($this->createUser('t.principal', [['principal', $other]]));
        self::assertSame(403, $this->post('/schools', ['name' => 'y', 'code' => 'NOPE', 'default_locale' => 'he'])->status);
    }

    public function testHebrewNames(): void
    {
        self::assertSame("ז'", SchoolSetup::levelName(7, 'he'));
        self::assertSame('י"א', SchoolSetup::levelName(11, 'he'));
        self::assertSame('ז2', SchoolSetup::className(7, 2, 'he'));
        self::assertSame('الحادي عشر 1', SchoolSetup::className(11, 1, 'ar'));
    }
}
