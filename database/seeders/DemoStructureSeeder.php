<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;

/**
 * Fictional school structure for the two demo schools: terms, grade levels,
 * subjects, rooms, teachers, classes, students, assignments, positions, calendar.
 * Names are drawn at random from generic lists — they describe no real person.
 * A fixed seed makes every run produce the same data.
 */
final class DemoStructureSeeder
{
    private const SUBJECTS = [
        'he' => ['מתמטיקה', 'עברית', 'אנגלית', 'מדעים', 'היסטוריה', 'ערבית', 'חינוך גופני', 'אמנות'],
        'ar' => ['الرياضيات', 'اللغة العربية', 'اللغة الإنجليزية', 'العلوم', 'التاريخ', 'اللغة العبرية', 'التربية البدنية', 'الفنون'],
    ];
    private const LEVELS = [
        'he' => [7 => 'ז', 8 => 'ח', 9 => 'ט'],
        'ar' => [7 => 'السابع', 8 => 'الثامن', 9 => 'التاسع'],
    ];
    private const FIRST = [
        'he' => ['נועה', 'יעל', 'תמר', 'מאיה', 'שירה', 'אדם', 'איתי', 'דניאל', 'יונתן', 'עומר', 'רוני', 'גיל', 'ליה', 'אורי', 'עידו', 'הילה'],
        'ar' => ['ريم', 'لينا', 'سلمى', 'نور', 'ياسمين', 'آدم', 'يوسف', 'كريم', 'سامي', 'عمر', 'رامي', 'هبة', 'دانا', 'خالد', 'مجد', 'تالا'],
    ];
    private const LAST = [
        'he' => ['אלון', 'ברק', 'גבע', 'דרור', 'הראל', 'זיו', 'טל', 'יער', 'כרמל', 'לביא', 'מור', 'נוי', 'סער', 'עוז', 'פז', 'רגב', 'שחר', 'תבור'],
        'ar' => ['النجار', 'الحداد', 'الخياط', 'السعدي', 'القاسم', 'الزعبي', 'العلي', 'الصالح', 'المصري', 'الشامي', 'الحلبي', 'الفارس', 'البدر', 'الأمين', 'الرشيد', 'الكرمي'],
    ];
    private const TERMS = [
        'he' => ['מחצית א', 'מחצית ב'],
        'ar' => ['الفصل الأول', 'الفصل الثاني'],
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string, int>   $schools  locale key => school id
     * @param array<string, ?int>  $accounts role in the demo ('teacher', 'coordinator') => user id, per school key
     */
    public function run(array $schools, array $accounts): void
    {
        mt_srand(20261112);
        foreach ($schools as $key => $schoolId) {
            $this->seedSchool($key, $schoolId, $accounts[$key] ?? []);
        }
        mt_srand();
    }

    private function seedSchool(string $key, int $schoolId, array $accounts): void
    {
        $db = $this->db;
        $yearId = (int) $db->value('SELECT id FROM academic_years WHERE school_id = ? AND is_current = 1', [$schoolId]);
        $base = ['school_id' => $schoolId];
        $pick = static fn (array $list): string => $list[mt_rand(0, count($list) - 1)];

        foreach ([['2026-09-01', '2027-01-31'], ['2027-02-01', '2027-06-30']] as $i => [$start, $end]) {
            $db->insert('academic_terms', $base + [
                'academic_year_id' => $yearId, 'name' => self::TERMS[$key][$i], 'sort_order' => $i + 1,
                'start_date' => $start, 'end_date' => $end,
            ]);
        }

        $levels = [];
        foreach (self::LEVELS[$key] as $number => $name) {
            $levels[$number] = $db->insert('grade_levels', $base + ['name' => $name, 'level_number' => $number]);
        }
        $subjects = [];
        foreach (self::SUBJECTS[$key] as $name) {
            $subjects[] = $db->insert('subjects', $base + ['name' => $name]);
        }
        $rooms = [];
        for ($i = 1; $i <= 6; $i++) {
            $rooms[] = $db->insert('rooms', $base + ['name' => ($key === 'he' ? 'חדר ' : 'غرفة ') . (100 + $i), 'capacity' => 32]);
        }

        // Twelve teachers; the first two are linked to demo user accounts where those exist.
        $teachers = [];
        $linked = array_values(array_filter([$accounts['teacher'] ?? null, $accounts['coordinator'] ?? null]));
        for ($i = 0; $i < 12; $i++) {
            $teacherId = $db->insert('teachers', $base + [
                'first_name' => $pick(self::FIRST[$key]), 'last_name' => $pick(self::LAST[$key]),
                'staff_code' => sprintf('T-%03d', $i + 1), 'user_id' => $linked[$i] ?? null,
            ]);
            $teachers[] = $teacherId;
            foreach ([$subjects[$i % count($subjects)], $subjects[($i + 3) % count($subjects)]] as $subjectId) {
                $db->insert('teacher_subjects', $base + ['teacher_id' => $teacherId, 'subject_id' => $subjectId]);
            }
        }

        // Two classes per level, 24–26 students each.
        $classIndex = 0;
        $studentNumber = 0;
        foreach ($levels as $number => $levelId) {
            for ($section = 1; $section <= 2; $section++) {
                $classId = $db->insert('classes', $base + [
                    'academic_year_id' => $yearId, 'grade_level_id' => $levelId,
                    'name' => self::LEVELS[$key][$number] . ($key === 'he' ? '' : ' ') . $section,
                    'homeroom_teacher_id' => $teachers[$classIndex], 'room_id' => $rooms[$classIndex],
                ]);
                // Every subject is taught in every class; teachers rotate so each has a realistic load.
                foreach ($subjects as $s => $subjectId) {
                    $db->insert('teaching_assignments', $base + [
                        'academic_year_id' => $yearId, 'class_id' => $classId, 'subject_id' => $subjectId,
                        'teacher_id' => $s === 0 ? $teachers[$classIndex] : $teachers[($s + $classIndex) % count($teachers)],
                        'weekly_hours' => [5, 4, 4, 3, 2, 2, 2, 1][$s],
                    ]);
                }
                for ($n = 0, $size = 24 + mt_rand(0, 2); $n < $size; $n++) {
                    $studentId = $db->insert('students', $base + [
                        'student_code' => sprintf('S%05d', ++$studentNumber),
                        'first_name' => $pick(self::FIRST[$key]), 'last_name' => $pick(self::LAST[$key]),
                        'gender' => mt_rand(0, 1) ? 'f' : 'm',
                        'birth_date' => sprintf('%d-%02d-%02d', 2026 - 6 - $number, mt_rand(1, 12), mt_rand(1, 28)),
                    ]);
                    $db->insert('class_enrollments', $base + [
                        'student_id' => $studentId, 'class_id' => $classId, 'enrolled_on' => '2026-09-01',
                    ]);
                }
                $classIndex++;
            }
        }

        $db->insert('staff_positions', $base + [
            'academic_year_id' => $yearId, 'teacher_id' => $teachers[6], 'position_type' => 'grade_coordinator',
            'grade_level_id' => $levels[7],
        ]);
        $db->insert('staff_positions', $base + [
            'academic_year_id' => $yearId, 'teacher_id' => $teachers[7], 'position_type' => 'subject_coordinator',
            'subject_id' => $subjects[0],
        ]);

        $events = $key === 'he'
            ? [['פתיחת שנת הלימודים', 'event', '2026-09-01', '2026-09-01'], ['חופשת חנוכה', 'holiday', '2026-12-06', '2026-12-12'],
                ['ישיבה פדגוגית – מחצית א', 'meeting', '2027-01-20', '2027-01-20'], ['מבחני סוף מחצית', 'exam', '2027-01-10', '2027-01-21']]
            : [['افتتاح السنة الدراسية', 'event', '2026-09-01', '2026-09-01'], ['عطلة الشتاء', 'holiday', '2026-12-24', '2027-01-02'],
                ['جلسة تربوية – الفصل الأول', 'meeting', '2027-01-20', '2027-01-20'], ['امتحانات نهاية الفصل', 'exam', '2027-01-10', '2027-01-21']];
        foreach ($events as [$title, $type, $start, $end]) {
            $db->insert('calendar_events', $base + [
                'academic_year_id' => $yearId, 'title' => $title, 'event_type' => $type, 'start_date' => $start, 'end_date' => $end,
            ]);
        }
    }
}
