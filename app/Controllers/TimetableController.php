<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AccessScope;
use App\Services\GridTimetableReader;
use App\Services\SharedLessons;
use App\Services\SpreadsheetReader;
use App\Services\TimetableImportService;

/**
 * The weekly timetable of the working year: a grid per class or per teacher, and the
 * import of a timetable built in the DarsPlus generator (upload → preview → confirm;
 * the uploaded file is kept under a random name only between the two steps).
 */
final class TimetableController extends Controller
{
    private const SESSION_KEY = 'timetable_import';
    private const FILE_PATTERN = '/^timetable_[a-f0-9]{32}\.(json|xlsx)$/';

    private function year(): array
    {
        return $this->auth()->year() ?? throw new RedirectException('/years', 'app.errors.no_year');
    }

    /** Periods of a year, by number; when none were imported, the numbers used by its slots. */
    public static function periods(Database $db, int $schoolId, int $yearId): array
    {
        $periods = [];
        foreach ($db->all('SELECT * FROM timetable_periods WHERE school_id = ? AND academic_year_id = ? ORDER BY period_number', [$schoolId, $yearId]) as $row) {
            $periods[(int) $row['period_number']] = $row;
        }
        return $periods;
    }

    /** School days of a year (0 = Sunday), from its latest import. */
    public static function days(Database $db, int $schoolId, int $yearId): array
    {
        $days = (string) ($db->value('SELECT days FROM timetable_imports WHERE school_id = ? AND academic_year_id = ? ORDER BY id DESC LIMIT 1', [$schoolId, $yearId]) ?? '0,1,2,3,4');
        return array_map('intval', array_filter(explode(',', $days), 'is_numeric'));
    }

    /** "Period 3 · 09:50–10:35", or just "Period 3" when nothing was imported. */
    public static function periodLabel(array $periods, int $number): string
    {
        $period = $periods[$number] ?? null;
        $label = $period !== null && $period['name'] !== '' ? $period['name'] : __('attendance.period', ['n' => $number]);
        if ($period !== null && $period['start_time'] !== null && $period['end_time'] !== null) {
            $label .= ' – ' . substr($period['start_time'], 0, 5) . '-' . substr($period['end_time'], 0, 5);
        }
        return $label;
    }

    public function index(Request $request): Response
    {
        $year = $this->year();
        $schoolId = $this->schoolId();
        $classes = [];
        foreach ($this->db()->all('SELECT id, name FROM classes WHERE school_id = ? AND academic_year_id = ? ORDER BY grade_level_id, name', [$schoolId, $year['id']]) as $row) {
            $classes[(int) $row['id']] = $row['name'];
        }
        $teachers = [];
        foreach ($this->db()->all(
            "SELECT DISTINCT t.id, CONCAT(t.last_name, ' ', t.first_name) AS name FROM teachers t
               JOIN teaching_assignments ta ON ta.teacher_id = t.id AND ta.academic_year_id = ?
              WHERE t.school_id = ? AND t.deleted_at IS NULL ORDER BY name",
            [$year['id'], $schoolId]
        ) as $row) {
            $teachers[(int) $row['id']] = $row['name'];
        }

        // A teacher sees only their own timetable and that of the class they are homeroom teacher of
        // (a grade coordinator: the classes of their grade). Users who see the whole school see everything.
        if (!(new AccessScope($this->db(), $this->auth()))->isUnrestricted()) {
            $mine = array_map('intval', $this->db()->column(
                'SELECT id FROM teachers WHERE user_id = ? AND school_id = ? AND deleted_at IS NULL',
                [$this->auth()->id(), $schoolId]
            ));
            $own = $mine === [] ? [] : array_map('intval', $this->db()->column(
                'SELECT c.id FROM classes c WHERE c.school_id = ? AND c.academic_year_id = ? AND (
                        c.homeroom_teacher_id IN (' . implode(',', $mine) . ')
                     OR c.grade_level_id IN (SELECT sp.grade_level_id FROM staff_positions sp
                                              WHERE sp.academic_year_id = c.academic_year_id AND sp.grade_level_id IS NOT NULL
                                                AND sp.teacher_id IN (' . implode(',', $mine) . ')))',
                [$schoolId, $year['id']]
            ));
            $teachers = array_intersect_key($teachers, array_flip($mine));
            $classes = array_intersect_key($classes, array_flip($own));
        }

        $view = $request->str('view') === 'teacher' ? 'teacher' : ($request->str('view') === 'class' ? 'class' : null);
        $id = $request->int('id');
        if ($view === null) {
            // A teacher first sees their own timetable.
            $own = (int) $this->db()->value('SELECT id FROM teachers WHERE user_id = ? AND school_id = ? AND deleted_at IS NULL', [$this->auth()->id(), $schoolId]);
            [$view, $id] = isset($teachers[$own]) ? ['teacher', $own] : ($classes !== [] ? ['class', (int) array_key_first($classes)] : ['teacher', (int) array_key_first($teachers)]);
        }
        $options = $view === 'teacher' ? $teachers : $classes;
        if (!isset($options[$id])) {
            if ($request->has('id')) {
                throw new HttpException(404);
            }
            $id = (int) array_key_first($options);
        }
        $id = isset($options[$id]) ? $id : 0;

        $grid = [];
        if ($id > 0) {
            foreach ($this->db()->all(
                "SELECT ts.weekday, ts.period_number, ts.teaching_assignment_id, s.name AS subject_name, c.name AS class_name, ta.group_name, ta.class_id,
                        ta.teacher_id, CONCAT(t.last_name, ' ', t.first_name) AS teacher_name, r.name AS room_name
                   FROM timetable_slots ts JOIN teaching_assignments ta ON ta.id = ts.teaching_assignment_id
                   JOIN subjects s ON s.id = ta.subject_id JOIN classes c ON c.id = ta.class_id JOIN teachers t ON t.id = ta.teacher_id
                   LEFT JOIN rooms r ON r.id = ts.room_id
                  WHERE ts.school_id = ? AND ts.academic_year_id = ? AND " . ($view === 'teacher' ? 'ta.teacher_id' : 'ta.class_id') . " = ?
                  ORDER BY s.name, t.last_name",
                [$schoolId, $year['id'], $id]
            ) as $slot) {
                $grid[(int) $slot['period_number']][(int) $slot['weekday']][] = $slot;
            }
        }
        // A teacher's lessons open this week's lesson page (to record attendance and the lesson), as on the
        // home page: a lesson already opened links to it; one that took place this week can be opened now.
        $scope = new AccessScope($this->db(), $this->auth());
        $week = [];
        if ($view === 'teacher' && $id > 0 && $grid !== []) {
            $today = date('Y-m-d');
            $sunday = date('Y-m-d', strtotime($today . ' -' . date('w') . ' days'));
            $sessions = [];
            foreach ($this->db()->all(
                "SELECT ls.id, ls.teaching_assignment_id, ls.lesson_date, ls.period_number FROM lesson_sessions ls
                   JOIN teaching_assignments ta ON ta.id = ls.teaching_assignment_id
                  WHERE ls.school_id = ? AND ta.teacher_id = ? AND ls.lesson_date BETWEEN ? AND ?",
                [$schoolId, $id, $sunday, date('Y-m-d', strtotime("$sunday +6 days"))]
            ) as $row) {
                $sessions["{$row['teaching_assignment_id']}|{$row['lesson_date']}|{$row['period_number']}"] = (int) $row['id'];
            }
            $canRecord = can('attendance.create') && !$year['is_locked'];
            foreach ($grid as $number => $days) {
                foreach ($days as $day => $slots) {
                    $date = date('Y-m-d', strtotime("$sunday +$day days"));
                    foreach ($slots as $i => $slot) {
                        $assignment = (int) $slot['teaching_assignment_id'];
                        $mine = $scope->canEditAssignment($assignment);
                        $grid[$number][$day][$i] += [
                            'date' => $date,
                            'session_id' => $mine || can('attendance.view') ? ($sessions["$assignment|$date|$number"] ?? null) : null,
                            'openable' => $mine && $canRecord && $date <= $today && $date >= $year['start_date'] && $date <= $year['end_date'],
                        ];
                    }
                }
            }
            $week = ['sunday' => $sunday, 'today' => $today];
        }

        $periods = self::periods($this->db(), $schoolId, (int) $year['id']);
        foreach (array_keys($grid) as $number) {
            $periods[$number] ??= ['period_number' => $number, 'name' => '', 'start_time' => null, 'end_time' => null, 'is_break' => 0];
        }
        ksort($periods);

        return $this->view('timetable/index', [
            'week' => $week,
            'year' => $year, 'view' => $view, 'id' => $id, 'classes' => $classes, 'teachers' => $teachers,
            'grid' => $grid, 'periods' => $periods, 'days' => self::days($this->db(), $schoolId, (int) $year['id']),
            'lastImport' => $this->db()->one(
                "SELECT ti.created_at, ti.source_name, ti.summary FROM timetable_imports ti
                  WHERE ti.school_id = ? AND ti.academic_year_id = ? ORDER BY ti.id DESC LIMIT 1",
                [$schoolId, $year['id']]
            ),
        ]);
    }

    // ---- Import ----------------------------------------------------------------

    public function importForm(Request $request): Response
    {
        $this->discard();
        $year = $this->year();
        return $this->view('timetable/import', ['plan' => null, 'year' => $year, 'history' => $this->history($year)]);
    }

    public function preview(Request $request): Response
    {
        $this->discard();
        $year = $this->year();
        if ($year['is_locked']) {
            return $this->redirect('/timetable/import', null, 'app.errors.year_locked');
        }
        $file = $request->file('file');
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $error = match (true) {
            $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($file['tmp_name']) => 'documents.errors.missing',
            !in_array($extension, ['json', 'xlsx'], true) => 'timetable.import.errors.type',
            filesize($file['tmp_name']) > TimetableImportService::MAX_BYTES => 'timetable.import.errors.too_large',
            !is_uploaded_file($file['tmp_name']) && App::config('app.env') !== 'testing' => 'documents.errors.failed',
            default => null,
        };
        if ($error !== null) {
            return $this->invalid('/timetable/import', ['file' => [__($error)]], []);
        }
        $name = 'timetable_' . bin2hex(random_bytes(16)) . '.' . $extension;
        copy($file['tmp_name'], App::root("storage/cache/$name"));
        App::session()->set(self::SESSION_KEY, [
            'file' => $name, 'school_id' => $this->schoolId(), 'year_id' => (int) $year['id'],
            'source' => mb_substr(basename((string) $file['name']), 0, 190),
        ]);
        try {
            $plan = $this->analyse($year);
        } catch (\DomainException $e) {
            $this->discard();
            return $this->invalid('/timetable/import', ['file' => [__($e->getMessage())]], []);
        }
        return $this->view('timetable/import', ['plan' => $plan, 'year' => $year, 'history' => []]);
    }

    public function confirm(Request $request): Response
    {
        $year = $this->year();
        if ($year['is_locked']) {
            $this->discard();
            return $this->redirect('/timetable/import', null, 'app.errors.year_locked');
        }
        try {
            $plan = $this->analyse($year);
        } catch (\DomainException) {
            $this->discard();
            return $this->redirect('/timetable/import', null, 'timetable.import.errors.expired');
        }
        $source = (string) (App::session()->get(self::SESSION_KEY)['source'] ?? '');
        $result = (new TimetableImportService($this->db()))->commit($plan, $this->schoolId(), $year, $this->auth()->id(), $source);
        $this->discard();
        // Shared lessons (one teacher, one subject, several classes at once) become study groups.
        $groups = SharedLessons::create($this->db(), $this->schoolId(), (int) $year['id'], $this->auth()->id(),
            SharedLessons::detect($this->db(), $this->schoolId(), (int) $year['id']), __('study_groups.shared.note'));
        $this->audit('import', 'timetable', $result['import_id'], null, ['source' => $source, 'project' => $plan['source']['name']] + $plan['counts'] + $result + ['study_groups' => $groups]);
        return $this->redirect('/timetable', __('timetable.import.done', [
            'slots' => $result['slots'], 'created' => $result['assignments_created'], 'updated' => $result['assignments_updated'],
        ]) . ($groups > 0 ? ' ' . __('study_groups.shared.after_import', ['count' => $groups]) : ''));
    }

    public function cancel(Request $request): Response
    {
        $this->discard();
        return Response::redirect('/timetable');
    }

    private function analyse(array $year): array
    {
        $pending = App::session()->get(self::SESSION_KEY);
        $path = is_array($pending) && preg_match(self::FILE_PATTERN, (string) ($pending['file'] ?? ''))
            ? App::root('storage/cache/' . $pending['file']) : null;
        // The pending file is bound to the school and year it was uploaded in.
        if ($path === null || !is_file($path) || $pending['school_id'] !== $this->schoolId() || $pending['year_id'] !== (int) $year['id']) {
            throw new \DomainException('timetable.import.errors.expired');
        }
        $locale = (string) ($this->auth()->school()['default_locale'] ?? 'he');
        if (str_ends_with($path, '.xlsx')) {
            // A grid exported by iSchool (classes as columns, days and periods as rows).
            try {
                $rows = SpreadsheetReader::read($path, 'xlsx');
            } catch (\DomainException $e) {
                throw new \DomainException($e->getMessage() === 'students.import.errors.xlsx_unavailable' ? $e->getMessage() : 'timetable.import.errors.grid_format');
            }
            $data = GridTimetableReader::toExport($rows, $locale, (string) pathinfo((string) ($pending['source'] ?? ''), PATHINFO_FILENAME));
        } else {
            $data = TimetableImportService::decode((string) file_get_contents($path));
        }
        return (new TimetableImportService($this->db()))->analyse($data, $this->schoolId(), $year, $locale);
    }

    private function discard(): void
    {
        $pending = App::session()->pull(self::SESSION_KEY);
        if (is_array($pending) && preg_match(self::FILE_PATTERN, (string) ($pending['file'] ?? ''))) {
            @unlink(App::root('storage/cache/' . $pending['file']));
        }
    }

    private function history(array $year): array
    {
        return $this->db()->all(
            "SELECT ti.id, ti.created_at, ti.source_name, ti.summary, u.full_name
               FROM timetable_imports ti LEFT JOIN users u ON u.id = ti.imported_by
              WHERE ti.school_id = ? AND ti.academic_year_id = ? ORDER BY ti.id DESC LIMIT 10",
            [$this->schoolId(), $year['id']]
        );
    }
}
