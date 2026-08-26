<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Import\Services\SpreadsheetParserService;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Campaign W4-1, gate r1 [IMPORTANT] — an XLSX cell that Excel formatted as a
 * DATE must not reach validation as an Excel serial.
 *
 * `parseExcel()` read `$cell->getValue()`, the RAW value, so a date-typed cell
 * arrived as `46387` rather than `2027-01-31`. `expiry_date`'s rule is
 * `date_format:Y-m-d`, so the serial fails it and `validateJob` marks the WHOLE
 * ROW invalid — the product itself is not imported, not merely its expiry. Excel
 * auto-formats a typed `2027-09-30` as a date, so this was the DEFAULT outcome
 * for any operator who opened the official template in Excel and saved as .xlsx.
 *
 * Only cells Excel actually TYPED as a date are converted. Everything else stays
 * on the raw path — a blanket `getFormattedValue()` would run money and quantity
 * cells through Excel's display format and break the precision contract
 * (rule 19) by rounding `7.140` to whatever the column shows.
 */
final class SpreadsheetParserDateCellTest extends TestCase
{
    private SpreadsheetParserService $parser;

    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SpreadsheetParserService;
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_a_date_formatted_cell_is_read_as_an_iso_date_not_an_excel_serial(): void
    {
        $path = $this->xlsx(function (Spreadsheet $spreadsheet): void {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([['name', 'sku', 'expiry_date']]);
            $sheet->setCellValue('A2', 'Sirop');
            $sheet->setCellValue('B2', 'SIRO-1');
            $sheet->setCellValue('C2', ExcelDate::PHPToExcel(new \DateTimeImmutable('2027-01-31')));
            $sheet->getStyle('C2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        });

        $result = $this->parser->parse($path);

        $this->assertSame(
            '2027-01-31',
            $result['rows'][1]['expiry_date'],
            'a Date-formatted cell must arrive as Y-m-d; the raw serial fails date_format and kills the whole row',
        );
    }

    public function test_a_date_cell_carrying_a_time_keeps_the_time_rather_than_losing_it(): void
    {
        $path = $this->xlsx(function (Spreadsheet $spreadsheet): void {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([['name', 'document_date']]);
            $sheet->setCellValue('A2', 'Invoice');
            $sheet->setCellValue('B2', ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-03-04 14:30:00')));
            $sheet->getStyle('B2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DATETIME);
        });

        $result = $this->parser->parse($path);

        $this->assertSame(
            '2026-03-04 14:30:00',
            $result['rows'][1]['document_date'],
            'midnight collapses to Y-m-d, but a real time must survive — silently truncating it would be a second '
            .'invented value',
        );
    }

    public function test_a_numeric_cell_is_left_on_the_raw_path_so_money_precision_survives(): void
    {
        $path = $this->xlsx(function (Spreadsheet $spreadsheet): void {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([['name', 'purchase_price', 'quantity']]);
            $sheet->setCellValue('A2', 'Sirop');
            $sheet->setCellValue('B2', 7.14);
            $sheet->setCellValue('C2', 6);
            // The shape that would break if the fix reached for getFormattedValue():
            // a 2-decimal display format over a 3-decimal money value.
            $sheet->getStyle('B2')->getNumberFormat()->setFormatCode('#,##0.0');
        });

        $result = $this->parser->parse($path);

        $this->assertSame('7.14', $result['rows'][1]['purchase_price'], 'money must never be read through a display format');
        $this->assertSame('6', $result['rows'][1]['quantity']);
    }

    public function test_a_blank_cell_in_a_date_formatted_column_stays_blank(): void
    {
        $path = $this->xlsx(function (Spreadsheet $spreadsheet): void {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([['name', 'sku', 'expiry_date']]);
            $sheet->setCellValue('A2', 'Sirop');
            $sheet->setCellValue('B2', 'SIRO-2');
            // The column carries a date format but THIS cell is empty — the shape
            // an operator leaves behind when they fill the expiry for some rows and
            // not others. Excel stores no value, and the date-aware read must not
            // turn that into serial 0 (1899-12-30) or today. Blank means "not
            // supplied", which is the whole W4-1 contract for this column.
            $sheet->getStyle('C2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        });

        $result = $this->parser->parse($path);

        $this->assertSame(
            '',
            $result['rows'][1]['expiry_date'],
            'a blank date-formatted cell must stay blank; any date here would be an invented one',
        );
    }

    public function test_a_plain_text_date_string_is_untouched(): void
    {
        $path = $this->xlsx(function (Spreadsheet $spreadsheet): void {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([
                ['name', 'expiry_date'],
                ['Sirop', '2027-09-30'],
            ]);
        });

        $result = $this->parser->parse($path);

        $this->assertSame('2027-09-30', $result['rows'][1]['expiry_date']);
    }

    private function xlsx(callable $build): string
    {
        $spreadsheet = new Spreadsheet;
        $build($spreadsheet);

        $path = tempnam(sys_get_temp_dir(), 'w41_parser_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->paths[] = $path;

        return $path;
    }
}
