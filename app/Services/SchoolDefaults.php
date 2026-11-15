<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Starter lookups every school needs before it can work: assessment types,
 * pedagogical domains and identification criteria. Filled once per school, in
 * the school's default language, and only into tables that are still empty —
 * whatever the school changes afterwards is never touched.
 */
final class SchoolDefaults
{
    private const DATA = [
        'he' => [
            'types' => [['מבחן', 'exam', 1], ['בוחן', 'exam', 1], ['עבודה', 'assignment', 1], ['מטלה', 'assignment', 1],
                ['פרויקט', 'project', 1], ['הערכה חלופית', 'alternative', 0], ['הערכה תקופתית', 'periodic', 1]],
            'domains' => ['הישגים לימודיים', 'אקלים חינוכי', 'פיתוח מקצועי', 'הכלה והשתלבות', 'קהילה והורים'],
            'criteria' => [['ממוצע נמוך מ-55', 'low_average', 55, null], ['ירידה של 10 נקודות ומעלה בין תקופות', 'decline', 10, null],
                ['3 מקצועות ומעלה מתחת ל-55', 'multi_subject', 55, 3], ['8 היעדרויות לא מוצדקות ומעלה בתקופה', 'absences', null, 8]],
        ],
        'ar' => [
            'types' => [['امتحان', 'exam', 1], ['اختبار قصير', 'exam', 1], ['وظيفة', 'assignment', 1], ['مهمة', 'assignment', 1],
                ['مشروع', 'project', 1], ['تقييم بديل', 'alternative', 0], ['تقييم فصلي', 'periodic', 1]],
            'domains' => ['التحصيل الدراسي', 'المناخ التربوي', 'التطوير المهني', 'الاحتواء والدمج', 'المجتمع والأهل'],
            'criteria' => [['معدل أقل من 55', 'low_average', 55, null], ['تراجع بـ10 نقاط أو أكثر بين الفصول', 'decline', 10, null],
                ['3 مواضيع أو أكثر تحت 55', 'multi_subject', 55, 3], ['8 غيابات غير مبرَّرة أو أكثر في الفصل', 'absences', null, 8]],
        ],
    ];

    public static function ensureAll(Database $db): void
    {
        foreach ($db->all('SELECT id, default_locale FROM schools WHERE deleted_at IS NULL') as $school) {
            self::ensure($db, (int) $school['id'], (string) $school['default_locale']);
        }
    }

    public static function ensure(Database $db, int $schoolId, string $locale): void
    {
        $data = self::DATA[$locale] ?? self::DATA['he'];
        $empty = static fn (string $table): bool => $db->value("SELECT 1 FROM `$table` WHERE school_id = ? LIMIT 1", [$schoolId]) === null;

        if ($empty('assessment_types')) {
            foreach ($data['types'] as [$name, $category, $numeric]) {
                $db->insert('assessment_types', ['school_id' => $schoolId, 'name' => $name, 'category' => $category, 'is_numeric' => $numeric]);
            }
        }
        if ($empty('pedagogical_domains')) {
            foreach ($data['domains'] as $name) {
                $db->insert('pedagogical_domains', ['school_id' => $schoolId, 'name' => $name]);
            }
        }
        if ($empty('risk_criteria')) {
            foreach ($data['criteria'] as [$name, $type, $threshold, $count]) {
                $db->insert('risk_criteria', [
                    'school_id' => $schoolId, 'name' => $name, 'criterion_type' => $type, 'threshold' => $threshold, 'min_count' => $count,
                ]);
            }
        }
    }
}
