<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Modules\Taxation\Infrastructure\Strategies\TunisiaVatStrategy;
use PHPUnit\Framework\TestCase;

class TunisiaVatStrategyTest extends TestCase
{
    private TunisiaVatStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new TunisiaVatStrategy;
    }

    public function test_default_period_type(): void
    {
        $this->assertSame(VatPeriodType::Monthly, $this->strategy->getDefaultPeriodType());
    }

    public function test_generates_correct_periods(): void
    {
        $periods = $this->strategy->generatePeriods(1, 2026);

        $this->assertCount(12, $periods);

        // January
        $this->assertSame('January 2026', $periods[0]['label']);
        $this->assertSame('2026-01-01', $periods[0]['period_start']);
        $this->assertSame('2026-01-31', $periods[0]['period_end']);
        $this->assertSame('MONTHLY', $periods[0]['period_type']);

        // February
        $this->assertSame('February 2026', $periods[1]['label']);
        $this->assertSame('2026-02-01', $periods[1]['period_start']);
        $this->assertSame('2026-02-28', $periods[1]['period_end']);

        // December
        $this->assertSame('December 2026', $periods[11]['label']);
        $this->assertSame('2026-12-01', $periods[11]['period_start']);
        $this->assertSame('2026-12-31', $periods[11]['period_end']);
    }

    public function test_expected_rates(): void
    {
        $rates = $this->strategy->getExpectedRates();

        $this->assertSame(['19.00', '13.00', '7.00', '0.00'], $rates);
    }

    public function test_supported_export_formats(): void
    {
        $formats = $this->strategy->getSupportedExportFormats();

        $this->assertSame([VatExportFormat::Pdf, VatExportFormat::Csv, VatExportFormat::TeifXml], $formats);
    }

    public function test_map_to_declaration(): void
    {
        $summary = new VatSummary(
            outputBreakdowns: [
                new VatAggregation(
                    direction: 'OUTPUT',
                    taxRate: '19.00',
                    baseAmount: '10000.000',
                    vatAmount: '1900.000',
                    documentCount: 5,
                    isRecoverable: false,
                ),
                new VatAggregation(
                    direction: 'OUTPUT',
                    taxRate: '7.00',
                    baseAmount: '5000.000',
                    vatAmount: '350.000',
                    documentCount: 3,
                    isRecoverable: false,
                ),
            ],
            inputBreakdowns: [
                new VatAggregation(
                    direction: 'INPUT',
                    taxRate: '19.00',
                    baseAmount: '4000.000',
                    vatAmount: '760.000',
                    documentCount: 2,
                    isRecoverable: true,
                ),
            ],
            totalOutputVat: '2250.000',
            totalInputVat: '760.000',
        );

        $declaration = $this->strategy->mapToDeclaration($summary);

        $this->assertSame('DGI', $declaration->formReference);
        $this->assertSame('10000.000', $declaration->fields['base_19']);
        $this->assertSame('1900.000', $declaration->fields['vat_19']);
        $this->assertSame('5000.000', $declaration->fields['base_7']);
        $this->assertSame('350.000', $declaration->fields['vat_7']);
        $this->assertSame('2250.000', $declaration->fields['total_output_vat']);
        $this->assertSame('760.000', $declaration->fields['total_deductible_vat']);
    }
}
