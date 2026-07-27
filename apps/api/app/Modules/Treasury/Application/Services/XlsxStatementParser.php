<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\Contracts\StatementParserInterface;
use App\Modules\Treasury\Application\DTOs\ParsedStatement;
use App\Modules\Treasury\Domain\StatementImportProfile;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;
use ZipArchive;

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
            $rawNumericValues = $this->readRawNumericValues(
                $storedFilePath,
                $spreadsheet->getActiveSheetIndex(),
            );
            $headerRow = $profile->header_rows + 1;
            if ($headerRow > $sheet->getHighestDataRow()) {
                throw new RuntimeException('Statement XLSX header row is missing.');
            }

            $headers = $this->readRow($sheet, $headerRow, $rawNumericValues);
            $rows = [];
            $readerErrors = [];
            for ($row = $headerRow + 1; $row <= $sheet->getHighestDataRow(); $row++) {
                try {
                    $values = $this->readRow($sheet, $row, $rawNumericValues);
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

    /**
     * @param  array<string, string>  $rawNumericValues
     * @return list<string>
     */
    private function readRow(Worksheet $sheet, int $row, array $rawNumericValues): array
    {
        $values = [];
        $highestColumn = $sheet->getHighestDataColumn($row);
        $lastColumn = Coordinate::columnIndexFromString($highestColumn);

        for ($column = 1; $column <= $lastColumn; $column++) {
            $cell = $sheet->getCell([$column, $row]);
            $values[] = $this->readCell($cell, $rawNumericValues[$cell->getCoordinate()] ?? null);
        }

        return $values;
    }

    private function readCell(Cell $cell, ?string $rawNumericValue): string
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
                $calculated = $rawNumericValue ?? $cell->getCalculatedValueString();
            } catch (Throwable) {
                throw new RuntimeException("Formula could not be evaluated in cell {$cell->getCoordinate()}");
            }
            if (str_starts_with($calculated, '#') || str_starts_with($calculated, '=')) {
                throw new RuntimeException("Formula could not be evaluated in cell {$cell->getCoordinate()}");
            }

            return trim($calculated);
        }

        if ($rawNumericValue !== null) {
            return trim($rawNumericValue);
        }

        return trim($cell->getValueString());
    }

    /**
     * PhpSpreadsheet hydrates ordinary numeric cells as PHP floats and its display
     * formatter may round them. Read the exact serialized numeric strings from the
     * XLSX worksheet XML so money reaches the mapper without either transformation.
     *
     * @return array<string, string>
     */
    private function readRawNumericValues(string $path, int $activeSheetIndex): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Statement XLSX archive could not be opened: {$path}");
        }

        try {
            $workbook = $this->xmlDocument($zip, 'xl/workbook.xml');
            $workbookXPath = new DOMXPath($workbook);
            $workbookXPath->registerNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $workbookXPath->registerNamespace('rel', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $sheets = $workbookXPath->query('/main:workbook/main:sheets/main:sheet');
            if ($sheets === false) {
                throw new RuntimeException('XLSX worksheet metadata could not be queried.');
            }
            $sheet = $sheets->item($activeSheetIndex);
            if (! $sheet instanceof DOMElement) {
                throw new RuntimeException('Active XLSX worksheet metadata is missing.');
            }
            $relationshipId = $sheet->getAttributeNS(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'id',
            );

            $relationships = $this->xmlDocument($zip, 'xl/_rels/workbook.xml.rels');
            $relationshipsXPath = new DOMXPath($relationships);
            $relationshipsXPath->registerNamespace('pkg', 'http://schemas.openxmlformats.org/package/2006/relationships');
            $relationshipNodes = $relationshipsXPath->query('/pkg:Relationships/pkg:Relationship');
            $target = null;
            if ($relationshipNodes !== false) {
                foreach ($relationshipNodes as $relationship) {
                    if ($relationship instanceof DOMElement && $relationship->getAttribute('Id') === $relationshipId) {
                        $target = $relationship->getAttribute('Target');

                        break;
                    }
                }
            }
            if ($target === null || $target === '') {
                throw new RuntimeException('Active XLSX worksheet relationship is missing.');
            }

            $worksheet = $this->xmlDocument($zip, $this->worksheetEntryName($target));
            $worksheetXPath = new DOMXPath($worksheet);
            $worksheetXPath->registerNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $cells = $worksheetXPath->query('//main:sheetData/main:row/main:c');
            $values = [];
            if ($cells === false) {
                return $values;
            }

            foreach ($cells as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }
                $coordinate = $cell->getAttribute('r');
                $type = $cell->getAttribute('t');
                $formula = $worksheetXPath->query('./main:f', $cell);
                $valueNodes = $worksheetXPath->query('./main:v', $cell);
                $value = $valueNodes === false ? null : $valueNodes->item(0);
                $isFormula = $formula !== false && $formula->length > 0;
                if ($coordinate !== ''
                    && $value instanceof DOMElement
                    && ($isFormula || $type === '' || $type === 'n')) {
                    $values[$coordinate] = $value->textContent;
                }
            }

            return $values;
        } finally {
            $zip->close();
        }
    }

    private function xmlDocument(ZipArchive $zip, string $entry): DOMDocument
    {
        $xml = $zip->getFromName($entry);
        if ($xml === false) {
            throw new RuntimeException("Statement XLSX entry is missing: {$entry}");
        }

        $document = new DOMDocument;
        if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException("Statement XLSX entry is invalid XML: {$entry}");
        }

        return $document;
    }

    private function worksheetEntryName(string $target): string
    {
        $rootedTarget = ltrim($target, '/');
        $segments = explode('/', str_starts_with($rootedTarget, 'xl/') ? $rootedTarget : 'xl/'.$rootedTarget);
        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($normalized);

                continue;
            }
            $normalized[] = $segment;
        }

        return implode('/', $normalized);
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
