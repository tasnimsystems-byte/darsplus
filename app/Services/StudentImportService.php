<?php

declare(strict_types=1);

namespace App\Services;

use App\Controllers\StudentController;
use App\Core\Database;

/**
 * Two-step import of students: analyse() checks every row and changes nothing;
 * commit() inserts the rows that passed, in one transaction.
 * A row whose student code already exists in the school is skipped, never overwritten.
 */
final class StudentImportService
{
    /** Accepted column headings (lower-cased) in English, Hebrew and Arabic. */
    /**
     * Accepted column headings, compared after normalize() (so "الاسم الشخصيّ بالعربيّة"
     * matches "الاسم الشخصي بالعربية"). Includes the column names of common exports from
     * the school administration systems.
     */
    private const HEADERS = [
        'student_code' => ['student_code', 'code', 'קוד', 'קוד תלמיד', 'מספר תלמיד', 'رمز الطالب', 'الرمز'],
        'first_name' => ['first_name', 'first name', 'שם פרטי', 'שם פרטי בעברית', 'الاسم الشخصي', 'الاسم', 'الاسم الشخصي بالعربية'],
        'last_name' => ['last_name', 'last name', 'שם משפחה', 'שם משפחה בעברית', 'اسم العائلة', 'العائلة', 'اسم العائلة بالعربية'],
        'gender' => ['gender', 'מין', 'מגדר', 'الجنس'],
        'birth_date' => ['birth_date', 'birth date', 'תאריך לידה', 'تاريخ الميلاد'],
        'class' => ['class', 'כיתה', 'שם כיתה', 'الصف', 'اسم الصف', 'اسم الصف بالعربي', 'اسم الصف بالعربية'],
    ];

    /** How many rows from the top may hold titles above the real heading row. */
    private const HEADER_SEARCH_ROWS = 6;

    /**
     * For comparing headings and class names: lower case, no diacritics (shadda, nikud…),
     * no tatweel, and none of the punctuation that differs between exports — so
     * "السّابع (1)" equals "السابع 1" and "ז'1" equals "ז1".
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string) preg_replace('/[\p{Mn}\x{0640}]/u', '', $text);
        $text = (string) preg_replace('/[()\[\]\'"׳״`´\.\-_\/\\\\]/u', ' ', $text);
        return (string) preg_replace('/\s+/u', '', $text);
    }

    private const GENDERS = [
        'm' => 'm', 'male' => 'm', 'ז' => 'm', 'זכר' => 'm', 'בן' => 'm', 'ذكر' => 'm',
        'f' => 'f', 'female' => 'f', 'נ' => 'f', 'נקבה' => 'f', 'בת' => 'f', 'أنثى' => 'f', 'انثى' => 'f',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<int, string[]> $rows        first row = headings
     * @param array<int, string>   $classes     class id => name, the classes this user may enrol into
     * @return array{rows: array, counts: array{ok: int, error: int, exists: int}, ignored: string[]}
     */
    public function analyse(array $rows, int $schoolId, array $classes): array
    {
        // The heading row is the first of the top rows that names both first and last name;
        // title rows above it (as in many exports) are skipped.
        $aliases = [];
        foreach (self::HEADERS as $field => $names) {
            foreach ($names as $name) {
                $aliases[self::normalize($name)] = $field;
            }
        }
        $map = [];
        $headingRow = null;
        foreach (array_slice($rows, 0, self::HEADER_SEARCH_ROWS) as $position => $candidate) {
            $found = [];
            foreach ($candidate as $index => $heading) {
                $field = $aliases[self::normalize((string) $heading)] ?? null;
                if ($field !== null) {
                    $found[$field] ??= $index;
                }
            }
            if (isset($found['first_name'], $found['last_name'])) {
                [$map, $headingRow] = [$found, $position];
                break;
            }
        }
        if ($headingRow === null) {
            throw new \DomainException('students.import.errors.headers');
        }
        // Columns that are present but not read (for example an ID number) are listed, so it is
        // visible that they were left out — they are never stored.
        $ignored = [];
        foreach ($rows[$headingRow] as $index => $heading) {
            if (trim((string) $heading) !== '' && !in_array($index, $map, true)) {
                $ignored[] = trim((string) $heading);
            }
        }
        $firstLine = $headingRow + 2;
        $rows = array_slice($rows, $headingRow + 1);

        $existing = array_flip(array_map('strtolower', $this->db->column('SELECT student_code FROM students WHERE school_id = ?', [$schoolId])));
        $classIds = [];
        foreach ($classes as $id => $name) {
            $classIds[self::normalize($name)] = (int) $id;
        }

        $result = [];
        $counts = ['ok' => 0, 'error' => 0, 'exists' => 0];
        $seen = [];
        foreach ($rows as $i => $row) {
            $cell = static fn (string $field): string => isset($map[$field]) ? trim((string) ($row[$map[$field]] ?? '')) : '';
            $data = [
                'student_code' => $cell('student_code'), 'first_name' => $cell('first_name'), 'last_name' => $cell('last_name'),
                'gender' => null, 'birth_date' => null, 'class_id' => null, 'class' => $cell('class'),
            ];
            $errors = [];

            foreach (['first_name', 'last_name'] as $field) {
                if ($data[$field] === '') {
                    $errors[] = "students.import.row_errors.$field";
                } elseif (mb_strlen($data[$field]) > 80) {
                    $errors[] = 'students.import.row_errors.too_long';
                }
            }
            if ($data['student_code'] !== '') {
                $key = strtolower($data['student_code']);
                if (!preg_match('/^[A-Za-z0-9_-]{1,30}$/', $data['student_code'])) {
                    $errors[] = 'students.import.row_errors.code';
                } elseif (isset($seen[$key])) {
                    $errors[] = 'students.import.row_errors.duplicate';
                }
                $seen[$key] = true;
            }
            if (($gender = mb_strtolower($cell('gender'))) !== '') {
                $data['gender'] = self::GENDERS[$gender] ?? null;
                if ($data['gender'] === null) {
                    $errors[] = 'students.import.row_errors.gender';
                }
            }
            if (($birth = $cell('birth_date')) !== '') {
                $data['birth_date'] = self::parseDate($birth);
                if ($data['birth_date'] === null || $data['birth_date'] > date('Y-m-d')) {
                    $errors[] = 'students.import.row_errors.birth_date';
                }
            }
            if ($data['class'] !== '') {
                $data['class_id'] = $classIds[self::normalize($data['class'])] ?? null;
                if ($data['class_id'] === null) {
                    $errors[] = 'students.import.row_errors.class';
                }
            }

            $status = $errors !== [] ? 'error'
                : ($data['student_code'] !== '' && isset($existing[strtolower($data['student_code'])]) ? 'exists' : 'ok');
            $counts[$status]++;
            $result[] = ['line' => $i + $firstLine, 'data' => $data, 'errors' => $errors, 'status' => $status];
        }
        return ['rows' => $result, 'counts' => $counts, 'ignored' => array_values(array_unique($ignored))];
    }

    /** @return int number of students created */
    public function commit(array $analysis, int $schoolId, ?array $year, ?int $userId): int
    {
        $enrollments = new EnrollmentService($this->db);
        return $this->db->transaction(function () use ($analysis, $schoolId, $year, $userId, $enrollments): int {
            $created = 0;
            foreach ($analysis['rows'] as $row) {
                if ($row['status'] !== 'ok') {
                    continue;
                }
                $data = $row['data'];
                $studentId = $this->db->insert('students', [
                    'school_id' => $schoolId,
                    'student_code' => $data['student_code'] !== '' ? $data['student_code'] : StudentController::nextCode($this->db, $schoolId),
                    'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                    'gender' => $data['gender'], 'birth_date' => $data['birth_date'],
                    'status' => 'active', 'created_by' => $userId,
                ]);
                if ($data['class_id'] !== null && $year !== null) {
                    $enrollments->setClass($schoolId, $studentId, $data['class_id'], $year);
                }
                $created++;
            }
            return $created;
        });
    }

    /** Accepts 2014-03-25, 25/03/2014, 25.03.2014, 25-03-2014 and Excel serial numbers. */
    public static function parseDate(string $value): ?string
    {
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $value)) {
            $days = (int) floor((float) $value);
            return $days < 20000 || $days > 80000 ? null : gmdate('Y-m-d', ($days - 25569) * 86400);
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $value, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) && $y >= 1950 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
}
