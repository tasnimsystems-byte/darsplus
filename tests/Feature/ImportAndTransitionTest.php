<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\StudentImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class ImportAndTransitionTest extends TestCase
{
    private int $school;
    private int $year;
    private int $level7;
    private int $level8;
    private int $level9;
    private int $classA;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->year = $this->createYear($this->school);
        $this->level7 = $this->createLevel($this->school, 'ז', 7);
        $this->level8 = $this->createLevel($this->school, 'ח', 8);
        $this->level9 = $this->createLevel($this->school, 'ט', 9);
        $this->classA = $this->createClass($this->school, $this->year, $this->level7, 'ז1');
        $this->loginAs($this->createUser('t.principal', [['principal', $this->school]]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tempFiles, 'is_file'));
        parent::tearDown();
    }

    private function uploadImport(string $name, string $content): \App\Core\Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, $content);
        $this->tempFiles[] = $tmp;
        return $this->post('/students/import', [], true, [
            'file' => ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)],
        ]);
    }

    public function testCsvPreviewChangesNothingAndConfirmImportsOnlyValidRows(): void
    {
        $this->createStudent($this->school, 'Existing', null, ['student_code' => 'A100']);
        $csv = "\xEF\xBB\xBF" . implode("\n", [
            'קוד תלמיד,שם פרטי,שם משפחה,מגדר,תאריך לידה,כיתה,ת.ז',
            'A101,יעל,כהן,נ,02/04/2013,ז1,123456789',
            ',דוד,לוי,ז,2013-11-30,,',
            'A100,כפול,קיים,,,,',
            'A102,,חסרשם,,,,',
            'A103,רון,כיתהשגויה,,,י5,',
            'A104,טל,תאריךשגוי,x,31/02/2013,,',
            'A101,שוב,כפולבקובץ,,,,',
            ',,,,,,',
        ]);

        $response = $this->uploadImport('students.csv', $csv);
        self::assertSame(200, $response->status);
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM students'), 'Preview must not save anything.');
        self::assertStringContainsString('כהן', $response->body);
        self::assertStringNotContainsString('123456789', $response->body, 'Unknown columns are never read.');

        $this->assertRedirect('/students', $this->post('/students/import/confirm'));
        $imported = App::db()->all("SELECT * FROM students WHERE last_name IN ('כהן', 'לוי') ORDER BY last_name");
        self::assertCount(2, $imported);
        self::assertSame(3, (int) App::db()->value('SELECT COUNT(*) FROM students'));

        [$cohen, $levi] = $imported;
        self::assertSame(['A101', 'f', '2013-04-02'], [$cohen['student_code'], $cohen['gender'], $cohen['birth_date']]);
        self::assertSame($this->classA, (int) App::db()->value('SELECT class_id FROM class_enrollments WHERE student_id = ?', [$cohen['id']]));
        self::assertMatchesRegularExpression('/^S\d{5}$/', $levi['student_code']);
        self::assertSame(['m', '2013-11-30'], [$levi['gender'], $levi['birth_date']]);
        self::assertNull(App::db()->value('SELECT 1 FROM class_enrollments WHERE student_id = ?', [$levi['id']]));
        self::assertSame('S', App::db()->value("SELECT first_name FROM students WHERE student_code = 'A100'"), 'Existing students are never overwritten.');

        self::assertSame(1, $this->auditCount('import', 'student'));
        self::assertSame([], glob(App::root('storage/cache/import_*')), 'The uploaded file is deleted after the import.');

        // Confirming again without a pending file does nothing.
        $this->assertRedirect('/students/import', $this->post('/students/import/confirm'));
        self::assertSame(3, (int) App::db()->value('SELECT COUNT(*) FROM students'));
    }

    public function testBadImportFilesAreRejected(): void
    {
        $cases = [
            'students.txt' => "first_name,last_name\na,b",
            'students.csv' => "name,surname\na,b",                                  // required headings missing
            'empty.csv' => "first_name,last_name\n",
            'latin.csv' => "first_name,last_name\n" . mb_convert_encoding('יעל,כהן', 'ISO-8859-8', 'UTF-8'),
            'fake.xlsx' => 'this is not a spreadsheet',
        ];
        foreach ($cases as $name => $content) {
            $this->assertRedirect('/students/import', $this->uploadImport($name, $content));
            self::assertArrayHasKey('file', $this->session->pullFlash('errors') ?? [], $name);
        }
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM students'));
        self::assertSame([], glob(App::root('storage/cache/import_*')));
    }

    public function testSemicolonDelimiterArabicHeadingsAndCancel(): void
    {
        $response = $this->uploadImport('s.csv', "الاسم الشخصي;اسم العائلة;الجنس\nريم;حداد;أنثى\n");
        self::assertSame(200, $response->status);
        self::assertStringContainsString('حداد', $response->body);
        $this->assertRedirect('/students', $this->post('/students/import/cancel'));
        self::assertSame([], glob(App::root('storage/cache/import_*')));
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM students'));
    }

    public function testXlsxImport(): void
    {
        if (!extension_loaded('zip')) {
            self::markTestSkipped('The zip extension is not enabled; xlsx import is unavailable (CSV still works).');
        }
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([
            ['שם פרטי', 'שם משפחה', 'תאריך לידה', 'כיתה', 'קוד תלמיד'],
            ['נועה', 'אקסל', 41366, 'ז1', 1001],          // 41366 = 2013-04-02 as an Excel serial date
            ['=1+1', '=HYPERLINK("http://x","y")', '', '', ''], // formulas are read as text, never evaluated
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'xls');
        (new Xlsx($book))->save($tmp);
        $content = file_get_contents($tmp);
        unlink($tmp);

        self::assertSame(200, $this->uploadImport('students.xlsx', $content)->status);
        $this->post('/students/import/confirm');
        $student = App::db()->one("SELECT * FROM students WHERE last_name = 'אקסל'");
        self::assertSame(['1001', '2013-04-02'], [$student['student_code'], $student['birth_date']]);
        self::assertSame('=1+1', App::db()->value("SELECT first_name FROM students WHERE last_name LIKE '=HYPERLINK%'"));
    }

    public function testExportWithTitleRowsDiacriticsAndIdColumn(): void
    {
        $this->createClass($this->school, $this->year, $this->level7, 'السابع 1');
        $csv = implode("\n", [
            'التلميذ,,,,,,,الاب,الام',
            'تفاصيل أساسيّة,,,,,,تفاصيل الاتصال,تفاصيل الاتصال,تفاصيل الاتصال',
            'رقم الهويّة,الاسم الشخصيّ بالعربيّة,اسم الاب باللغة العربية,اسم العائلة بالعربيّة,اسم الصف بالعربي, ,رقم المحمول 1,رقم المحمول 1,رقم المحمول 1',
            '000000018,ليلى,سامي,الخطيب,السّابع (1),,050-0000001,050-0000002,050-0000003',
            '000000026,عمر,خالد,النجار,السّابع (1),,,050-0000004,',
        ]);
        $response = $this->uploadImport('export.csv', $csv);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('رقم الهويّة', $response->body, 'The skipped ID column is named in the preview.');

        $this->post('/students/import/confirm');
        $students = App::db()->all("SELECT * FROM students WHERE last_name IN ('الخطيب', 'النجار') ORDER BY last_name");
        self::assertCount(2, $students);
        $class = (int) App::db()->value("SELECT id FROM classes WHERE name = 'السابع 1'");
        foreach ($students as $student) {
            self::assertSame($class, (int) App::db()->value('SELECT class_id FROM class_enrollments WHERE student_id = ?', [$student['id']]), '"السّابع (1)" matches the class "السابع 1".');
            self::assertMatchesRegularExpression('/^S\d{5}$/', $student['student_code'], 'An internal code, never the ID number.');
        }
        $stored = json_encode(App::db()->all('SELECT * FROM students'), JSON_UNESCAPED_UNICODE) . json_encode(App::db()->all('SELECT new_values FROM audit_logs'), JSON_UNESCAPED_UNICODE);
        foreach (['000000018', '050-0000001', '050-0000004', 'سامي'] as $notStored) {
            self::assertStringNotContainsString($notStored, $stored);
        }
    }

    public function testNormalisation(): void
    {
        self::assertSame(StudentImportService::normalize('السابع 1'), StudentImportService::normalize('السّابع (1)'));
        self::assertSame(StudentImportService::normalize('ז1'), StudentImportService::normalize("ז'1"));
        self::assertSame(StudentImportService::normalize('الاسم الشخصي بالعربية'), StudentImportService::normalize('الاسم الشخصيّ بالعربيّة'));
        self::assertNotSame(StudentImportService::normalize('ז1'), StudentImportService::normalize('ז2'));
    }

    public function testDateParsing(): void
    {
        self::assertSame('2013-04-02', StudentImportService::parseDate('2013-04-02'));
        self::assertSame('2013-04-02', StudentImportService::parseDate('2/4/2013'));
        self::assertSame('2013-04-02', StudentImportService::parseDate('02.04.2013'));
        self::assertSame('2013-04-02', StudentImportService::parseDate('41366'));
        self::assertNull(StudentImportService::parseDate('31/02/2013'));
        self::assertNull(StudentImportService::parseDate('next week'));
    }

    public function testImportIsLimitedToTheUsersOwnClasses(): void
    {
        $classB = $this->createClass($this->school, $this->year, $this->level7, 'ז2');
        $role = $this->createRole($this->school, 'registrar', ['dashboard.view', 'students.view', 'students.create']);
        $user = $this->createUser('t.registrar');
        App::db()->insert('user_roles', ['user_id' => $user, 'role_id' => $role, 'school_id' => $this->school]);
        App::db()->insert('user_scopes', ['user_id' => $user, 'school_id' => $this->school, 'academic_year_id' => $this->year, 'scope_type' => 'class', 'scope_id' => $this->classA]);
        $this->loginAs($user);

        $this->uploadImport('s.csv', "first_name,last_name,class\na,Mine,ז1\nb,NotMine,ז2\n");
        $this->post('/students/import/confirm');
        self::assertNotNull(App::db()->value("SELECT id FROM students WHERE last_name = 'Mine'"));
        self::assertNull(App::db()->value("SELECT id FROM students WHERE last_name = 'NotMine'"), "A class outside the user's scope is 'not found'.");
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM class_enrollments WHERE class_id = ?', [$classB]));
    }

    public function testYearTransitionPromotesGraduatesAndKeepsHistory(): void
    {
        $class9 = $this->createClass($this->school, $this->year, $this->level9, 'ט1');
        $class8 = $this->createClass($this->school, $this->year, $this->level8, 'מצוינות');
        $stay = $this->createStudent($this->school, 'Promoted', $this->classA);
        $grad = $this->createStudent($this->school, 'Graduating', $class9);
        $left = $this->createStudent($this->school, 'LeftSchool', $this->classA, ['status' => 'left']);
        $skipped = $this->createStudent($this->school, 'Skipped', $class8);
        App::db()->insert('academic_terms', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'name' => 'מחצית א', 'sort_order' => 1, 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $teacher = $this->createTeacher($this->school, 'כהן');
        $this->assign($this->school, $this->year, $teacher, $this->classA, $this->createSubject($this->school, 'מתמטיקה'));

        // The proposal suggests the next level and a renamed class.
        $page = $this->get("/years/{$this->year}/transition");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('value="ח1"', $page->body);
        self::assertStringContainsString('value="2027-09-01"', $page->body);

        $form = [
            'name' => '2027-28', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'make_current' => '1',
            'classes' => [
                $this->classA => ['target' => $this->level8, 'name' => 'ח1'],
                $class9 => ['target' => 'graduate', 'name' => ''],
                $class8 => ['target' => 'skip', 'name' => ''],
            ],
        ];

        // Invalid attempts change nothing.
        $bad = $form;
        $bad['start_date'] = '2027-01-01';
        $this->post("/years/{$this->year}/transition", $bad);
        self::assertArrayHasKey('start_date', $this->session->pullFlash('errors'));
        $bad = $form;
        unset($bad['classes'][$class9]);
        $this->post("/years/{$this->year}/transition", $bad);
        self::assertArrayHasKey('classes', $this->session->pullFlash('errors'));
        $bad = $form;
        $bad['classes'][$this->classA]['target'] = $this->createLevel($this->createSchool(), 'ח', 8);
        $this->post("/years/{$this->year}/transition", $bad);
        self::assertArrayHasKey('classes', $this->session->pullFlash('errors'));
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM academic_years WHERE school_id = ?', [$this->school]));

        $response = $this->post("/years/{$this->year}/transition", $form);
        $newYear = App::db()->one("SELECT * FROM academic_years WHERE name = '2027-28'");
        $this->assertRedirect('/years/' . $newYear['id'], $response);
        self::assertSame(1, (int) $newYear['is_current']);
        self::assertSame(0, (int) App::db()->value('SELECT is_current FROM academic_years WHERE id = ?', [$this->year]));
        self::assertSame((int) $newYear['id'], (int) App::auth()->year()['id']);

        // Terms shifted by a year; one new class in level ח with the promoted student only.
        self::assertSame(['2027-09-01', '2028-01-31'], array_values(App::db()->one('SELECT start_date, end_date FROM academic_terms WHERE academic_year_id = ?', [$newYear['id']])));
        $newClasses = App::db()->all('SELECT * FROM classes WHERE academic_year_id = ?', [$newYear['id']]);
        self::assertCount(1, $newClasses);
        self::assertSame(['ח1', $this->level8], [$newClasses[0]['name'], (int) $newClasses[0]['grade_level_id']]);
        self::assertSame(
            [$stay],
            array_map('intval', App::db()->column('SELECT student_id FROM class_enrollments WHERE class_id = ?', [$newClasses[0]['id']]))
        );

        // History: the old enrolment is closed at the old year's end, not deleted.
        self::assertSame('2027-06-30', App::db()->value('SELECT left_on FROM class_enrollments WHERE student_id = ? AND class_id = ?', [$stay, $this->classA]));
        self::assertSame('graduated', App::db()->value('SELECT status FROM students WHERE id = ?', [$grad]));
        self::assertSame('left', App::db()->value('SELECT status FROM students WHERE id = ?', [$left]));
        self::assertNull(App::db()->value('SELECT left_on FROM class_enrollments WHERE student_id = ?', [$skipped]), 'Skipped classes are untouched.');
        // Assignments are not copied.
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM teaching_assignments WHERE academic_year_id = ?', [$newYear['id']]));
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM teaching_assignments WHERE academic_year_id = ?', [$this->year]));
        self::assertSame(1, $this->auditCount('year_transition', 'academic_year'));

        // Last year's roster is still readable by switching year.
        $this->post('/year', ['year_id' => $this->year]);
        self::assertStringContainsString('>ז1<', $this->get('/classes')->body);
    }
}
