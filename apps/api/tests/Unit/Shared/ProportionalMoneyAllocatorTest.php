<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\ProportionalMoneyAllocator;
use PHPUnit\Framework\TestCase;

final class ProportionalMoneyAllocatorTest extends TestCase
{
    public function test_allocates_exact_total_to_last_positive_base_absorber(): void
    {
        $allocator = new ProportionalMoneyAllocator;

        $shares = $allocator->allocate('100.001', [
            '1.000',
            '1.000',
            '0.000',
            '1.000',
        ], 3, 7);

        $this->assertSame([
            '33.333',
            '33.333',
            '0.000',
            '33.335',
        ], $shares);

        $this->assertSame('100.001', array_reduce(
            $shares,
            static fn (string $carry, string $share): string => bcadd($carry, $share, 3),
            '0.000',
        ));
    }

    public function test_zero_bases_receive_zero_shares(): void
    {
        $allocator = new ProportionalMoneyAllocator;

        $this->assertSame([
            '0.000',
            '0.000',
        ], $allocator->allocate('17.250', [
            '0.000',
            '0.000',
        ], 3, 7));
    }

    /**
     * Ticket 2026-08-03-w4-purchasing-inventory-defects.md #1 (MTP-PUR-17).
     *
     * 30.000 freight split over line values 100.000 / 50.000. The two-step
     * "truncate the proportion, then multiply" path drifts a millime:
     * bcdiv(100, 150, 7) = 0.6666666 (residue dropped) -> * 30.000 =
     * 19.999998 -> truncated to 19.999, with the absorber (last positive
     * line) forced to 10.001 to keep the sum exact. The correct exact-ratio
     * split is 20.000 / 10.000 — nothing should be truncated before the
     * final division.
     */
    public function test_allocates_exact_ratio_without_millime_drift(): void
    {
        $allocator = new ProportionalMoneyAllocator;

        $shares = $allocator->allocate('30.000', [
            '100.000',
            '50.000',
        ], 3, 7);

        $this->assertSame([
            '20.000',
            '10.000',
        ], $shares);

        $this->assertSame('30.000', array_reduce(
            $shares,
            static fn (string $carry, string $share): string => bcadd($carry, $share, 3),
            '0.000',
        ));
    }
}
