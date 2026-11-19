<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Reads a weekly timetable laid out as a grid, as exported by iSchool
 * ("מערכת שבועית לכל הכיתות", ischool_schedule_report.xlsx):
 *
 *   row 1:  "#" | "#" | class 1 | class 2 | …
 *   then, for every day and period, one row: day name (on the day's first row only) | period | one cell per class.
 *   A cell holds up to three lines: subject(s), teacher(s) (comma separated), lesson type (פרונטלי / מפוצל / מקבץ).
 *
 * The export leaves out the day cell on a day's other rows, so those rows start one column earlier
 * (period in column A); both layouts are accepted. A note in parentheses after a teacher's name,
 * such as "(מצוינות)", is ignored.
 *
 * toExport() turns the grid into the same structure as a DarsPlus JSON export, so the rest of the import
 * (matching names, the preview and the commit) is shared: each teacher × class × subject becomes one
 * activity, placed in every period the grid shows it.
 */
final class GridTimetableReader
{
    private const DAYS = [
        'الاحد' => 0, 'احد' => 0, 'الاثنين' => 1, 'اثنين' => 1, 'الثلاثاء' => 2, 'ثلاثاء' => 2, 'الاربعاء' => 3, 'اربعاء' => 3,
        'الخميس' => 4, 'خميس' => 4, 'الجمعه' => 5, 'جمعه' => 5, 'السبت' => 6, 'سبت' => 6,
        'ראשון' => 0, 'שני' => 1, 'שלישי' => 2, 'רביעי' => 3, 'חמישי' => 4, 'שישי' => 5, 'שבת' => 6,
        'א' => 0, 'ב' => 1, 'ג' => 2, 'ד' => 3, 'ה' => 4, 'ו' => 5, 'ש' => 6,
    ];

    /** Weekday 0–6 of a label such as "يوم الاحد" or "יום ראשון"; null when it is not a day. */
    public static function weekday(string $label): ?int
    {
        $key = TimetableImportService::key($label);
        $key = (string) preg_replace('/^(يوم|יום)/u', '', $key);
        return $key === '' ? null : (self::DAYS[$key] ?? null);
    }

    private static function clean(string $text, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, $max);
    }

    /** @return string[] comma-separated names, without notes in parentheses */
    private static function names(string $line, int $max): array
    {
        $line = (string) preg_replace('/\([^)]*\)/u', '', $line);
        return array_values(array_filter(array_map(static fn ($n) => self::clean($n, $max), explode(',', $line)), static fn ($n) => $n !== ''));
    }

    /** True when the rows look like this grid: a header starting with "#" and at least one day row. */
    public static function looksLikeGrid(array $rows): bool
    {
        $first = $rows[0] ?? [];
        if (trim((string) ($first[0] ?? '')) !== '#') {
            return false;
        }
        foreach (array_slice($rows, 1, 20) as $row) {
            if (self::weekday((string) ($row[0] ?? '')) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<int, string>> $rows the sheet, row by row (first row = header)
     * @return array a DarsPlus-shaped export for TimetableImportService::analyse()
     */
    public static function toExport(array $rows, string $locale, string $title): array
    {
        if (!self::looksLikeGrid($rows)) {
            throw new \DomainException('timetable.import.errors.grid_format');
        }

        // ---- Classes (header) and their grade levels ----
        $header = array_map(static fn ($c) => self::clean((string) $c, 40), $rows[0]);
        $start = 0;
        while (($header[$start] ?? null) === '#') {
            $start++;
        }
        $classNames = array_slice($header, $start);
        while ($classNames !== [] && end($classNames) === '') {
            array_pop($classNames);
        }
        if ($classNames === []) {
            throw new \DomainException('timetable.import.errors.grid_format');
        }
        $grades = [];
        $groups = [];
        foreach ($classNames as $index => $name) {
            $gradeName = trim((string) preg_replace('/[\s\-\/]*\d+\s*$/u', '', $name)) ?: $name;
            $gradeId = $grades[$gradeName]['id'] ?? count($grades) + 1;
            $grades[$gradeName] = ['id' => $gradeId, 'name' => $gradeName, 'name_ar' => $gradeName];
            $groups[] = ['id' => $index + 1, 'name' => $name !== '' ? $name : '#' . ($index + 1), 'name_ar' => $name, 'grade_id' => $gradeId, 'parent_id' => 0];
        }

        // ---- Lessons ----
        $teachers = [];
        $subjects = [];
        $activities = [];
        $cells = [];
        $periodNumbers = [];
        $days = [];
        $day = null;
        $ambiguous = 0;
        foreach (array_slice($rows, 1) as $row) {
            $row = array_values(array_map(static fn ($c) => trim((string) ($c ?? '')), $row));
            $at = 0;
            $weekday = self::weekday($row[0] ?? '');
            if ($weekday !== null) {
                $day = $weekday;
                $at = 1;
            } elseif (($row[0] ?? '') === '' && preg_match('/^\d{1,2}$/', $row[1] ?? '')) {
                $at = 1;   // a layout that keeps the empty day cell
            }
            if ($day === null || !preg_match('/^\d{1,2}$/', $row[$at] ?? '')) {
                continue;
            }
            $period = (int) $row[$at];
            $periodNumbers[$period] = true;
            $days[$day] = true;
            foreach (array_slice($row, $at + 1) as $index => $cell) {
                if ($cell === '' || !isset($classNames[$index])) {
                    continue;
                }
                $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $cell) ?: []), static fn ($l) => $l !== ''));
                $subjectNames = self::names($lines[0] ?? '', 100);
                $teacherNames = self::names($lines[1] ?? '', 160);
                if ($subjectNames === [] || $teacherNames === []) {
                    continue;
                }
                // Pairs: subject i with teacher i when the counts agree; otherwise every teacher with every subject
                // when one side has a single name, or with the first subject.
                $pairs = [];
                if (count($subjectNames) === count($teacherNames)) {
                    foreach ($teacherNames as $i => $teacher) {
                        $pairs[] = [$teacher, $subjectNames[$i]];
                    }
                } elseif (count($subjectNames) === 1 || count($teacherNames) === 1) {
                    foreach ($teacherNames as $teacher) {
                        foreach ($subjectNames as $subject) {
                            $pairs[] = [$teacher, $subject];
                        }
                    }
                } else {
                    $ambiguous++;
                    foreach ($teacherNames as $teacher) {
                        $pairs[] = [$teacher, $subjectNames[0]];
                    }
                }
                foreach ($pairs as [$teacher, $subject]) {
                    $tKey = TimetableImportService::key($teacher);
                    $sKey = TimetableImportService::key($subject);
                    if (!isset($teachers[$tKey])) {
                        $parts = explode(' ', $teacher, 2);
                        $teachers[$tKey] = ['id' => count($teachers) + 1, 'first_name' => $parts[0], 'last_name' => $parts[1] ?? ''];
                    }
                    $subjects[$sKey] ??= ['id' => count($subjects) + 1, 'name' => $subject];
                    $aKey = "$tKey|$index|$sKey";
                    if (!isset($activities[$aKey])) {
                        $activities[$aKey] = ['id' => count($activities) + 1, 'subject_id' => $subjects[$sKey]['id'],
                            'teacher_id' => $teachers[$tKey]['id'], 'group_id' => $index + 1, 'lessons_per_week' => 0, 'duration' => 1];
                    }
                    $cellKey = "{$activities[$aKey]['id']}|$day|$period";
                    if (!isset($cells[$cellKey])) {
                        $cells[$cellKey] = ['activity_id' => $activities[$aKey]['id'], 'day' => $day, 'period' => $period];
                        $activities[$aKey]['lessons_per_week']++;
                    }
                }
            }
        }
        if ($cells === []) {
            throw new \DomainException('timetable.import.errors.grid_empty');
        }

        ksort($periodNumbers);
        $label = $locale === 'ar' ? 'الحصة :n' : 'שיעור :n';
        return [
            'project' => ['name' => self::clean($title, 150), 'school_name' => 'iSchool', 'days' => implode(',', array_keys($days))],
            'periods' => array_map(static fn ($n) => ['num' => $n, 'name' => str_replace(':n', (string) $n, $label), 'is_break' => 0], array_keys($periodNumbers)),
            'grades' => array_values($grades),
            'groups' => $groups,
            'teachers' => array_values($teachers),
            'subjects' => array_values($subjects),
            'rooms' => [],
            'activities' => array_map(static fn ($a) => array_diff_key($a, ['teacher_id' => 1, 'group_id' => 1]), array_values($activities)),
            'activity_teachers' => array_map(static fn ($a) => ['activity_id' => $a['id'], 'teacher_id' => $a['teacher_id']], array_values($activities)),
            'activity_groups' => array_map(static fn ($a) => ['activity_id' => $a['id'], 'group_id' => $a['group_id']], array_values($activities)),
            'cells' => array_values($cells),
            'extra_warnings' => $ambiguous > 0 ? [['timetable.import.warnings.ambiguous_cells', ['count' => $ambiguous]]] : [],
        ];
    }
}
