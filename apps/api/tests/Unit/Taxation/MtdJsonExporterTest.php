<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Modules\Taxation\Infrastructure\Exporters\MtdJsonExporter;
use PHPUnit\Framework\TestCase;

class MtdJsonExporterTest extends TestCase
{
    private MtdJsonExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new MtdJsonExporter;
    }

    public function test_supports_mtd_json_format(): void
    {
        $this->assertTrue($this->exporter->supports(VatExportFormat::MtdJson));
        $this->assertFalse($this->exporter->supports(VatExportFormat::Csv));
        $this->assertFalse($this->exporter->supports(VatExportFormat::Fec));
    }

    public function test_content_type_is_json(): void
    {
        $this->assertSame('application/json', $this->exporter->getContentType());
    }

    public function test_filename_format(): void
    {
        $period = $this->createMock(VatPeriod::class);
        $period->method('__get')->willReturnMap([
            ['country_code', 'GB'],
            ['period_start', new \Illuminate\Support\Carbon('2026-01-01')],
            ['period_end', new \Illuminate\Support\Carbon('2026-03-31')],
        ]);

        $filename = $this->exporter->getFilename($period);
        $this->assertSame('vat_return_GB_20260101_20260331.json', $filename);
    }

    public function test_nine_box_json_structure(): void
    {
        $summary = $this->createSampleSummary();
        $declaration = $this->createUkDeclaration();

        $response = $this->exporter->export($summary, $declaration);
        $json = $this->captureAndDecode($response);

        // All 9 boxes must exist
        $this->assertArrayHasKey('vatDueSales', $json);
        $this->assertArrayHasKey('vatDueAcquisitions', $json);
        $this->assertArrayHasKey('totalVatDue', $json);
        $this->assertArrayHasKey('vatReclaimedCurrPeriod', $json);
        $this->assertArrayHasKey('netVatDue', $json);
        $this->assertArrayHasKey('totalValueSalesExVAT', $json);
        $this->assertArrayHasKey('totalValuePurchasesExVAT', $json);
        $this->assertArrayHasKey('totalValueGoodsSuppliedExVAT', $json);
        $this->assertArrayHasKey('totalAcquisitionsExVAT', $json);
    }

    public function test_boxes_1_to_5_are_decimals_with_two_places(): void
    {
        $summary = $this->createSampleSummary();
        $declaration = $this->createUkDeclaration();

        $response = $this->exporter->export($summary, $declaration);
        $output = $this->captureStreamedResponse($response);
        $json = json_decode($output, true);

        // Boxes 1-5 should be decimal values (2 places)
        $this->assertSame(2500.00, $json['vatDueSales']);
        $this->assertSame(0.00, $json['vatDueAcquisitions']);
        $this->assertSame(2500.00, $json['totalVatDue']);
        $this->assertSame(600.00, $json['vatReclaimedCurrPeriod']);
        $this->assertSame(1900.00, $json['netVatDue']);
    }

    public function test_boxes_6_to_9_are_integers_whole_pounds(): void
    {
        $summary = $this->createSampleSummary();
        $declaration = $this->createUkDeclaration();

        $response = $this->exporter->export($summary, $declaration);
        $output = $this->captureStreamedResponse($response);
        $json = json_decode($output, true);

        // Boxes 6-9 should be integers (whole pounds)
        $this->assertSame(15000, $json['totalValueSalesExVAT']);
        $this->assertSame(3000, $json['totalValuePurchasesExVAT']);
        $this->assertSame(0, $json['totalValueGoodsSuppliedExVAT']);
        $this->assertSame(0, $json['totalAcquisitionsExVAT']);
    }

    public function test_net_vat_due_is_always_positive(): void
    {
        // Input > Output scenario: net is negative but netVatDue must be abs
        $summary = new VatSummary(
            outputBreakdowns: [
                new VatAggregation(
                    direction: 'OUTPUT',
                    taxRate: '20.00',
                    baseAmount: '1000.000',
                    vatAmount: '200.000',
                    documentCount: 2,
                    isRecoverable: false,
                ),
            ],
            inputBreakdowns: [
                new VatAggregation(
                    direction: 'INPUT',
                    taxRate: '20.00',
                    baseAmount: '5000.000',
                    vatAmount: '1000.000',
                    documentCount: 3,
                    isRecoverable: true,
                ),
            ],
            totalOutputVat: '200.000',
            totalInputVat: '1000.000',
        );

        $declaration = new VatDeclarationData(
            fields: [
                'box1_vat_due_sales' => '200.00',
                'box2_vat_due_acquisitions' => '0.00',
                'box3_total_vat_due' => '200.00',
                'box4_vat_reclaimed' => '1000.00',
                'box5_net_vat' => '-800.00',
                'box6_total_sales_ex_vat' => '1000',
                'box7_total_purchases_ex_vat' => '5000',
                'box8_goods_supplied_ex_vat' => '0',
                'box9_acquisitions_ex_vat' => '0',
            ],
            formReference: 'VAT100',
        );

        $response = $this->exporter->export($summary, $declaration);
        $output = $this->captureStreamedResponse($response);
        $json = json_decode($output, true);

        // netVatDue is always positive (abs value)
        $this->assertSame(800.00, $json['netVatDue']);
        $this->assertGreaterThanOrEqual(0, $json['netVatDue']);
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

    private function createUkDeclaration(): VatDeclarationData
    {
        return new VatDeclarationData(
            fields: [
                'box1_vat_due_sales' => '2500.00',
                'box2_vat_due_acquisitions' => '0.00',
                'box3_total_vat_due' => '2500.00',
                'box4_vat_reclaimed' => '600.00',
                'box5_net_vat' => '1900.00',
                'box6_total_sales_ex_vat' => '15000',
                'box7_total_purchases_ex_vat' => '3000',
                'box8_goods_supplied_ex_vat' => '0',
                'box9_acquisitions_ex_vat' => '0',
            ],
            formReference: 'VAT100',
        );
    }

    /**
     * @return array<string, float|int>
     */
    private function captureAndDecode(\Symfony\Component\HttpFoundation\StreamedResponse $response): array
    {
        $output = $this->captureStreamedResponse($response);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);

        /** @var array<string, float|int> $decoded */
        return $decoded;
    }

    private function captureStreamedResponse(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
