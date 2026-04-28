<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Modules\Taxation\Infrastructure\Exporters\CsvVatExporter;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvVatExporterTest extends TestCase
{
    private CsvVatExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new CsvVatExporter;
    }

    public function test_supports_csv_format(): void
    {
        $this->assertTrue($this->exporter->supports(VatExportFormat::Csv));
        $this->assertFalse($this->exporter->supports(VatExportFormat::Pdf));
        $this->assertFalse($this->exporter->supports(VatExportFormat::Fec));
    }

    public function test_content_type_is_csv(): void
    {
        $this->assertSame('text/csv', $this->exporter->getContentType());
    }

    public function test_filename_format(): void
    {
        $period = $this->createMock(VatPeriod::class);
        $period->method('__get')->willReturnMap([
            ['country_code', 'FR'],
            ['period_start', new Carbon('2026-01-01')],
            ['period_end', new Carbon('2026-03-31')],
        ]);

        $filename = $this->exporter->getFilename($period);
        $this->assertSame('vat_report_FR_20260101_20260331.csv', $filename);
    }

    public function test_output_contains_headers(): void
    {
        $summary = $this->createSampleSummary();
        $declaration = new VatDeclarationData(fields: [], formReference: 'CA3');

        $response = $this->exporter->export($summary, $declaration);

        $output = $this->captureStreamedResponse($response);
        $lines = explode("\n", trim($output));

        $this->assertSame(
            'Rate,Direction,"Base HT","VAT Amount","Document Count","Is Recoverable"',
            $lines[0]
        );
    }

    public function test_output_rows_correctly_formatted(): void
    {
        $summary = $this->createSampleSummary();
        $declaration = new VatDeclarationData(fields: [], formReference: 'CA3');

        $response = $this->exporter->export($summary, $declaration);

        $output = $this->captureStreamedResponse($response);
        $lines = explode("\n", trim($output));

        // Header + 2 output breakdowns + 1 input breakdown = 4 lines
        $this->assertCount(4, $lines);

        // First data row: 20% output
        $this->assertSame('20.00,Output,10000.000,2000.000,15,No', $lines[1]);

        // Second data row: 10% output
        $this->assertSame('10.00,Output,5000.000,500.000,8,No', $lines[2]);

        // Third data row: 20% input
        $this->assertSame('20.00,Input,3000.000,600.000,5,Yes', $lines[3]);
    }

    public function test_empty_breakdowns_produces_only_header(): void
    {
        $summary = new VatSummary(
            outputBreakdowns: [],
            inputBreakdowns: [],
            totalOutputVat: '0.000',
            totalInputVat: '0.000',
        );
        $declaration = new VatDeclarationData(fields: [], formReference: 'CA3');

        $response = $this->exporter->export($summary, $declaration);
        $output = $this->captureStreamedResponse($response);
        $lines = explode("\n", trim($output));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Rate,Direction', $lines[0]);
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
                new VatAggregation(
                    direction: 'OUTPUT',
                    taxRate: '10.00',
                    baseAmount: '5000.000',
                    vatAmount: '500.000',
                    documentCount: 8,
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
            totalOutputVat: '2500.000',
            totalInputVat: '600.000',
        );
    }

    private function captureStreamedResponse(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
