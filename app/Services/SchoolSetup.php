<?php

declare(strict_types=1);

namespace App\Services;

use App\Controllers\SchoolSettingsController;
use App\Core\Database;

/**
 * A new school ready to work, in one transaction: the school and its defaults, optionally its first
 * school year with evenly split terms, its grade levels (and classes), and its principal's account.
 */
final class SchoolSetup
{
    private const AR_LEVELS = [1 => 'الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس', 'السابع', 'الثامن', 'التاسع', 'العاشر', 'الحادي عشر', 'الثاني عشر'];
    private const HE_LEVELS = [1 => 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט', 'י', 'יא', 'יב'];

    /** Name of a grade level and of its n-th class in the school's language. */
    public static function levelName(int $number, string $locale): string
    {
        if ($locale === 'ar') {
            return self::AR_LEVELS[$number] ?? (string) $number;
        }
        $letters = self::HE_LEVELS[$number] ?? (string) $number;
        return mb_strlen($letters) === 2 ? mb_substr($letters, 0, 1) . '"' . mb_substr($letters, 1) : $letters . "'";
    }

    public static function className(int $number, int $n, string $locale): string
    {
        return $locale === 'ar' ? (self::AR_LEVELS[$number] ?? (string) $number) . " $n" : (self::HE_LEVELS[$number] ?? (string) $number) . $n;
    }

    /**
     * @param array $data name, code, default_locale; year_name, year_start, year_end, term_count; levels_from, levels_to,
     *                    classes_per_level; principal_name, principal_username (all after the first three optional)
     * @return array{school_id: int, principal_username: ?string, password: ?string, summary: array}
     */
    public static function create(Database $db, array $data, ?int $actorId): array
    {
        $locale = $data['default_locale'];
        return $db->transaction(function () use ($db, $data, $actorId, $locale): array {
            $schoolId = $db->insert('schools', ['name' => $data['name'], 'code' => $data['code'], 'default_locale' => $locale, 'is_active' => 1]);
            SchoolDefaults::ensure($db, $schoolId, $locale);
            $summary = ['terms' => 0, 'levels' => 0, 'classes' => 0];

            // The first school year, its terms split evenly.
            $yearId = null;
            if (!empty($data['year_start']) && !empty($data['year_end'])) {
                $name = $data['year_name'] !== '' ? $data['year_name'] : substr($data['year_start'], 0, 4) . '-' . substr($data['year_end'], 0, 4);
                $yearId = $db->insert('academic_years', [
                    'school_id' => $schoolId, 'name' => $name, 'start_date' => $data['year_start'], 'end_date' => $data['year_end'], 'is_current' => 1,
                ]);
                $count = max(1, min(4, (int) $data['term_count']));
                $start = new \DateTimeImmutable($data['year_start']);
                $days = (int) $start->diff(new \DateTimeImmutable($data['year_end']))->days + 1;
                for ($i = 0; $i < $count; $i++) {
                    $db->insert('academic_terms', [
                        'school_id' => $schoolId, 'academic_year_id' => $yearId, 'sort_order' => $i + 1,
                        'name' => SchoolSettingsController::termName($i + 1, $count, $locale),
                        'start_date' => $start->modify('+' . intdiv($days * $i, $count) . ' days')->format('Y-m-d'),
                        'end_date' => $start->modify('+' . (intdiv($days * ($i + 1), $count) - 1) . ' days')->format('Y-m-d'),
                    ]);
                    $summary['terms']++;
                }
            }

            // Grade levels, and classes in them when a year exists.
            $from = (int) ($data['levels_from'] ?? 0);
            $to = (int) ($data['levels_to'] ?? 0);
            if ($from >= 1 && $to >= $from && $to <= 12) {
                for ($number = $from; $number <= $to; $number++) {
                    $levelId = $db->insert('grade_levels', ['school_id' => $schoolId, 'name' => self::levelName($number, $locale), 'level_number' => $number]);
                    $summary['levels']++;
                    for ($n = 1; $yearId !== null && $n <= (int) ($data['classes_per_level'] ?? 0); $n++) {
                        $db->insert('classes', ['school_id' => $schoolId, 'academic_year_id' => $yearId, 'grade_level_id' => $levelId, 'name' => self::className($number, $n, $locale)]);
                        $summary['classes']++;
                    }
                }
            }

            // The principal's account, with a starting password to change at the first sign-in.
            $username = null;
            $password = null;
            if (!empty($data['principal_username'])) {
                $username = $data['principal_username'];
                $password = Passwords::generate();
                $userId = $db->insert('users', [
                    'username' => $username, 'full_name' => $data['principal_name'] ?: $username, 'locale' => $locale,
                    'password_hash' => Passwords::hash($password), 'must_change_password' => 1, 'created_by' => $actorId,
                ]);
                $db->insert('user_roles', ['user_id' => $userId, 'school_id' => $schoolId, 'created_by' => $actorId,
                    'role_id' => (int) $db->value("SELECT id FROM roles WHERE school_id IS NULL AND code = 'principal'")]);
            }
            return ['school_id' => $schoolId, 'principal_username' => $username, 'password' => $password, 'summary' => $summary];
        });
    }
}
