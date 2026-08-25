<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\POS\Domain\Services\TransactionDiscountVatAllocator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * D-1 — the PHP twin of `apps/pos/src/lib/fiscal/vatDiscountAllocation.ts`.
 *
 * The two implementations must agree DIGIT FOR DIGIT: the device seals its
 * ventilation and the server-authored path computes its own, and a discounted
 * receipt must declare the same base whichever path authored it. The worked
 * example below is the same fixture the device suite pins
 * (`vatDiscountAllocation.test.ts`), with the residue landing on the same two
 * groups.
 */
final class TransactionDiscountVatAllocatorTest extends TestCase
{
    private TransactionDiscountVatAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new TransactionDiscountVatAllocator;
    }

    public function test_zero_discount_returns_the_line_sums_untouched(): void
    {
        $out = $this->allocator->allocate([
            ['tax_rate' => '19.00', 'net_amount' => '100.000', 'vat_amount' => '19.000'],
            ['tax_rate' => '7.00', 'net_amount' => '50.000', 'vat_amount' => '3.500'],
        ], '0.000', 3);

        $this->assertSame('100.000', $out[0]['net_amount']);
        $this->assertSame('19.000', $out[0]['vat_amount']);
        $this->assertSame('0.000', $out[0]['discount_allocated']);
        $this->assertSame('50.000', $out[1]['net_amount']);
        $this->assertSame('0.000', $out[1]['discount_allocated']);
    }

    /**
     * The canonical worked example: 7 / 13 / 19 % plus an exempt group,
     * Σ gross 640.000, a 50.000 remise at TND scale 3.
     *
     * Exact shares are 18.59375 / 17.65625 / 8.359375 / 5.390625; the floors sum
     * to 49.998, so the two largest remainders (19 % at .750 and exempt at .625)
     * each take one ulp.
     */
    public function test_worked_example_matches_the_device_digit_for_digit(): void
    {
        $out = $this->allocator->allocate([
            ['tax_rate' => '19.00', 'net_amount' => '200.000', 'vat_amount' => '38.000'],
            ['tax_rate' => '13.00', 'net_amount' => '200.000', 'vat_amount' => '26.000'],
            ['tax_rate' => '7.00', 'net_amount' => '100.000', 'vat_amount' => '7.000'],
            ['tax_rate' => '0.00', 'net_amount' => '69.000', 'vat_amount' => '0.000'],
        ], '50.000', 3);

        $this->assertSame([
            ['19.00', '18.594', '184.375', '35.031', '219.406'],
            ['13.00', '17.656', '184.375', '23.969', '208.344'],
            ['7.00', '8.359', '92.188', '6.453', '98.641'],
            ['0.00', '5.391', '63.609', '0.000', '63.609'],
        ], array_map(static fn (array $r): array => [
            $r['tax_rate'], $r['discount_allocated'], $r['net_amount'], $r['vat_amount'], $r['gross_amount'],
        ], $out));

        $sumDiscount = array_reduce($out, static fn (string $c, array $r): string => bcadd($c, $r['discount_allocated'], 3), '0.000');
        $sumNet = array_reduce($out, static fn (string $c, array $r): string => bcadd($c, $r['net_amount'], 3), '0.000');
        $sumVat = array_reduce($out, static fn (string $c, array $r): string => bcadd($c, $r['vat_amount'], 3), '0.000');

        $this->assertSame('50.000', $sumDiscount);
        $this->assertSame('524.547', $sumNet);
        $this->assertSame('65.453', $sumVat);
        // 640.000 gross - 50.000 remise = 590.000 paid, and the base + VAT the
        // ticket declares reconstitutes exactly that.
        $this->assertSame('590.000', bcadd($sumNet, $sumVat, 3));
    }

    public function test_full_comp_zeroes_every_group_with_no_ulp_residue(): void
    {
        $out = $this->allocator->allocate([
            ['tax_rate' => '19.00', 'net_amount' => '200.005', 'vat_amount' => '38.001'],
            ['tax_rate' => '7.00', 'net_amount' => '100.003', 'vat_amount' => '7.000'],
        ], '345.009', 3);

        foreach ($out as $row) {
            $this->assertSame('0.000', $row['net_amount']);
            $this->assertSame('0.000', $row['vat_amount']);
            $this->assertSame('0.000', $row['gross_amount']);
        }
    }

    public function test_residue_is_fully_allocated_on_an_uneven_split(): void
    {
        $out = $this->allocator->allocate([
            ['tax_rate' => '19.00', 'net_amount' => '100.000', 'vat_amount' => '19.000'],
            ['tax_rate' => '13.00', 'net_amount' => '105.310', 'vat_amount' => '13.690'],
            ['tax_rate' => '7.00', 'net_amount' => '111.215', 'vat_amount' => '7.785'],
        ], '10.001', 3);

        $sum = array_reduce($out, static fn (string $c, array $r): string => bcadd($c, $r['discount_allocated'], 3), '0.000');
        $this->assertSame('10.001', $sum);
    }

    public function test_never_allocates_more_than_a_group_can_absorb(): void
    {
        $out = $this->allocator->allocate([
            ['tax_rate' => '19.00', 'net_amount' => '0.001', 'vat_amount' => '0.000'],
            ['tax_rate' => '0.00', 'net_amount' => '999.999', 'vat_amount' => '0.000'],
        ], '500.000', 3);

        foreach ($out as $row) {
            $gross = bcadd($row['net_amount'], $row['vat_amount'], 3);
            $this->assertSame(-1, bccomp('-0.001', $gross, 3), 'group gross must stay non-negative');
        }
        $this->assertSame(0, bccomp('500.000', array_reduce(
            $out,
            static fn (string $c, array $r): string => bcadd($c, $r['discount_allocated'], 3),
            '0.000'
        ), 3));
    }

    public function test_refuses_a_discount_larger_than_the_ticket_gross(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pos_transaction_discount_exceeds_gross/');

        $this->allocator->allocate([
            ['tax_rate' => '19.00', 'net_amount' => '100.000', 'vat_amount' => '19.000'],
        ], '200.000', 3);
    }

    public function test_refuses_a_positive_discount_on_a_zero_gross_ticket(): void
    {
        $this->expectException(RuntimeException::class);

        $this->allocator->allocate([
            ['tax_rate' => '19.00', 'net_amount' => '0.000', 'vat_amount' => '0.000'],
        ], '1.000', 3);
    }
}
