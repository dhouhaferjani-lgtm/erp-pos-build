<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\Contracts\StatementParserInterface;
use App\Modules\Treasury\Application\DTOs\ParsedStatement;
use App\Modules\Treasury\Domain\StatementImportProfile;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

final readonly class XlsxStatementParser implements StatementParserInterface
{
    public function __construct(private StatementRowMapper $rowMapper) {}

    public function parse(string $storedFilePath, StatementImportProfile $profile): ParsedStatement
    {
        $reader = IOFactory::createReaderForFile($storedFilePath);
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($storedFilePath);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $headerRow = $profile->header_rows + 1;
            if ($headerRow > $sheet->getHighestDataRow()) {
                throw new RuntimeException('Statement XLSX header row is missing.');
            }

            $headers = $this->readRow($sheet, $headerRow);
            $rows = [];
            $readerErrors = [];
            for ($row = $headerRow + 1; $row <= $sheet->getHighestDataRow(); $row++) {
                try {
                    $values = $this->readRow($sheet, $row);
                    if ($this->isEmptyRow($values)) {
                        continue;
                    }
                    $rows[] = ['row' => $row, 'values' => $values];
                } catch (RuntimeException $exception) {
                    $readerErrors[] = ['row' => $row, 'reason' => $exception->getMessage()];
                }
            }

            return $this->rowMapper->map($headers, $rows, $profile, $readerErrors);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** @return list<string> */
    private function readRow(Worksheet $sheet, int $row): array
    {
        $values = [];
        $highestColumn = $sheet->getHighestDataColumn($row);
        $lastColumn = Coordinate::columnIndexFromString($highestColumn);

        for ($column = 1; $column <= $lastColumn; $column++) {
            $cell = $sheet->getCell([$column, $row]);
            $values[] = $this->readCell($cell);
        }

        return $values;
    }

    private function readCell(Cell $cell): string
    {
        if ($cell->getValue() === null) {
            return '';
        }

        if (Date::isDateTime($cell)) {
            $serial = $cell->getCalculatedValue();
            if (! is_int($serial) && ! is_float($serial)) {
                throw new RuntimeException("Excel date could not be evaluated in cell {$cell->getCoordinate()}");
            }

            return Date::excelToDateTimeObject($serial)->format('Y-m-d');
        }

        if ($cell->isFormula()) {
            try {
                $calculated = $cell->getCalculatedValue();
                $formatted = $cell->getFormattedValue();
            } catch (Throwable) {
                throw new RuntimeException("Formula could not be evaluated in cell {$cell->getCoordinate()}");
            }
            if ((is_string($calculated) && (str_starts_with($calculated, '#') || str_starts_with($calculated, '=')))
                || str_starts_with($formatted, '#')
                || str_starts_with($formatted, '=')) {
                throw new RuntimeException("Formula could not be evaluated in cell {$cell->getCoordinate()}");
            }

            return trim($formatted);
        }

        return trim($cell->getFormattedValue());
    }

    /** @param list<string> $values */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }
}
