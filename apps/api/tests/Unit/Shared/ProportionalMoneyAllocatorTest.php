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
}
