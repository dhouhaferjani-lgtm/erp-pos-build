<?php

declare(strict_types=1);

namespace Tests\Unit\Progression;

use App\Modules\Progression\Domain\Enums\ModuleReadinessStatus;
use PHPUnit\Framework\TestCase;

final class ModuleReadinessStatusTest extends TestCase
{
    public function test_has_exactly_four_cases(): void
    {
        $this->assertCount(4, ModuleReadinessStatus::cases());
    }

    public function test_cases_are_locked_available_ready_active(): void
    {
        $this->assertSame('locked', ModuleReadinessStatus::Locked->value);
        $this->assertSame('available', ModuleReadinessStatus::Available->value);
        $this->assertSame('ready', ModuleReadinessStatus::Ready->value);
        $this->assertSame('active', ModuleReadinessStatus::Active->value);
    }

    public function test_can_activate_returns_true_only_for_ready(): void
    {
        $this->assertTrue(ModuleReadinessStatus::Ready->canActivate());
        $this->assertFalse(ModuleReadinessStatus::Locked->canActivate());
        $this->assertFalse(ModuleReadinessStatus::Available->canActivate());
        $this->assertFalse(ModuleReadinessStatus::Active->canActivate());
    }
}
