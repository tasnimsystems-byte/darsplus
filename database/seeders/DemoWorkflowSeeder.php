<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;

/** Fictional workflow data for the demo schools: guardians, interventions, meetings, tasks, contacts, observations. */
final class DemoWorkflowSeeder
{
    private const TEXT = [
        'he' => [
            'guardian' => ['הורה של', 'אם'], 'area' => ['הבנת הנקרא', 'ארגון למידה', 'מיומנויות חשבון'],
            'goal' => 'שיפור הממוצע במקצוע ב-8 נקודות עד סוף המחצית', 'action' => ['שעת תגבור שבועית', 'שיחה עם ההורים', 'מעקב שבועי של המחנך'],
            'review' => 'נצפה שיפור בהגשת המטלות; נדרש המשך תרגול.',
            'meetings' => ['ישיבה פדגוגית – ניתוח מיפויים', 'ישיבת מחנכים שכבה ז'], 'agenda' => "ניתוח תוצאות המיפוי\nתלמידים במעקב\nתיאום תגבורים",
            'minutes' => 'הוצגו תוצאות המיפוי לפי כיתות. סוכם על פתיחת קבוצות תגבור במתמטיקה ועל מעקב חודשי.',
            'decisions' => ['פתיחת שתי קבוצות תגבור במתמטיקה', 'עדכון ההורים של התלמידים במעקב'],
            'tasks' => ['הכנת דוח נוכחות חודשי', 'עדכון תוכנית הלימודים במדעים', 'תיאום יום הורים'],
            'summary' => 'סוכם על קריאה יומית של 15 דקות ועל מעקב משותף בעוד חודש.', 'internal' => 'ההורים משתפים פעולה; לשקול שעת תגבור.',
            'strengths' => 'פתיחת שיעור ממוקדת ושימוש טוב בשאלות פתוחות.', 'growth' => 'לשלב יותר עבודה בזוגות.', 'feedback' => 'שיעור מאורגן; התלמידים היו שותפים.',
            'recommendation' => 'לשלב משימת סיכום קצרה בסוף כל שיעור', 'teacher_goal' => 'שילוב הערכה מעצבת בכל יחידת הוראה',
            'training' => ['הוראה דיפרנציאלית', 'מרכז פסג"ה'],
        ],
        'ar' => [
            'guardian' => ['وليّ أمر', 'أم'], 'area' => ['فهم المقروء', 'تنظيم التعلّم', 'مهارات الحساب'],
            'goal' => 'رفع المعدل في الموضوع بـ8 نقاط حتى نهاية الفصل', 'action' => ['حصة تقوية أسبوعية', 'محادثة مع الأهل', 'متابعة أسبوعية من المربّي'],
            'review' => 'لوحظ تحسّن في تسليم المهام؛ يلزم استمرار التمرين.',
            'meetings' => ['جلسة تربوية – تحليل المسوح', 'جلسة مربّي الطبقة السابعة'], 'agenda' => "تحليل نتائج المسح\nطلاب في المتابعة\nتنسيق التقوية",
            'minutes' => 'عُرضت نتائج المسح حسب الصفوف. اتُّفق على فتح مجموعات تقوية في الرياضيات وعلى متابعة شهرية.',
            'decisions' => ['فتح مجموعتَي تقوية في الرياضيات', 'إبلاغ أهالي الطلاب في المتابعة'],
            'tasks' => ['إعداد تقرير الحضور الشهري', 'تحديث منهاج العلوم', 'تنسيق يوم الأهل'],
            'summary' => 'اتُّفق على قراءة يومية لمدة 15 دقيقة وعلى متابعة مشتركة بعد شهر.', 'internal' => 'الأهل متعاونون؛ يُنظر في حصة تقوية.',
            'strengths' => 'افتتاح مركَّز للحصة واستخدام جيد للأسئلة المفتوحة.', 'growth' => 'دمج عمل ثنائي أكثر.', 'feedback' => 'حصة منظَّمة؛ الطلاب كانوا شركاء.',
            'recommendation' => 'دمج مهمة تلخيص قصيرة في نهاية كل حصة', 'teacher_goal' => 'دمج التقييم التكويني في كل وحدة تعليمية',
            'training' => ['التدريس المتمايز', 'مركز الاستكمالات'],
        ],
    ];

    public function __construct(private Database $db)
    {
    }

    /** @param array<string, array{school: int, lead: int, staff: int[]}> $schools */
    public function run(array $schools): void
    {
        foreach ($schools as $key => $info) {
            $this->seedSchool(self::TEXT[$key], $info['school'], $info['lead'], $info['staff']);
        }
    }

    private function seedSchool(array $t, int $schoolId, int $lead, array $staff): void
    {
        $db = $this->db;
        $base = ['school_id' => $schoolId];
        $yearId = (int) $db->value('SELECT id FROM academic_years WHERE school_id = ? AND is_current = 1', [$schoolId]);
        $day = static fn (int $offset): string => date('Y-m-d', strtotime("$offset days"));
        $colleague = $staff[0] ?? $lead;

        // Guardians and interventions for the first students who have an indication.
        $students = $db->all(
            'SELECT DISTINCT ri.student_id, s.last_name, MIN(ri.id) AS indication_id FROM risk_indications ri JOIN students s ON s.id = ri.student_id
              WHERE ri.school_id = ? GROUP BY ri.student_id, s.last_name ORDER BY ri.student_id LIMIT 6',
            [$schoolId]
        );
        foreach ($students as $i => $student) {
            $studentId = (int) $student['student_id'];
            $guardianId = $db->insert('guardians', $base + [
                'student_id' => $studentId, 'full_name' => $t['guardian'][0] . ' ' . $student['last_name'], 'relation' => $t['guardian'][1],
                'phone' => sprintf('050-555%04d', $studentId), 'is_primary' => 1,
            ]);
            if ($i >= 3) {
                continue;
            }
            $interventionId = $db->insert('student_interventions', $base + [
                'academic_year_id' => $yearId, 'student_id' => $studentId, 'risk_indication_id' => (int) $student['indication_id'],
                'difficulty_area' => $t['area'][$i], 'improvement_goal' => $t['goal'], 'owner_user_id' => $lead,
                'start_date' => $day(-25 + $i * 5), 'review_date' => $day($i === 0 ? -2 : 14), 'status' => 'active', 'created_by' => $lead,
            ]);
            $db->update('risk_indications', ['review_status' => 'intervention', 'reviewed_by' => $lead, 'reviewed_at' => date('Y-m-d H:i:s')], ['id' => $student['indication_id']]);
            foreach ($t['action'] as $a => $action) {
                $done = $a === 0;
                $db->insert('intervention_actions', $base + [
                    'intervention_id' => $interventionId, 'description' => $action, 'owner_user_id' => $a === 2 ? $colleague : $lead,
                    'due_date' => $day(-10 + $a * 12), 'status' => $done ? 'done' : 'open', 'completed_at' => $done ? date('Y-m-d H:i:s', strtotime('-8 days')) : null,
                ]);
            }
            if ($i === 1) {
                $db->insert('intervention_reviews', $base + [
                    'intervention_id' => $interventionId, 'review_date' => $day(-3), 'findings' => $t['review'], 'progress_rating' => 'improved', 'created_by' => $lead,
                ]);
            }
            $db->insert('parent_contacts', $base + [
                'academic_year_id' => $yearId, 'student_id' => $studentId, 'guardian_id' => $guardianId, 'staff_user_id' => $lead,
                'contact_type' => $i === 0 ? 'meeting' : 'call', 'contacted_on' => $day(-12 + $i), 'internal_notes' => $t['internal'],
                'shared_summary' => $t['summary'], 'follow_up_date' => $day(18), 'follow_up_status' => 'open', 'created_by' => $lead,
            ]);
        }

        // One meeting that was held (minutes, decisions, a task) and one upcoming.
        $held = $db->insert('meetings', $base + [
            'academic_year_id' => $yearId, 'meeting_type' => 'pedagogical', 'title' => $t['meetings'][0], 'meeting_date' => $day(-9), 'start_time' => '14:30',
            'organizer_user_id' => $lead, 'agenda' => $t['agenda'], 'minutes' => $t['minutes'], 'status' => 'held', 'created_by' => $lead,
        ]);
        $upcoming = $db->insert('meetings', $base + [
            'academic_year_id' => $yearId, 'meeting_type' => 'homeroom', 'title' => $t['meetings'][1], 'meeting_date' => $day(6), 'start_time' => '15:00',
            'organizer_user_id' => $lead, 'agenda' => $t['agenda'], 'status' => 'planned', 'created_by' => $lead,
        ]);
        foreach ([$held, $upcoming] as $meetingId) {
            foreach (array_unique([$lead, ...$staff]) as $userId) {
                $db->insert('meeting_participants', $base + ['meeting_id' => $meetingId, 'user_id' => $userId, 'attendance' => $meetingId === $held ? 'attended' : 'invited']);
            }
        }
        foreach ($t['decisions'] as $d => $decision) {
            $decisionId = $db->insert('meeting_decisions', $base + [
                'meeting_id' => $held, 'decision' => $decision, 'owner_user_id' => $d === 0 ? $colleague : $lead, 'due_date' => $day($d === 0 ? -1 : 10),
            ]);
            $db->insert('tasks', $base + [
                'title' => $decision, 'description' => $decision, 'assignee_user_id' => $d === 0 ? $colleague : $lead, 'assigned_by' => $lead,
                'due_date' => $day($d === 0 ? -1 : 10), 'source_type' => 'meeting_decision', 'source_id' => $decisionId, 'created_by' => $lead,
            ]);
        }
        foreach ($t['tasks'] as $n => $title) {
            $db->insert('tasks', $base + [
                'title' => $title, 'assignee_user_id' => $staff[$n % max(1, count($staff))] ?? $lead, 'assigned_by' => $lead,
                'due_date' => $day([-4, 5, 20][$n]), 'priority' => ['high', 'normal', 'low'][$n], 'status' => ['open', 'in_progress', 'open'][$n], 'created_by' => $lead,
            ]);
        }

        // Staff development and one shared observation for the first teacher that has a user account.
        $teacher = $db->one('SELECT id, user_id FROM teachers WHERE school_id = ? AND user_id IS NOT NULL ORDER BY id LIMIT 1', [$schoolId]);
        if ($teacher !== null) {
            $db->insert('teacher_goals', $base + ['academic_year_id' => $yearId, 'teacher_id' => (int) $teacher['id'], 'title' => $t['teacher_goal'], 'due_date' => $day(120)]);
            $db->insert('teacher_trainings', $base + ['teacher_id' => (int) $teacher['id'], 'title' => $t['training'][0], 'provider' => $t['training'][1], 'hours' => 30, 'start_date' => $day(-40), 'status' => 'in_progress']);
            $observationId = $db->insert('observations', $base + [
                'academic_year_id' => $yearId, 'teacher_id' => (int) $teacher['id'], 'observer_user_id' => $lead, 'record_type' => 'lesson_visit',
                'observed_on' => $day(-6), 'strengths' => $t['strengths'], 'areas_for_growth' => $t['growth'], 'feedback' => $t['feedback'],
                'shared_with_teacher' => 1, 'created_by' => $lead,
            ]);
            $db->insert('observation_recommendations', $base + ['observation_id' => $observationId, 'recommendation' => $t['recommendation'], 'follow_up_date' => $day(15)]);
        }
    }
}
