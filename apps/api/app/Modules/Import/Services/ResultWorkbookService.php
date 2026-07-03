<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ResultWorkbookService
{
    /**
     * Generate an XLSX result workbook and return its absolute temporary path.
     */
    public function generate(ImportJob $job): string
    {
        $spreadsheet = new Spreadsheet;

        $importedRows = $job->rows()
            ->where('is_valid', true)
            ->whereNull('import_error')
            ->get();
        $rejectedRows = $job->rows()
            ->where(function ($query): void {
                $query->where('is_valid', false)
                    ->orWhereNotNull('import_error');
            })
            ->get();

        $importedSheet = $spreadsheet->getActiveSheet();
        $importedSheet->setTitle('Imported');
        $this->writeRows($importedSheet, array_values($importedRows->all()), false);

        $rejectedSheet = new Worksheet($spreadsheet, 'Rejected');
        $spreadsheet->addSheet($rejectedSheet);
        $this->writeRows($rejectedSheet, array_values($rejectedRows->all()), true);

        $path = tempnam(sys_get_temp_dir(), 'import-result-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create temporary workbook file.');
        }

        $xlsxPath = $path.'.xlsx';
        rename($path, $xlsxPath);

        (new Xlsx($spreadsheet))->save($xlsxPath);
        $spreadsheet->disconnectWorksheets();

        return $xlsxPath;
    }

    /**
     * @param  list<ImportRow>  $rows
     */
    private function writeRows(Worksheet $sheet, array $rows, bool $includeReasons): void
    {
        $headers = $this->headersForRows($rows);
        $headers[] = 'warnings';
        if ($includeReasons) {
            $headers[] = 'reasons';
        }

        foreach ($headers as $columnIndex => $header) {
            $sheet->setCellValue([$columnIndex + 1, 1], $header);
        }

        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 2;
            foreach ($headers as $columnIndex => $header) {
                $value = match ($header) {
                    'warnings' => $this->formatWarnings($row),
                    'reasons' => $this->formatReasons($row),
                    default => $this->formatValue($row->data[$header] ?? null),
                };

                $sheet->setCellValue([$columnIndex + 1, $excelRow], $value);
            }
        }

        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }
    }

    /**
     * @param  list<ImportRow>  $rows
     * @return list<string>
     */
    private function headersForRows(array $rows): array
    {
        $headers = [];

        foreach ($rows as $row) {
            foreach (array_keys($row->data) as $header) {
                if (! in_array($header, $headers, true)) {
                    $headers[] = $header;
                }
            }
        }

        return $headers;
    }

    private function formatWarnings(ImportRow $row): string
    {
        $warnings = $row->warnings ?? [];

        return implode('; ', array_map(
            static fn (array $warning): string => sprintf(
                '%s: %s',
                $warning['code'],
                $warning['detail']
            ),
            $warnings
        ));
    }

    private function formatReasons(ImportRow $row): string
    {
        $messages = $row->getErrorMessages();
        if ($row->import_error !== null) {
            $messages[] = 'import: '.$row->import_error;
        }

        return implode('; ', $messages);
    }

    private function formatValue(null|bool|int|float|string $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
