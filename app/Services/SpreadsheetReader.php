<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/**
 * Reads the first sheet of an uploaded CSV or XLSX file as plain rows of strings.
 * Values only: formulas are never evaluated and no macros are involved.
 * Failures are reported as \DomainException carrying a translation key.
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 3000;

    /** @return array<int, array<int, string>> */
    public static function read(string $path, string $extension): array
    {
        $rows = match ($extension) {
            'csv' => self::csv($path),
            'xlsx' => self::xlsx($path),
            default => throw new \DomainException('students.import.errors.type'),
        };
        // Drop completely empty rows and normalise cells.
        $clean = [];
        foreach ($rows as $row) {
            $cells = array_map(static fn ($cell) => trim((string) ($cell ?? '')), $row);
            if (implode('', $cells) !== '') {
                $clean[] = $cells;
            }
        }
        if (count($clean) < 2) {
            throw new \DomainException('students.import.errors.empty');
        }
        if (count($clean) - 1 > self::MAX_ROWS) {
            throw new \DomainException('students.import.errors.too_many');
        }
        return $clean;
    }

    private static function csv(string $path): array
    {
        $content = (string) file_get_contents($path);
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new \DomainException('students.import.errors.encoding');
        }
        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = ',';
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($firstLine, $candidate) > substr_count($firstLine, $delimiter)) {
                $delimiter = $candidate;
            }
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS + 50) {
                break;
            }
        }
        fclose($stream);
        return $rows;
    }

    private static function xlsx(string $path): array
    {
        if (!extension_loaded('zip') || !class_exists(Xlsx::class)) {
            throw new \DomainException('students.import.errors.xlsx_unavailable');
        }
        try {
            $reader = new Xlsx();
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $sheet = $reader->load($path)->getSheet(0);
            if ($sheet->getHighestDataRow() > self::MAX_ROWS + 50) {
                throw new \DomainException('students.import.errors.too_many');
            }
            // Raw cell values: no formula calculation, no number formatting.
            return $sheet->toArray(null, false, false, false);
        } catch (\DomainException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new \DomainException('students.import.errors.unreadable');
        }
    }
}
