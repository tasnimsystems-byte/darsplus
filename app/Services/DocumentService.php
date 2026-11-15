<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Uploaded files live outside the web root under a random name and are served
 * only through DocumentController after an access check.
 *
 * Visibility:
 *   staff      – anyone in the school with documents.view
 *   restricted – the uploader, plus whoever may view an entity the document is
 *                linked to (each module registers a checker for its entity type).
 *                A link whose entity type has no checker grants nothing.
 */
final class DocumentService
{
    /** @var array<string, callable(int $entityId, Auth $auth): bool> */
    private static array $entityCheckers = [];

    public function __construct(private Database $db, private Auth $auth, private string $storageDir)
    {
        // A work-plan goal is visible to everyone in the school who may view the work plan.
        self::$entityCheckers['goal'] ??= static fn (int $goalId, Auth $auth): bool => $auth->can('work_plan.view')
            && App::db()->value('SELECT 1 FROM annual_goals WHERE id = ? AND school_id = ? AND deleted_at IS NULL', [$goalId, $auth->schoolId()]) !== null;
    }

    /** May the current user attach documents to (and so see) this record? Unknown record types are refused. */
    public function canAccessEntity(string $entityType, int $entityId): bool
    {
        $checker = self::$entityCheckers[$entityType] ?? null;
        return $checker !== null && $checker($entityId, $this->auth);
    }

    /** Documents linked to one record that the current user may open. */
    public function forEntity(string $entityType, int $entityId, int $schoolId): array
    {
        $rows = $this->db->all(
            'SELECT d.* FROM documents d JOIN document_links l ON l.document_id = d.id
              WHERE l.entity_type = ? AND l.entity_id = ? AND d.school_id = ? AND d.deleted_at IS NULL ORDER BY d.id DESC',
            [$entityType, $entityId, $schoolId]
        );
        return array_values(array_filter($rows, fn ($row) => $this->canView($row)));
    }

    public static function registerEntityChecker(string $entityType, callable $checker): void
    {
        self::$entityCheckers[$entityType] = $checker;
    }

    /**
     * Validates an uploaded file. Returns [extension, detected MIME] or a translation key describing the problem.
     *
     * @return array{0: string, 1: string}|string
     */
    public function inspect(?array $file): array|string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'documents.errors.missing';
        }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return 'documents.errors.too_large';
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_file($file['tmp_name'])) {
            return 'documents.errors.failed';
        }
        $size = filesize($file['tmp_name']);
        if ($size === false || $size === 0) {
            return 'documents.errors.failed';
        }
        if ($size > (int) App::config('upload.max_bytes')) {
            return 'documents.errors.too_large';
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $allowed = App::config('upload.allowed');
        if (!isset($allowed[$extension])) {
            return 'documents.errors.type';
        }
        // The browser-supplied type is ignored; the content itself must match the extension.
        // Reading can fail when security software has already locked or removed the file.
        $mime = @(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if ($mime === false) {
            return 'documents.errors.failed';
        }
        if (!in_array($mime, $allowed[$extension], true)) {
            return 'documents.errors.type';
        }
        return [$extension, $mime];
    }

    public function store(array $file, string $extension, string $mime, array $meta, int $schoolId): int
    {
        $storedName = bin2hex(random_bytes(20));
        $target = $this->storageDir . '/' . $storedName;
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0750, true) && !is_dir($this->storageDir)) {
            throw new \RuntimeException('Upload directory is not writable.');
        }
        if (!$this->moveFile($file['tmp_name'], $target)) {
            throw new \RuntimeException('Could not store uploaded file.');
        }

        $original = mb_substr(basename(str_replace('\\', '/', (string) $file['name'])), -255);
        try {
            return $this->db->transaction(function () use ($target, $storedName, $original, $mime, $meta, $schoolId): int {
                $id = $this->db->insert('documents', [
                    'school_id' => $schoolId,
                    'title' => $meta['title'],
                    'original_name' => $original,
                    'stored_name' => $storedName,
                    'mime_type' => $mime,
                    'size_bytes' => (int) filesize($target),
                    'sha256' => hash_file('sha256', $target),
                    'category' => $meta['category'],
                    'visibility' => $meta['visibility'],
                    'uploaded_by' => $this->auth->id(),
                ]);
                AuditLogger::log('create', 'document', $id, null, [
                    'title' => $meta['title'], 'original_name' => $original, 'visibility' => $meta['visibility'],
                ]);
                return $id;
            });
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }
    }

    /** Documents of the school that the current user may open. */
    public function listVisible(int $schoolId): array
    {
        $rows = $this->db->all(
            'SELECT d.*, u.full_name AS uploader_name
               FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by
              WHERE d.school_id = ? AND d.deleted_at IS NULL
              ORDER BY d.created_at DESC, d.id DESC',
            [$schoolId]
        );
        return array_values(array_filter($rows, fn ($row) => $this->canView($row)));
    }

    /** The document if it belongs to the active school and the user may open it; 404 otherwise. */
    public function findViewable(int $id, int $schoolId): array
    {
        $document = $this->db->one(
            'SELECT * FROM documents WHERE id = ? AND school_id = ? AND deleted_at IS NULL',
            [$id, $schoolId]
        );
        if ($document === null || !$this->canView($document)) {
            throw new HttpException(404);
        }
        return $document;
    }

    public function canView(array $document): bool
    {
        if ($document['visibility'] === 'staff' || (int) $document['uploaded_by'] === $this->auth->id()) {
            return true;
        }
        foreach ($this->db->all(
            'SELECT entity_type, entity_id FROM document_links WHERE document_id = ?',
            [$document['id']]
        ) as $link) {
            $checker = self::$entityCheckers[$link['entity_type']] ?? null;
            if ($checker !== null && $checker((int) $link['entity_id'], $this->auth)) {
                return true;
            }
        }
        return false;
    }

    /** Uploaders delete their own documents; others need documents.delete and view access. */
    public function canDelete(array $document): bool
    {
        return (int) $document['uploaded_by'] === $this->auth->id()
            || ($this->auth->can('documents.delete') && $this->canView($document));
    }

    public function path(array $document): string
    {
        if (!preg_match('/^[a-f0-9]{40}$/', (string) $document['stored_name'])) {
            throw new HttpException(404);
        }
        $path = $this->storageDir . '/' . $document['stored_name'];
        if (!is_file($path)) {
            throw new HttpException(404);
        }
        return $path;
    }

    public function delete(array $document): void
    {
        $this->db->update('documents', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $document['id']]);
        AuditLogger::log('delete', 'document', (int) $document['id'], ['title' => $document['title']], null);
    }

    public function link(int $documentId, string $entityType, int $entityId): void
    {
        $this->db->run(
            'INSERT IGNORE INTO document_links (document_id, entity_type, entity_id) VALUES (?, ?, ?)',
            [$documentId, $entityType, $entityId]
        );
    }

    private function moveFile(string $from, string $to): bool
    {
        if (is_uploaded_file($from)) {
            return move_uploaded_file($from, $to);
        }
        // Non-HTTP uploads exist only in the automated tests.
        return App::config('app.env') === 'testing' && copy($from, $to);
    }
}
