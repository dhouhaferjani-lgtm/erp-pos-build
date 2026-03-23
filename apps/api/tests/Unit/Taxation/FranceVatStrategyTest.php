<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Modules\Taxation\Infrastructure\Strategies\FranceVatStrategy;
use PHPUnit\Framework\TestCase;

class FranceVatStrategyTest extends TestCase
{
    private FranceVatStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new FranceVatStrategy;
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
        $this->assertSame('Janvier 2026', $periods[0]['label']);
        $this->assertSame('2026-01-01', $periods[0]['period_start']);
        $this->assertSame('2026-01-31', $periods[0]['period_end']);
        $this->assertSame('MONTHLY', $periods[0]['period_type']);

        // December
        $this->assertSame('Décembre 2026', $periods[11]['label']);
        $this->assertSame('2026-12-01', $periods[11]['period_start']);
        $this->assertSame('2026-12-31', $periods[11]['period_end']);
    }

    public function test_expected_rates(): void
    {
        $rates = $this->strategy->getExpectedRates();

        $this->assertSame(['20.00', '10.00', '5.50', '2.10'], $rates);
    }

    public function test_supported_export_formats(): void
    {
        $formats = $this->strategy->getSupportedExportFormats();

        $this->assertSame([VatExportFormat::Pdf, VatExportFormat::Csv, VatExportFormat::Fec], $formats);
    }

    public function test_map_to_declaration(): void
    {
        $summary = new VatSummary(
            outputBreakdowns: [
                new VatAggregation(
                    direction: 'OUTPUT',
                    taxRate: '20.00',
                    baseAmount: '50000.000',
                    vatAmount: '10000.000',
                    documentCount: 20,
                    isRecoverable: false,
                ),
                new VatAggregation(
                    direction: 'OUTPUT',
                    taxRate: '5.50',
                    baseAmount: '10000.000',
                    vatAmount: '550.000',
                    documentCount: 5,
                    isRecoverable: false,
                ),
                new VatAggregation(
                    direction: 'OUTPUT',
                    taxRate: '10.00',
                    baseAmount: '8000.000',
                    vatAmount: '800.000',
                    documentCount: 4,
                    isRecoverable: false,
                ),
                new VatAggregation(
                    direction: 'OUTPUT',
                    taxRate: '2.10',
                    baseAmount: '2000.000',
                    vatAmount: '42.000',
                    documentCount: 1,
                    isRecoverable: false,
                ),
            ],
            inputBreakdowns: [
                new VatAggregation(
                    direction: 'INPUT',
                    taxRate: '20.00',
                    baseAmount: '20000.000',
                    vatAmount: '4000.000',
                    documentCount: 10,
                    isRecoverable: true,
                ),
            ],
            totalOutputVat: '11392.000',
            totalInputVat: '4000.000',
        );

        $declaration = $this->strategy->mapToDeclaration($summary);

        $this->assertSame('CA3', $declaration->formReference);
        // CA3 line numbers
        $this->assertSame('50000.000', $declaration->fields['line_08_base']);
        $this->assertSame('10000.000', $declaration->fields['line_08_vat']);
        $this->assertSame('10000.000', $declaration->fields['line_09_base']);
        $this->assertSame('550.000', $declaration->fields['line_09_vat']);
        $this->assertSame('8000.000', $declaration->fields['line_9B_base']);
        $this->assertSame('800.000', $declaration->fields['line_9B_vat']);
        $this->assertSame('2000.000', $declaration->fields['line_11_base']);
        $this->assertSame('42.000', $declaration->fields['line_11_vat']);
        $this->assertSame('11392.000', $declaration->fields['line_16_total_output']);
        $this->assertSame('4000.000', $declaration->fields['line_19_deductible_goods']);
    }
}
