<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\TimetableImportService;
use Tests\TestCase;

/**
 * Import of a weekly timetable exported from the DarsPlus generator (timetable-web,
 * "JSON backup"), the timetable grid and its use when recording attendance.
 * The fixture has the exact shape of export.php?format=json (fictional names).
 */
final class TimetableTest extends TestCase
{
    private int $school;
    private int $year;
    private int $level7;
    private int $class71;
    private int $teacherLevi;
    private int $math;
    private int $history;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->year = $this->createYear($this->school);
        $this->level7 = $this->createLevel($this->school, 'ז', 7);
        $this->class71 = $this->createClass($this->school, $this->year, $this->level7, 'ז1');
        $this->teacherLevi = App::db()->insert('teachers', ['school_id' => $this->school, 'first_name' => 'רונית', 'last_name' => 'לוי']);
        $this->math = $this->createSubject($this->school, 'מתמטיקה');
        $this->history = $this->createSubject($this->school, 'היסטוריה');
        $this->loginAs($this->createUser('t.principal', [['principal', $this->school]]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tempFiles, 'is_file'));
        parent::tearDown();
    }

    private function export(): array
    {
        return [
            'project' => ['id' => 3, 'name' => 'מערכת בדיקה', 'school_name' => 'בית ספר בדיקה', 'days' => '0,1,2,3,4'],
            'periods' => [
                ['num' => 1, 'name' => 'שיעור 1', 'start_time' => '08:00:00', 'end_time' => '08:45:00', 'is_break' => 0],
                ['num' => 2, 'name' => 'שיעור 2', 'start_time' => '08:50:00', 'end_time' => '09:35:00', 'is_break' => 0],
                ['num' => 3, 'name' => 'הפסקה', 'start_time' => '09:35:00', 'end_time' => '09:55:00', 'is_break' => 1],
                ['num' => 4, 'name' => 'שיעור 3', 'start_time' => '09:55:00', 'end_time' => '10:40:00', 'is_break' => 0],
            ],
            'grades' => [
                ['id' => 10, 'name' => "שכבת ז'", 'name_ar' => 'السّابع', 'sort' => 1],
                ['id' => 11, 'name' => "שכבת ח'", 'name_ar' => 'الثّامن', 'sort' => 2],
            ],
            'groups' => [
                ['id' => 100, 'grade_id' => 10, 'parent_id' => null, 'name' => "ז' 1", 'name_ar' => 'السّابع 1', 'students_count' => 0],
                ['id' => 101, 'grade_id' => 11, 'parent_id' => null, 'name' => "ח' 1", 'name_ar' => 'الثّامن 1', 'students_count' => 0],
                ['id' => 102, 'grade_id' => 11, 'parent_id' => 101, 'name' => 'מקבצה א', 'name_ar' => 'مجموعة أ', 'students_count' => 0],
            ],
            'teachers' => [
                ['id' => 1, 'first_name' => 'רונית', 'last_name' => 'לוי', 'first_name_ar' => 'رونيت', 'last_name_ar' => 'ليفي',
                    'short_name' => 'רל', 'email' => 'ronit@example.test', 'phone' => '050-0000099'],
                ['id' => 2, 'first_name' => 'דן', 'last_name' => 'כהן', 'first_name_ar' => '', 'last_name_ar' => '',
                    'short_name' => 'דכ', 'email' => '', 'phone' => '050-0000098'],
            ],
            'subjects' => [
                ['id' => 1, 'name' => 'מתמטיקה', 'name_ar' => 'الرياضيات', 'short_name' => 'מת'],
                ['id' => 2, 'name' => 'אנגלית', 'name_ar' => 'اللغة الانجليزية', 'short_name' => 'אנ'],
            ],
            'rooms' => [['id' => 7, 'name' => 'מעבדה']],
            'activities' => [
                ['id' => 1, 'subject_id' => 1, 'room_id' => null, 'lessons_per_week' => 2, 'duration' => 1],
                ['id' => 2, 'subject_id' => 2, 'room_id' => null, 'lessons_per_week' => 1, 'duration' => 2],
                ['id' => 3, 'subject_id' => 1, 'room_id' => null, 'lessons_per_week' => 1, 'duration' => 1],
                ['id' => 4, 'subject_id' => 2, 'room_id' => null, 'lessons_per_week' => 1, 'duration' => 1],
            ],
            'activity_teachers' => [
                ['activity_id' => 1, 'teacher_id' => 1], ['activity_id' => 2, 'teacher_id' => 1],
                ['activity_id' => 2, 'teacher_id' => 2], ['activity_id' => 3, 'teacher_id' => 2],
            ],
            'activity_groups' => [
                ['activity_id' => 1, 'group_id' => 100], ['activity_id' => 2, 'group_id' => 100],
                ['activity_id' => 2, 'group_id' => 101], ['activity_id' => 3, 'group_id' => 102], ['activity_id' => 4, 'group_id' => 100],
            ],
            'cells' => [
                ['activity_id' => 1, 'day' => 0, 'period' => 1, 'room_id' => null, 'locked' => 0],
                ['activity_id' => 1, 'day' => 2, 'period' => 2, 'room_id' => 7, 'locked' => 0],
                // Two periods long: spans period 2 and, across the break, period 4.
                ['activity_id' => 2, 'day' => 1, 'period' => 2, 'room_id' => null, 'locked' => 0],
                ['activity_id' => 3, 'day' => 3, 'period' => 4, 'room_id' => null, 'locked' => 0],
            ],
            'unavailability' => [], 'teacher_duties' => [['id' => 1]], 'changes' => [],
        ];
    }

    private function upload(string $content, string $name = 'מערכת.json'): \App\Core\Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tt');
        file_put_contents($tmp, $content);
        $this->tempFiles[] = $tmp;
        return $this->post('/timetable/import', [], true, [
            'file' => ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)],
        ]);
    }

    private function import(?array $data = null): void
    {
        $preview = $this->upload(json_encode($data ?? $this->export(), JSON_UNESCAPED_UNICODE));
        self::assertSame(200, $preview->status);
        $this->assertRedirect('/timetable', $this->post('/timetable/import/confirm'));
    }

    private function rows(string $table): int
    {
        return (int) App::db()->value("SELECT COUNT(*) FROM `$table` WHERE school_id = ?", [$this->school]);
    }

    public function testPreviewShowsThePlanAndChangesNothing(): void
    {
        $this->assign($this->school, $this->year, $this->teacherLevi, $this->class71, $this->history);
        $before = [$this->rows('teachers'), $this->rows('classes'), $this->rows('teaching_assignments'), $this->rows('subjects')];

        $response = $this->upload(json_encode($this->export(), JSON_UNESCAPED_UNICODE));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('מערכת בדיקה', $response->body);
        self::assertStringContainsString('dir="rtl"', $response->body);
        self::assertStringNotContainsString('050-0000099', $response->body);
        self::assertStringContainsString(__('timetable.import.warnings.incomplete_activities', ['count' => 1]), $response->body);
        self::assertStringContainsString(__('timetable.import.stale', ['count' => 1]), $response->body, 'The history assignment is not in the new timetable.');
        self::assertSame($before, [$this->rows('teachers'), $this->rows('classes'), $this->rows('teaching_assignments'), $this->rows('subjects')]);
        self::assertSame(0, $this->rows('timetable_slots'));
    }

    public function testConfirmCreatesAssignmentsSlotsAndPeriods(): void
    {
        $existing = $this->assign($this->school, $this->year, $this->teacherLevi, $this->class71, $this->math);
        $stale = $this->assign($this->school, $this->year, $this->teacherLevi, $this->class71, $this->history);
        $this->import();

        // Matched: the teacher, ז1 (from "ז' 1"), level 7 and mathematics. Created: דן כהן, ח' 1, level 8, English, the room.
        self::assertSame(2, $this->rows('teachers'));
        $cohen = App::db()->one("SELECT * FROM teachers WHERE school_id = ? AND last_name = 'כהן'", [$this->school]);
        self::assertSame('דן', $cohen['first_name']);
        self::assertNull($cohen['phone'], 'Phone numbers are never imported.');
        $class81 = (int) App::db()->value("SELECT id FROM classes WHERE school_id = ? AND name = \"ח' 1\"", [$this->school]);
        self::assertGreaterThan(0, $class81);
        self::assertSame(8, (int) App::db()->value('SELECT g.level_number FROM classes c JOIN grade_levels g ON g.id = c.grade_level_id WHERE c.id = ?', [$class81]));
        self::assertSame(3, $this->rows('subjects'));
        self::assertSame(1, $this->rows('rooms'));

        // 1 existing (updated) + 4 from the joint English lesson + 1 sub-group = 6, plus the untouched stale one.
        self::assertSame(7, $this->rows('teaching_assignments'));
        self::assertSame('2.0', App::db()->value('SELECT weekly_hours FROM teaching_assignments WHERE id = ?', [$existing]));
        self::assertNotNull(App::db()->value('SELECT id FROM teaching_assignments WHERE id = ?', [$stale]), 'Assignments are never deleted.');
        self::assertSame("מקבצה א", App::db()->value(
            'SELECT group_name FROM teaching_assignments WHERE class_id = ? AND teacher_id = ? AND subject_id = ?',
            [$class81, $cohen['id'], $this->math]
        ));

        // Slots: math 2, English 4 assignments × 2 periods, sub-group 1.
        self::assertSame(11, $this->rows('timetable_slots'));
        self::assertSame([2, 4], array_map('intval', App::db()->column(
            'SELECT DISTINCT period_number FROM timetable_slots WHERE weekday = 1 AND school_id = ? ORDER BY period_number', [$this->school]
        )), 'A double lesson skips the break.');
        self::assertSame('מעבדה', App::db()->value('SELECT r.name FROM timetable_slots ts JOIN rooms r ON r.id = ts.room_id WHERE ts.weekday = 2'));
        self::assertSame(4, $this->rows('timetable_periods'));
        self::assertSame('0,1,2,3,4', App::db()->value('SELECT days FROM timetable_imports WHERE school_id = ?', [$this->school]));
        self::assertSame(1, $this->auditCount('import', 'timetable'));
        self::assertStringNotContainsString('ronit@example.test', json_encode(App::db()->all('SELECT * FROM teachers')) . json_encode(App::db()->all('SELECT new_values FROM audit_logs')));
    }

    public function testReimportReplacesSlotsWithoutDuplicates(): void
    {
        $this->import();
        $assignments = $this->rows('teaching_assignments');
        $teachers = $this->rows('teachers');

        $data = $this->export();
        $data['cells'] = [['activity_id' => 1, 'day' => 4, 'period' => 1, 'room_id' => null, 'locked' => 0]];
        $data['periods'][0]['name'] = 'פתיחה';
        $this->import($data);

        self::assertSame($assignments, $this->rows('teaching_assignments'));
        self::assertSame($teachers, $this->rows('teachers'));
        self::assertSame(1, $this->rows('timetable_slots'));
        self::assertSame('פתיחה', App::db()->value('SELECT name FROM timetable_periods WHERE period_number = 1 AND school_id = ?', [$this->school]));
        self::assertSame(2, $this->rows('timetable_imports'));
    }

    public function testArabicSchoolGetsArabicNamesAndMatchesDiacritics(): void
    {
        $school = $this->createSchool(null, 'ar');
        $year = $this->createYear($school);
        $level = $this->createLevel($school, 'السابع', 7);
        $class = $this->createClass($school, $year, $level, 'السابع 1');
        $this->loginAs($this->createUser('t.ar', [['principal', $school]]));
        $this->import();

        self::assertSame(1, (int) App::db()->value("SELECT COUNT(*) FROM classes WHERE school_id = ? AND name LIKE 'السابع%'", [$school]), '"السّابع 1" matched "السابع 1".');
        self::assertNotNull(App::db()->value("SELECT id FROM classes WHERE school_id = ? AND name = 'الثامن 1'", [$school]), 'Created without shadda.');
        self::assertNotNull(App::db()->value("SELECT id FROM subjects WHERE school_id = ? AND name = 'الرياضيات'", [$school]));
        self::assertNotNull(App::db()->value("SELECT id FROM teachers WHERE school_id = ? AND last_name = 'ليفي'", [$school]));
        self::assertNotNull(App::db()->value("SELECT id FROM teachers WHERE school_id = ? AND last_name = 'כהן'", [$school]), 'Falls back to the Hebrew name.');
        self::assertGreaterThan(0, (int) App::db()->value('SELECT COUNT(*) FROM timetable_slots ts JOIN teaching_assignments ta ON ta.id = ts.teaching_assignment_id WHERE ta.class_id = ?', [$class]));

        $page = $this->get('/timetable', ['view' => 'class', 'id' => (string) $class]);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('الأحد', $page->body);
        self::assertStringContainsString('الرياضيات', $page->body);
    }

    public function testTimetableGridByClassAndTeacher(): void
    {
        $this->import();
        $page = $this->get('/timetable', ['view' => 'class', 'id' => (string) $this->class71]);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('מתמטיקה', $page->body);
        self::assertStringContainsString('ראשון', $page->body);
        self::assertStringContainsString('08:00–08:45', $page->body);
        self::assertStringContainsString('מעבדה', $page->body);

        $page = $this->get('/timetable', ['view' => 'teacher', 'id' => (string) $this->teacherLevi]);
        self::assertSame(200, $page->status);
        self::assertStringContainsString(e("ח' 1"), $page->body);

        $other = $this->createSchool();
        $otherClass = $this->createClass($other, $this->createYear($other), $this->createLevel($other, 'ז', 7), 'ז1');
        self::assertSame(404, $this->get('/timetable', ['view' => 'class', 'id' => (string) $otherClass])->status);
        self::assertSame(200, $this->get('/timetable')->status);
        self::assertSame(200, $this->get('/timetable/import')->status);
    }

    public function testTeacherSeesOwnTimetableAndOpensLessonsFromIt(): void
    {
        $this->import();
        $user = $this->createUser('t.levi', [['subject_teacher', $this->school]]);
        App::db()->update('teachers', ['user_id' => $user], ['id' => $this->teacherLevi]);
        $this->loginAs($user);

        $page = $this->get('/timetable');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('לוי', $page->body, 'A teacher first sees their own timetable.');

        // Only their own timetable: no colleague's, no class they are not homeroom teacher of.
        $others = array_map('intval', App::db()->column('SELECT DISTINCT teacher_id FROM teaching_assignments WHERE school_id = ? AND teacher_id <> ?', [$this->school, $this->teacherLevi]));
        self::assertNotSame([], $others);
        self::assertSame(404, $this->get('/timetable', ['view' => 'teacher', 'id' => (string) $others[0]])->status);
        self::assertSame(404, $this->get('/timetable', ['view' => 'class', 'id' => (string) $this->class71])->status);
        self::assertStringNotContainsString('id="tt-class"', $page->body, 'No class list for a teacher who is not a homeroom teacher.');
        self::assertDoesNotMatchRegularExpression('/<option value="' . $others[0] . '"/', $page->body);
        // As homeroom teacher of ז1 they see that class too.
        App::db()->update('classes', ['homeroom_teacher_id' => $this->teacherLevi], ['id' => $this->class71]);
        self::assertSame(200, $this->get('/timetable', ['view' => 'class', 'id' => (string) $this->class71])->status);
        self::assertSame(403, $this->get('/timetable/import')->status);
        self::assertSame(403, $this->upload('{}')->status);

        // 2026-09-06 is a Sunday: period 1, mathematics in ז1.
        $attendance = $this->get('/attendance', ['date' => '2026-09-06']);
        self::assertStringContainsString(__('timetable.today'), $attendance->body);
        self::assertStringContainsString('שיעור 1 – 08:00-08:45', $attendance->body);
        $assignment = (int) App::db()->value('SELECT id FROM teaching_assignments WHERE teacher_id = ? AND class_id = ? AND subject_id = ?', [$this->teacherLevi, $this->class71, $this->math]);
        $response = $this->post('/attendance/sessions', ['teaching_assignment_id' => (string) $assignment, 'lesson_date' => '2026-09-06', 'period_number' => '1']);
        self::assertTrue($response->isRedirect());
        self::assertStringContainsString(__('timetable.opened'), $this->get('/attendance', ['date' => '2026-09-06'])->body);
    }

    public function testInvalidFilesAndLockedYearAreRefused(): void
    {
        foreach ([
            ['{"project": {}}', 'x.json', 'timetable.import.errors.format'],
            ['not json', 'x.json', 'timetable.import.errors.format'],
            [json_encode($this->export()), 'x.csv', 'timetable.import.errors.type'],
        ] as [$content, $name, $error]) {
            $response = $this->upload($content, $name);
            self::assertTrue($response->isRedirect(), $error);
            self::assertStringContainsString(__($error), $this->get('/timetable/import')->body, $error);
        }
        $noPeriods = $this->export();
        $noPeriods['periods'] = [];
        $this->upload(json_encode($noPeriods));
        self::assertStringContainsString(__('timetable.import.errors.no_periods'), $this->get('/timetable/import')->body);
        self::assertRedirect('/timetable/import', $this->post('/timetable/import/confirm'));
        self::assertSame(0, $this->rows('timetable_slots'));

        App::db()->update('academic_years', ['is_locked' => 1], ['id' => $this->year]);
        App::resetAuth();
        $this->loginAs($this->createUser('t.principal2', [['principal', $this->school]]));
        $this->upload(json_encode($this->export()));
        self::assertSame(0, $this->rows('teaching_assignments'));
    }

    /** Imports the fixture and signs in as רונית לוי, linked to her teacher record. */
    private function asTeacherLevi(): int
    {
        $this->import();
        $user = $this->createUser('t.levi', [['subject_teacher', $this->school]]);
        App::db()->update('teachers', ['user_id' => $user], ['id' => $this->teacherLevi]);
        $this->loginAs($user);
        return $user;
    }

    private function mathAssignment(): int
    {
        return (int) App::db()->value(
            'SELECT id FROM teaching_assignments WHERE teacher_id = ? AND class_id = ? AND subject_id = ?',
            [$this->teacherLevi, $this->class71, $this->math]
        );
    }

    public function testTeacherHomeShowsTheWeekWithDatesAndOpensLessons(): void
    {
        $this->asTeacherLevi();
        // The week of Sunday 6.9.2026: mathematics in ז1 on Sunday, period 1.
        $home = $this->get('/', ['week' => '2026-09-09']);
        self::assertSame(200, $home->status);
        self::assertStringContainsString(__('timetable.my_week'), $home->body);
        self::assertStringContainsString('06/09', $home->body, 'Real dates of the week, from Sunday.');
        self::assertStringContainsString('name="lesson_date" value="2026-09-06"', $home->body);
        self::assertStringContainsString('name="lesson_date" value="2026-09-08"', $home->body, 'Tuesday, period 2.');

        $this->post('/attendance/sessions', ['teaching_assignment_id' => (string) $this->mathAssignment(), 'lesson_date' => '2026-09-06', 'period_number' => '1']);
        $session = (int) App::db()->value('SELECT id FROM lesson_sessions WHERE teaching_assignment_id = ?', [$this->mathAssignment()]);
        $home = $this->get('/', ['week' => '2026-09-06']);
        self::assertStringContainsString('/attendance/sessions/' . $session, $home->body, 'A recorded lesson links to its page.');
        self::assertStringNotContainsString('name="lesson_date" value="2026-09-06"', $home->body);

        // A week in the future shows the lessons but cannot open them.
        $future = $this->get('/', ['week' => date('Y-m-d', strtotime('+14 days'))]);
        self::assertStringContainsString(__('timetable.my_week'), $future->body);
        self::assertStringNotContainsString('name="teaching_assignment_id"', $future->body);

        // A principal without a teacher record does not get the grid.
        $this->loginAs($this->createUser('t.principal3', [['principal', $this->school]]));
        self::assertStringNotContainsString(__('timetable.my_week_help'), $this->get('/')->body);
    }

    public function testLessonRecordWithCurriculumTopicHomeworkAndHistory(): void
    {
        $this->asTeacherLevi();
        $student = $this->createStudent($this->school, 'כהן', $this->class71);
        $db = App::db();
        $plan = $db->insert('curriculum_plans', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'subject_id' => $this->math,
            'grade_level_id' => $this->level7, 'title' => 'מתמטיקה ז']);
        $unit = $db->insert('curriculum_units', ['school_id' => $this->school, 'plan_id' => $plan, 'title' => 'שברים']);
        $topic = $db->insert('curriculum_topics', ['school_id' => $this->school, 'unit_id' => $unit, 'title' => 'חיבור שברים']);
        $assignment = (string) $this->mathAssignment();

        $this->post('/attendance/sessions', ['teaching_assignment_id' => $assignment, 'lesson_date' => '2026-09-06', 'period_number' => '1']);
        $first = (int) $db->value('SELECT id FROM lesson_sessions WHERE lesson_date = ?', ['2026-09-06']);
        $page = $this->get("/attendance/sessions/$first");
        self::assertStringContainsString('חיבור שברים', $page->body);
        self::assertStringContainsString('ראשון', $page->body);
        self::assertStringContainsString('שיעור 1 – 08:00-08:45', $page->body);

        $response = $this->post("/attendance/sessions/$first", [
            'students' => [$student => ['status' => 'absent']],
            'topic' => 'מבוא לשברים', 'curriculum_topic_id' => (string) $topic, 'topic_progress' => 'in_progress',
            'homework' => "עמוד 12\nתרגילים 1-5", 'lesson_notes' => 'לחזור על מכנה משותף',
        ]);
        $this->assertRedirect("/attendance/sessions/$first", $response);
        $row = $db->one('SELECT * FROM lesson_sessions WHERE id = ?', [$first]);
        self::assertSame('מבוא לשברים', $row['topic']);
        self::assertSame($topic, (int) $row['curriculum_topic_id']);
        self::assertSame("עמוד 12\nתרגילים 1-5", $row['homework']);
        self::assertSame('absent', $db->value('SELECT status FROM attendance_records WHERE lesson_session_id = ?', [$first]));
        self::assertSame(['in_progress', '2026-09-06'], array_values($db->one('SELECT status, actual_date FROM curriculum_progress WHERE topic_id = ?', [$topic])));

        // The next lesson shows the previous one's topic and homework, and the student's absences so far.
        $this->post('/attendance/sessions', ['teaching_assignment_id' => $assignment, 'lesson_date' => '2026-09-08', 'period_number' => '2']);
        $second = (int) $db->value('SELECT id FROM lesson_sessions WHERE lesson_date = ?', ['2026-09-08']);
        $page = $this->get("/attendance/sessions/$second");
        self::assertStringContainsString(__('attendance.previous_lesson'), $page->body);
        self::assertStringContainsString('מבוא לשברים', $page->body);
        self::assertStringContainsString('עמוד 12', $page->body);
        self::assertStringContainsString(__('attendance.so_far', ['absences' => 1, 'lates' => 0]), $page->body);

        // Completing the topic.
        // No topic written: the curriculum topic becomes the lesson's topic.
        $this->post("/attendance/sessions/$second", ['curriculum_topic_id' => (string) $topic, 'topic_progress' => 'done']);
        self::assertSame('חיבור שברים', $db->value('SELECT topic FROM lesson_sessions WHERE id = ?', [$second]));
        self::assertSame(['done', '2026-09-06'], array_values($db->one('SELECT status, actual_date FROM curriculum_progress WHERE topic_id = ?', [$topic])), 'The first teaching date is kept.');

        // A topic of another plan is refused and nothing changes.
        $otherPlan = $db->insert('curriculum_plans', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'subject_id' => $this->history,
            'grade_level_id' => $this->level7, 'title' => 'היסטוריה']);
        $otherTopic = $db->insert('curriculum_topics', ['school_id' => $this->school, 'title' => 'x',
            'unit_id' => $db->insert('curriculum_units', ['school_id' => $this->school, 'plan_id' => $otherPlan, 'title' => 'y'])]);
        $this->post("/attendance/sessions/$second", ['topic' => 'שונה', 'curriculum_topic_id' => (string) $otherTopic]);
        self::assertSame('חיבור שברים', $db->value('SELECT topic FROM lesson_sessions WHERE id = ?', [$second]), 'Unchanged.');

        // A lesson that did not take place: the record is saved, attendance is left alone.
        $this->post("/attendance/sessions/$first/cancel");
        $this->post("/attendance/sessions/$first", ['topic' => 'טיול שנתי']);
        self::assertSame('טיול שנתי', $db->value('SELECT topic FROM lesson_sessions WHERE id = ?', [$first]));
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM attendance_records WHERE lesson_session_id = ?', [$first]));
    }

    public function testAnotherTeacherCannotRecordTheLesson(): void
    {
        $this->asTeacherLevi();
        $this->post('/attendance/sessions', ['teaching_assignment_id' => (string) $this->mathAssignment(), 'lesson_date' => '2026-09-06', 'period_number' => '1']);
        $session = (int) App::db()->value('SELECT id FROM lesson_sessions');

        $other = $this->createUser('t.other', [['subject_teacher', $this->school]]);
        App::db()->update('teachers', ['user_id' => $other], ['last_name' => 'כהן', 'school_id' => $this->school]);
        $this->loginAs($other);
        self::assertSame(404, $this->get("/attendance/sessions/$session")->status);
        self::assertContains($this->post("/attendance/sessions/$session", ['topic' => 'x'])->status, [403, 404]);
        self::assertNull(App::db()->value('SELECT topic FROM lesson_sessions WHERE id = ?', [$session]));
    }

    public function testEachLessonShowsTheStudentsWholeDayAcrossTeachers(): void
    {
        $this->asTeacherLevi();
        $db = App::db();
        $student = $this->createStudent($this->school, 'כהן', $this->class71);
        $other = $this->createStudent($this->school, 'אבו', $this->class71);
        // Sunday 6.9: period 1 mathematics (לוי). Another teacher's history lesson in period 2.
        $this->post('/attendance/sessions', ['teaching_assignment_id' => (string) $this->mathAssignment(), 'lesson_date' => '2026-09-06', 'period_number' => '1']);
        $first = (int) $db->value('SELECT id FROM lesson_sessions');
        $this->post("/attendance/sessions/$first", ['topic' => 'נושא', 'students' => [$student => ['status' => 'absent'], $other => ['status' => 'justified']]]);

        $historyTeacher = $this->createUser('t.history', [['subject_teacher', $this->school]]);
        $teacher = App::db()->insert('teachers', ['school_id' => $this->school, 'first_name' => 'נועם', 'last_name' => 'בר', 'user_id' => $historyTeacher]);
        $assignment = $this->assign($this->school, $this->year, $teacher, $this->class71, $this->history);
        $this->loginAs($historyTeacher);
        $this->post('/attendance/sessions', ['teaching_assignment_id' => (string) $assignment, 'lesson_date' => '2026-09-06', 'period_number' => '2']);
        $second = (int) $db->value('SELECT id FROM lesson_sessions WHERE teaching_assignment_id = ?', [$assignment]);

        $page = $this->get("/attendance/sessions/$second")->body;
        self::assertStringContainsString(e(__('attendance.period_box', ['period' => '1', 'status' => __('attendance.day_states.absent')])), $page,
            'The history teacher sees that the student missed period 1.');
        self::assertStringContainsString(e(__('attendance.period_box', ['period' => '1', 'status' => __('attendance.day_states.justified')])), $page);
        self::assertStringContainsString(e(__('attendance.period_box', ['period' => '3', 'status' => __('attendance.day_states.none')])), $page);
        self::assertSame(2, substr_count($page, 'data-att-cycle'), 'Only this lesson\'s circle can be clicked.');
        self::assertStringContainsString('name="students[' . $student . '][status]" value="present"', $page);

        // Clicking ends in a plain post of the chosen state.
        $this->post("/attendance/sessions/$second", ['topic' => 'נושא', 'students' => [$student => ['status' => 'late']]]);
        self::assertSame('late', $db->value('SELECT status FROM attendance_records WHERE lesson_session_id = ? AND student_id = ?', [$second, $student]));
        self::assertNull($db->value('SELECT status FROM attendance_records WHERE lesson_session_id = ? AND student_id = ?', [$second, $other]));
    }

    public function testDayBoxesAreNumberedFromTheTimetable(): void
    {
        $db = App::db();
        self::assertSame(['1', '2', '3', '4', '5', '6', '7', '8'], array_column(\App\Services\StudentDay::periods($db, $this->school, $this->year), 'label'), 'Without a timetable: 8 boxes.');
        $this->import();
        self::assertSame([1 => '1', 2 => '2', 4 => '3'], array_map(static fn ($p) => $p['label'], \App\Services\StudentDay::periods($db, $this->school, $this->year)), 'Breaks are left out; labels follow the lesson names.');
        // A morning meeting before lesson 1 is box 0.
        $db->run("UPDATE timetable_periods SET name = 'מפגש בוקר' WHERE period_number = 1 AND school_id = ?", [$this->school]);
        $db->run("UPDATE timetable_periods SET name = 'שיעור 1' WHERE period_number = 2 AND school_id = ?", [$this->school]);
        $db->run("UPDATE timetable_periods SET name = 'שיעור 2' WHERE period_number = 4 AND school_id = ?", [$this->school]);
        self::assertSame([1 => '0', 2 => '1', 4 => '2'], array_map(static fn ($p) => $p['label'], \App\Services\StudentDay::periods($db, $this->school, $this->year)));
    }

    public function testLessonRemarksFromTheListDoNotChangeAttendance(): void
    {
        $this->asTeacherLevi();
        $db = App::db();
        $present = $this->createStudent($this->school, 'כהן', $this->class71);
        $absent = $this->createStudent($this->school, 'אבו', $this->class71);
        $assignment = (string) $this->mathAssignment();
        $this->post('/attendance/sessions', ['teaching_assignment_id' => $assignment, 'lesson_date' => '2026-09-06', 'period_number' => '1']);
        $first = (int) $db->value('SELECT id FROM lesson_sessions');

        $page = $this->get("/attendance/sessions/$first")->body;
        self::assertStringContainsString(__('attendance.remarks.no_homework'), $page);
        self::assertStringContainsString(__('attendance.remarks.disruption'), $page);
        self::assertStringContainsString(e(__('attendance.remark_groups.positive')), $page);

        $this->post("/attendance/sessions/$first", ['topic' => 'נושא', 'students' => [
            $present => ['status' => 'present', 'remark' => 'no_homework'],
            $absent => ['status' => 'absent', 'remark' => 'not-a-remark'],
        ]]);
        self::assertSame(['present', 'no_homework'], array_values($db->one('SELECT status, remark FROM attendance_records WHERE student_id = ?', [$present])),
            'A present student with a remark keeps a row.');
        self::assertSame(['absent', null], array_values($db->one('SELECT status, remark FROM attendance_records WHERE student_id = ?', [$absent])), 'Unknown remarks are dropped.');
        self::assertStringContainsString('value="no_homework" selected', $this->get("/attendance/sessions/$first")->body);

        // Attendance figures are unchanged by the remark.
        $rows = array_column(\App\Services\AttendanceStats::byStudent($db, $this->class71, ['1 = 1', []], null, null), null, 'id');
        self::assertSame(100.0, $rows[$present]['rate']);
        self::assertSame(0.0, $rows[$absent]['rate']);
        self::assertSame(['absent' => 0, 'justified' => 0, 'late' => 0], \App\Services\AttendanceStats::forStudent($db, $present, $this->year, ['1 = 1', []]));

        // The next lesson shows the count of remarks so far; clearing a remark removes the row.
        $this->post('/attendance/sessions', ['teaching_assignment_id' => $assignment, 'lesson_date' => '2026-09-08', 'period_number' => '2']);
        $second = (int) $db->value('SELECT id FROM lesson_sessions WHERE lesson_date = ?', ['2026-09-08']);
        self::assertStringContainsString(__('attendance.remarks_so_far', ['count' => 1]), $this->get("/attendance/sessions/$second")->body);
        $this->post("/attendance/sessions/$first", ['topic' => 'נושא', 'students' => [$present => ['status' => 'present', 'remark' => ''], $absent => ['status' => 'absent']]]);
        self::assertNull($db->value('SELECT id FROM attendance_records WHERE student_id = ?', [$present]));
    }

    public function testNameKeysAndLevelNumbers(): void
    {
        self::assertSame(7, TimetableImportService::levelNumber("שכבת ז'"));
        self::assertSame(11, TimetableImportService::levelNumber('שכבת יא'));
        self::assertSame(9, TimetableImportService::levelNumber('التّاسع'));
        self::assertSame(12, TimetableImportService::levelNumber('الصف الثاني عشر'));
        self::assertSame(10, TimetableImportService::levelNumber('10'));
        self::assertNull(TimetableImportService::levelNumber('מקבצה'));
        self::assertSame(TimetableImportService::key('اللغة الإنجليزية'), TimetableImportService::key('اللغه الانجليزيه'));
        self::assertSame(TimetableImportService::key("ז' 1"), TimetableImportService::key('ז1'));
    }
}
