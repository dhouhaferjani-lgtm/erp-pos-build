<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Enums;

use App\Modules\Loyalty\Domain\Enums\QualificationType;
use PHPUnit\Framework\TestCase;

class QualificationTypeTest extends TestCase
{
    public function test_enum_has_all_expected_cases(): void
    {
        $cases = QualificationType::cases();

        $this->assertCount(4, $cases);
        $this->assertContains(QualificationType::Spend, $cases);
        $this->assertContains(QualificationType::PointsEarned, $cases);
        $this->assertContains(QualificationType::Visits, $cases);
        $this->assertContains(QualificationType::Manual, $cases);
    }

    public function test_enum_values_are_correct(): void
    {
        $this->assertEquals('spend', QualificationType::Spend->value);
        $this->assertEquals('points_earned', QualificationType::PointsEarned->value);
        $this->assertEquals('visits', QualificationType::Visits->value);
        $this->assertEquals('manual', QualificationType::Manual->value);
    }

    public function test_can_create_from_value(): void
    {
        $this->assertEquals(QualificationType::Spend, QualificationType::from('spend'));
        $this->assertEquals(QualificationType::PointsEarned, QualificationType::from('points_earned'));
        $this->assertEquals(QualificationType::Visits, QualificationType::from('visits'));
        $this->assertEquals(QualificationType::Manual, QualificationType::from('manual'));
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(QualificationType::tryFrom('invalid'));
    }
}
