<?php

declare(strict_types=1);

namespace App\Reports;

use App\Core\App;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Turns a report table into an Excel or PDF file under storage/exports and returns its path. */
final class Exporter
{
    private static function target(string $extension): string
    {
        $dir = App::root('storage/exports');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        // Remove leftovers of earlier downloads (files are sent and no longer needed).
        foreach (glob("$dir/report_*") ?: [] as $old) {
            if (filemtime($old) < time() - 600) {
                @unlink($old);
            }
        }
        return $dir . '/report_' . bin2hex(random_bytes(12)) . '.' . $extension;
    }

    /**
     * Text is always written as an explicit string cell, so a value such as
     * "=HYPERLINK(...)" entered somewhere in the system can never run as a formula.
     */
    public static function excel(string $title, array $meta, array $table): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle(mb_substr(preg_replace('/[\[\]\*\/\\\\\?:]/u', ' ', $title), 0, 31));

        $sheet->setCellValueExplicit([1, 1], $title, DataType::TYPE_STRING);
        $sheet->getStyle([1, 1])->getFont()->setBold(true)->setSize(14);
        $line = 2;
        foreach ($meta as $text) {
            $sheet->setCellValueExplicit([1, $line++], $text, DataType::TYPE_STRING);
        }
        $line++;
        foreach ($table['columns'] as $i => $column) {
            $sheet->setCellValueExplicit([$i + 1, $line], $column, DataType::TYPE_STRING);
        }
        $sheet->getStyle([1, $line, max(1, count($table['columns'])), $line])->getFont()->setBold(true);
        $sheet->freezePane([1, $line + 1]);
        foreach ($table['rows'] as $row) {
            $line++;
            foreach (array_values($row) as $i => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_int($value) || is_float($value)) {
                    $sheet->setCellValueExplicit([$i + 1, $line], $value, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicit([$i + 1, $line], (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        $line += 2;
        foreach ($table['notes'] as $note) {
            $sheet->setCellValueExplicit([1, $line++], $note, DataType::TYPE_STRING);
        }
        foreach (range(1, max(1, count($table['columns']))) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $path = self::target('xlsx');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        return $path;
    }

    /** $html is produced by the reports/pdf view, where every value is escaped. */
    public static function pdf(string $html): string
    {
        $temp = App::root('storage/cache/mpdf');
        if (!is_dir($temp)) {
            mkdir($temp, 0750, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => $temp, 'default_font' => 'dejavusans',
            'margin_top' => 14, 'margin_bottom' => 14, 'margin_left' => 12, 'margin_right' => 12,
            'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->setFooter('{PAGENO} / {nbpg}');
        $mpdf->WriteHTML($html);
        $path = self::target('pdf');
        $mpdf->Output($path, 'F');
        return $path;
    }
}
