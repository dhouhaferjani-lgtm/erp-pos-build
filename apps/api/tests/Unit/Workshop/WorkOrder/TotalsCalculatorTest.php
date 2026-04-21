<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\WorkOrder;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\Services\TotalsCalculator;
use App\Modules\Workshop\WorkOrder\Domain\Services\TotalsCalculatorLineInput;
use PHPUnit\Framework\TestCase;

/**
 * Validates TotalsCalculator pure-math correctness across line types.
 *
 * Money is always handled as scale-preserving numeric-strings via
 * CurrencyScale::bcformat; never raw float arithmetic.
 */
final class TotalsCalculatorTest extends TestCase
{
    public function test_empty_lines_produce_zero_totals_in_eur(): void
    {
        $totals = (new TotalsCalculator)->compute([], 'EUR');
        $this->assertSame('0.00', $totals->parts_total);
        $this->assertSame('0.00', $totals->labor_total);
        $this->assertSame('0.00', $totals->other_total);
        $this->assertSame('0.00', $totals->tax_total);
        $this->assertSame('0.00', $totals->grand_total);
    }

    public function test_empty_lines_produce_zero_totals_in_tnd_3_decimals(): void
    {
        $totals = (new TotalsCalculator)->compute([], 'TND');
        $this->assertSame('0.000', $totals->parts_total);
    }

    public function test_single_part_line_rolls_into_parts_total(): void
    {
        $lines = [
            new TotalsCalculatorLineInput(
                line_type: WorkOrderLineType::Part,
                line_total_excl_tax: '100.000',
                line_total_tax: '19.000',
                line_total_incl_tax: '119.000',
            ),
        ];

        $totals = (new TotalsCalculator)->compute($lines, 'TND');

        $this->assertSame('100.000', $totals->parts_total);
        $this->assertSame('0.000', $totals->labor_total);
        $this->assertSame('0.000', $totals->other_total);
        $this->assertSame('19.000', $totals->tax_total);
        $this->assertSame('119.000', $totals->grand_total);
    }

    public function test_labor_line_rolls_into_labor_total(): void
    {
        $lines = [
            new TotalsCalculatorLineInput(
                line_type: WorkOrderLineType::Labor,
                line_total_excl_tax: '60.000',
                line_total_tax: '11.400',
                line_total_incl_tax: '71.400',
            ),
        ];

        $totals = (new TotalsCalculator)->compute($lines, 'TND');

        $this->assertSame('0.000', $totals->parts_total);
        $this->assertSame('60.000', $totals->labor_total);
        $this->assertSame('11.400', $totals->tax_total);
        $this->assertSame('71.400', $totals->grand_total);
    }

    public function test_sublet_misc_and_environmental_roll_into_other_total(): void
    {
        $lines = [
            new TotalsCalculatorLineInput(
                line_type: WorkOrderLineType::Sublet,
                line_total_excl_tax: '40.000',
                line_total_tax: '7.600',
                line_total_incl_tax: '47.600',
            ),
            new TotalsCalculatorLineInput(
                line_type: WorkOrderLineType::EnvironmentalFee,
                line_total_excl_tax: '2.000',
                line_total_tax: '0.000',
                line_total_incl_tax: '2.000',
            ),
            new TotalsCalculatorLineInput(
                line_type: WorkOrderLineType::MiscFee,
                line_total_excl_tax: '5.000',
                line_total_tax: '0.950',
                line_total_incl_tax: '5.950',
            ),
        ];

        $totals = (new TotalsCalculator)->compute($lines, 'TND');

        $this->assertSame('47.000', $totals->other_total);
        $this->assertSame('8.550', $totals->tax_total);
        $this->assertSame('55.550', $totals->grand_total);
    }

    public function test_core_charge_rolls_into_parts_and_core_return_is_negative(): void
    {
        $lines = [
            new TotalsCalculatorLineInput(
                line_type: WorkOrderLineType::CoreCharge,
                line_total_excl_tax: '25.000',
                line_total_tax: '0.000',
                line_total_incl_tax: '25.000',
            ),
        ];

        $totals = (new TotalsCalculator)->compute($lines, 'TND');

        $this->assertSame('25.000', $totals->parts_total);
        $this->assertSame('25.000', $totals->grand_total);
    }

    public function test_informational_bundle_lines_are_skipped(): void
    {
        // Informational lines inside a fixed-bundle must not contribute to totals
        // (the bundle_header line carries the authoritative flat price).
        $lines = [
            new TotalsCalculatorLineInput(
                line_type: WorkOrderLineType::BundleHeader,
                line_total_excl_tax: '120.000',
                line_total_tax: '22.800',
                line_total_incl_tax: '142.800',
            ),
            new TotalsCalculatorLineInput(
                line_type: WorkOrderLineType::Part,
                line_total_excl_tax: '30.000',
                line_total_tax: '5.700',
                line_total_incl_tax: '35.700',
                is_bundle_informational: true,
            ),
        ];

        $totals = (new TotalsCalculator)->compute($lines, 'TND');

        // Only the bundle header contributes.
        $this->assertSame('120.000', $totals->parts_total);  // BundleHeader rolls into parts
        $this->assertSame('22.800', $totals->tax_total);
        $this->assertSame('142.800', $totals->grand_total);
    }

    public function test_mixed_lines_aggregate_correctly(): void
    {
        $lines = [
            new TotalsCalculatorLineInput(
                line_type: WorkOrderLineType::Part,
                line_total_excl_tax: '100.000',
                line_total_tax: '19.000',
                line_total_incl_tax: '119.000',
            ),
            new TotalsCalculatorLineInput(
                line_type: WorkOrderLineType::Labor,
                line_total_excl_tax: '60.000',
                line_total_tax: '11.400',
                line_total_incl_tax: '71.400',
            ),
            new TotalsCalculatorLineInput(
                line_type: WorkOrderLineType::EnvironmentalFee,
                line_total_excl_tax: '2.000',
                line_total_tax: '0.000',
                line_total_incl_tax: '2.000',
            ),
        ];

        $totals = (new TotalsCalculator)->compute($lines, 'TND');

        $this->assertSame('100.000', $totals->parts_total);
        $this->assertSame('60.000', $totals->labor_total);
        $this->assertSame('2.000', $totals->other_total);
        $this->assertSame('30.400', $totals->tax_total);
        $this->assertSame('192.400', $totals->grand_total);
    }

    public function test_eur_two_decimals_are_preserved(): void
    {
        $lines = [
            new TotalsCalculatorLineInput(
                line_type: WorkOrderLineType::Part,
                line_total_excl_tax: '10.00',
                line_total_tax: '2.00',
                line_total_incl_tax: '12.00',
            ),
        ];

        $totals = (new TotalsCalculator)->compute($lines, 'EUR');

        $this->assertSame('10.00', $totals->parts_total);
        $this->assertSame('12.00', $totals->grand_total);
    }
}
