<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\LessonAttachments;
use Tests\TestCase;

/**
 * The teacher's lesson page: attendance is saved only with the lesson's record (its topic); a lesson is
 * in-person by default and the "joined / did not join" note appears only for a remote one; homework
 * carries a due date, links and files, which the lesson's students and their parents see in the portal
 * and nobody else can open.
 */
final class LessonRecordTest extends TestCase
{
    private int $school;
    private int $teacherUser;
    private int $lesson;
    private int $groupLesson;
    private int $groupLessonB;
    /** @var array<string, int> */
    private array $s = [];
    /** @var array<string, int> */
    private array $u = [];
    /** @var string[] */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = App::db();
        $this->school = $this->createSchool(null, 'ar');
        $year = $this->createYear($this->school, '2026-27', true, date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('+200 days')));
        $level = $this->createLevel($this->school, 'الثامن', 8);
        $classA = $this->createClass($this->school, $year, $level, 'الثامن 1');
        $classB = $this->createClass($this->school, $year, $level, 'الثامن 2');
        $this->teacherUser = $this->createUser('t.record', [['subject_teacher', $this->school]], ['full_name' => 'سلمى']);
        $teacher = $this->createTeacher($this->school, 'معلمة', $this->teacherUser);
        $math = $this->createSubject($this->school, 'الرياضيات');
        $assignment = $this->assign($this->school, $year, $teacher, $classA, $math);
        $this->lesson = $db->insert('lesson_sessions', ['school_id' => $this->school, 'teaching_assignment_id' => $assignment,
            'lesson_date' => date('Y-m-d'), 'period_number' => 2, 'status' => 'held']);
        foreach (['rami' => $classA, 'sami' => $classA, 'lina' => $classB] as $name => $class) {
            $this->u[$name] = $this->createUser('s.' . $name, [['student', $this->school]], ['full_name' => $name]);
            $this->s[$name] = $this->createStudent($this->school, 'طالب', $class, ['first_name' => $name, 'user_id' => $this->u[$name]]);
        }
        // A study group of both classes, taught together: Lina (B) is a member.
        $group = $db->insert('study_groups', ['school_id' => $this->school, 'academic_year_id' => $year, 'name' => 'روّاد']);
        $science = $this->createSubject($this->school, 'العلوم');
        $groupA = $this->assign($this->school, $year, $teacher, $classA, $science);
        $groupB = $this->assign($this->school, $year, $teacher, $classB, $science);
        $db->run('UPDATE teaching_assignments SET study_group_id = ? WHERE id IN (?, ?)', [$group, $groupA, $groupB]);
        foreach (['rami', 'lina'] as $member) {
            $db->insert('study_group_students', ['school_id' => $this->school, 'group_id' => $group, 'student_id' => $this->s[$member]]);
        }
        $this->groupLesson = $db->insert('lesson_sessions', ['school_id' => $this->school, 'teaching_assignment_id' => $groupA,
            'lesson_date' => date('Y-m-d'), 'period_number' => 4, 'status' => 'held']);
        $this->groupLessonB = $db->insert('lesson_sessions', ['school_id' => $this->school, 'teaching_assignment_id' => $groupB,
            'lesson_date' => date('Y-m-d'), 'period_number' => 4, 'status' => 'held']);
    }

    protected function tearDown(): void
    {
        foreach (App::db()->column('SELECT stored_name FROM lesson_attachments WHERE stored_name IS NOT NULL') as $stored) {
            @unlink(LessonAttachments::dir() . '/' . $stored);
        }
        foreach ($this->tmp as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function upload(string $name, string $content): array
    {
        $this->tmp[] = $tmp = tempnam(sys_get_temp_dir(), 'hw');
        file_put_contents($tmp, $content);
        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
    }

    public function testAttendanceIsNotSavedWithoutTheLessonRecord(): void
    {
        $this->loginAs($this->teacherUser, $this->school);
        $page = $this->get("/attendance/sessions/{$this->lesson}")->body;
        self::assertMatchesRegularExpression('/name="topic"[^>]*required|required[^>]*name="topic"/', $page, 'The browser asks for the topic too.');

        $response = $this->post("/attendance/sessions/{$this->lesson}", ['topic' => '  ', 'students' => [$this->s['sami'] => ['status' => 'absent']]]);
        $this->assertRedirect("/attendance/sessions/{$this->lesson}", $response);
        self::assertSame(0, (int) App::db()->value('SELECT COUNT(*) FROM attendance_records WHERE lesson_session_id = ?', [$this->lesson]), 'Nothing is saved.');
        $page = $this->get("/attendance/sessions/{$this->lesson}")->body;
        self::assertStringContainsString(e(__('attendance.errors.record_required')), $page);
        self::assertMatchesRegularExpression('/name="students\[' . $this->s['sami'] . '\]\[status\]" value="absent"/', $page, 'The marks made are shown again.');

        $this->post("/attendance/sessions/{$this->lesson}", ['topic' => 'الكسور', 'students' => [$this->s['sami'] => ['status' => 'absent']]]);
        self::assertSame('الكسور', App::db()->value('SELECT topic FROM lesson_sessions WHERE id = ?', [$this->lesson]));
        self::assertSame('absent', App::db()->value('SELECT status FROM attendance_records WHERE lesson_session_id = ?', [$this->lesson]));
    }

    public function testInPersonByDefaultAndTheJoinedNoteOnlyForRemoteLessons(): void
    {
        $db = App::db();
        $meeting = $db->insert('online_meetings', ['school_id' => $this->school, 'lesson_session_id' => $this->lesson, 'room_name' => 'dp-test-room']);
        $db->insert('online_meeting_participants', ['school_id' => $this->school, 'meeting_id' => $meeting, 'user_id' => $this->u['rami'],
            'student_id' => $this->s['rami'], 'role' => 'student']);
        $db->run('UPDATE online_meetings SET ended_at = NOW() WHERE id = ?', [$meeting]);

        $this->loginAs($this->teacherUser, $this->school);
        self::assertSame('in_person', $db->value('SELECT delivery FROM lesson_sessions WHERE id = ?', [$this->lesson]));
        $page = $this->get("/attendance/sessions/{$this->lesson}")->body;
        self::assertMatchesRegularExpression('/value="in_person"[^>]*data-delivery checked/', $page);
        self::assertMatchesRegularExpression('/online-joined-badge[^"]*" data-remote-only hidden/', $page, 'In class: no joined note.');

        $this->post("/attendance/sessions/{$this->lesson}", ['topic' => 'الكسور', 'delivery' => 'remote']);
        self::assertSame('remote', $db->value('SELECT delivery FROM lesson_sessions WHERE id = ?', [$this->lesson]));
        $page = $this->get("/attendance/sessions/{$this->lesson}")->body;
        self::assertMatchesRegularExpression('/online-joined-badge is-joined" data-remote-only>/', $page, 'Rami joined.');
        self::assertMatchesRegularExpression('/online-joined-badge" data-remote-only>[^<]*<i[^>]*><\/i> ' . preg_quote(__('online.not_joined_badge'), '/') . '/', $page, 'Sami did not.');

        $this->post("/attendance/sessions/{$this->lesson}", ['topic' => 'الكسور', 'delivery' => 'zoom']);
        self::assertSame('remote', $db->value('SELECT delivery FROM lesson_sessions WHERE id = ?', [$this->lesson]), 'An unknown mode is refused.');
    }

    public function testStartingAnOnlineMeetingMakesTheLessonRemote(): void
    {
        \App\Services\LiveKit::override(['enabled' => true, 'url' => 'wss://live.example.test', 'api_url' => '', 'api_key' => 'k',
            'api_secret' => 'secret-of-the-test-server-0123456789', 'token_minutes' => 90], static fn () => [200, '{}']);
        try {
            $this->loginAs($this->teacherUser, $this->school);
            $this->post("/attendance/sessions/{$this->groupLesson}/online");
            self::assertSame(['remote', 'remote'], App::db()->column('SELECT delivery FROM lesson_sessions WHERE id IN (?, ?) ORDER BY id',
                [$this->groupLesson, $this->groupLessonB]), 'Both classes taught together.');
        } finally {
            \App\Services\LiveKit::override(null);
        }
    }

    public function testHomeworkWithDueDateLinksAndFilesReachesTheLessonsStudentsOnly(): void
    {
        $db = App::db();
        $this->loginAs($this->teacherUser, $this->school);
        $response = $this->post("/attendance/sessions/{$this->groupLesson}", [
            'topic' => 'الخلية', 'homework' => 'اقرأ الفصل الثاني', 'homework_due' => date('Y-m-d', strtotime('+3 days')),
            'homework_links' => [['url' => 'www.example.org/cells', 'title' => 'فيديو الخلية'], ['url' => '', 'title' => '']],
        ], true, ['homework_files' => [
            'name' => ['ورقة عمل.pdf'], 'tmp_name' => [$this->upload('x.pdf', $this->pdf())['tmp_name']],
            'error' => [UPLOAD_ERR_OK], 'size' => [strlen($this->pdf())], 'type' => ['application/pdf'],
        ]]);
        $this->assertRedirect("/attendance/sessions/{$this->groupLesson}", $response);

        // Copied to both classes' lessons; the file is stored once.
        $rows = $db->all('SELECT * FROM lesson_attachments ORDER BY lesson_session_id, id');
        self::assertCount(4, $rows);
        self::assertSame('https://www.example.org/cells', $rows[0]['url'], 'An address without a scheme gets https.');
        self::assertCount(1, array_unique(array_filter(array_column($rows, 'stored_name'))));
        self::assertSame(date('Y-m-d', strtotime('+3 days')), $db->value('SELECT homework_due FROM lesson_sessions WHERE id = ?', [$this->groupLessonB]));
        $fileB = (int) $db->value("SELECT id FROM lesson_attachments WHERE kind = 'file' AND lesson_session_id = ?", [$this->groupLessonB]);
        $fileA = (int) $db->value("SELECT id FROM lesson_attachments WHERE kind = 'file' AND lesson_session_id = ?", [$this->groupLesson]);

        // Lina (class B, a member) sees it in her portal and downloads it.
        $this->loginAs($this->u['lina'], $this->school);
        $portal = $this->get('/me/homework')->body;
        self::assertStringContainsString('فيديو الخلية', $portal);
        self::assertStringContainsString('ورقة عمل.pdf', $portal);
        self::assertStringContainsString(e(__('portal.due_on', ['date' => date('d/m/Y', strtotime('+3 days'))])), $portal);
        $download = $this->get("/lesson-files/$fileB");
        self::assertSame(200, $download->status);
        self::assertStringStartsWith('attachment;', $download->headers['Content-Disposition']);
        self::assertSame($this->pdf(), file_get_contents($download->filePath));

        // Sami is in class A but not in the group: no access.
        $this->loginAs($this->u['sami'], $this->school);
        self::assertStringNotContainsString('ورقة عمل.pdf', $this->get('/me/homework')->body);
        self::assertSame(404, $this->get("/lesson-files/$fileA")->status);
        self::assertSame(404, $this->get("/lesson-files/$fileB")->status);

        // Removing on the teacher's page removes it from both classes and from the disk.
        $path = LessonAttachments::dir() . '/' . $db->value('SELECT stored_name FROM lesson_attachments WHERE id = ?', [$fileA]);
        self::assertFileExists($path);
        $this->loginAs($this->teacherUser, $this->school);
        $this->post("/attendance/sessions/{$this->groupLesson}", ['topic' => 'الخلية', 'remove_attachments' => [(string) $fileA]]);
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM lesson_attachments WHERE kind = 'file'"));
        self::assertSame(2, (int) $db->value("SELECT COUNT(*) FROM lesson_attachments WHERE kind = 'link'"), 'The link stays.');
        self::assertFileDoesNotExist($path);
    }

    public function testBadLinksFilesAndDueDatesAreRefused(): void
    {
        $db = App::db();
        $this->loginAs($this->teacherUser, $this->school);
        foreach (['javascript:alert(1)', 'ftp://example.org/x', 'http://'] as $bad) {
            $this->post("/attendance/sessions/{$this->lesson}", ['topic' => 'x', 'homework_links' => [['url' => $bad]]]);
            self::assertNull($db->value('SELECT topic FROM lesson_sessions WHERE id = ?', [$this->lesson]), "Refused: $bad");
        }
        // A file whose content does not match its extension.
        $this->post("/attendance/sessions/{$this->lesson}", ['topic' => 'x'], true,
            ['homework_files' => ['name' => ['a.pdf'], 'tmp_name' => [$this->upload('a.pdf', '<?php echo 1;')['tmp_name']], 'error' => [UPLOAD_ERR_OK], 'size' => [13], 'type' => ['application/pdf']]]);
        // An executable type.
        $this->post("/attendance/sessions/{$this->lesson}", ['topic' => 'x'], true,
            ['homework_files' => ['name' => ['run.exe'], 'tmp_name' => [$this->upload('run.exe', 'MZ')['tmp_name']], 'error' => [UPLOAD_ERR_OK], 'size' => [2], 'type' => ['x']]]);
        $this->post("/attendance/sessions/{$this->lesson}", ['topic' => 'x', 'homework_due' => date('Y-m-d', strtotime('-1 day'))]);
        self::assertNull($db->value('SELECT topic FROM lesson_sessions WHERE id = ?', [$this->lesson]));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM lesson_attachments'));

        $links = array_map(static fn ($i) => ['url' => "https://example.org/$i"], range(1, 6));
        $this->post("/attendance/sessions/{$this->lesson}", ['topic' => 'x', 'homework_links' => $links]);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM lesson_attachments'), 'At most five links.');
    }

    public function testStaffOutsideTheLessonAndOtherSchoolsCannotOpenAFile(): void
    {
        $db = App::db();
        $this->loginAs($this->teacherUser, $this->school);
        $this->post("/attendance/sessions/{$this->lesson}", ['topic' => 'x'], true,
            ['homework_files' => ['name' => ['a.pdf'], 'tmp_name' => [$this->upload('a.pdf', $this->pdf())['tmp_name']], 'error' => [UPLOAD_ERR_OK], 'size' => [10], 'type' => ['application/pdf']]]);
        $file = (int) $db->value("SELECT id FROM lesson_attachments WHERE kind = 'file'");
        self::assertSame(200, $this->get("/lesson-files/$file")->status, 'The teacher.');

        $other = $this->createUser('t.other', [['subject_teacher', $this->school]]);
        $this->createTeacher($this->school, 'آخر', $other);
        $this->loginAs($other, $this->school);
        self::assertSame(404, $this->get("/lesson-files/$file")->status, 'A teacher of other classes.');

        $elsewhere = $this->createSchool(null, 'ar');
        $principal = $this->createUser('p.else', [['principal', $elsewhere]]);
        $this->loginAs($principal, $elsewhere);
        self::assertSame(404, $this->get("/lesson-files/$file")->status, 'Another school.');
    }
}
