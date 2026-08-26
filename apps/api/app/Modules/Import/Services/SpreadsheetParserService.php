<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Service for parsing spreadsheet files (CSV, XLSX, XLS)
 */
final class SpreadsheetParserService
{
    /**
     * Parse a spreadsheet file and return headers and rows
     *
     * @return array{headers: array<string>, rows: array<int, array<string, mixed>>}
     *
     * @throws ReaderException
     */
    public function parse(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // For CSV files, use native parsing for better performance
        if ($extension === 'csv' || $extension === 'txt') {
            return $this->parseCsv($filePath);
        }

        return $this->parseExcel($filePath);
    }

    /**
     * Parse a CSV file
     *
     * Detects the delimiter (comma, semicolon, or tab — semicolon is the
     * default Excel CSV export in French/European locales), strips a UTF-8
     * BOM (written by Excel "CSV UTF-8"), and streams rows through fgetcsv
     * so quoted fields with embedded newlines parse correctly.
     *
     * Excel on Windows exports CSVs as Windows-1252 (CP1252), not UTF-8, in
     * many French/European locales. Each header and each cell value is
     * checked independently and converted from Windows-1252 to UTF-8 only
     * when it is not already valid UTF-8 — a whole-file check would treat a
     * single stray CP1252 byte anywhere in the file as reason to re-decode
     * every other, already-correct UTF-8 value, corrupting it (e.g. "Café"
     * would become "CafÃ©"). Per-value conversion avoids that.
     *
     * @return array{headers: array<string>, rows: array<int, array<string, mixed>>}
     */
    private function parseCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to read CSV file');
        }

        try {
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $headerLine = fgets($handle);
            if ($headerLine === false) {
                return ['headers' => [], 'rows' => []];
            }

            $delimiter = $this->detectDelimiter($headerLine);
            $rawHeaders = str_getcsv(rtrim($headerLine, "\r\n"), $delimiter, '"', '');
            /** @var array<string> $headers */
            $headers = array_map(fn (?string $h) => strtolower(trim($this->toUtf8($h ?? ''))), $rawHeaders);

            $rows = [];
            $rowNumber = 0;

            while (($values = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                /** @var array<string> $values */
                $values = array_map(
                    fn (?string $v) => $this->toUtf8(rtrim($v ?? '', "\r")),
                    $values
                );

                if ($this->isEmptyRow($values)) {
                    continue;
                }

                $rowNumber++;

                // Ensure values array has same length as headers
                while (count($values) < count($headers)) {
                    $values[] = '';
                }

                /** @var array<string, mixed> $data */
                $data = array_combine($headers, array_slice($values, 0, count($headers)));
                $rows[$rowNumber] = $data;
            }

            return [
                'headers' => $headers,
                'rows' => $rows,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Convert a single CSV value to UTF-8 if it is not already valid UTF-8.
     *
     * Applied per-value (header or cell) rather than to the whole file so
     * that a stray Windows-1252 byte in one field cannot cause an
     * already-valid UTF-8 value elsewhere in the file to be re-decoded and
     * corrupted.
     */
    private function toUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /**
     * Detect the CSV delimiter from the header line by picking the candidate
     * that yields the most columns.
     */
    private function detectDelimiter(string $headerLine): string
    {
        $best = ',';
        $bestCount = 0;

        foreach ([',', ';', "\t"] as $candidate) {
            $count = count(str_getcsv($headerLine, $candidate, '"', ''));
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Parse an Excel file (XLSX or XLS)
     *
     * @return array{headers: array<string>, rows: array<int, array<string, mixed>>}
     *
     * @throws ReaderException
     */
    private function parseExcel(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();

        $rows = [];
        $headers = [];
        $rowNumber = 0;

        foreach ($worksheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $rowData = [];
            foreach ($cellIterator as $cell) {
                $value = $cell->getValue();
                if ($value === null) {
                    $rowData[] = '';
                } elseif ($this->isDateCell($cell)) {
                    $rowData[] = $this->readDateCell($value);
                } elseif (is_scalar($value) || $value instanceof \Stringable) {
                    $rowData[] = (string) $value;
                } else {
                    $rowData[] = '';
                }
            }

            // First row is headers
            if (empty($headers)) {
                /** @var array<string> $headers */
                $headers = array_map(fn (string $h) => strtolower(trim($h)), $rowData);

                continue;
            }

            // Skip empty rows
            if ($this->isEmptyRow($rowData)) {
                continue;
            }

            $rowNumber++;

            // Ensure values array has same length as headers
            while (count($rowData) < count($headers)) {
                $rowData[] = '';
            }

            /** @var array<string, mixed> $data */
            $data = array_combine($headers, array_slice($rowData, 0, count($headers)));
            $rows[$rowNumber] = $data;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * Is this cell one Excel itself typed as a date/time?
     *
     * 🚨 W4-1 gate r1 — a Date-formatted XLSX cell holds an Excel SERIAL
     * (`46387`), not `2027-01-31`. Read raw, that serial reaches validation and
     * `expiry_date`'s `date_format:Y-m-d` rejects it, which marks the WHOLE ROW
     * invalid — the product is not imported at all, not merely its expiry. Excel
     * auto-formats a typed `2027-09-30` as a date, so this was the default
     * outcome for any operator who opened the official template in Excel.
     *
     * Deliberately NARROW: only cells whose number format is a date/time are
     * converted. A blanket `getFormattedValue()` would push money and quantity
     * cells through Excel's DISPLAY format — a `#,##0.0` column would hand back
     * `7.1` for a stored `7.140` — and silently break the precision contract
     * (rule 19) on every numeric import.
     */
    private function isDateCell(Cell $cell): bool
    {
        $value = $cell->getValue();

        // Only a numeric cell can be a serial. A text cell that merely sits in a
        // date-formatted column is already the literal the operator typed.
        if (! is_int($value) && ! is_float($value)) {
            return false;
        }

        return ExcelDate::isDateTime($cell);
    }

    /**
     * An Excel date serial as a plain string.
     *
     * Midnight collapses to `Y-m-d` (what every date rule in `ImportType` wants);
     * a real time survives as `Y-m-d H:i:s` rather than being truncated, since
     * dropping it would be a second invented value.
     */
    private function readDateCell(mixed $value): string
    {
        if (! is_int($value) && ! is_float($value)) {
            return '';
        }

        $date = ExcelDate::excelToDateTimeObject($value);

        return $date->format('H:i:s') === '00:00:00'
            ? $date->format('Y-m-d')
            : $date->format('Y-m-d H:i:s');
    }

    /**
     * Check if a row is empty
     *
     * @param  array<string>  $rowData
     */
    private function isEmptyRow(array $rowData): bool
    {
        foreach ($rowData as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Detect the file type from content and extension
     */
    public function detectFileType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx' => 'excel',
            'xls' => 'excel_legacy',
            'csv', 'txt' => 'csv',
            default => throw new \InvalidArgumentException("Unsupported file type: {$extension}"),
        };
    }

    /**
     * Validate file can be parsed
     */
    public function canParse(string $filePath): bool
    {
        try {
            $this->detectFileType($filePath);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
