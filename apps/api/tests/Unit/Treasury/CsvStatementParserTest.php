<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Treasury\Application\Services\CsvStatementParser;
use App\Modules\Treasury\Application\Services\StatementParserRegistry;
use App\Modules\Treasury\Application\Services\XlsxStatementParser;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\StatementDirectionConvention;
use App\Modules\Treasury\Domain\Enums\StatementParserKey;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\StatementImportProfile;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use ZipArchive;

final class CsvStatementParserTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_csv_parses_preamble_semicolon_dates_locale_amounts_and_reports_dropped_rows(): void
    {
        $profile = $this->profile([
            'header_rows' => 2,
            'decimal_format' => 'comma',
            'column_map' => [
                'value_date' => 'Value Date',
                'booking_date' => 'Booking Date',
                'amount' => 'Amount',
                'reference' => 'Reference',
                'bank_transaction_id' => 'Transaction ID',
                'label' => 'Label',
                'counterparty_hint' => 'Counterparty',
            ],
        ]);

        $result = $this->app->make(CsvStatementParser::class)->parse(
            $this->fixture('biat_semicolon.csv'),
            $profile,
        );

        $this->assertCount(2, $result->lines);
        $this->assertSame(1, $result->droppedZeroAmountRows);
        $this->assertSame([['row' => 7, 'reason' => 'Invalid value date: not-a-date']], $result->unparseableRows);
        $this->assertSame('2026-07-17', $result->lines[0]->valueDate);
        $this->assertSame('2026-07-18', $result->lines[0]->bookingDate);
        $this->assertSame(MovementDirection::In, $result->lines[0]->direction);
        $this->assertSame('1234.560', $result->lines[0]->amount);
        $this->assertSame('BIAT-001', $result->lines[0]->bankTransactionId);
        $this->assertSame(MovementDirection::Out, $result->lines[1]->direction);
        $this->assertSame('42.500', $result->lines[1]->amount);
    }

    public function test_csv_parses_separate_debit_and_credit_columns(): void
    {
        $profile = $this->profile([
            'direction_convention' => StatementDirectionConvention::DebitCreditColumns,
            'decimal_format' => 'dot',
            'column_map' => [
                'value_date' => 'Date',
                'debit' => 'Debit',
                'credit' => 'Credit',
                'reference' => 'Reference',
                'label' => 'Label',
            ],
        ]);

        $result = $this->app->make(CsvStatementParser::class)->parse(
            $this->fixture('amen_debit_credit.csv'),
            $profile,
        );

        $this->assertCount(2, $result->lines);
        $this->assertSame(MovementDirection::Out, $result->lines[0]->direction);
        $this->assertSame('25.750', $result->lines[0]->amount);
        $this->assertSame(MovementDirection::In, $result->lines[1]->direction);
        $this->assertSame('300.000', $result->lines[1]->amount);
    }

    public function test_locale_decimal_thousands_trap_and_identical_occurrences_get_distinct_fingerprints(): void
    {
        $profile = $this->profile([
            'decimal_format' => 'comma',
            'column_map' => [
                'value_date' => 'Date',
                'amount' => 'Amount',
                'reference' => 'Reference',
                'label' => 'Label',
            ],
        ]);

        $result = $this->app->make(CsvStatementParser::class)->parse(
            $this->fixture('locale_decimals.csv'),
            $profile,
        );

        $this->assertCount(3, $result->lines);
        $this->assertSame('7140.000', $result->lines[0]->amount, '7.140 is a thousands trap under comma-decimal format.');
        $this->assertSame('7140.000', $result->lines[1]->amount);
        $this->assertSame(1, $result->lines[0]->occurrenceIndex);
        $this->assertSame(2, $result->lines[1]->occurrenceIndex);
        $this->assertNotSame($result->lines[0]->fingerprint, $result->lines[1]->fingerprint);
        $this->assertSame('1234.560', $result->lines[2]->amount);
    }

    public function test_signed_amounts_are_canonicalized_before_fingerprinting(): void
    {
        $firstPath = $this->temporaryPath('csv');
        $secondPath = $this->temporaryPath('csv');
        file_put_contents($firstPath, "Date,Amount,Reference,Label\n25/07/2026,+1234.5, REF-001 ,Monthly   fee\n");
        file_put_contents($secondPath, "Date,Amount,Reference,Label\n25/07/2026,1234.500,ref-001,monthly fee\n");

        $profile = $this->profile([
            'decimal_format' => 'dot',
            'column_map' => [
                'value_date' => 'Date',
                'amount' => 'Amount',
                'reference' => 'Reference',
                'label' => 'Label',
            ],
        ]);

        $first = $this->app->make(CsvStatementParser::class)->parse($firstPath, $profile);
        $second = $this->app->make(CsvStatementParser::class)->parse($secondPath, $profile);

        $this->assertSame('1234.500', $first->lines[0]->amount);
        $this->assertSame('1234.500', $second->lines[0]->amount);
        $this->assertSame($first->lines[0]->fingerprint, $second->lines[0]->fingerprint);
    }

    public function test_zero_amount_balance_row_is_dropped_after_balance_detection(): void
    {
        $path = $this->temporaryPath('csv');
        file_put_contents($path, "Date,Amount,Label,Opening,Closing\n25/07/2026,0,Opening balance,1000,1000\n");
        $profile = $this->profile([
            'decimal_format' => 'dot',
            'column_map' => [
                'value_date' => 'Date',
                'amount' => 'Amount',
                'label' => 'Label',
                'opening_balance' => 'Opening',
                'closing_balance' => 'Closing',
            ],
        ]);

        $result = $this->app->make(CsvStatementParser::class)->parse($path, $profile);

        $this->assertSame([], $result->lines);
        $this->assertSame(1, $result->droppedZeroAmountRows);
        $this->assertSame('1000.000', $result->detectedOpening);
        $this->assertSame('1000.000', $result->detectedClosing);
    }

    public function test_csv_detects_windows_1252_and_tab_delimiter(): void
    {
        $utf8 = "Date\tAmount\tReference\tLabel\n24/07/2026\t12,500\tENC-001\tFrais d'été\n";
        $encoded = mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
        $path = $this->temporaryPath('csv');
        file_put_contents($path, $encoded);

        $profile = $this->profile([
            'decimal_format' => 'comma',
            'column_map' => [
                'value_date' => 'Date',
                'amount' => 'Amount',
                'reference' => 'Reference',
                'label' => 'Label',
            ],
        ]);
        $result = $this->app->make(CsvStatementParser::class)->parse($path, $profile);

        $this->assertCount(1, $result->lines);
        $this->assertSame("Frais d'été", $result->lines[0]->label);
    }

    public function test_xlsx_parses_excel_serial_dates_and_formula_values_but_reports_formula_errors(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Portal export'],
            ['Generated 2026-07-25'],
            ['Date', 'Amount', 'Reference', 'Label'],
        ]);
        $sheet->setCellValue('A4', Date::PHPToExcel(new \DateTimeImmutable('2026-07-25')));
        $sheet->getStyle('A4')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->setCellValue('B4', '=100+20');
        $sheet->setCellValue('C4', 'XLSX-001');
        $sheet->setCellValue('D4', 'Calculated settlement');
        $sheet->setCellValue('A5', Date::PHPToExcel(new \DateTimeImmutable('2026-07-26')));
        $sheet->getStyle('A5')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->setCellValue('B5', 1234.567);
        $sheet->getStyle('B5')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->setCellValue('C5', 'XLSX-002');
        $sheet->setCellValue('D5', 'Display must not round source');
        $sheet->setCellValue('A6', Date::PHPToExcel(new \DateTimeImmutable('2026-07-27')));
        $sheet->getStyle('A6')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->setCellValue('B6', '=1/0');
        $sheet->setCellValue('C6', 'XLSX-BAD');
        $sheet->setCellValue('D6', 'Broken formula');
        $path = $this->temporaryPath('xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->replaceRawWorksheetValue($path, 'B5', '1234.567');

        $profile = $this->profile([
            'parser_key' => StatementParserKey::Xlsx,
            'header_rows' => 2,
            'decimal_format' => 'dot',
            'column_map' => [
                'value_date' => 'Date',
                'amount' => 'Amount',
                'reference' => 'Reference',
                'label' => 'Label',
            ],
        ]);

        $result = $this->app->make(XlsxStatementParser::class)->parse($path, $profile);

        $this->assertCount(2, $result->lines);
        $this->assertSame('2026-07-25', $result->lines[0]->valueDate);
        $this->assertSame('120.000', $result->lines[0]->amount);
        $this->assertSame('1234.567', $result->lines[1]->amount);
        $this->assertSame([['row' => 6, 'reason' => 'Formula could not be evaluated in cell B6']], $result->unparseableRows);
        $this->assertStringNotContainsString('=', $result->lines[0]->amount);
    }

    public function test_registry_dispatches_both_launch_parser_keys(): void
    {
        $registry = $this->app->make(StatementParserRegistry::class);

        $this->assertInstanceOf(CsvStatementParser::class, $registry->parser(StatementParserKey::Csv));
        $this->assertInstanceOf(XlsxStatementParser::class, $registry->parser(StatementParserKey::Xlsx));
    }

    /** @param array<string, mixed> $overrides */
    private function profile(array $overrides = []): StatementImportProfile
    {
        $profile = new StatementImportProfile;
        $profile->forceFill(array_replace_recursive([
            'parser_key' => StatementParserKey::Csv,
            'column_map' => [],
            'date_format' => 'd/m/Y',
            'decimal_format' => 'dot',
            'direction_convention' => StatementDirectionConvention::SignedAmount,
            'header_rows' => 0,
        ], $overrides));
        $repository = new PaymentRepository;
        $repository->forceFill(['currency' => 'TND']);
        $profile->setRelation('repository', $repository);

        return $profile;
    }

    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/statements/'.$name);
    }

    private function temporaryPath(string $extension): string
    {
        $path = sys_get_temp_dir().'/treasury-statement-'.bin2hex(random_bytes(8)).'.'.$extension;
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function replaceRawWorksheetValue(string $path, string $coordinate, string $value): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $entry = 'xl/worksheets/sheet1.xml';
        $xml = $zip->getFromName($entry);
        $this->assertIsString($xml);
        $pattern = '/(<c r="'.preg_quote($coordinate, '/').'"[^>]*><v>)[^<]+(<\/v><\/c>)/';
        $updated = preg_replace($pattern, '${1}'.$value.'${2}', $xml, 1, $count);
        $this->assertSame(1, $count);
        $this->assertIsString($updated);
        $this->assertTrue($zip->addFromString($entry, $updated));
        $zip->close();
    }
}
