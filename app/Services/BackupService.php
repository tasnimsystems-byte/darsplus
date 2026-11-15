<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * Encrypted backups of the database and of the uploaded files.
 *
 * File format: "SPMS1" · 16-byte salt · 12-byte nonce · 16-byte tag · ciphertext
 * Cipher: AES-256-GCM (authenticated — a modified or truncated file fails to open).
 * Key: PBKDF2-SHA256 of BACKUP_KEY with the file's own salt.
 *
 * The backup is built in memory, which suits a school-sized database (tens of
 * megabytes). The key is never written anywhere by the application: whoever
 * loses BACKUP_KEY loses the backups.
 */
final class BackupService
{
    private const MAGIC = 'SPMS1';
    private const ITERATIONS = 200_000;

    public static function encrypt(string $plain, string $passphrase): string
    {
        self::assertPassphrase($passphrase);
        $salt = random_bytes(16);
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key($passphrase, $salt), OPENSSL_RAW_DATA, $nonce, $tag, self::MAGIC);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return self::MAGIC . $salt . $nonce . $tag . $cipher;
    }

    public static function decrypt(string $blob, string $passphrase): string
    {
        if (strlen($blob) < 49 || !str_starts_with($blob, self::MAGIC)) {
            throw new \RuntimeException('Not an SPMS backup file.');
        }
        $plain = openssl_decrypt(
            substr($blob, 49),
            'aes-256-gcm',
            self::key($passphrase, substr($blob, 5, 16)),
            OPENSSL_RAW_DATA,
            substr($blob, 21, 12),
            substr($blob, 33, 16),
            self::MAGIC
        );
        if ($plain === false) {
            throw new \RuntimeException('Wrong key, or the backup file is damaged.');
        }
        return $plain;
    }

    private static function key(string $passphrase, string $salt): string
    {
        return hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
    }

    private static function assertPassphrase(string $passphrase): void
    {
        if (strlen($passphrase) < 24) {
            throw new \RuntimeException('BACKUP_KEY must be set in .env and be at least 24 characters long.');
        }
    }

    /** Plain SQL dump of the configured database, produced by mysqldump. */
    public static function dumpDatabase(): string
    {
        $db = App::config('database');
        $command = [
            (string) env('MYSQLDUMP_PATH', 'mysqldump'), '--single-transaction', '--routines', '--no-tablespaces', '--hex-blob',
            '--default-character-set=utf8mb4', '--host=' . $db['host'], '--port=' . $db['port'], '--user=' . $db['username'], $db['database'],
        ];
        return self::run($command, $db['password'], null);
    }

    public static function restoreDatabase(string $sql): void
    {
        $db = App::config('database');
        self::run([
            (string) env('MYSQL_PATH', 'mysql'), '--default-character-set=utf8mb4',
            '--host=' . $db['host'], '--port=' . $db['port'], '--user=' . $db['username'], $db['database'],
        ], $db['password'], $sql);
    }

    /** The password travels in the child's environment, never on the command line (where other users could see it). */
    private static function run(array $command, string $password, ?string $stdin): string
    {
        $environment = getenv();
        $environment['MYSQL_PWD'] = $password;
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException("Could not start {$command[0]}. Set MYSQLDUMP_PATH / MYSQL_PATH in .env.");
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException(trim($command[0] . ' failed: ' . $errors));
        }
        return (string) $output;
    }

    /**
     * Writes an encrypted database backup and, when files exist, an encrypted archive of the uploads.
     *
     * @return string[] paths of the files written
     */
    public static function create(string $passphrase): array
    {
        self::assertPassphrase($passphrase);
        $dir = App::root('storage/backups');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $stamp = date('Ymd-His');
        $written = [];

        $path = "$dir/spms-$stamp.db.enc";
        file_put_contents($path, self::encrypt(gzencode(self::dumpDatabase(), 6), $passphrase));
        @chmod($path, 0600);
        $written[] = $path;

        $uploads = App::root('storage/uploads');
        $files = array_filter(glob("$uploads/*") ?: [], 'is_file');
        if ($files !== []) {
            $tar = App::root("storage/cache/uploads-$stamp.tar");
            $archive = new \PharData($tar);
            foreach ($files as $file) {
                $archive->addFile($file, basename($file));
            }
            unset($archive);
            $path = "$dir/spms-$stamp.files.enc";
            file_put_contents($path, self::encrypt(gzencode((string) file_get_contents($tar), 6), $passphrase));
            @chmod($path, 0600);
            unlink($tar);
            $written[] = $path;
        }
        return $written;
    }

    /** Deletes backups older than the given number of days. */
    public static function prune(int $days): int
    {
        $removed = 0;
        foreach (glob(App::root('storage/backups') . '/spms-*.enc') ?: [] as $file) {
            if (filemtime($file) < time() - $days * 86400 && unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }
}
