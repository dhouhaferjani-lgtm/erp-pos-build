<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use Illuminate\Support\Facades\Storage;

/**
 * Service for generating CSV exports of failed/skipped import rows.
 *
 * Generates downloadable CSV files containing rows that failed validation
 * or had execution errors during import, along with their error reasons.
 */
final class FailedRowsExportService
{
    /**
     * Directory for storing failed rows CSV files.
     */
    private const EXPORT_DIRECTORY = 'imports/failed';

    /**
     * Generate a CSV file containing all failed/skipped rows with their error reasons.
     *
     * @return string|null The file path relative to storage, or null if no failed rows
     */
    public function generateFailedRowsCsv(ImportJob $job): ?string
    {
        // Get all rows with errors (validation or execution)
        $failedRows = $job->rows()
            ->where(function ($query) {
                $query->where('is_valid', false)
                    ->orWhereNotNull('import_error');
            })
            ->orderBy('row_number')
            ->get();

        if ($failedRows->isEmpty()) {
            return null;
        }

        // Build CSV content
        $csvContent = $this->buildCsvContent($failedRows);

        // Generate unique filename
        $filename = sprintf(
            '%s/%s-failed-rows-%s.csv',
            self::EXPORT_DIRECTORY,
            $job->id,
            now()->format('Ymd-His')
        );

        // Store the file
        Storage::disk('local')->put($filename, $csvContent);

        return $filename;
    }

    /**
     * Get the download URL for a failed rows CSV file.
     */
    public function getDownloadUrl(ImportJob $job): ?string
    {
        $filename = $this->generateFailedRowsCsv($job);

        if ($filename === null) {
            return null;
        }

        // Return relative URL (frontend api client adds /api/v1 prefix)
        return '/imports/'.$job->id.'/failed-rows.csv';
    }

    /**
     * Get the file path for an existing failed rows CSV.
     */
    public function getFilePath(ImportJob $job): ?string
    {
        $pattern = self::EXPORT_DIRECTORY.'/'.$job->id.'-failed-rows-*.csv';
        $files = Storage::disk('local')->files(self::EXPORT_DIRECTORY);

        foreach ($files as $file) {
            if (str_starts_with(basename($file), $job->id.'-failed-rows-')) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Build CSV content from failed rows.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, ImportRow>  $failedRows
     */
    private function buildCsvContent($failedRows): string
    {
        // Get all data columns from the first row
        $firstRow = $failedRows->first();
        /** @var array<string> $dataColumns */
        $dataColumns = $firstRow ? array_keys($firstRow->data) : [];

        // Build header row
        $headers = array_merge(
            ['row_number'],
            $dataColumns,
            ['error_type', 'error_reason']
        );

        // Start building CSV
        $lines = [];
        $lines[] = $this->formatCsvRow($headers);

        // Add data rows
        foreach ($failedRows as $row) {
            $rowData = [];
            $rowData[] = (string) $row->row_number;

            // Add data columns
            foreach ($dataColumns as $column) {
                $rowData[] = (string) ($row->data[$column] ?? '');
            }

            // Determine error type and reason
            if ($row->import_error !== null) {
                $rowData[] = 'execution';
                $rowData[] = $row->import_error;
            } else {
                $rowData[] = 'validation';
                $rowData[] = $this->formatValidationErrors($row->errors);
            }

            $lines[] = $this->formatCsvRow($rowData);
        }

        return implode("\n", $lines);
    }

    /**
     * Format validation errors as a readable string.
     *
     * @param  array<string, array<string>>|null  $errors
     */
    private function formatValidationErrors(?array $errors): string
    {
        if ($errors === null || empty($errors)) {
            return 'Unknown validation error';
        }

        $parts = [];
        foreach ($errors as $field => $messages) {
            $parts[] = $field.': '.implode(', ', $messages);
        }

        return implode('; ', $parts);
    }

    /**
     * Format a row for CSV output (handle escaping and quoting).
     *
     * @param  array<string>  $values
     */
    private function formatCsvRow(array $values): string
    {
        $escaped = array_map(function ($value) {
            // Escape double quotes
            $value = str_replace('"', '""', $value);

            // Quote if contains comma, newline, or double quote
            if (str_contains($value, ',') || str_contains($value, "\n") || str_contains($value, '"')) {
                return '"'.$value.'"';
            }

            return $value;
        }, $values);

        return implode(',', $escaped);
    }

    /**
     * Delete any existing failed rows CSV files for a job.
     */
    public function cleanup(ImportJob $job): void
    {
        $files = Storage::disk('local')->files(self::EXPORT_DIRECTORY);

        foreach ($files as $file) {
            if (str_starts_with(basename($file), $job->id.'-failed-rows-')) {
                Storage::disk('local')->delete($file);
            }
        }
    }
}
