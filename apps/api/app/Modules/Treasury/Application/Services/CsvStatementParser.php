<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\Contracts\StatementParserInterface;
use App\Modules\Treasury\Application\DTOs\ParsedStatement;
use App\Modules\Treasury\Domain\StatementImportProfile;
use RuntimeException;

final readonly class CsvStatementParser implements StatementParserInterface
{
    public function __construct(private StatementRowMapper $rowMapper) {}

    public function parse(string $storedFilePath, StatementImportProfile $profile): ParsedStatement
    {
        $contents = file_get_contents($storedFilePath);
        if ($contents === false) {
            throw new RuntimeException("Statement file could not be read: {$storedFilePath}");
        }

        $contents = $this->toUtf8($contents);
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $physicalLines = preg_split('/\R/u', $contents);
        if (! is_array($physicalLines)) {
            throw new RuntimeException('Statement CSV rows could not be separated.');
        }
        $headerLine = $physicalLines[$profile->header_rows] ?? null;
        if (! is_string($headerLine) || trim($headerLine) === '') {
            throw new RuntimeException('Statement CSV header row is missing.');
        }
        $delimiter = $this->detectDelimiter($headerLine);

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Temporary CSV parser stream could not be opened.');
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);
            $physicalRow = 0;
            $headers = null;
            $rows = [];

            while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $physicalRow++;
                /** @var list<string> $normalizedValues */
                $normalizedValues = array_map(
                    static fn (?string $value): string => rtrim($value ?? '', "\r"),
                    $values,
                );

                if ($physicalRow <= $profile->header_rows) {
                    continue;
                }
                if ($headers === null) {
                    $headers = $normalizedValues;

                    continue;
                }
                if ($this->isEmptyRow($normalizedValues)) {
                    continue;
                }

                $rows[] = ['row' => $physicalRow, 'values' => $normalizedValues];
            }

            if ($headers === null) {
                throw new RuntimeException('Statement CSV header row is missing.');
            }

            return $this->rowMapper->map($headers, $rows, $profile);
        } finally {
            fclose($stream);
        }
    }

    private function toUtf8(string $contents): string
    {
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        $encoding = mb_detect_encoding(
            $contents,
            ['Windows-1252', 'ISO-8859-1'],
            true,
        );
        if ($encoding === false) {
            throw new RuntimeException('Statement CSV encoding is not supported.');
        }

        $converted = mb_convert_encoding($contents, 'UTF-8', $encoding);
        if ($converted === false) {
            throw new RuntimeException('Statement CSV encoding conversion failed.');
        }

        return $converted;
    }

    private function detectDelimiter(string $headerLine): string
    {
        $best = ',';
        $bestColumns = 0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $columns = count(str_getcsv($headerLine, $candidate, '"', ''));
            if ($columns > $bestColumns) {
                $best = $candidate;
                $bestColumns = $columns;
            }
        }

        return $best;
    }

    /** @param list<string> $values */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }
}
