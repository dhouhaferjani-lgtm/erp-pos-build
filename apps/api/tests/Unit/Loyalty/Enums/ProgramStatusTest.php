<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Enums;

use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use PHPUnit\Framework\TestCase;

class ProgramStatusTest extends TestCase
{
    public function test_enum_has_all_expected_cases(): void
    {
        $cases = ProgramStatus::cases();

        $this->assertCount(4, $cases);
        $this->assertContains(ProgramStatus::Draft, $cases);
        $this->assertContains(ProgramStatus::Active, $cases);
        $this->assertContains(ProgramStatus::Paused, $cases);
        $this->assertContains(ProgramStatus::Archived, $cases);
    }

    public function test_enum_values_are_correct(): void
    {
        $this->assertEquals('draft', ProgramStatus::Draft->value);
        $this->assertEquals('active', ProgramStatus::Active->value);
        $this->assertEquals('paused', ProgramStatus::Paused->value);
        $this->assertEquals('archived', ProgramStatus::Archived->value);
    }

    public function test_can_create_from_value(): void
    {
        $this->assertEquals(ProgramStatus::Draft, ProgramStatus::from('draft'));
        $this->assertEquals(ProgramStatus::Active, ProgramStatus::from('active'));
        $this->assertEquals(ProgramStatus::Paused, ProgramStatus::from('paused'));
        $this->assertEquals(ProgramStatus::Archived, ProgramStatus::from('archived'));
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(ProgramStatus::tryFrom('invalid'));
    }
}
