<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Imports a weekly timetable produced by the DarsPlus timetable generator
 * (timetable-web → Reports → "JSON backup", i.e. export.php?format=json).
 *
 * analyse() reads the file and plans every change without writing anything;
 * commit() carries out that plan in one transaction:
 *   grades → grade levels, top-level groups → classes of the working year,
 *   sub-groups → the group_name of an assignment in their parent class,
 *   teachers / subjects / rooms matched by name (Hebrew or Arabic) or created,
 *   each activity × teacher × class → a teaching assignment (weekly hours summed),
 *   each placed lesson → timetable slots; the year's periods and slots are replaced.
 * Assignments are never deleted: ones missing from the new timetable are listed.
 * Only names, hours and placements are read — teacher e-mail and phone are ignored.
 */
final class TimetableImportService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_ROWS = 50000;
    private const REQUIRED = ['project', 'periods', 'groups', 'teachers', 'subjects', 'activities', 'cells', 'activity_teachers', 'activity_groups'];

    private const HEBREW_LEVELS = ['א' => 1, 'ב' => 2, 'ג' => 3, 'ד' => 4, 'ה' => 5, 'ו' => 6, 'ז' => 7, 'ח' => 8, 'ט' => 9, 'י' => 10, 'יא' => 11, 'יב' => 12];
    private const ARABIC_LEVELS = [
        'الحاديعشر' => 11, 'الثانيعشر' => 12, 'الاول' => 1, 'الثاني' => 2, 'الثالث' => 3, 'الرابع' => 4, 'الخامس' => 5,
        'السادس' => 6, 'السابع' => 7, 'الثامن' => 8, 'التاسع' => 9, 'العاشر' => 10,
    ];

    public function __construct(private Database $db)
    {
    }

    /** Comparison key for names: normalised like the student import, plus Arabic letter variants folded. */
    public static function key(string $text): string
    {
        return strtr(StudentImportService::normalize($text), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
    }

    /** Grade number from "שכבת ז'", "ז", "السّابع", "الصف السابع" or "7"; null when unknown. */
    public static function levelNumber(string $name): ?int
    {
        $key = self::key($name);
        if (preg_match('/(\d{1,2})/', $key, $m)) {
            return (int) $m[1] >= 1 && (int) $m[1] <= 14 ? (int) $m[1] : null;
        }
        $hebrew = (string) preg_replace('/^(שכבת|שכבה|כיתות|כיתה)/u', '', $key);
        if (isset(self::HEBREW_LEVELS[$hebrew])) {
            return self::HEBREW_LEVELS[$hebrew];
        }
        foreach (self::ARABIC_LEVELS as $word => $number) {
            if (str_contains($key, $word)) {
                return $number;
            }
        }
        return null;
    }

    /** Decodes and checks the shape of an export; throws DomainException with a lang key. */
    public static function decode(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new \DomainException('timetable.import.errors.too_large');
        }
        $json = (string) preg_replace('/^\xEF\xBB\xBF/', '', $json);
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('timetable.import.errors.format');
        }
        if (!is_array($data) || !is_array($data['project'] ?? null)) {
            throw new \DomainException('timetable.import.errors.format');
        }
        $rows = 0;
        foreach (self::REQUIRED as $section) {
            if (!array_key_exists($section, $data) || !is_array($data[$section])) {
                throw new \DomainException('timetable.import.errors.format');
            }
            if ($section !== 'project') {
                foreach ($data[$section] as $row) {
                    if (!is_array($row)) {
                        throw new \DomainException('timetable.import.errors.format');
                    }
                }
                $rows += count($data[$section]);
            }
        }
        if ($rows > self::MAX_ROWS) {
            throw new \DomainException('timetable.import.errors.too_large');
        }
        foreach (['grades', 'rooms'] as $optional) {
            $data[$optional] = is_array($data[$optional] ?? null) ? array_filter($data[$optional], 'is_array') : [];
        }
        return $data;
    }

    private static function text(array $row, string $field, int $max): string
    {
        $value = $row[$field] ?? '';
        $value = is_scalar($value) ? trim((string) $value) : '';
        return mb_substr((string) preg_replace('/\s+/u', ' ', $value), 0, $max);
    }

    private static function int(array $row, string $field): int
    {
        $value = $row[$field] ?? 0;
        return is_numeric($value) ? (int) $value : 0;
    }

    /** The name in the school's language, falling back to the other one. */
    private static function pick(array $row, string $field, string $locale, int $max): string
    {
        $he = self::text($row, $field, $max);
        $ar = self::text($row, $field . '_ar', $max);
        return $locale === 'ar' ? ($ar !== '' ? $ar : $he) : ($he !== '' ? $he : $ar);
    }

    /** Class and grade names are created without Arabic short vowels and shadda, like names typed by hand. */
    private static function plain(string $name): string
    {
        return trim((string) preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $name));
    }

    /** @return string[] both language variants of a name, as keys */
    private static function keys(array $row, string $field): array
    {
        return array_values(array_unique(array_filter([
            self::key(self::text($row, $field, 200)), self::key(self::text($row, $field . '_ar', 200)),
        ])));
    }

    /**
     * @param array $data   decoded export
     * @param array $year   the working year (row of academic_years)
     * @return array the plan: entities to match or create, assignments, slots, periods, warnings
     */
    public function analyse(array $data, int $schoolId, array $year, string $locale): array
    {
        $yearId = (int) $year['id'];
        $warnings = array_values(array_filter(is_array($data['extra_warnings'] ?? null) ? $data['extra_warnings'] : [], 'is_array'));

        // ---- Periods -------------------------------------------------------
        $periods = [];
        foreach ($data['periods'] as $row) {
            $number = self::int($row, 'num');
            if ($number < 1 || $number > 20) {
                continue;
            }
            $start = preg_match('/^\d{1,2}:\d{2}/', self::text($row, 'start_time', 8)) ? substr(self::text($row, 'start_time', 8), 0, 5) : null;
            $end = preg_match('/^\d{1,2}:\d{2}/', self::text($row, 'end_time', 8)) ? substr(self::text($row, 'end_time', 8), 0, 5) : null;
            $periods[$number] = [
                'period_number' => $number, 'name' => self::text($row, 'name', 60),
                'start_time' => $start, 'end_time' => $end, 'is_break' => !empty($row['is_break']) ? 1 : 0,
            ];
            if ($start !== null && $end !== null) {
                $minutes = (strtotime("2000-01-01 $end") - strtotime("2000-01-01 $start")) / 60;
                if ($minutes <= 0 || $minutes > 180) {
                    $warnings[] = ['timetable.import.warnings.period_time', ['name' => $periods[$number]['name'] ?: $number, 'start' => $start, 'end' => $end]];
                }
            }
        }
        ksort($periods);
        if ($periods === []) {
            throw new \DomainException('timetable.import.errors.no_periods');
        }
        $teachingPeriods = array_values(array_keys(array_filter($periods, static fn ($p) => !$p['is_break'])));

        $days = array_values(array_unique(array_filter(
            array_map('intval', array_filter(explode(',', (string) ($data['project']['days'] ?? '')), 'is_numeric')),
            static fn (int $d) => $d >= 0 && $d <= 6
        )));
        sort($days);
        if ($days === []) {
            $days = [0, 1, 2, 3, 4];
        }

        // ---- Grade levels ---------------------------------------------------
        $localLevels = $this->db->all('SELECT id, name, level_number FROM grade_levels WHERE school_id = ?', [$schoolId]);
        $levelByNumber = array_column($localLevels, 'id', 'level_number');
        $levelByKey = [];
        foreach ($localLevels as $level) {
            $levelByKey[self::key($level['name'])] = (int) $level['id'];
        }
        $usedNumbers = array_map('intval', array_keys($levelByNumber));
        $levels = [];   // external grade id => plan entry
        foreach ($data['grades'] as $row) {
            $id = self::int($row, 'id');
            $name = self::plain(self::pick($row, 'name', $locale, 40));
            $number = self::levelNumber(self::text($row, 'name', 40)) ?? self::levelNumber(self::text($row, 'name_ar', 40));
            $local = $number !== null && isset($levelByNumber[$number]) ? (int) $levelByNumber[$number] : null;
            foreach (self::keys($row, 'name') as $key) {
                $local ??= $levelByKey[$key] ?? null;
            }
            if ($local === null) {
                if ($number === null || in_array($number, $usedNumbers, true)) {
                    $number = max([0, ...$usedNumbers]) + 1;
                }
                $usedNumbers[] = $number;
            }
            $levels[$id] = ['ref' => $local !== null ? "L$local" : "N$id", 'local_id' => $local, 'name' => $name !== '' ? $name : (string) $number, 'level_number' => $number];
        }

        // ---- Classes and groups ---------------------------------------------
        $localClasses = $this->db->all('SELECT id, name FROM classes WHERE school_id = ? AND academic_year_id = ?', [$schoolId, $yearId]);
        $classByKey = [];
        foreach ($localClasses as $class) {
            $classByKey[self::key($class['name'])] ??= (int) $class['id'];
        }
        $groups = [];
        foreach ($data['groups'] as $row) {
            $groups[self::int($row, 'id')] = $row;
        }
        $classes = [];  // ref => plan entry
        $groupTarget = [];  // external group id => [class ref, group_name]
        foreach ($groups as $id => $row) {
            // Walk up to the top-level group: that is the class.
            [$top, $depth] = [$row, 0];
            while (($parent = self::int($top, 'parent_id')) > 0 && isset($groups[$parent]) && $depth++ < 10) {
                $top = $groups[$parent];
            }
            $topId = self::int($top, 'id');
            if (!isset($classes["G$topId"]) && !isset($groupTarget[$topId])) {
                $local = null;
                foreach (self::keys($top, 'name') as $key) {
                    $local ??= $classByKey[$key] ?? null;
                }
                $level = $levels[self::int($top, 'grade_id')] ?? null;
                if ($local === null && $level === null) {
                    // A class needs a grade level: derive one from the class name when the group has none.
                    $number = self::levelNumber(self::text($top, 'name', 40)) ?? self::levelNumber(self::text($top, 'name_ar', 40));
                    if ($number !== null) {
                        $levelLocal = isset($levelByNumber[$number]) ? (int) $levelByNumber[$number] : null;
                        foreach ($levels as $planned) {
                            $level ??= $planned['level_number'] === $number ? $planned : null;
                        }
                        if ($level === null && $levelLocal === null && in_array($number, $usedNumbers, true)) {
                            $number = max([0, ...$usedNumbers]) + 1;
                        }
                        $usedNumbers[] = $number;
                        $level ??= $levels["n$number"] = ['ref' => $levelLocal !== null ? "L$levelLocal" : "D$number", 'local_id' => $levelLocal,
                            'name' => (string) $number, 'level_number' => $number];
                    }
                }
                if ($local === null && $level === null) {
                    $warnings[] = ['timetable.import.warnings.no_level', ['name' => self::pick($top, 'name', $locale, 40)]];
                    $groupTarget[$topId] = null;
                } else {
                    $ref = $local !== null ? "C$local" : "G$topId";
                    $classes[$ref] ??= ['ref' => $ref, 'local_id' => $local, 'name' => self::plain(self::pick($top, 'name', $locale, 40)), 'level_ref' => $level['ref'] ?? null];
                    $groupTarget[$topId] = [$ref, ''];
                }
            }
            if ($id !== $topId) {
                $groupTarget[$id] = $groupTarget[$topId] === null ? null : [$groupTarget[$topId][0], self::pick($row, 'name', $locale, 50)];
            }
        }

        // ---- Teachers, subjects, rooms --------------------------------------
        $teachers = $this->matchPeople($data['teachers'], $schoolId, $locale);
        $subjects = $this->matchNamed($data['subjects'], 'subjects', $schoolId, $locale, 100);
        $rooms = $this->matchNamed($data['rooms'], 'rooms', $schoolId, $locale, 60);

        // ---- Activities → assignments ---------------------------------------
        $activityTeachers = [];
        foreach ($data['activity_teachers'] as $row) {
            $activityTeachers[self::int($row, 'activity_id')][] = self::int($row, 'teacher_id');
        }
        $activityGroups = [];
        foreach ($data['activity_groups'] as $row) {
            $activityGroups[self::int($row, 'activity_id')][] = self::int($row, 'group_id');
        }
        $assignments = [];  // key => plan entry
        $activityKeys = [];  // activity id => assignment keys
        $activities = [];
        $skipped = 0;
        $planned = 0;
        foreach ($data['activities'] as $row) {
            $id = self::int($row, 'id');
            $activities[$id] = $row;
            $subject = $subjects['map'][self::int($row, 'subject_id')] ?? null;
            $hours = max(1, self::int($row, 'lessons_per_week')) * max(1, self::int($row, 'duration'));
            $planned += max(1, self::int($row, 'lessons_per_week'));
            $keys = [];
            foreach (array_unique($activityTeachers[$id] ?? []) as $teacherExt) {
                foreach (array_unique($activityGroups[$id] ?? []) as $groupExt) {
                    $teacher = $teachers['map'][$teacherExt] ?? null;
                    $target = $groupTarget[$groupExt] ?? null;
                    if ($teacher === null || $target === null || $subject === null) {
                        continue;
                    }
                    $key = "$teacher|{$target[0]}|$subject|{$target[1]}";
                    $assignments[$key] ??= ['teacher_ref' => $teacher, 'class_ref' => $target[0], 'subject_ref' => $subject,
                        'group_name' => $target[1], 'weekly_hours' => 0, 'local_id' => null];
                    $assignments[$key]['weekly_hours'] += $hours;
                    $keys[$key] = true;
                }
            }
            if ($keys === []) {
                $skipped++;
            }
            $activityKeys[$id] = array_keys($keys);
        }
        if ($skipped > 0) {
            $warnings[] = ['timetable.import.warnings.incomplete_activities', ['count' => $skipped]];
        }

        // Existing assignments of the year: matched ones are updated, the rest listed.
        $existing = [];
        foreach ($this->db->all(
            "SELECT ta.id, ta.teacher_id, ta.class_id, ta.subject_id, ta.group_name, c.name AS class_name, s.name AS subject_name,
                    CONCAT(t.last_name, ' ', t.first_name) AS teacher_name
               FROM teaching_assignments ta JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id
               JOIN teachers t ON t.id = ta.teacher_id
              WHERE ta.school_id = ? AND ta.academic_year_id = ?",
            [$schoolId, $yearId]
        ) as $row) {
            $existing["T{$row['teacher_id']}|C{$row['class_id']}|S{$row['subject_id']}|" . mb_strtolower($row['group_name'])] = $row;
        }
        $matched = [];
        foreach ($assignments as $key => &$assignment) {
            $lookup = "{$assignment['teacher_ref']}|{$assignment['class_ref']}|{$assignment['subject_ref']}|" . mb_strtolower($assignment['group_name']);
            if (isset($existing[$lookup])) {
                $assignment['local_id'] = (int) $existing[$lookup]['id'];
                $matched[$assignment['local_id']] = true;
            }
        }
        unset($assignment);
        $stale = array_values(array_filter($existing, static fn ($row) => !isset($matched[(int) $row['id']])));

        // ---- Placed lessons → slots -----------------------------------------
        $slots = [];
        $placed = 0;
        foreach ($data['cells'] as $row) {
            $activity = $activities[self::int($row, 'activity_id')] ?? null;
            $day = self::int($row, 'day');
            $period = self::int($row, 'period');
            $position = array_search($period, $teachingPeriods, true);
            if ($activity === null || $position === false || $day < 0 || $day > 6) {
                continue;
            }
            $placed++;
            $roomExt = self::int($row, 'room_id') ?: self::int($activity, 'room_id');
            $span = array_slice($teachingPeriods, (int) $position, max(1, self::int($activity, 'duration')));
            foreach ($activityKeys[self::int($activity, 'id')] ?? [] as $key) {
                foreach ($span as $number) {
                    $slots["$key#$day#$number"] ??= ['key' => $key, 'weekday' => $day, 'period_number' => $number, 'room_ref' => $rooms['map'][$roomExt] ?? null];
                }
            }
        }
        if ($planned > $placed) {
            $warnings[] = ['timetable.import.warnings.unplaced', ['count' => $planned - $placed]];
        }

        $new = static fn (array $items) => count(array_filter($items, static fn ($i) => $i['local_id'] === null));
        return [
            'source' => [
                'name' => self::text($data['project'], 'name', 150),
                'school_name' => self::text($data['project'], 'school_name', 150),
            ],
            'days' => $days,
            'periods' => array_values($periods),
            'levels' => array_values(array_filter($levels, static fn ($l) => $l['local_id'] === null)),
            'classes' => $classes,
            'teachers' => $teachers['items'],
            'subjects' => $subjects['items'],
            'rooms' => $rooms['items'],
            'assignments' => $assignments,
            'slots' => array_values($slots),
            'stale' => $stale,
            'warnings' => $warnings,
            'not_imported' => array_filter([
                'teacher_duties' => count(is_array($data['teacher_duties'] ?? null) ? $data['teacher_duties'] : []),
                'unavailability' => count(is_array($data['unavailability'] ?? null) ? $data['unavailability'] : []),
                'changes' => count(is_array($data['changes'] ?? null) ? $data['changes'] : []),
            ]),
            'counts' => [
                'classes' => count($classes), 'classes_new' => $new($classes),
                'levels_new' => count(array_unique(array_column(array_filter($levels, static fn ($l) => $l['local_id'] === null), 'ref'))),
                'teachers' => count($teachers['items']), 'teachers_new' => $new($teachers['items']),
                'subjects' => count($subjects['items']), 'subjects_new' => $new($subjects['items']),
                'rooms_new' => $new($rooms['items']),
                'assignments' => count($assignments), 'assignments_new' => $new($assignments),
                'slots' => count($slots), 'lessons' => $placed, 'stale' => count($stale),
            ],
        ];
    }

    /** Teachers matched by full name in either language and either order. */
    private function matchPeople(array $rows, int $schoolId, string $locale): array
    {
        $byKey = [];
        foreach ($this->db->all('SELECT id, first_name, last_name FROM teachers WHERE school_id = ? AND deleted_at IS NULL ORDER BY id', [$schoolId]) as $t) {
            $byKey[self::key($t['first_name'] . $t['last_name'])] ??= (int) $t['id'];
            $byKey[self::key($t['last_name'] . $t['first_name'])] ??= (int) $t['id'];
        }
        $items = [];
        $map = [];
        foreach ($rows as $row) {
            $first = self::pick($row, 'first_name', $locale, 80);
            $last = self::pick($row, 'last_name', $locale, 80);
            if ($first === '' && $last === '') {
                $first = self::text($row, 'short_name', 80);
            }
            if ($first === '' && $last === '') {
                continue;
            }
            $candidates = [];
            foreach (['', '_ar'] as $suffix) {
                $f = self::text($row, "first_name$suffix", 80);
                $l = self::text($row, "last_name$suffix", 80);
                if ($f . $l !== '') {
                    $candidates[] = self::key($f . $l);
                    $candidates[] = self::key($l . $f);
                }
            }
            $local = null;
            foreach ($candidates as $key) {
                $local ??= $byKey[$key] ?? null;
            }
            $ref = $local !== null ? "T$local" : 'NT' . self::key($first . $last);
            $items[$ref] ??= ['ref' => $ref, 'local_id' => $local, 'first_name' => $first, 'last_name' => $last];
            $map[self::int($row, 'id')] = $ref;
        }
        return ['items' => $items, 'map' => $map];
    }

    /** Subjects or rooms matched by name in either language. */
    private function matchNamed(array $rows, string $table, int $schoolId, string $locale, int $max): array
    {
        $prefix = $table === 'subjects' ? 'S' : 'R';
        $byKey = [];
        foreach ($this->db->all("SELECT id, name FROM `$table` WHERE school_id = ? ORDER BY id", [$schoolId]) as $r) {
            $byKey[self::key($r['name'])] ??= (int) $r['id'];
        }
        $items = [];
        $map = [];
        foreach ($rows as $row) {
            $name = self::pick($row, 'name', $locale, $max);
            if ($name === '') {
                continue;
            }
            $local = null;
            foreach (self::keys($row, 'name') as $key) {
                $local ??= $byKey[$key] ?? null;
            }
            $ref = $local !== null ? "$prefix$local" : "N$prefix" . self::key($name);
            $items[$ref] ??= ['ref' => $ref, 'local_id' => $local, 'name' => $name];
            $map[self::int($row, 'id')] = $ref;
        }
        return ['items' => $items, 'map' => $map];
    }

    /** @return array{assignments_created: int, assignments_updated: int, slots: int, import_id: int} */
    public function commit(array $plan, int $schoolId, array $year, ?int $userId, string $sourceName): array
    {
        $yearId = (int) $year['id'];
        return $this->db->transaction(function () use ($plan, $schoolId, $yearId, $userId, $sourceName): array {
            $ids = [];
            foreach ($plan['levels'] as $level) {
                $ids[$level['ref']] ??= $this->db->insert('grade_levels', [
                    'school_id' => $schoolId, 'name' => $this->unique('grade_levels', 'name', $level['name'], $schoolId, null, 40),
                    'level_number' => $level['level_number'],
                ]);
            }
            foreach ($plan['classes'] as $ref => $class) {
                if ($class['local_id'] !== null) {
                    $ids[$ref] = $class['local_id'];
                    continue;
                }
                $levelRef = $class['level_ref'];
                $levelId = str_starts_with((string) $levelRef, 'L') ? (int) substr($levelRef, 1) : ($ids[$levelRef] ?? null);
                $ids[$ref] = $this->db->insert('classes', [
                    'school_id' => $schoolId, 'academic_year_id' => $yearId, 'grade_level_id' => $levelId,
                    'name' => $this->unique('classes', 'name', $class['name'], $schoolId, $yearId, 40),
                ]);
            }
            foreach ($plan['teachers'] as $ref => $teacher) {
                $ids[$ref] = $teacher['local_id'] ?? $this->db->insert('teachers', [
                    'school_id' => $schoolId, 'first_name' => $teacher['first_name'], 'last_name' => $teacher['last_name'], 'created_by' => $userId,
                ]);
            }
            foreach (['subjects' => 100, 'rooms' => 60] as $table => $max) {
                foreach ($plan[$table] as $ref => $item) {
                    $ids[$ref] = $item['local_id'] ?? $this->db->insert($table, [
                        'school_id' => $schoolId, 'name' => $this->unique($table, 'name', $item['name'], $schoolId, null, $max),
                    ]);
                }
            }

            $created = $updated = 0;
            $assignmentIds = [];
            foreach ($plan['assignments'] as $key => $a) {
                $hours = min(99.9, (float) $a['weekly_hours']);
                if ($a['local_id'] !== null) {
                    $this->db->update('teaching_assignments', ['weekly_hours' => $hours], ['id' => $a['local_id'], 'school_id' => $schoolId]);
                    $assignmentIds[$key] = $a['local_id'];
                    $updated++;
                    continue;
                }
                $row = [
                    'teacher_id' => $ids[$a['teacher_ref']], 'class_id' => $ids[$a['class_ref']], 'subject_id' => $ids[$a['subject_ref']],
                    'group_name' => $a['group_name'],
                ];
                // The same assignment may already exist with a different working year only if data were edited
                // between preview and confirm; reuse it rather than fail on the unique key.
                $existing = $this->db->value(
                    'SELECT id FROM teaching_assignments WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND group_name = ?',
                    array_values($row)
                );
                if ($existing !== null) {
                    $this->db->update('teaching_assignments', ['weekly_hours' => $hours], ['id' => $existing]);
                    $assignmentIds[$key] = (int) $existing;
                    $updated++;
                    continue;
                }
                $assignmentIds[$key] = $this->db->insert('teaching_assignments', $row + [
                    'school_id' => $schoolId, 'academic_year_id' => $yearId, 'weekly_hours' => $hours,
                ]);
                $created++;
            }

            $this->db->run('DELETE FROM timetable_slots WHERE school_id = ? AND academic_year_id = ?', [$schoolId, $yearId]);
            $this->db->run('DELETE FROM timetable_periods WHERE school_id = ? AND academic_year_id = ?', [$schoolId, $yearId]);
            foreach ($plan['periods'] as $period) {
                $this->db->insert('timetable_periods', $period + ['school_id' => $schoolId, 'academic_year_id' => $yearId]);
            }
            foreach ($plan['slots'] as $slot) {
                $this->db->insert('timetable_slots', [
                    'school_id' => $schoolId, 'academic_year_id' => $yearId, 'teaching_assignment_id' => $assignmentIds[$slot['key']],
                    'weekday' => $slot['weekday'], 'period_number' => $slot['period_number'],
                    'room_id' => $slot['room_ref'] !== null ? $ids[$slot['room_ref']] : null,
                ]);
            }
            $result = ['assignments_created' => $created, 'assignments_updated' => $updated, 'slots' => count($plan['slots'])];
            $result['import_id'] = $this->db->insert('timetable_imports', [
                'school_id' => $schoolId, 'academic_year_id' => $yearId, 'source_name' => mb_substr($sourceName, 0, 190),
                'days' => implode(',', $plan['days']), 'imported_by' => $userId,
                'summary' => json_encode(['project' => $plan['source']['name']] + $plan['counts'] + $result, JSON_UNESCAPED_UNICODE),
            ]);
            return $result;
        });
    }

    /** A name that does not collide with an existing one (exact match in the database collation). */
    private function unique(string $table, string $column, string $name, int $schoolId, ?int $yearId, int $max): string
    {
        $scope = $yearId !== null ? 'academic_year_id = ?' : 'school_id = ?';
        $candidate = $name;
        for ($i = 2; $this->db->value("SELECT 1 FROM `$table` WHERE $scope AND `$column` = ?", [$yearId ?? $schoolId, $candidate]) !== null; $i++) {
            $candidate = mb_substr($name, 0, $max - 4) . " ($i)";
        }
        return $candidate;
    }
}
