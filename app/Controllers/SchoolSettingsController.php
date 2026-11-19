<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Response;

/**
 * School settings (principal / vice principal):
 *  - the number of terms of the working year, split evenly over the year;
 *  - the components of the term grade (assessment types with a weight in percent, total 100).
 */
final class SchoolSettingsController extends Controller
{
    public const MAX_TERMS = 4;
    public const MAX_COMPONENTS = 12;
    /** New rows offered on the form for adding components. */
    private const NEW_ROWS = 3;

    /** Suggested components: [lang key, category, weight]. */
    public const TEMPLATE = [
        ['term_exam', 'periodic', 35],
        ['monthly_exam', 'exam', 25],
        ['computerized_tasks', 'assignment', 15],
        ['homework', 'assignment', 10],
        ['participation', 'alternative', 10],
        ['project', 'project', 5],
    ];

    private function year(): array
    {
        return $this->auth()->year() ?? throw new RedirectException('/years', 'app.errors.no_year');
    }

    private function terms(int $yearId): array
    {
        return $this->db()->all(
            'SELECT t.*, (SELECT COUNT(*) FROM assessments a WHERE a.term_id = t.id AND a.deleted_at IS NULL) AS assessments
               FROM academic_terms t WHERE t.school_id = ? AND t.academic_year_id = ? ORDER BY t.sort_order, t.start_date',
            [$this->schoolId(), $yearId]
        );
    }

    private function components(): array
    {
        return $this->db()->all(
            'SELECT t.id, t.name, t.category, t.is_numeric, t.term_weight,
                    (SELECT COUNT(*) FROM assessments a WHERE a.type_id = t.id AND a.deleted_at IS NULL) AS assessments
               FROM assessment_types t WHERE t.school_id = ? ORDER BY t.term_weight IS NULL, t.sort_order, t.id',
            [$this->schoolId()]
        );
    }

    public function index(Request $request): Response
    {
        $year = $this->year();
        return $this->view('school_settings/index', [
            'year' => $year, 'terms' => $this->terms((int) $year['id']), 'components' => $this->components(),
            'newRows' => self::NEW_ROWS, 'template' => self::TEMPLATE,
        ]);
    }

    /** Term names by count: "الفصل الأول…" / "מחצית א'…", "שליש א'…". */
    public static function termName(int $index, int $count, string $locale): string
    {
        return __('school_settings.term_names.' . ($locale === 'ar' ? 'ar' : "he$count") . '.' . $index);
    }

    /** Sets the number of terms of the working year; the dates are split evenly over the year. */
    public function saveTerms(Request $request): Response
    {
        $year = $this->year();
        if ($year['is_locked']) {
            return $this->redirect('/school-settings', null, 'app.errors.year_locked');
        }
        $count = $request->int('term_count');
        if ($count < 1 || $count > self::MAX_TERMS) {
            return $this->redirect('/school-settings', null, 'school_settings.errors.term_count');
        }
        $yearId = (int) $year['id'];
        $terms = $this->terms($yearId);
        $locale = (string) ($this->auth()->school()['default_locale'] ?? 'he');

        // Even split: term i runs from day ⌊D·i/n⌋ to the day before ⌊D·(i+1)/n⌋.
        $start = new \DateTimeImmutable($year['start_date']);
        $days = (int) $start->diff(new \DateTimeImmutable($year['end_date']))->days + 1;
        $ranges = [];
        for ($i = 0; $i < $count; $i++) {
            $ranges[] = [
                $start->modify('+' . intdiv($days * $i, $count) . ' days')->format('Y-m-d'),
                $start->modify('+' . (intdiv($days * ($i + 1), $count) - 1) . ' days')->format('Y-m-d'),
            ];
        }
        $removed = array_slice($terms, $count);
        foreach ($removed as $term) {
            if ((int) $term['assessments'] > 0) {
                return $this->redirect('/school-settings', null, __('school_settings.errors.term_in_use', ['name' => $term['name']]));
            }
        }

        try {
            $this->db()->transaction(function () use ($terms, $removed, $ranges, $count, $yearId, $locale): void {
                foreach ($removed as $term) {
                    $this->db()->run('DELETE FROM academic_terms WHERE id = ?', [$term['id']]);
                }
                // Temporary names first: the names are unique within the year.
                foreach (array_slice($terms, 0, $count) as $term) {
                    $this->db()->update('academic_terms', ['name' => '~' . $term['id']], ['id' => $term['id']]);
                }
                foreach ($ranges as $i => [$from, $to]) {
                    $data = ['name' => self::termName($i + 1, $count, $locale), 'sort_order' => $i + 1, 'start_date' => $from, 'end_date' => $to];
                    if (isset($terms[$i])) {
                        $this->db()->update('academic_terms', $data, ['id' => $terms[$i]['id']]);
                    } else {
                        $this->db()->insert('academic_terms', $data + ['school_id' => $this->schoolId(), 'academic_year_id' => $yearId]);
                    }
                }
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirect('/school-settings', null, 'app.errors.in_use');
            }
            throw $e;
        }
        $this->audit('update', 'school_settings', null, ['terms' => count($terms)], ['terms' => $count]);
        return $this->redirect('/school-settings', __('school_settings.terms_saved', ['count' => $count]));
    }

    /** Saves the components of the term grade: names and weights, new components, total 100. */
    public function saveComponents(Request $request): Response
    {
        $existing = array_column($this->components(), null, 'id');
        $input = $request->input('components', []);
        $input = is_array($input) ? $input : [];
        $rows = [];
        $errors = [];
        $weightOf = static function ($value) use (&$errors): ?float {
            $value = is_scalar($value) ? str_replace(',', '.', trim((string) $value)) : '';
            if ($value === '') {
                return null;
            }
            if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                $errors['weight'] = true;
                return null;
            }
            return round((float) $value, 2);
        };
        foreach ($existing as $id => $type) {
            $row = is_array($input[$id] ?? null) ? $input[$id] : [];
            $name = mb_substr(trim((string) (is_scalar($row['name'] ?? null) ? $row['name'] : $type['name'])), 0, 80);
            $rows[] = ['id' => (int) $id, 'name' => $name !== '' ? $name : $type['name'], 'category' => $type['category'],
                'weight' => $type['is_numeric'] ? $weightOf($row['weight'] ?? '') : null];
        }
        $new = $request->input('new', []);
        foreach (is_array($new) ? $new : [] as $row) {
            if (!is_array($row) || count($rows) >= self::MAX_COMPONENTS + count($existing)) {
                continue;
            }
            $name = mb_substr(trim((string) (is_scalar($row['name'] ?? null) ? $row['name'] : '')), 0, 80);
            $weight = $weightOf($row['weight'] ?? '');
            if ($name === '') {
                if ($weight !== null) {
                    $errors['new_name'] = true;
                }
                continue;
            }
            $category = in_array($row['category'] ?? '', AssessmentTypeController::CATEGORIES, true) ? $row['category'] : 'exam';
            $rows[] = ['id' => null, 'name' => $name, 'category' => $category, 'weight' => $weight];
        }

        $keys = array_map(static fn ($r) => mb_strtolower($r['name']), $rows);
        if (count($keys) !== count(array_unique($keys))) {
            $errors['duplicate'] = true;
        }
        $weights = array_filter(array_column($rows, 'weight'), static fn ($w) => $w !== null);
        $total = round(array_sum($weights), 2);
        // Either no weights at all (plain mean of all assessments), or a total of exactly 100.
        if ($weights !== [] && abs($total - 100) > 0.001) {
            $errors['total'] = true;
        }
        if ($errors !== []) {
            $messages = [];
            foreach (array_keys($errors) as $key) {
                $messages[] = __('school_settings.errors.' . $key, ['total' => rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.')]);
            }
            return $this->invalid('/school-settings#components', ['components' => $messages], $request->post);
        }

        $this->db()->transaction(function () use ($rows): void {
            foreach ($rows as $order => $row) {
                $data = ['name' => $row['name'], 'term_weight' => $row['weight'], 'sort_order' => $order + 1];
                if ($row['id'] !== null) {
                    $this->db()->update('assessment_types', $data, ['id' => $row['id'], 'school_id' => $this->schoolId()]);
                } else {
                    $this->db()->insert('assessment_types', $data + ['school_id' => $this->schoolId(), 'category' => $row['category'], 'is_numeric' => 1]);
                }
            }
        });
        $this->audit('update', 'school_settings', null, null, ['components' => array_map(static fn ($r) => [$r['name'], $r['weight']], $rows)]);
        return $this->redirect('/school-settings#components', $weights === [] ? 'school_settings.components_cleared' : 'school_settings.components_saved');
    }

    /** Fills the suggested components: creates the missing types and gives every other type no weight. */
    public function applyTemplate(Request $request): Response
    {
        $byName = [];
        foreach ($this->components() as $type) {
            $byName[mb_strtolower($type['name'])] = (int) $type['id'];
        }
        $this->db()->transaction(function () use ($byName): void {
            $this->db()->run('UPDATE assessment_types SET term_weight = NULL WHERE school_id = ?', [$this->schoolId()]);
            foreach (self::TEMPLATE as $order => [$key, $category, $weight]) {
                $name = __('school_settings.template.' . $key);
                $data = ['term_weight' => $weight, 'sort_order' => $order + 1];
                if (isset($byName[mb_strtolower($name)])) {
                    $this->db()->update('assessment_types', $data + ['is_numeric' => 1], ['id' => $byName[mb_strtolower($name)]]);
                } else {
                    $this->db()->insert('assessment_types', $data + ['school_id' => $this->schoolId(), 'name' => $name, 'category' => $category, 'is_numeric' => 1]);
                }
            }
        });
        $this->audit('update', 'school_settings', null, null, ['components' => 'template']);
        return $this->redirect('/school-settings#components', 'school_settings.components_saved');
    }
}
