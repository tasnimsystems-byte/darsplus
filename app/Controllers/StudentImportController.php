<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AccessScope;
use App\Services\SpreadsheetReader;
use App\Services\StudentImportService;

/**
 * Upload → preview (nothing saved) → confirm. The uploaded file is kept under a
 * random name in storage/cache only between the two steps and is then deleted.
 */
final class StudentImportController extends Controller
{
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const SESSION_KEY = 'student_import';

    public function form(Request $request): Response
    {
        $this->discard();
        return $this->view('students/import', ['analysis' => null, 'year' => $this->auth()->year()]);
    }

    public function preview(Request $request): Response
    {
        $this->discard();
        $file = $request->file('file');
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $error = match (true) {
            $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($file['tmp_name']) => 'documents.errors.missing',
            !in_array($extension, ['csv', 'xlsx'], true) => 'students.import.errors.type',
            filesize($file['tmp_name']) > self::MAX_BYTES => 'students.import.errors.too_large',
            !is_uploaded_file($file['tmp_name']) && App::config('app.env') !== 'testing' => 'documents.errors.failed',
            default => null,
        };
        if ($error !== null) {
            return $this->invalid('/students/import', ['file' => [__($error)]], []);
        }

        $name = 'import_' . bin2hex(random_bytes(16)) . '.' . $extension;
        copy($file['tmp_name'], App::root("storage/cache/$name"));
        App::session()->set(self::SESSION_KEY, ['file' => $name, 'school_id' => $this->schoolId()]);

        try {
            $analysis = $this->analyse();
        } catch (\DomainException $e) {
            $this->discard();
            return $this->invalid('/students/import', ['file' => [__($e->getMessage(), ['max' => SpreadsheetReader::MAX_ROWS])]], []);
        }
        return $this->view('students/import', ['analysis' => $analysis, 'year' => $this->auth()->year()]);
    }

    public function confirm(Request $request): Response
    {
        try {
            $analysis = $this->analyse();
        } catch (\DomainException) {
            $this->discard();
            return $this->redirect('/students/import', null, 'students.import.errors.expired');
        }
        $year = $this->auth()->year();
        $created = (new StudentImportService($this->db()))->commit(
            $analysis,
            $this->schoolId(),
            $year !== null && !$year['is_locked'] ? $year : null,
            $this->auth()->id()
        );
        $this->discard();
        $this->audit('import', 'student', null, null, $analysis['counts'] + ['created' => $created]);
        return $this->redirect('/students', __('students.import.done', ['count' => $created]));
    }

    public function cancel(Request $request): Response
    {
        $this->discard();
        return Response::redirect('/students');
    }

    private function analyse(): array
    {
        $pending = App::session()->get(self::SESSION_KEY);
        $path = is_array($pending) && preg_match('/^import_[a-f0-9]{32}\.(csv|xlsx)$/', (string) ($pending['file'] ?? ''))
            ? App::root('storage/cache/' . $pending['file']) : null;
        // The pending file is bound to the school it was uploaded in.
        if ($path === null || !is_file($path) || $pending['school_id'] !== $this->schoolId()) {
            throw new \DomainException('students.import.errors.expired');
        }
        $schoolId = $this->schoolId();
        $yearId = (int) ($this->auth()->year()['id'] ?? 0);
        [$condition, $params] = (new AccessScope($this->db(), $this->auth()))->classCondition('c.id');
        $classes = [];
        foreach ($this->db()->all(
            "SELECT c.id, c.name FROM classes c WHERE c.school_id = ? AND c.academic_year_id = ? AND $condition",
            [$schoolId, $yearId, ...$params]
        ) as $class) {
            $classes[(int) $class['id']] = $class['name'];
        }
        return (new StudentImportService($this->db()))->analyse(
            SpreadsheetReader::read($path, pathinfo($path, PATHINFO_EXTENSION)),
            $schoolId,
            $classes
        );
    }

    private function discard(): void
    {
        $pending = App::session()->pull(self::SESSION_KEY);
        if (is_array($pending) && preg_match('/^import_[a-f0-9]{32}\.(csv|xlsx)$/', (string) ($pending['file'] ?? ''))) {
            @unlink(App::root('storage/cache/' . $pending['file']));
        }
    }
}
