<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ReportCards;
use App\Services\SchoolSettings;

/**
 * Term certificates (report cards), for the principal: choose the language, the term and the class,
 * then print the whole class or one student. The certificate header (school names, phones, logo,
 * principal's name) and the homeroom teacher's remarks are kept here too.
 */
final class CertificateController extends Controller
{
    private const HEADER_FIELDS = ['school_name_ar' => 150, 'school_name_he' => 150, 'phone' => 40, 'fax' => 40, 'principal_name' => 120];
    private const LOGO_MAX_BYTES = 1024 * 1024;
    private const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    private function year(): array
    {
        return $this->auth()->year() ?? throw new RedirectException('/years', 'app.errors.no_year');
    }

    private function terms(int $yearId): array
    {
        return array_column($this->db()->all(
            'SELECT id, name FROM academic_terms WHERE school_id = ? AND academic_year_id = ? ORDER BY sort_order, start_date',
            [$this->schoolId(), $yearId]
        ), 'name', 'id');
    }

    private function classes(int $yearId): array
    {
        return array_column($this->db()->all(
            'SELECT c.id, c.name FROM classes c JOIN grade_levels gl ON gl.id = c.grade_level_id
              WHERE c.school_id = ? AND c.academic_year_id = ? ORDER BY gl.level_number, c.name',
            [$this->schoolId(), $yearId]
        ), 'name', 'id');
    }

    private function students(int $classId): array
    {
        return $this->db()->all(
            'SELECT s.id, s.first_name, s.last_name FROM students s JOIN class_enrollments e ON e.student_id = s.id
              WHERE e.class_id = ? AND e.left_on IS NULL AND s.deleted_at IS NULL ORDER BY s.last_name, s.first_name',
            [$classId]
        );
    }

    /** Language, term, class and student from the request, each checked against this school and year. */
    private function selection(Request $request, array $year): array
    {
        $school = $this->auth()->school();
        $lang = $request->str('lang');
        $lang = in_array($lang, ['ar', 'he'], true) ? $lang : ((string) ($school['default_locale'] ?? 'he') === 'ar' ? 'ar' : 'he');
        $terms = $this->terms((int) $year['id']);
        $termId = $request->int('term_id');
        if (!isset($terms[$termId])) {
            $termId = (int) ($this->db()->value(
                'SELECT id FROM academic_terms WHERE school_id = ? AND academic_year_id = ? AND ? BETWEEN start_date AND end_date',
                [$this->schoolId(), $year['id'], date('Y-m-d')]
            ) ?? array_key_last($terms) ?? 0);
        }
        $classes = $this->classes((int) $year['id']);
        $classId = $request->int('class_id');
        $classId = isset($classes[$classId]) ? $classId : 0;
        $students = $classId === 0 ? [] : $this->students($classId);
        $studentId = $request->int('student_id');
        $studentId = in_array($studentId, array_map(static fn ($s) => (int) $s['id'], $students), true) ? $studentId : 0;
        return compact('lang', 'terms', 'termId', 'classes', 'classId', 'students', 'studentId');
    }

    /** The certificate header: stored settings, with the school's name and the principal as defaults. */
    private function header(): array
    {
        return self::headerFor($this->db(), $this->auth());
    }

    /** The certificate header of the current school (also printed on the portal's certificates). */
    public static function headerFor(\App\Core\Database $db, \App\Core\Auth $auth): array
    {
        $schoolId = (int) $auth->schoolId();
        $settings = SchoolSettings::all($db, $schoolId, 'certificate.');
        $header = [];
        foreach (array_keys(self::HEADER_FIELDS) as $field) {
            $header[$field] = (string) ($settings["certificate.$field"] ?? '');
        }
        $schoolName = (string) ($auth->school()['name'] ?? '');
        $header['school_name_ar'] = $header['school_name_ar'] ?: $schoolName;
        $header['school_name_he'] = $header['school_name_he'] ?: $schoolName;
        $header['principal_name'] = $header['principal_name'] ?: (string) $db->value(
            "SELECT u.full_name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
              WHERE ur.school_id = ? AND r.code = 'principal' AND u.is_active = 1 AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1",
            [$schoolId]
        );
        $header['logo'] = self::logoDataUri($settings['certificate.logo'] ?? null);
        return $header;
    }

    private static function logoPath(?string $file): ?string
    {
        return $file !== null && preg_match('/^school_\d+\.(png|jpg)$/', $file) ? App::root('storage/uploads/logos/' . $file) : null;
    }

    /** The logo is embedded in the page (img-src allows data:), so no file route is needed. */
    private static function logoDataUri(?string $file): ?string
    {
        $path = self::logoPath($file);
        if ($path === null || !is_file($path)) {
            return null;
        }
        return 'data:' . (str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg') . ';base64,' . base64_encode((string) file_get_contents($path));
    }

    public function index(Request $request): Response
    {
        $year = $this->year();
        $selection = $this->selection($request, $year);
        $published = $selection['termId'] !== 0 && $selection['classId'] !== 0
            ? $this->db()->value('SELECT published_at FROM certificate_publications WHERE term_id = ? AND class_id = ?', [$selection['termId'], $selection['classId']])
            : null;
        return $this->view('certificates/index', $selection + ['year' => $year, 'header' => $this->header(), 'publishedAt' => $published]);
    }

    /** The certificates themselves, in the chosen language, one A4 page each. */
    public function print(Request $request): Response
    {
        $year = $this->year();
        $s = $this->selection($request, $year);
        if ($s['termId'] === 0 || $s['classId'] === 0) {
            return $this->redirect('/certificates', null, 'certificates.errors.choose');
        }
        $ids = $s['studentId'] !== 0 ? [$s['studentId']] : array_map(static fn ($st) => (int) $st['id'], $s['students']);
        $data = ReportCards::build($this->db(), $this->schoolId(), $year, $s['termId'], $s['classId'], $ids);
        $this->audit('print', 'certificate', $s['classId'], null, ['term_id' => $s['termId'], 'students' => count($data['cards']), 'lang' => $s['lang']]);
        // The page is written in the certificate's language, whatever the interface language is.
        App::lang()->setLocale($s['lang']);
        return $this->view('certificates/print', $data + [
            'year' => $year, 'termId' => $s['termId'], 'header' => $this->header(), 'lang' => $s['lang'],
        ], 'layouts/print');
    }

    public function remarks(Request $request): Response
    {
        $year = $this->year();
        $s = $this->selection($request, $year);
        if ($s['termId'] === 0 || $s['classId'] === 0) {
            return $this->redirect('/certificates', null, 'certificates.errors.choose');
        }
        $remarks = $s['students'] === [] ? [] : array_column($this->db()->all(
            'SELECT student_id, remark FROM report_card_remarks WHERE remark IS NOT NULL AND term_id = ? AND student_id IN (' . implode(',', array_map(static fn ($st) => (int) $st['id'], $s['students'])) . ')',
            [$s['termId']]
        ), 'remark', 'student_id');
        return $this->view('certificates/remarks', $s + ['year' => $year, 'remarks' => $remarks, 'locked' => (bool) $year['is_locked']]);
    }

    public function saveRemarks(Request $request): Response
    {
        $year = $this->year();
        $s = $this->selection($request, $year);
        if ($s['termId'] === 0 || $s['classId'] === 0 || $year['is_locked']) {
            throw new HttpException(403);
        }
        $input = $request->input('remarks', []);
        $input = is_array($input) ? $input : [];
        $saved = 0;
        $this->db()->transaction(function () use ($s, $input, &$saved): void {
            foreach ($s['students'] as $student) {
                $id = (int) $student['id'];
                if (!array_key_exists($id, $input) || !is_scalar($input[$id])) {
                    continue;
                }
                $text = mb_substr(trim((string) $input[$id]), 0, 600);
                if (ReportCards::saveRecord($this->db(), $this->schoolId(), $id, $s['termId'], ['remark' => $text === '' ? null : $text], $this->auth()->id()) && $text !== '') {
                    $saved++;
                }
            }
        });
        $this->audit('update', 'report_card_remarks', $s['classId'], null, ['term_id' => $s['termId'], 'remarks' => $saved]);
        return $this->redirect("/certificates/remarks?lang={$s['lang']}&term_id={$s['termId']}&class_id={$s['classId']}", __('certificates.remarks_saved', ['count' => $saved]));
    }

    /** Publishes (or withdraws) the certificates of a class for a term: its students and parents see them in the portal. */
    public function publish(Request $request): Response
    {
        $year = $this->year();
        $s = $this->selection($request, $year);
        if ($s['termId'] === 0 || $s['classId'] === 0) {
            return $this->redirect('/certificates', null, 'certificates.errors.choose');
        }
        $back = '/certificates?' . http_build_query(['lang' => $s['lang'], 'term_id' => $s['termId'], 'class_id' => $s['classId']]);
        if ($request->str('withdraw') === '1') {
            $this->db()->run('DELETE FROM certificate_publications WHERE term_id = ? AND class_id = ?', [$s['termId'], $s['classId']]);
            $this->audit('unpublish', 'certificate', $s['classId'], null, ['term_id' => $s['termId']]);
            return $this->redirect($back, 'certificates.withdrawn');
        }
        $this->db()->run(
            'INSERT IGNORE INTO certificate_publications (school_id, term_id, class_id, published_by) VALUES (?, ?, ?, ?)',
            [$this->schoolId(), $s['termId'], $s['classId'], $this->auth()->id()]
        );
        $this->audit('publish', 'certificate', $s['classId'], null, ['term_id' => $s['termId']]);
        return $this->redirect($back, 'certificates.published');
    }

    /** Header of the certificates: school names, phones, principal's name and the logo. */
    public function saveHeader(Request $request): Response
    {
        $errors = [];
        $values = [];
        foreach (self::HEADER_FIELDS as $field => $max) {
            $value = $request->str($field);
            if (mb_strlen($value) > $max) {
                $errors[$field] = [__('certificates.errors.too_long', ['max' => $max])];
            }
            $values[$field] = $value;
        }

        $logo = null;
        $file = $request->file('logo');
        if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $mime = ($file['error'] ?? 1) === UPLOAD_ERR_OK && is_file($file['tmp_name']) ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : '';
            if (!isset(self::LOGO_TYPES[$mime]) || @getimagesize($file['tmp_name']) === false) {
                $errors['logo'] = [__('certificates.errors.logo_type')];
            } elseif (filesize($file['tmp_name']) > self::LOGO_MAX_BYTES) {
                $errors['logo'] = [__('certificates.errors.logo_size')];
            } elseif (!is_uploaded_file($file['tmp_name']) && App::config('app.env') !== 'testing') {
                $errors['logo'] = [__('documents.errors.failed')];
            } else {
                $logo = ['tmp' => $file['tmp_name'], 'ext' => self::LOGO_TYPES[$mime]];
            }
        }
        if ($errors !== []) {
            return $this->invalid('/certificates#header', $errors, $request->post);
        }

        foreach ($values as $field => $value) {
            SchoolSettings::set($this->db(), $this->schoolId(), "certificate.$field", $value);
        }
        $current = SchoolSettings::all($this->db(), $this->schoolId(), 'certificate.logo')['certificate.logo'] ?? null;
        if ($logo !== null || $request->str('remove_logo') === '1') {
            $old = self::logoPath($current);
            if ($old !== null && is_file($old)) {
                @unlink($old);
            }
            $name = null;
            if ($logo !== null) {
                $dir = App::root('storage/uploads/logos');
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $name = 'school_' . $this->schoolId() . '.' . $logo['ext'];
                copy($logo['tmp'], "$dir/$name");
            }
            SchoolSettings::set($this->db(), $this->schoolId(), 'certificate.logo', $name);
        }
        $this->audit('update', 'certificate_header', null, null, $values + ['logo' => $logo !== null ? 'uploaded' : ($request->str('remove_logo') === '1' ? 'removed' : 'kept')]);
        return $this->redirect('/certificates', 'certificates.header_saved');
    }
}
