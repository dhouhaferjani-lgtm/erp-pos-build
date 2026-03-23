<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Domain\Enums\VatExportFormat;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Modules\Taxation\Infrastructure\Strategies\UkVatStrategy;
use PHPUnit\Framework\TestCase;

class UkVatStrategyTest extends TestCase
{
    private UkVatStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new UkVatStrategy;
    }

    public function test_default_period_type(): void
    {
        $this->assertSame(VatPeriodType::Quarterly, $this->strategy->getDefaultPeriodType());
    }

    public function test_generates_correct_periods(): void
    {
        // UK fiscal year starts in April (month 4)
        $periods = $this->strategy->generatePeriods(4, 2026);

        $this->assertCount(4, $periods);

        // Q1: April - June
        $this->assertSame('Q1 2026/27', $periods[0]['label']);
        $this->assertSame('2026-04-01', $periods[0]['period_start']);
        $this->assertSame('2026-06-30', $periods[0]['period_end']);
        $this->assertSame('QUARTERLY', $periods[0]['period_type']);

        // Q2: July - September
        $this->assertSame('Q2 2026/27', $periods[1]['label']);
        $this->assertSame('2026-07-01', $periods[1]['period_start']);
        $this->assertSame('2026-09-30', $periods[1]['period_end']);

        // Q3: October - December
        $this->assertSame('Q3 2026/27', $periods[2]['label']);
        $this->assertSame('2026-10-01', $periods[2]['period_start']);
        $this->assertSame('2026-12-31', $periods[2]['period_end']);

        // Q4: January - March (next year)
        $this->assertSame('Q4 2026/27', $periods[3]['label']);
        $this->assertSame('2027-01-01', $periods[3]['period_start']);
        $this->assertSame('2027-03-31', $periods[3]['period_end']);
    }

    public function test_fiscal_year_starting_january_produces_calendar_quarters(): void
    {
        $periods = $this->strategy->generatePeriods(1, 2026);

        $this->assertCount(4, $periods);

        $this->assertSame('Q1 2026', $periods[0]['label']);
        $this->assertSame('2026-01-01', $periods[0]['period_start']);
        $this->assertSame('2026-03-31', $periods[0]['period_end']);

        $this->assertSame('Q4 2026', $periods[3]['label']);
        $this->assertSame('2026-10-01', $periods[3]['period_start']);
        $this->assertSame('2026-12-31', $periods[3]['period_end']);
    }

    public function test_expected_rates(): void
    {
        $rates = $this->strategy->getExpectedRates();

        $this->assertSame(['20.00', '5.00', '0.00'], $rates);
    }

    public function test_supported_export_formats(): void
    {
        $formats = $this->strategy->getSupportedExportFormats();

        $this->assertSame([VatExportFormat::Pdf, VatExportFormat::Csv, VatExportFormat::MtdJson], $formats);
    }

    public function test_map_to_declaration_9_box_model(): void
    {
        $summary = new VatSummary(
            outputBreakdowns: [
                new VatAggregation(
                    direction: 'OUTPUT',
                    taxRate: '20.00',
                    baseAmount: '100000.000',
                    vatAmount: '20000.000',
                    documentCount: 50,
                    isRecoverable: false,
                ),
                new VatAggregation(
                    direction: 'OUTPUT',
                    taxRate: '5.00',
                    baseAmount: '5000.000',
                    vatAmount: '250.000',
                    documentCount: 3,
                    isRecoverable: false,
                ),
            ],
            inputBreakdowns: [
                new VatAggregation(
                    direction: 'INPUT',
                    taxRate: '20.00',
                    baseAmount: '40000.000',
                    vatAmount: '8000.000',
                    documentCount: 20,
                    isRecoverable: true,
                ),
            ],
            totalOutputVat: '20250.000',
            totalInputVat: '8000.000',
        );

        $declaration = $this->strategy->mapToDeclaration($summary);

        $this->assertSame('VAT100', $declaration->formReference);

        // Box 1: VAT due on sales
        $this->assertSame('20250.000', $declaration->fields['box_1_vat_due_sales']);
        // Box 2: VAT due on acquisitions (0 for domestic-only)
        $this->assertSame('0.000', $declaration->fields['box_2_vat_due_acquisitions']);
        // Box 3: Total VAT due (box 1 + box 2)
        $this->assertSame('20250.000', $declaration->fields['box_3_total_vat_due']);
        // Box 4: VAT reclaimed
        $this->assertSame('8000.000', $declaration->fields['box_4_vat_reclaimed']);
        // Box 5: Net VAT (box 3 - box 4) — must be whole pounds
        $this->assertSame(12250, $declaration->fields['box_5_net_vat']);
        // Box 6: Total sales excluding VAT — whole pounds
        $this->assertSame(105000, $declaration->fields['box_6_total_sales_ex_vat']);
        // Box 7: Total purchases excluding VAT — whole pounds
        $this->assertSame(40000, $declaration->fields['box_7_total_purchases_ex_vat']);
        // Box 8: Total supplies ex VAT to EU — whole pounds
        $this->assertSame(0, $declaration->fields['box_8_total_supplies_eu']);
        // Box 9: Total acquisitions ex VAT from EU — whole pounds
        $this->assertSame(0, $declaration->fields['box_9_total_acquisitions_eu']);
    }
}
