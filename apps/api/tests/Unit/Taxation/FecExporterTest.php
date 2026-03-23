<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Modules\Taxation\Infrastructure\Exporters\FecExporter;
use PHPUnit\Framework\TestCase;

class FecExporterTest extends TestCase
{
    private FecExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new FecExporter;
    }

    public function test_supports_fec_format(): void
    {
        $this->assertTrue($this->exporter->supports(VatExportFormat::Fec));
        $this->assertFalse($this->exporter->supports(VatExportFormat::Csv));
        $this->assertFalse($this->exporter->supports(VatExportFormat::MtdJson));
    }

    public function test_content_type_is_plain_text(): void
    {
        $this->assertSame('text/plain; charset=UTF-8', $this->exporter->getContentType());
    }

    public function test_filename_format_uses_siren_and_closing_date(): void
    {
        $period = $this->createMock(VatPeriod::class);
        $period->method('__get')->willReturnMap([
            ['country_code', 'FR'],
            ['period_start', new \Illuminate\Support\Carbon('2026-01-01')],
            ['period_end', new \Illuminate\Support\Carbon('2026-03-31')],
        ]);

        $exporter = new FecExporter('123456789');
        $filename = $exporter->getFilename($period);
        $this->assertSame('123456789FEC20260331.txt', $filename);
    }

    public function test_filename_format_without_siren(): void
    {
        $period = $this->createMock(VatPeriod::class);
        $period->method('__get')->willReturnMap([
            ['country_code', 'FR'],
            ['period_start', new \Illuminate\Support\Carbon('2026-01-01')],
            ['period_end', new \Illuminate\Support\Carbon('2026-03-31')],
        ]);

        $filename = $this->exporter->getFilename($period);
        $this->assertSame('000000000FEC20260331.txt', $filename);
    }

    public function test_header_has_18_tab_delimited_fields(): void
    {
        $summary = $this->createSampleSummary();
        $declaration = $this->createFrDeclaration();

        $response = $this->exporter->export($summary, $declaration);
        $output = $this->captureStreamedResponse($response);
        $lines = explode("\n", trim($output));

        $headerFields = explode("\t", $lines[0]);
        $this->assertCount(18, $headerFields);
    }

    public function test_correct_field_order(): void
    {
        $summary = $this->createSampleSummary();
        $declaration = $this->createFrDeclaration();

        $response = $this->exporter->export($summary, $declaration);
        $output = $this->captureStreamedResponse($response);
        $lines = explode("\n", trim($output));

        $expectedHeaders = [
            'JournalCode',
            'JournalLib',
            'EcritureNum',
            'EcritureDate',
            'CompteNum',
            'CompteLib',
            'CompAuxNum',
            'CompAuxLib',
            'PieceRef',
            'PieceDate',
            'EcritureLib',
            'Debit',
            'Credit',
            'EcritureLet',
            'DateLet',
            'ValidDate',
            'Montantdevise',
            'Idevise',
        ];

        $headerFields = explode("\t", $lines[0]);
        $this->assertSame($expectedHeaders, $headerFields);
    }

    public function test_date_format_aaaammjj(): void
    {
        $summary = $this->createSampleSummary();
        $declaration = $this->createFrDeclaration();

        $response = $this->exporter->export($summary, $declaration);
        $output = $this->captureStreamedResponse($response);
        $lines = explode("\n", trim($output));

        // Data rows start at index 1
        $this->assertGreaterThan(1, count($lines));

        $firstRow = explode("\t", $lines[1]);
        // EcritureDate is field index 3 — format AAAAMMJJ (8 digits)
        $this->assertMatchesRegularExpression('/^\d{8}$/', $firstRow[3]);
    }

    public function test_vat_accounts_use_44x_prefix(): void
    {
        $summary = $this->createSampleSummary();
        $declaration = $this->createFrDeclaration();

        $response = $this->exporter->export($summary, $declaration);
        $output = $this->captureStreamedResponse($response);
        $lines = explode("\n", trim($output));

        // Check that account numbers start with 44 (VAT accounts in PCG)
        for ($i = 1; $i < count($lines); $i++) {
            $fields = explode("\t", $lines[$i]);
            $compteNum = $fields[4]; // CompteNum is field index 4
            $this->assertStringStartsWith('44', $compteNum, "Account '$compteNum' should start with 44");
        }
    }

    public function test_debit_credit_are_numeric(): void
    {
        $summary = $this->createSampleSummary();
        $declaration = $this->createFrDeclaration();

        $response = $this->exporter->export($summary, $declaration);
        $output = $this->captureStreamedResponse($response);
        $lines = explode("\n", trim($output));

        for ($i = 1; $i < count($lines); $i++) {
            $fields = explode("\t", $lines[$i]);
            // Debit is field 11, Credit is field 12
            $this->assertIsNumeric($fields[11], 'Debit field should be numeric');
            $this->assertIsNumeric($fields[12], 'Credit field should be numeric');
        }
    }

    private function createSampleSummary(): VatSummary
    {
        return new VatSummary(
            outputBreakdowns: [
                new VatAggregation(
                    direction: 'OUTPUT',
                    taxRate: '20.00',
                    baseAmount: '10000.000',
                    vatAmount: '2000.000',
                    documentCount: 15,
                    isRecoverable: false,
                ),
            ],
            inputBreakdowns: [
                new VatAggregation(
                    direction: 'INPUT',
                    taxRate: '20.00',
                    baseAmount: '3000.000',
                    vatAmount: '600.000',
                    documentCount: 5,
                    isRecoverable: true,
                ),
            ],
            totalOutputVat: '2000.000',
            totalInputVat: '600.000',
        );
    }

    private function createFrDeclaration(): VatDeclarationData
    {
        return new VatDeclarationData(
            fields: [
                'ca3_line_08' => '10000.00',
                'ca3_line_09' => '2000.00',
                'ca3_line_20' => '600.00',
            ],
            formReference: 'CA3',
        );
    }

    private function captureStreamedResponse(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
