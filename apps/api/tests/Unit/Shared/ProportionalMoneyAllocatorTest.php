<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\ProportionalMoneyAllocator;
use PHPUnit\Framework\TestCase;

class ProportionalMoneyAllocatorTest extends TestCase
{
    public function test_reconciles_remainder_to_last_positive_base(): void
    {
        $allocator = new ProportionalMoneyAllocator;

        $shares = $allocator->allocate(
            total: '0.05',
            bases: ['1', '1', '1'],
            scale: 2,
            workingScale: 6,
        );

        $this->assertSame(['0.01', '0.01', '0.03'], $shares);
        $this->assertSame('0.05', bcadd(bcadd($shares[0], $shares[1], 2), $shares[2], 2));
    }

    public function test_zero_value_lines_never_absorb_remainder(): void
    {
        $allocator = new ProportionalMoneyAllocator;

        $shares = $allocator->allocate(
            total: '0.05',
            bases: ['1', '1', '0'],
            scale: 2,
            workingScale: 6,
        );

        $this->assertSame(['0.02', '0.03', '0.00'], $shares);
    }

    public function test_single_line_receives_total_exactly(): void
    {
        $allocator = new ProportionalMoneyAllocator;

        $shares = $allocator->allocate(
            total: '123.456',
            bases: ['9.99'],
            scale: 3,
            workingScale: 7,
        );

        $this->assertSame(['123.456'], $shares);
    }
}
