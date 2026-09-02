<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
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
            ->whereIn('outcome', [ImportRowOutcome::Imported, ImportRowOutcome::MergedLine])
            ->get();
        $skippedRows = $job->rows()
            ->where(function ($query): void {
                $query->whereIn('outcome', [ImportRowOutcome::DuplicateSkipped, ImportRowOutcome::DuplicateLoser])
                    ->orWhere(function ($pending): void {
                        $pending->where('is_valid', true)
                            ->where('outcome', ImportRowOutcome::Pending);
                    });
            })
            ->get();
        $rejectedRows = $job->rows()
            ->where(function ($query): void {
                $query->where('is_valid', false)
                    ->orWhereIn('outcome', [ImportRowOutcome::Failed, ImportRowOutcome::OpeningLocked]);
            })
            ->get();

        $importedSheet = $spreadsheet->getActiveSheet();
        $importedSheet->setTitle('Imported');
        $this->writeRows($importedSheet, array_values($importedRows->all()), false);

        $skippedSheet = new Worksheet($spreadsheet, 'Skipped');
        $spreadsheet->addSheet($skippedSheet);
        $this->writeRows($skippedSheet, array_values($skippedRows->all()), true);

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
                // Underscore-prefixed keys are internal execution bookkeeping
                // (e.g. _results) — arrays, not user columns.
                if (str_starts_with($header, '_')) {
                    continue;
                }
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
        if ($row->is_valid && $row->outcome === ImportRowOutcome::Pending) {
            $messages[] = 'not_processed: This row was not processed.';
        }
        if ($row->import_error !== null) {
            $errorCode = $row->import_error_code;
            $code = $errorCode instanceof ImportErrorCode ? $errorCode->value : 'import';
            $messages[] = $code.': '.$row->import_error;
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
