<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

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
     * @return array{headers: array<string>, rows: array<int, array<string, mixed>>}
     */
    private function parseCsv(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException('Failed to read CSV file');
        }

        $lines = explode("\n", trim($content));
        /** @var string $headerLine */
        $headerLine = array_shift($lines);
        $rawHeaders = str_getcsv($headerLine);
        /** @var array<string> $headers */
        $headers = array_map(fn (?string $h) => strtolower(trim($h ?? '')), $rawHeaders);

        $rows = [];
        $rowNumber = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rowNumber++;
            $values = str_getcsv($line);

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
