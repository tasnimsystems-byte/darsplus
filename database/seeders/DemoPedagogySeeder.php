<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;
use App\Services\RiskIndicatorService;

/**
 * Fictional pedagogical data for the demo schools: a work plan with goals, one
 * curriculum plan with reported progress, published assessments with scores,
 * per-lesson attendance, and the indications that the school's criteria produce
 * from them. All numbers are generated — they describe no real student.
 */
final class DemoPedagogySeeder
{
    private const TEXT = [
        'he' => [
            'vision' => 'בית ספר שבו כל תלמידה ותלמיד מתקדמים, מרגישים שייכים ולומדים לחשוב.',
            'objectives' => ['שיפור ההישגים במקצועות הליבה', 'חיזוק האקלים החינוכי והנוכחות'],
            'goals' => [
                [0, 'העלאת הממוצע במתמטיקה בשכבה ז', 'ממוצע 68 במיפוי תחילת השנה', 'ממוצע 73 בסוף מחצית ב', 'in_progress', 120],
                [0, 'צמצום שיעור הנכשלים באנגלית', '18% מתחת ל-55', 'פחות מ-10%', 'not_started', 200],
                [1, 'העלאת שיעור הנוכחות', '91% נוכחות', '95% נוכחות', 'in_progress', 150],
                [1, 'סדנת פתיחת שנה לצוות', 'לא התקיימה אשתקד', 'התקיימה עם משוב', 'in_progress', -20],
            ],
            'actions' => ['מיפוי פערים בתחילת השנה', 'קבוצות תגבור שבועיות', 'ישיבת צוות לניתוח התוצאות'],
            'indicator' => ['ממוצע שכבתי', 'נק\''],
            'plan' => 'מתמטיקה – שכבה ז', 'units' => ['מספרים שלמים', 'שברים'],
            'topics' => [['חיבור וחיסור מספרים מכוונים', 'כפל וחילוק', 'סדר פעולות'], ['שברים פשוטים', 'חיבור שברים', 'כפל שברים']],
            'assessments' => ['בוחן פתיחה', 'מבחן אמצע מחצית', 'עבודה מסכמת'],
        ],
        'ar' => [
            'vision' => 'مدرسة يتقدّم فيها كل طالب وطالبة، ويشعرون بالانتماء ويتعلمون التفكير.',
            'objectives' => ['تحسين التحصيل في المواضيع الأساسية', 'تعزيز المناخ التربوي والحضور'],
            'goals' => [
                [0, 'رفع المعدل في الرياضيات في الطبقة السابعة', 'معدل 68 في مسح بداية السنة', 'معدل 73 في نهاية الفصل الثاني', 'in_progress', 120],
                [0, 'تقليص نسبة الراسبين في الإنجليزية', '18% تحت 55', 'أقل من 10%', 'not_started', 200],
                [1, 'رفع نسبة الحضور', '91% حضور', '95% حضور', 'in_progress', 150],
                [1, 'ورشة افتتاح السنة للطاقم', 'لم تُعقد في السنة الماضية', 'عُقدت مع تغذية راجعة', 'in_progress', -20],
            ],
            'actions' => ['مسح الفجوات في بداية السنة', 'مجموعات تقوية أسبوعية', 'جلسة طاقم لتحليل النتائج'],
            'indicator' => ['معدل الطبقة', 'نقاط'],
            'plan' => 'الرياضيات – الطبقة السابعة', 'units' => ['الأعداد الصحيحة', 'الكسور'],
            'topics' => [['جمع وطرح الأعداد الموجَّهة', 'الضرب والقسمة', 'ترتيب العمليات'], ['الكسور البسيطة', 'جمع الكسور', 'ضرب الكسور']],
            'assessments' => ['اختبار افتتاحي', 'امتحان منتصف الفصل', 'وظيفة ختامية'],
        ],
    ];

    public function __construct(private Database $db)
    {
    }

    /** @param array<string, int> $schools locale key => school id */
    public function run(array $schools, array $owners): void
    {
        mt_srand(20261113);
        foreach ($schools as $key => $schoolId) {
            $this->seedSchool($key, $schoolId, $owners[$key] ?? null);
        }
        mt_srand();
    }

    private function seedSchool(string $key, int $schoolId, ?int $ownerUserId): void
    {
        $db = $this->db;
        $text = self::TEXT[$key];
        $base = ['school_id' => $schoolId];
        $year = $db->one('SELECT * FROM academic_years WHERE school_id = ? AND is_current = 1', [$schoolId]);
        $yearId = (int) $year['id'];
        $term = $db->one('SELECT * FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order LIMIT 1', [$yearId]);
        $today = date('Y-m-d');
        $day = static fn (int $offset): string => date('Y-m-d', strtotime("$offset days"));

        // ---- Work plan
        $planId = $db->insert('work_plans', $base + ['academic_year_id' => $yearId, 'vision' => $text['vision']]);
        $objectives = [];
        foreach ($text['objectives'] as $i => $title) {
            $objectives[] = $db->insert('plan_objectives', $base + ['work_plan_id' => $planId, 'title' => $title, 'sort_order' => $i + 1]);
        }
        $domains = $db->column('SELECT id FROM pedagogical_domains WHERE school_id = ? ORDER BY id', [$schoolId]);
        foreach ($text['goals'] as $g => [$objective, $title, $baseline, $target, $status, $endOffset]) {
            $goalId = $db->insert('annual_goals', $base + [
                'academic_year_id' => $yearId, 'plan_objective_id' => $objectives[$objective], 'domain_id' => $domains[$objective] ?? null,
                'title' => $title, 'baseline_description' => $baseline, 'target_description' => $target, 'owner_user_id' => $ownerUserId,
                'start_date' => $year['start_date'], 'end_date' => $day($endOffset), 'status' => $status,
            ]);
            $done = 0;
            foreach ($text['actions'] as $a => $action) {
                $isDone = $status !== 'not_started' && $a < $g;
                $done += (int) $isDone;
                $db->insert('goal_actions', $base + [
                    'goal_id' => $goalId, 'title' => $action, 'owner_user_id' => $ownerUserId, 'due_date' => $day(($a - 1) * 30),
                    'status' => $isDone ? 'done' : 'open', 'completed_at' => $isDone ? date('Y-m-d H:i:s') : null,
                ]);
            }
            $db->update('annual_goals', [
                'progress_percent' => (int) round($done / count($text['actions']) * 100),
                'status' => $done === count($text['actions']) ? 'completed' : ($done > 0 ? 'in_progress' : $status),
            ], ['id' => $goalId]);
            if ($g === 0) {
                $db->insert('goal_indicators', $base + ['goal_id' => $goalId, 'name' => $text['indicator'][0], 'unit' => $text['indicator'][1],
                    'baseline_value' => 68, 'target_value' => 73, 'current_value' => 70.5]);
            }
        }

        // ---- Assessments: three per teaching assignment in the first term
        $types = $db->column("SELECT id FROM assessment_types WHERE school_id = ? AND is_numeric = 1 ORDER BY id", [$schoolId]);
        $ability = [];
        $assignments = $db->all('SELECT id, class_id, subject_id FROM teaching_assignments WHERE school_id = ? AND academic_year_id = ?', [$schoolId, $yearId]);
        $roster = [];
        foreach ($db->all('SELECT e.class_id, e.student_id FROM class_enrollments e WHERE e.school_id = ? AND e.left_on IS NULL', [$schoolId]) as $row) {
            $roster[(int) $row['class_id']][] = (int) $row['student_id'];
            $ability[(int) $row['student_id']] ??= mt_rand(48, 95);
        }
        $span = max(10, (int) ((strtotime($today) - strtotime($term['start_date'])) / 86400) - 5);
        foreach ($assignments as $assignment) {
            foreach ($text['assessments'] as $n => $title) {
                $max = [50, 100, 100][$n];
                $assessmentId = $db->insert('assessments', $base + [
                    'academic_year_id' => $yearId, 'teaching_assignment_id' => (int) $assignment['id'], 'term_id' => (int) $term['id'],
                    'type_id' => $types[[1, 0, 2][$n]] ?? $types[0], 'title' => $title,
                    'assessment_date' => date('Y-m-d', strtotime($term['start_date'] . ' +' . (int) ($span * ($n + 1) / 4) . ' days')),
                    'max_score' => $max, 'weight' => [1, 3, 2][$n], 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
                ]);
                foreach ($roster[(int) $assignment['class_id']] ?? [] as $studentId) {
                    $absent = mt_rand(1, 100) <= 3;
                    $percent = max(20, min(100, $ability[$studentId] + mt_rand(-12, 12) + ((int) $assignment['subject_id'] % 3 - 1) * 4));
                    $db->insert('assessment_results', $base + [
                        'assessment_id' => $assessmentId, 'student_id' => $studentId,
                        'score' => $absent ? null : round($percent / 100 * $max), 'result_status' => $absent ? 'absent' : 'graded',
                    ]);
                }
            }
        }

        // ---- Attendance: the last school days, first two subjects of every class
        $subjects = array_slice($db->column('SELECT id FROM subjects WHERE school_id = ? ORDER BY id', [$schoolId]), 0, 2);
        foreach ($assignments as $assignment) {
            if (!in_array($assignment['subject_id'], $subjects, false)) {
                continue;
            }
            for ($offset = -21; $offset <= -1; $offset++) {
                $date = $day($offset);
                if (in_array((int) date('N', strtotime($date)), [5, 6], true) || $date < $term['start_date']) {
                    continue;
                }
                $sessionId = $db->insert('lesson_sessions', $base + [
                    'teaching_assignment_id' => (int) $assignment['id'], 'lesson_date' => $date,
                    'period_number' => array_search($assignment['subject_id'], $subjects, false) + 1,
                ]);
                foreach ($roster[(int) $assignment['class_id']] ?? [] as $studentId) {
                    // Weaker students miss more lessons; a few students miss a lot.
                    $chance = $ability[$studentId] < 55 ? 22 : 5;
                    if (mt_rand(1, 100) <= $chance) {
                        $db->insert('attendance_records', $base + [
                            'lesson_session_id' => $sessionId, 'student_id' => $studentId,
                            'status' => ['absent', 'absent', 'justified', 'late'][mt_rand(0, 3)],
                        ]);
                    }
                }
            }
        }

        // ---- One curriculum plan (first subject, lowest level) with progress reported for one class
        $level = $db->one('SELECT id FROM grade_levels WHERE school_id = ? ORDER BY level_number LIMIT 1', [$schoolId]);
        $subjectId = (int) $subjects[0];
        $curriculumId = $db->insert('curriculum_plans', $base + [
            'academic_year_id' => $yearId, 'subject_id' => $subjectId, 'grade_level_id' => (int) $level['id'], 'title' => $text['plan'], 'status' => 'approved',
        ]);
        $topicIds = [];
        foreach ($text['units'] as $u => $unitTitle) {
            $unitId = $db->insert('curriculum_units', $base + ['plan_id' => $curriculumId, 'term_id' => (int) $term['id'], 'title' => $unitTitle, 'sort_order' => $u + 1]);
            foreach ($text['topics'][$u] as $t => $topicTitle) {
                $topicIds[] = $db->insert('curriculum_topics', $base + [
                    'unit_id' => $unitId, 'title' => $topicTitle, 'planned_hours' => 4, 'sort_order' => $t + 1,
                    'planned_week_start' => $day(-56 + ($u * 3 + $t) * 14),
                ]);
            }
        }
        $first = $db->value(
            'SELECT ta.id FROM teaching_assignments ta JOIN classes c ON c.id = ta.class_id
              WHERE ta.school_id = ? AND ta.subject_id = ? AND c.grade_level_id = ? ORDER BY ta.id LIMIT 1',
            [$schoolId, $subjectId, $level['id']]
        );
        foreach (array_slice($topicIds, 0, 3) as $i => $topicId) {
            $db->insert('curriculum_progress', $base + [
                'topic_id' => $topicId, 'teaching_assignment_id' => (int) $first, 'status' => $i < 2 ? 'done' : 'in_progress',
                'actual_date' => $i < 2 ? $day(-50 + $i * 14) : null, 'actual_hours' => $i < 2 ? 4.5 : 2,
            ]);
        }

        // ---- Indications, exactly as the application would produce them
        (new RiskIndicatorService($db))->run($schoolId, $year, $term);
    }
}
