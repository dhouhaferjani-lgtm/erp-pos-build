<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Enums;

use App\Modules\Loyalty\Domain\Enums\ProgramType;
use PHPUnit\Framework\TestCase;

class ProgramTypeTest extends TestCase
{
    public function test_enum_has_all_expected_cases(): void
    {
        $cases = ProgramType::cases();

        $this->assertCount(5, $cases);
        $this->assertContains(ProgramType::Points, $cases);
        $this->assertContains(ProgramType::Stamps, $cases);
        $this->assertContains(ProgramType::Visits, $cases);
        $this->assertContains(ProgramType::Cashback, $cases);
        $this->assertContains(ProgramType::Hybrid, $cases);
    }

    public function test_enum_values_are_correct(): void
    {
        $this->assertEquals('points', ProgramType::Points->value);
        $this->assertEquals('stamps', ProgramType::Stamps->value);
        $this->assertEquals('visits', ProgramType::Visits->value);
        $this->assertEquals('cashback', ProgramType::Cashback->value);
        $this->assertEquals('hybrid', ProgramType::Hybrid->value);
    }

    public function test_can_create_from_value(): void
    {
        $this->assertEquals(ProgramType::Points, ProgramType::from('points'));
        $this->assertEquals(ProgramType::Stamps, ProgramType::from('stamps'));
        $this->assertEquals(ProgramType::Visits, ProgramType::from('visits'));
        $this->assertEquals(ProgramType::Cashback, ProgramType::from('cashback'));
        $this->assertEquals(ProgramType::Hybrid, ProgramType::from('hybrid'));
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(ProgramType::tryFrom('invalid'));
    }

    public function test_enum_is_comparable(): void
    {
        $this->assertTrue(ProgramType::Points === ProgramType::Points);
        $this->assertFalse(ProgramType::Points === ProgramType::Stamps);
    }

    public function test_enum_can_be_used_in_match(): void
    {
        $type = ProgramType::Points;

        $result = match ($type) {
            ProgramType::Points => 'Points-based',
            ProgramType::Stamps => 'Stamp card',
            ProgramType::Visits => 'Visit tracking',
            ProgramType::Cashback => 'Cashback',
            ProgramType::Hybrid => 'Hybrid program',
        };

        $this->assertEquals('Points-based', $result);
    }
}
