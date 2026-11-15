<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BackupService;
use PHPUnit\Framework\TestCase;

final class BackupTest extends TestCase
{
    private const KEY = 'a-long-random-backup-key-for-tests-0123456789';

    public function testRoundTripAndEveryFileIsDifferent(): void
    {
        $plain = str_repeat("INSERT INTO students VALUES ('תלמיד', 'طالب');\n", 500);
        $a = BackupService::encrypt($plain, self::KEY);
        $b = BackupService::encrypt($plain, self::KEY);

        self::assertSame($plain, BackupService::decrypt($a, self::KEY));
        self::assertNotSame($a, $b, 'A fresh salt and nonce for every backup.');
        self::assertStringNotContainsString('students', $a, 'Nothing readable is left in the file.');
        self::assertStringStartsWith('SPMS1', $a);
    }

    public function testWrongKeyAndTamperingAreDetected(): void
    {
        $blob = BackupService::encrypt('secret data', self::KEY);
        $cases = [
            'wrong key' => [$blob, self::KEY . 'x'],
            'flipped bit' => [substr($blob, 0, -1) . chr(ord($blob[-1]) ^ 1), self::KEY],
            'truncated' => [substr($blob, 0, -3), self::KEY],
            'not a backup' => [str_repeat('x', 100), self::KEY],
        ];
        foreach ($cases as $name => [$data, $key]) {
            try {
                BackupService::decrypt($data, $key);
                self::fail("$name was accepted");
            } catch (\RuntimeException $e) {
                self::assertNotSame('', $e->getMessage(), $name);
            }
        }
    }

    public function testShortKeyIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        BackupService::encrypt('data', 'too-short');
    }

    public function testDatabaseDumpContainsTheSchema(): void
    {
        $candidates = array_filter([(string) env('MYSQLDUMP_PATH', ''), 'C:/xampp/mysql/bin/mysqldump.exe', '/usr/bin/mysqldump'], 'is_file');
        if ($candidates === []) {
            self::markTestSkipped('mysqldump was not found; set MYSQLDUMP_PATH to test the database dump.');
        }
        $_ENV['MYSQLDUMP_PATH'] = reset($candidates);
        try {
            $dump = BackupService::dumpDatabase();
            self::assertStringContainsString('CREATE TABLE `users`', $dump);
            self::assertStringContainsString('CREATE TABLE `assessment_results`', $dump);

            // The same path the console command takes: encrypted file on disk, then verified.
            $files = BackupService::create(self::KEY);
            self::assertStringEndsWith('.db.enc', $files[0]);
            $restored = gzdecode(BackupService::decrypt((string) file_get_contents($files[0]), self::KEY));
            self::assertStringContainsString('CREATE TABLE `users`', (string) $restored);
            self::assertStringNotContainsString('CREATE TABLE', (string) file_get_contents($files[0]));
        } finally {
            foreach ($files ?? [] as $file) {
                @unlink($file);
            }
            unset($_ENV['MYSQLDUMP_PATH']);
        }
    }
}
