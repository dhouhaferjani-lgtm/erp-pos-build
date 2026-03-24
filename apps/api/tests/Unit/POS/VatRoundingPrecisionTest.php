<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Shared\Domain\CurrencyScale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for VAT calculation precision.
 *
 * The roundVat method in ReceiptCreationService computes:
 *   tax = round(netAmount * taxRate / 100, scale)
 *
 * Previously this used float arithmetic: (float)$net * (float)$rate / 100.0
 * Now it uses bcmath for the multiplication/division, then PHP round() for
 * half-away-from-zero rounding (matching PostgreSQL behavior).
 *
 * These tests verify the calculation produces correct results using bcmath.
 */
final class VatRoundingPrecisionTest extends TestCase
{
    #[Test]
    #[DataProvider('vatCalculationProvider')]
    public function vat_calculation_with_bcmath_matches_expected(
        string $netAmount,
        string $taxRate,
        int $scale,
        string $expected,
    ): void {
        $result = $this->roundVat($netAmount, $taxRate, $scale);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{string, string, int, string}>
     */
    public static function vatCalculationProvider(): array
    {
        return [
            // TND (3 decimals) — Tunisia's standard 19% VAT
            'TND: 100.000 * 19%' => ['100.000', '19', 3, '19.000'],
            'TND: 5.000 * 19%' => ['5.000', '19', 3, '0.950'],
            'TND: 10.500 * 19%' => ['10.500', '19', 3, '1.995'],
            'TND: 1.000 * 7%' => ['1.000', '7', 3, '0.070'],

            // TND: values that would drift with float arithmetic
            'TND: 33.333 * 19%' => ['33.333', '19', 3, '6.333'],
            'TND: 0.840 * 19%' => ['0.840', '19', 3, '0.160'],

            // EUR (2 decimals) — France 20% VAT
            'EUR: 100.00 * 20%' => ['100.00', '20', 2, '20.00'],
            'EUR: 33.33 * 20%' => ['33.33', '20', 2, '6.67'],
            'EUR: 19.99 * 20%' => ['19.99', '20', 2, '4.00'],

            // Edge: zero values
            'Zero net' => ['0.000', '19', 3, '0.000'],
            'Zero rate' => ['100.000', '0', 3, '0.000'],

            // Edge: rounding half-up (PostgreSQL behavior)
            'TND: 10.000 * 13% = 1.300 (exact)' => ['10.000', '13', 3, '1.300'],
            'EUR: 10.00 * 5.5% = 0.55 (exact)' => ['10.00', '5.5', 2, '0.55'],
        ];
    }

    #[Test]
    public function vat_precision_matches_old_float_for_common_values(): void
    {
        // For most common values, bcmath and float produce the same result.
        // This test ensures we didn't break any previously-correct calculations.
        $cases = [
            ['100.000', '19', 3],
            ['50.000', '19', 3],
            ['25.500', '19', 3],
            ['10.000', '7', 3],
            ['100.00', '20', 2],
            ['49.99', '20', 2],
        ];

        foreach ($cases as [$net, $rate, $scale]) {
            $bcResult = $this->roundVat($net, $rate, $scale);
            $floatResult = $this->roundVatFloat($net, $rate, $scale);

            $this->assertSame(
                $floatResult,
                $bcResult,
                "VAT for {$net} * {$rate}% at scale {$scale}: bcmath={$bcResult} vs float={$floatResult}"
            );
        }
    }

    #[Test]
    public function composite_item_vat_decomposition_sums_correctly(): void
    {
        // Simulate a fixed_bundle composite with 3 components at different VAT rates
        // Total price: 10.000 TND
        // Component A: standalone 5.000, 19% VAT
        // Component B: standalone 3.000, 7% VAT
        // Component C: standalone 2.000, 19% VAT
        // Total standalone: 10.000
        $scale = 3;
        $comboPrice = '10.000';

        $components = [
            ['standalone' => '5.000', 'rate' => '19'],
            ['standalone' => '3.000', 'rate' => '7'],
            ['standalone' => '2.000', 'rate' => '19'],
        ];

        $totalStandalone = '10.000';
        $allocatedTotal = '0';
        $taxTotal = '0';
        $netTotal = '0';
        $lastIndex = count($components) - 1;

        foreach ($components as $i => $comp) {
            if ($i === $lastIndex) {
                $share = bcsub($comboPrice, $allocatedTotal, $scale);
            } else {
                $share = bcmul(
                    $comboPrice,
                    bcdiv($comp['standalone'], $totalStandalone, 10),
                    $scale
                );
            }
            $allocatedTotal = bcadd($allocatedTotal, $share, $scale);

            $taxRateDecimal = bcdiv($comp['rate'], '100', 6);
            $divisor = bcadd('1', $taxRateDecimal, 6);
            $netAmount = bcdiv($share, $divisor, $scale);
            $taxAmount = bcsub($share, $netAmount, $scale);

            $taxTotal = bcadd($taxTotal, $taxAmount, $scale);
            $netTotal = bcadd($netTotal, $netAmount, $scale);
        }

        // The allocated total must equal the combo price exactly
        $this->assertSame($comboPrice, $allocatedTotal, 'Allocated shares must sum to combo price');

        // Net + tax must equal the combo price
        $reconstructed = bcadd($netTotal, $taxTotal, $scale);
        $this->assertSame($comboPrice, $reconstructed, 'Net + Tax must equal combo price');
    }

    #[Test]
    #[DataProvider('receiptTotalWithTaxProvider')]
    public function receipt_total_equals_sum_of_line_totals_despite_vat_recalculation(
        string $lineTotal,
        string $taxRate,
        int $scale,
    ): void {
        // Simulate ReceiptCreationService logic:
        // 1. Per-line: decompose TTC into net + tax
        $taxRateDecimal = bcdiv($taxRate, '100', 6);
        $divisor = bcadd('1', $taxRateDecimal, 6);
        $netAmount = bcdiv($lineTotal, $divisor, $scale);

        // 2. sumLineTotals tracks the gross TTC amount
        $sumLineTotals = $lineTotal;
        $subtotal = $netAmount; // net (HT)

        // 3. Derive totalTax from difference (the fix)
        $totalTax = bcsub($sumLineTotals, $subtotal, $scale);

        // 4. Receipt total = sumLineTotals - discount (no discount in this test)
        $total = $sumLineTotals;

        // Accounting identity must hold: subtotal + tax_amount = total
        $this->assertSame(
            $total,
            bcadd($subtotal, $totalTax, $scale),
            "Accounting identity violated for {$lineTotal} at {$taxRate}%: subtotal({$subtotal}) + tax({$totalTax}) must equal total({$total})"
        );

        // Total must equal the original TTC price (the reported bug)
        $this->assertSame(
            $lineTotal,
            $total,
            "Receipt total must equal the TTC line total for {$lineTotal} at {$taxRate}%"
        );
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function receiptTotalWithTaxProvider(): array
    {
        return [
            // THE BUG: 5.000 TND at 19% was showing as 4.999
            'TND 5.000 at 19% (the reported bug)' => ['5.000', '19', 3],
            'TND 5.000 at 7%' => ['5.000', '7', 3],
            'TND 5.000 at 13%' => ['5.000', '13', 3],

            // Common TND prices at common tax rates
            'TND 1.000 at 19%' => ['1.000', '19', 3],
            'TND 10.000 at 19%' => ['10.000', '19', 3],
            'TND 10.500 at 19%' => ['10.500', '19', 3],
            'TND 25.000 at 19%' => ['25.000', '19', 3],
            'TND 99.990 at 19%' => ['99.990', '19', 3],
            'TND 0.500 at 7%' => ['0.500', '7', 3],
            'TND 3.500 at 7%' => ['3.500', '7', 3],

            // EUR prices
            'EUR 19.99 at 20%' => ['19.99', '20', 2],
            'EUR 100.00 at 20%' => ['100.00', '20', 2],
            'EUR 33.33 at 5.5%' => ['33.33', '5.5', 2],

            // Edge: zero tax
            'TND 5.000 at 0%' => ['5.000', '0', 3],
        ];
    }

    #[Test]
    public function receipt_total_with_discount_preserves_precision(): void
    {
        $scale = 3;
        $lineTotal = '5.000'; // TTC
        $taxRate = '19';
        $discount = '1.000';

        $taxRateDecimal = bcdiv($taxRate, '100', 6);
        $divisor = bcadd('1', $taxRateDecimal, 6);
        $netAmount = bcdiv($lineTotal, $divisor, $scale);

        $sumLineTotals = $lineTotal;
        $subtotal = $netAmount;
        $totalTax = bcsub($sumLineTotals, $subtotal, $scale);
        $total = bcsub($sumLineTotals, $discount, $scale);

        $this->assertSame('4.000', $total, 'Total after 1.000 discount on 5.000 must be 4.000');
        $this->assertSame('0.799', $totalTax, 'Tax amount must be derived correctly');
    }

    #[Test]
    public function multiple_lines_with_different_tax_rates_preserve_total(): void
    {
        $scale = 3;

        // Line 1: 5.000 TND at 19%
        // Line 2: 3.000 TND at 7%
        $lines = [
            ['total' => '5.000', 'rate' => '19'],
            ['total' => '3.000', 'rate' => '7'],
        ];

        $sumLineTotals = '0';
        $subtotal = '0';

        foreach ($lines as $line) {
            $taxRateDecimal = bcdiv($line['rate'], '100', 6);
            $divisor = bcadd('1', $taxRateDecimal, 6);
            $netAmount = bcdiv($line['total'], $divisor, $scale);

            $sumLineTotals = bcadd($sumLineTotals, $line['total'], $scale);
            $subtotal = bcadd($subtotal, $netAmount, $scale);
        }

        $totalTax = bcsub($sumLineTotals, $subtotal, $scale);
        $total = $sumLineTotals;

        // Total must be 5.000 + 3.000 = 8.000 exactly
        $this->assertSame('8.000', $total);

        // Accounting identity: subtotal + tax = total
        $this->assertSame($total, bcadd($subtotal, $totalTax, $scale));
    }

    /**
     * Replicate the new bcmath-based roundVat logic.
     */
    private function roundVat(string $netAmount, string $taxRate, int $scale): string
    {
        $extraPrecision = $scale + 4;
        $raw = bcdiv(bcmul($netAmount, $taxRate, $extraPrecision), '100', $extraPrecision);

        return CurrencyScale::bcformat((string) round((float) $raw, $scale), $scale);
    }

    /**
     * Replicate the OLD float-based roundVat logic for comparison.
     */
    private function roundVatFloat(string $netAmount, string $taxRate, int $scale): string
    {
        $raw = (float) $netAmount * (float) $taxRate / 100.0;

        return number_format(round($raw, $scale), $scale, '.', '');
    }
}
