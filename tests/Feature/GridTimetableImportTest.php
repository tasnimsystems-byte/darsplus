<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\GridTimetableReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Import of the iSchool weekly report ("מערכת שבועית לכל הכיתות"): classes in columns, one row per
 * day and period, a cell of "subject / teachers / type". As in the real export, a day's rows after the
 * first leave out the day cell and start one column earlier.
 */
final class GridTimetableImportTest extends TestCase
{
    private int $school;
    private int $year;
    private int $class1;
    private int $levi;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('zip')) {
            self::markTestSkipped('The zip extension is needed to write xlsx fixtures.');
        }
        $this->school = $this->createSchool();
        $this->year = $this->createYear($this->school);
        $level = $this->createLevel($this->school, 'ז', 7);
        $this->class1 = $this->createClass($this->school, $this->year, $level, 'ז1');
        $this->levi = App::db()->insert('teachers', ['school_id' => $this->school, 'first_name' => 'רונית', 'last_name' => 'לוי']);
        $this->createSubject($this->school, 'מתמטיקה');
        $this->createSubject($this->school, 'היסטוריה');
        $this->loginAs($this->createUser('t.principal', [['principal', $this->school]]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tempFiles, 'is_file'));
        parent::tearDown();
    }

    /** @param array<int, array<int, string>> $rows written from column A, row by row */
    private function xlsx(array $rows): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if ($value !== '') {
                    $sheet->setCellValue([$c + 1, $r + 1], $value);
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'grid');
        (new Xlsx($book))->save($path);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function upload(string $path, string $name = 'ischool_schedule_report.xlsx'): \App\Core\Response
    {
        return $this->post('/timetable/import', [], true, [
            'file' => ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)],
        ]);
    }

    private function fixture(): string
    {
        return $this->xlsx([
            ['#', '#', 'ז1', 'ז2'],
            ["יום ראשון\n", "1\n", "מתמטיקה\nרונית לוי\nפרונטלי\n", "היסטוריה\nדן כהן, מיכל אבן\nמפוצל\n"],
            ["2\n", "מתמטיקה\nרונית  לוי(מצוינות)\nמקבץ\n", "מתמטיקה\nרונית לוי\nמקבץ\n"],
            ["יום שני\n", "1\n", '', "אנגלית, מתמטיקה\nשרה גל, רונית לוי\nמקבץ מפוצל\n"],
            ["2\n"],
        ]);
    }

    public function testGridIsPreviewedAndImported(): void
    {
        $preview = $this->upload($this->fixture());
        self::assertSame(200, $preview->status);
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM timetable_slots'), 'The preview writes nothing.');
        self::assertStringContainsString('ischool_schedule_report', $preview->body);

        $this->assertRedirect('/timetable', $this->post('/timetable/import/confirm'));
        $db = App::db();
        $class2 = (int) $db->value("SELECT id FROM classes WHERE name = 'ז2' AND school_id = ?", [$this->school]);
        self::assertGreaterThan(0, $class2, 'A missing class is created in its grade level.');
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM grade_levels WHERE school_id = ?', [$this->school]));

        $assignments = [];
        foreach ($db->all(
            "SELECT CONCAT(t.first_name, ' ', t.last_name) AS teacher, c.name AS class, s.name AS subject, ta.weekly_hours,
                    (SELECT GROUP_CONCAT(CONCAT(ts.weekday, '/', ts.period_number) ORDER BY ts.weekday, ts.period_number) FROM timetable_slots ts WHERE ts.teaching_assignment_id = ta.id) AS slots
               FROM teaching_assignments ta JOIN teachers t ON t.id = ta.teacher_id JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id
              WHERE ta.school_id = ?",
            [$this->school]
        ) as $row) {
            $assignments["{$row['teacher']}|{$row['class']}|{$row['subject']}"] = [(float) $row['weekly_hours'], $row['slots']];
        }
        ksort($assignments);
        self::assertSame([
            'דן כהן|ז2|היסטוריה' => [1.0, '0/1'],
            'מיכל אבן|ז2|היסטוריה' => [1.0, '0/1'],
            'רונית לוי|ז1|מתמטיקה' => [2.0, '0/1,0/2'],   // the shifted row and the note in parentheses
            'רונית לוי|ז2|מתמטיקה' => [2.0, '0/2,1/1'],   // "אנגלית, מתמטיקה" with "שרה גל, רונית לוי": paired in order
            'שרה גל|ז2|אנגלית' => [1.0, '1/1'],
        ], $assignments);
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM teachers WHERE school_id = ? AND last_name = 'לוי'", [$this->school]), 'The existing teacher is matched.');
        self::assertSame(['שיעור 1', 'שיעור 2'], $db->column('SELECT name FROM timetable_periods WHERE school_id = ? ORDER BY period_number', [$this->school]));
        self::assertStringContainsString('ז1', $this->get('/timetable', ['class_id' => (string) $this->class1])->body);

        // רונית לוי teaches מתמטיקה to ז1 and ז2 together on Sunday 2nd period: the shared lesson became a study group.
        $group = $db->one('SELECT id, name FROM study_groups WHERE school_id = ?', [$this->school]);
        self::assertSame('מתמטיקה – רונית לוי (ז1، ז2)', $group['name']);
        self::assertSame(2, (int) $db->value('SELECT COUNT(*) FROM teaching_assignments WHERE study_group_id = ?', [$group['id']]));
    }

    public function testOtherSpreadsheetsAreRefused(): void
    {
        $this->upload($this->xlsx([['שם', 'כיתה'], ['דנה', 'ז1']]));
        self::assertStringContainsString(e(__('timetable.import.errors.grid_format')), $this->get('/timetable/import')->body);
        $this->upload($this->xlsx([['#', '#', 'ז1'], ['יום ראשון', '1', '']]));
        self::assertStringContainsString(e(__('timetable.import.errors.grid_empty')), $this->get('/timetable/import')->body);
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM teaching_assignments'));
    }

    public function testDayNames(): void
    {
        self::assertSame(0, GridTimetableReader::weekday("يوم الاحد\n"));
        self::assertSame(3, GridTimetableReader::weekday('يوم الأربعاء'));
        self::assertSame(5, GridTimetableReader::weekday('يوم الجمعة'));
        self::assertSame(1, GridTimetableReader::weekday('יום שני'));
        self::assertSame(4, GridTimetableReader::weekday("יום ה'"));
        self::assertNull(GridTimetableReader::weekday('3'));
        self::assertNull(GridTimetableReader::weekday('#'));
    }
}
