<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\ReportCards;
use Tests\TestCase;

/** Term certificates: the principal prints a whole class or one student, in Arabic or Hebrew. */
final class CertificateTest extends TestCase
{
    private int $school;
    private int $year;
    private int $term1;
    private int $term2;
    private int $class;
    private int $principal;
    private int $teacherUser;
    /** @var array<string, int> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = App::db();
        $this->school = $this->createSchool(null, 'ar');
        $this->year = $this->createYear($this->school, '2025-2026', true, '2025-09-01', '2026-06-30');
        $this->term1 = $db->insert('academic_terms', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'name' => 'النصف الأول', 'sort_order' => 1, 'start_date' => '2025-09-01', 'end_date' => '2026-01-31']);
        $this->term2 = $db->insert('academic_terms', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'name' => 'النصف الثاني', 'sort_order' => 2, 'start_date' => '2026-02-01', 'end_date' => '2026-06-30']);
        $level = $this->createLevel($this->school, 'السابع', 7);
        $this->teacherUser = $this->createUser('t.teacher', [['subject_teacher', $this->school]]);
        $homeroom = $db->insert('teachers', ['school_id' => $this->school, 'first_name' => 'سناء', 'last_name' => 'سعيد', 'user_id' => $this->teacherUser]);
        $this->class = $this->createClass($this->school, $this->year, $level, 'السابع 1', $homeroom);
        $this->s['ahmad'] = $this->createStudent($this->school, 'موسى', $this->class, ['first_name' => 'أحمد', 'student_code' => 'S-1001']);
        $this->s['lina'] = $this->createStudent($this->school, 'حداد', $this->class, ['first_name' => 'لينا', 'student_code' => 'S-1002']);
        $exam = $db->insert('assessment_types', ['school_id' => $this->school, 'name' => 'امتحان', 'category' => 'exam']);

        // Arabic: 88 then 92 (annual 90); math: 100 then 91 (annual 95.5). Lina: math 63 in term 2 only.
        foreach ([['اللغة العربية', [$this->term1 => ['ahmad' => 88], $this->term2 => ['ahmad' => 92]]],
                  ['الرياضيات', [$this->term1 => ['ahmad' => 100], $this->term2 => ['ahmad' => 91, 'lina' => 63]]]] as [$name, $byTerm]) {
            $assignment = $this->assign($this->school, $this->year, $homeroom, $this->class, $this->createSubject($this->school, $name));
            foreach ($byTerm as $term => $scores) {
                $a = $db->insert('assessments', ['school_id' => $this->school, 'academic_year_id' => $this->year, 'teaching_assignment_id' => $assignment,
                    'term_id' => $term, 'type_id' => $exam, 'title' => 'x', 'assessment_date' => $term === $this->term1 ? '2025-10-01' : '2026-03-01', 'status' => 'published']);
                foreach ($scores as $key => $score) {
                    $db->insert('assessment_results', ['school_id' => $this->school, 'assessment_id' => $a, 'student_id' => $this->s[$key], 'score' => $score]);
                }
            }
            if ($name === 'الرياضيات') {
                // Term 2: Ahmad absent twice and late once (one absence is in term 1 and is not counted).
                foreach (['2026-03-02' => 'absent', '2026-03-03' => 'absent', '2026-03-04' => 'late', '2025-10-05' => 'absent'] as $date => $status) {
                    $session = $db->insert('lesson_sessions', ['school_id' => $this->school, 'teaching_assignment_id' => $assignment, 'lesson_date' => $date, 'period_number' => 1, 'status' => 'held']);
                    $db->insert('attendance_records', ['school_id' => $this->school, 'lesson_session_id' => $session, 'student_id' => $this->s['ahmad'], 'status' => $status]);
                }
            }
        }
        $this->principal = $this->createUser('t.principal', [['principal', $this->school]]);
        $this->loginAs($this->principal);
    }

    private function printPage(array $query): string
    {
        $response = $this->get('/certificates/print', $query);
        self::assertSame(200, $response->status);
        return $response->body;
    }

    public function testClassCertificatesWithTermsAnnualVerbalAndAttendance(): void
    {
        $page = $this->get('/certificates', ['term_id' => (string) $this->term2, 'class_id' => (string) $this->class])->body;
        self::assertStringContainsString(e(__('certificates.settings_title')), $page);
        self::assertStringContainsString('أحمد', $page);

        $this->post('/certificates/remarks', ['lang' => 'ar', 'term_id' => (string) $this->term2, 'class_id' => (string) $this->class,
            'remarks' => [$this->s['ahmad'] => 'تحصيلك جيد وأخلاقك عالية.', $this->s['lina'] => '']]);

        $body = $this->printPage(['lang' => 'ar', 'term_id' => (string) $this->term2, 'class_id' => (string) $this->class]);
        self::assertSame(2, substr_count($body, '<article class="cert"'), 'One certificate per student of the class.');
        self::assertStringContainsString('<html lang="ar" dir="rtl"', $body);
        self::assertStringContainsString('شهادة النصف الثاني - السنة الدراسية 2025-2026', $body);
        self::assertStringContainsString('S-1001', $body);
        self::assertStringContainsString('سناء سعيد', $body, 'Homeroom teacher.');
        // Ahmad: Arabic 88 / 92 → 90; math 100 / 91 → 95.5; general 94 / 91.5 → 92.8 (mean of 90 and 95.5).
        self::assertMatchesRegularExpression('/اللغة العربية<\/th>\s*<td class="cert-num">88<\/td>\s*<td class="cert-verbal">جيد جدًا<\/td>\s*<td class="cert-num">92<\/td>\s*<td class="cert-verbal">جيد جدًا<\/td>\s*<td class="cert-num cert-annual">90<\/td>/u', $body);
        self::assertMatchesRegularExpression('/الرياضيات<\/th>\s*<td class="cert-num">100<\/td>\s*<td class="cert-verbal">ممتاز<\/td>\s*<td class="cert-num">91<\/td>\s*<td class="cert-verbal">جيد جدًا<\/td>\s*<td class="cert-num cert-annual">95.5<\/td>/u', $body);
        self::assertMatchesRegularExpression('/المعدل العام<\/th>\s*<td class="cert-num">94<\/td>.*?<td class="cert-num">91.5<\/td>.*?cert-annual">92.8</su', $body);
        self::assertStringContainsString('الغيابات: 2', $body);
        self::assertStringContainsString('التأخرات: 1', $body);
        self::assertStringContainsString('تحصيلك جيد وأخلاقك عالية.', $body);
        // Lina has math in term 2 only: 63 = كافٍ, no grade in term 1.
        self::assertMatchesRegularExpression('/الرياضيات<\/th>\s*<td class="cert-num">—<\/td>\s*<td class="cert-verbal"><\/td>\s*<td class="cert-num">63<\/td>\s*<td class="cert-verbal">كافٍ<\/td>/u', $body);
        self::assertSame(1, (int) App::db()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'print' AND entity_type = 'certificate'"));
    }

    public function testOneStudentHebrewAndFirstTerm(): void
    {
        $body = $this->printPage(['lang' => 'he', 'term_id' => (string) $this->term1, 'class_id' => (string) $this->class, 'student_id' => (string) $this->s['ahmad']]);
        self::assertSame(1, substr_count($body, '<article class="cert"'));
        self::assertStringContainsString('<html lang="he" dir="rtl"', $body);
        self::assertStringContainsString('תעודת النصف الأول - שנת הלימודים 2025-2026', $body, 'Hebrew labels; the term keeps the name the school gave it.');
        self::assertStringContainsString('מצוין', $body);
        self::assertStringNotContainsString(e(__('certificates.annual')), $body, 'One term shown: no annual column.');
        self::assertStringNotContainsString('לינה', $body);

        // A student of another class is ignored: the whole class is printed.
        $other = $this->createStudent($this->school, 'غريب');
        self::assertSame(2, substr_count($this->printPage(['lang' => 'ar', 'term_id' => (string) $this->term1, 'class_id' => (string) $this->class, 'student_id' => (string) $other]), '<article class="cert"'));
    }

    public function testHeaderAndLogo(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $tmp = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($tmp, $png);
        $this->post('/certificates/header', ['school_name_ar' => 'المدرسة الإعدادية دير الأسد', 'school_name_he' => 'חט"ב דיר אל אסד', 'phone' => '04-6294412', 'fax' => '04-9582985', 'principal_name' => 'علي أحمد موسى'],
            true, ['logo' => ['name' => 'logo.png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)]]);
        $body = $this->printPage(['lang' => 'ar', 'term_id' => (string) $this->term1, 'class_id' => (string) $this->class]);
        self::assertStringContainsString('المدرسة الإعدادية دير الأسد', $body);
        self::assertStringContainsString(e('חט"ב דיר אל אסד'), $body);
        self::assertStringContainsString('04-9582985', $body);
        self::assertStringContainsString('علي أحمد موسى', $body);
        self::assertStringContainsString('src="data:image/png;base64,', $body);

        // A file that is not an image is refused; removing the logo deletes it.
        file_put_contents($tmp, 'not an image');
        $this->post('/certificates/header', ['school_name_ar' => 'x'], true, ['logo' => ['name' => 'logo.png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 12]]);
        self::assertStringContainsString(e(__('certificates.errors.logo_type')), $this->get('/certificates')->body);
        $this->post('/certificates/header', ['school_name_ar' => 'المدرسة', 'remove_logo' => '1']);
        self::assertStringNotContainsString('data:image/png', $this->printPage(['lang' => 'ar', 'term_id' => (string) $this->term1, 'class_id' => (string) $this->class]));
        @unlink($tmp);
    }

    public function testOnlyThePrincipalAndVerbalScale(): void
    {
        $this->loginAs($this->teacherUser);
        self::assertSame(403, $this->get('/certificates')->status);
        self::assertSame(403, $this->get('/certificates/print', ['term_id' => (string) $this->term1, 'class_id' => (string) $this->class])->status);
        $this->loginAs($this->createUser('t.vice', [['vice_principal', $this->school]]));
        self::assertSame(403, $this->get('/certificates')->status);

        foreach ([[100, 'excellent'], [95, 'excellent'], [94.9, 'very_good'], [85, 'very_good'], [75, 'good'], [70, 'almost_good'], [63, 'sufficient'], [50, 'barely'], [30, 'insufficient']] as [$grade, $key]) {
            self::assertSame($key, ReportCards::verbal($grade), (string) $grade);
        }
        self::assertNull(ReportCards::verbal(null));
    }
}
