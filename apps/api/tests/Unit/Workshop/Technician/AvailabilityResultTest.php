<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\Technician;

use App\Modules\Workshop\Technician\Domain\ValueObjects\AvailabilityResult;
use PHPUnit\Framework\TestCase;

final class AvailabilityResultTest extends TestCase
{
    public function test_yes_factory_sets_status_and_empty_reason(): void
    {
        $result = AvailabilityResult::yes();
        $this->assertTrue($result->isYes());
        $this->assertFalse($result->isNo());
        $this->assertFalse($result->isPartial());
        $this->assertSame('', $result->reason);
    }

    public function test_no_factory_carries_reason(): void
    {
        $result = AvailabilityResult::no('outside_schedule');
        $this->assertTrue($result->isNo());
        $this->assertFalse($result->isYes());
        $this->assertFalse($result->isPartial());
        $this->assertSame('outside_schedule', $result->reason);
    }

    public function test_partial_factory_carries_reason(): void
    {
        $result = AvailabilityResult::partial('partial_leave_overlap');
        $this->assertTrue($result->isPartial());
        $this->assertFalse($result->isYes());
        $this->assertFalse($result->isNo());
        $this->assertSame('partial_leave_overlap', $result->reason);
    }

    public function test_predicates_are_mutually_exclusive(): void
    {
        foreach ([AvailabilityResult::yes(), AvailabilityResult::no('x'), AvailabilityResult::partial('y')] as $r) {
            $flags = [$r->isYes(), $r->isNo(), $r->isPartial()];
            $this->assertSame(1, array_sum(array_map(static fn (bool $b): int => (int) $b, $flags)));
        }
    }
}
