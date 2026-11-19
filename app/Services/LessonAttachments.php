<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;

/**
 * Files and links attached to a lesson's homework.
 *
 * A study group's lesson has one lesson session per class, all recorded together, so an attachment is
 * copied to each of them under one share_key: every student then finds it on their own class's lesson.
 * A file is stored once (outside the web root, under a random name) and removed from the disk when the
 * last row that points to it is gone. Files are always served as a download, after an access check
 * (LessonAttachmentController), never from a public address.
 */
final class LessonAttachments
{
    public const MAX_FILES = 5;
    public const MAX_LINKS = 5;

    public static function dir(): string
    {
        return App::root('storage/uploads/lessons');
    }

    /** @return array<int, list<array>> lesson session id => its attachments, oldest first */
    public static function forSessions(Database $db, array $sessionIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $sessionIds)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($db->all(
            'SELECT id, lesson_session_id, share_key, kind, title, url, original_name, size_bytes
               FROM lesson_attachments WHERE lesson_session_id IN (' . implode(',', $ids) . ') ORDER BY id'
        ) as $row) {
            $out[(int) $row['lesson_session_id']][] = $row;
        }
        return $out;
    }

    /** A link a teacher may give: http(s) only, with a host. Returns the cleaned address or null. */
    public static function cleanUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === '' || mb_strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        return $url;
    }

    /** Adds a link to each of the lessons taught together. */
    public static function addLink(Database $db, int $schoolId, array $sessionIds, string $url, string $title, ?int $userId): void
    {
        $key = bin2hex(random_bytes(8));
        foreach ($sessionIds as $sessionId) {
            $db->insert('lesson_attachments', [
                'school_id' => $schoolId, 'lesson_session_id' => (int) $sessionId, 'share_key' => $key, 'kind' => 'link',
                'title' => mb_substr($title !== '' ? $title : (string) parse_url($url, PHP_URL_HOST), 0, 190), 'url' => $url,
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * Stores an uploaded file once and attaches it to each of the lessons taught together.
     * The file was already checked (DocumentService::inspect): [extension, detected MIME].
     */
    public static function addFile(Database $db, int $schoolId, array $sessionIds, array $file, string $mime, ?int $userId): string
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Upload directory is not writable.');
        }
        $stored = bin2hex(random_bytes(20));
        $target = $dir . '/' . $stored;
        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $target)
            : App::config('app.env') === 'testing' && copy($file['tmp_name'], $target);   // only the tests upload without HTTP
        if (!$moved) {
            throw new \RuntimeException('Could not store uploaded file.');
        }
        $original = mb_substr(basename(str_replace('\\', '/', (string) $file['name'])), -255);
        $key = bin2hex(random_bytes(8));
        foreach ($sessionIds as $sessionId) {
            $db->insert('lesson_attachments', [
                'school_id' => $schoolId, 'lesson_session_id' => (int) $sessionId, 'share_key' => $key, 'kind' => 'file',
                'title' => mb_substr((string) pathinfo($original, PATHINFO_FILENAME), 0, 190) ?: $original,
                'stored_name' => $stored, 'original_name' => $original, 'mime_type' => $mime,
                'size_bytes' => (int) filesize($target), 'created_by' => $userId,
            ]);
        }
        return $target;
    }

    /**
     * Removes the given attachments of these lessons (with their copies on the lessons taught together).
     * Returns the stored files they pointed to: the caller passes them to forgetFile() once the change
     * is committed, so a rolled-back save never loses a file.
     *
     * @return string[]
     */
    public static function remove(Database $db, array $sessionIds, array $attachmentIds): array
    {
        $sessions = implode(',', array_map('intval', $sessionIds) ?: [0]);
        $ids = array_values(array_filter(array_map('intval', $attachmentIds)));
        if ($ids === []) {
            return [];
        }
        $files = [];
        foreach ($db->all(
            "SELECT DISTINCT share_key, stored_name FROM lesson_attachments
              WHERE id IN (" . implode(',', $ids) . ") AND lesson_session_id IN ($sessions)"
        ) as $row) {
            $db->run("DELETE FROM lesson_attachments WHERE share_key = ? AND lesson_session_id IN ($sessions)", [$row['share_key']]);
            if ($row['stored_name'] !== null) {
                $files[] = $row['stored_name'];
            }
        }
        return $files;
    }

    /** Deletes a stored file from the disk once no attachment points to it. */
    public static function forgetFile(Database $db, ?string $stored): void
    {
        if ($stored === null || !preg_match('/^[a-f0-9]{40}$/', $stored)) {
            return;
        }
        if ($db->value('SELECT 1 FROM lesson_attachments WHERE stored_name = ? LIMIT 1', [$stored]) === null) {
            @unlink(self::dir() . '/' . $stored);
        }
    }

    public static function path(array $attachment): ?string
    {
        $stored = (string) ($attachment['stored_name'] ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/', $stored)) {
            return null;
        }
        $path = self::dir() . '/' . $stored;
        return is_file($path) ? $path : null;
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
