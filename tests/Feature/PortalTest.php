<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use Tests\TestCase;

/**
 * Student and parent portal: accounts made by the principal, and each account seeing only its own
 * student – grades, attendance (praise only, no conduct remarks), homework of their own lessons, and
 * certificates once published. No page takes a student id from the address.
 */
final class PortalTest extends TestCase
{
    private int $school;
    private int $year;
    private int $term;
    private int $classA;
    private int $classB;
    private int $principal;
    private int $math;
    private int $mathLesson;
    private int $groupLesson;
    /** @var array<string, int> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = App::db();
        $this->school = $this->createSchool(null, 'ar');
        $this->year = $this->createYear($this->school);
        $this->term = $db->insert('academic_terms', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'name' => 'الفصل الأول', 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $level = $this->createLevel($this->school, 'السابع', 7);
        $this->classA = $this->createClass($this->school, $this->year, $level, 'السابع 1');
        $this->classB = $this->createClass($this->school, $this->year, $level, 'السابع 2');
        $this->s['rami'] = $this->createStudent($this->school, 'حداد', $this->classA, ['first_name' => 'رامي', 'student_code' => '1001']);
        $this->s['lina'] = $this->createStudent($this->school, 'حداد', $this->classB, ['first_name' => 'لينا', 'student_code' => '1002']);
        $this->s['other'] = $this->createStudent($this->school, 'خوري', $this->classA, ['first_name' => 'سامي', 'student_code' => '1003']);
        // Rami and Lina are siblings: the same mother, registered with the same phone for both.
        foreach (['rami', 'lina'] as $child) {
            $db->insert('guardians', ['school_id' => $this->school, 'student_id' => $this->s[$child], 'full_name' => 'سعاد حداد', 'relation' => 'أم', 'phone' => '050-1234567']);
        }
        $db->insert('guardians', ['school_id' => $this->school, 'student_id' => $this->s['other'], 'full_name' => 'جورج خوري', 'phone' => '052-7654321']);

        $teacher = $this->createTeacher($this->school, 'معلم');
        $this->math = $this->createSubject($this->school, 'الرياضيات');
        $this->mathLesson = $this->assign($this->school, $this->year, $teacher, $this->classA, $this->math);
        // A study group in class A that Rami is NOT a member of.
        $this->groupLesson = $this->assign($this->school, $this->year, $teacher, $this->classA, $this->createSubject($this->school, 'روّاد'));
        $group = $db->insert('study_groups', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'name' => 'روّاد']);
        $db->update('teaching_assignments', ['study_group_id' => $group], ['id' => $this->groupLesson]);
        $db->insert('study_group_students', ['school_id' => $this->school, 'group_id' => $group, 'student_id' => $this->s['other']]);

        $exam = $db->insert('assessment_types', ['school_id' => $this->school, 'name' => 'امتحان', 'category' => 'exam']);
        $a = $db->insert('assessments', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'teaching_assignment_id' => $this->mathLesson,
            'term_id' => $this->term, 'type_id' => $exam, 'title' => 'امتحان الكسور', 'assessment_date' => '2026-10-01', 'status' => 'published']);
        $db->insert('assessment_results', ['school_id' => $this->school, 'assessment_id' => $a, 'student_id' => $this->s['rami'], 'score' => 87]);
        $db->insert('assessment_results', ['school_id' => $this->school, 'assessment_id' => $a, 'student_id' => $this->s['other'], 'score' => 41]);
        $draft = $db->insert('assessments', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'teaching_assignment_id' => $this->mathLesson,
            'term_id' => $this->term, 'type_id' => $exam, 'title' => 'مسودة سرية', 'assessment_date' => '2026-10-02', 'status' => 'draft']);
        $db->insert('assessment_results', ['school_id' => $this->school, 'assessment_id' => $draft, 'student_id' => $this->s['rami'], 'score' => 12]);

        foreach ([[$this->mathLesson, '2026-10-05', 'absent', null, 'حلّ تمارين 1-5'], [$this->mathLesson, '2026-10-06', 'present', 'disruption', null],
                  [$this->mathLesson, '2026-10-07', 'present', 'excellent_work', null], [$this->groupLesson, '2026-10-08', null, null, 'وظيفة الفرقة السرية']] as [$lesson, $date, $status, $remark, $homework]) {
            $session = $db->insert('lesson_sessions', ['school_id' => $this->school, 'teaching_assignment_id' => $lesson, 'lesson_date' => $date,
                'period_number' => 1, 'status' => 'held', 'homework' => $homework]);
            if ($status !== null) {
                $db->insert('attendance_records', ['school_id' => $this->school, 'lesson_session_id' => $session, 'student_id' => $this->s['rami'], 'status' => $status, 'remark' => $remark]);
            }
        }
        $this->principal = $this->createUser('t.principal', [['principal', $this->school]]);
        $this->loginAs($this->principal);
    }

    /** @return array<string, string> username => password from the one-time sheet */
    private function sheet(\App\Core\Response $response): array
    {
        $this->assertRedirect('/portal-accounts/credentials', $response);
        $body = $this->get('/portal-accounts/credentials')->body;
        preg_match_all('#<dd dir="ltr"><code>([^<]+)</code></dd>#', $body, $m);
        $pairs = [];
        for ($i = 0; $i + 1 < count($m[1]); $i += 2) {
            $pairs[html_entity_decode($m[1][$i])] = html_entity_decode($m[1][$i + 1]);
        }
        return $pairs;
    }

    private function signIn(string $username, string $password): void
    {
        App::resetAuth();
        $this->post('/login', ['username' => $username, 'password' => $password]);
        App::db()->update('users', ['must_change_password' => 0], ['username' => $username]);
        App::resetAuth();
        $this->post('/login', ['username' => $username, 'password' => $password]);
    }

    public function testAccountsAreMadeOnceAndSiblingsShareAParent(): void
    {
        $students = $this->sheet($this->post('/portal-accounts/students', ['class_id' => (string) $this->classA]));
        self::assertSame(['s1001', 's1003'], array_keys($students));
        self::assertSame(1, (int) App::db()->value('SELECT COUNT(*) FROM students WHERE id = ? AND user_id IS NOT NULL', [$this->s['rami']]));
        self::assertSame(1, (int) App::db()->value('SELECT must_change_password FROM users WHERE username = ?', ['s1001']));
        $this->assertRedirect('/portal-accounts', $this->get('/portal-accounts/credentials'), 'The sheet is shown once.');
        self::assertSame([], $this->sheet($this->post('/portal-accounts/students', ['class_id' => (string) $this->classA])), 'Nothing twice.');

        $parents = $this->sheet($this->post('/portal-accounts/parents', ['class_id' => (string) $this->classA]));
        self::assertCount(2, $parents);
        self::assertArrayHasKey('p501234567', $parents);
        // Lina's class: her mother already has an account (same phone) – linked, no new password.
        self::assertSame([], $this->sheet($this->post('/portal-accounts/parents', ['class_id' => (string) $this->classB])));
        self::assertSame(2, (int) App::db()->value("SELECT COUNT(*) FROM guardians g JOIN users u ON u.id = g.user_id WHERE u.username = 'p501234567'"));

        // Only the principal manages them.
        $this->signIn('s1001', $students['s1001']);
        self::assertSame(403, $this->get('/portal-accounts')->status);
    }

    public function testStudentSeesOnlyTheirOwnData(): void
    {
        $students = $this->sheet($this->post('/portal-accounts/students', ['class_id' => (string) $this->classA]));
        $this->signIn('s1001', $students['s1001']);

        $this->assertRedirect('/me', $this->get('/'));
        $home = $this->get('/me')->body;
        self::assertStringContainsString('مرحبًا رامي', $home);
        self::assertStringContainsString('data-audience="teen"', $home, 'Grade 7: the intermediate-school design.');
        self::assertStringNotContainsString('href="/students"', $home);
        foreach (['/students', '/students/' . $this->s['other'], '/assessments/gradebook', '/attendance', '/certificates', '/timetable'] as $path) {
            self::assertSame(403, $this->get($path)->status, $path);
        }

        $grades = $this->get('/me/grades')->body;
        self::assertStringContainsString('امتحان الكسور', $grades);
        self::assertStringContainsString('87/100', $grades);
        self::assertMatchesRegularExpression('/portal-final[^"]*"[^>]*>\s*87<small>جيد جدًا<\/small>/u', $grades);
        self::assertStringNotContainsString('41/100', $grades, "Another student's grade.");
        self::assertStringNotContainsString('مسودة سرية', $grades, 'Drafts stay with the teacher.');
        // Three views: the list (default) opens per subject; one subject from a list; every card.
        self::assertStringContainsString('<details class="portal-grade-row" id="subject-' . $this->math . '">', $grades);
        $one = $this->get('/me/grades', ['mode' => 'subject', 'subject_id' => (string) $this->math])->body;
        self::assertStringContainsString('id="pg-subject"', $one);
        self::assertStringContainsString('portal-subject-single', $one);
        self::assertStringContainsString('امتحان الكسور', $one);
        self::assertStringContainsString('class="card h-100 portal-subject"', $this->get('/me/grades', ['mode' => 'cards'])->body);
        self::assertStringContainsString('<details class="portal-grade-row"', $this->get('/me/grades', ['mode' => 'nonsense', 'subject_id' => '99999'])->body, 'Unknown values fall back to the list.');

        $attendance = $this->get('/me/attendance')->body;
        self::assertStringNotContainsString(__('attendance.remarks.excellent_work'), $attendance, 'Remarks have their own page.');

        // My remarks: praise and remarks to improve on, with the counts and a filter.
        $remarks = $this->get('/me/remarks')->body;
        self::assertStringContainsString(__('attendance.remarks.excellent_work'), $remarks);
        self::assertStringContainsString(__('attendance.remarks.disruption'), $remarks);
        self::assertStringContainsString('portal-remark-positive', $remarks);
        self::assertStringContainsString('portal-remark-negative', $remarks);
        $negativeOnly = $this->get('/me/remarks', ['kind' => 'negative'])->body;
        self::assertStringContainsString(__('attendance.remarks.disruption'), $negativeOnly);
        self::assertStringNotContainsString('portal-remark-positive', $negativeOnly);
        self::assertStringContainsString('href="/me/remarks"', $this->get('/me')->body, 'In the menu.');
        self::assertMatchesRegularExpression('/kpi-value">1<\/div>\s*<div class="kpi-label">' . preg_quote(__('portal.counts.absent'), '/') . '/u', $attendance);

        $homework = $this->get('/me/homework')->body;
        self::assertStringContainsString('حلّ تمارين 1-5', $homework);
        self::assertStringNotContainsString('وظيفة الفرقة السرية', $homework, 'Not a member of that study group.');

        // Certificates: only once published, and only their own.
        self::assertStringContainsString(e(__('portal.no_certificates')), $this->get('/me/certificates')->body);
        self::assertSame(404, $this->get('/me/certificates/' . $this->term)->status);
        $this->loginAs($this->principal);
        $this->post('/certificates/publish', ['term_id' => (string) $this->term, 'class_id' => (string) $this->classA, 'withdraw' => '0']);
        $this->signIn('s1001', $students['s1001']);
        $certificate = $this->get('/me/certificates/' . $this->term)->body;
        self::assertSame(1, substr_count($certificate, '<article class="cert"'));
        self::assertStringContainsString('رامي', $certificate);
        self::assertStringNotContainsString('سامي', $certificate);
        self::assertSame(403, $this->post('/me/child', ['student_id' => (string) $this->s['other']])->status, 'Cannot switch to another student.');
    }

    public function testParentSwitchesBetweenTheirChildrenOnly(): void
    {
        $this->post('/portal-accounts/parents', ['class_id' => (string) $this->classA]);
        $this->post('/portal-accounts/parents', ['class_id' => (string) $this->classB]);
        $this->post('/portal-accounts/reset', ['user_id' => (string) App::db()->value("SELECT id FROM users WHERE username = 'p501234567'")]);
        $sheet = $this->sheet(\App\Core\Response::redirect('/portal-accounts/credentials'));
        $this->signIn('p501234567', $sheet['p501234567']);

        $home = $this->get('/me')->body;
        self::assertStringContainsString('id="portal-child"', $home);
        self::assertStringContainsString('رامي حداد – السابع 1', $home);
        self::assertStringContainsString('لينا حداد – السابع 2', $home);
        self::assertStringNotContainsString('سامي', $home);

        $this->assertRedirect('/me/grades', $this->post('/me/child', ['student_id' => (string) $this->s['lina'], 'back' => '/me/grades']));
        self::assertStringContainsString('مرحبًا لينا', $this->get('/me')->body);
        self::assertSame(403, $this->post('/me/child', ['student_id' => (string) $this->s['other']])->status);
        self::assertStringContainsString('مرحبًا لينا', $this->get('/me')->body, 'The refused switch changed nothing.');
    }
}
