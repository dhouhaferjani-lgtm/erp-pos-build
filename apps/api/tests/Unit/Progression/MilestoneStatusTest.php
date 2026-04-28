<?php

declare(strict_types=1);

namespace Tests\Unit\Progression;

use App\Modules\Progression\Domain\Enums\MilestoneStatus;
use PHPUnit\Framework\TestCase;

final class MilestoneStatusTest extends TestCase
{
    public function test_has_exactly_four_cases(): void
    {
        $this->assertCount(4, MilestoneStatus::cases());
    }

    public function test_cases_are_pending_in_progress_completed_skipped(): void
    {
        $this->assertSame('pending', MilestoneStatus::Pending->value);
        $this->assertSame('in_progress', MilestoneStatus::InProgress->value);
        $this->assertSame('completed', MilestoneStatus::Completed->value);
        $this->assertSame('skipped', MilestoneStatus::Skipped->value);
    }

    public function test_is_terminal_returns_true_for_completed_and_skipped(): void
    {
        $this->assertTrue(MilestoneStatus::Completed->isTerminal());
        $this->assertTrue(MilestoneStatus::Skipped->isTerminal());
        $this->assertFalse(MilestoneStatus::Pending->isTerminal());
        $this->assertFalse(MilestoneStatus::InProgress->isTerminal());
    }
}
