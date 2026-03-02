<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\POS\Domain\Enums\ConsumptionMode;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConsumptionMode enum
 */
class ConsumptionModeEnumTest extends TestCase
{
    public function test_sur_place_has_correct_value(): void
    {
        $this->assertEquals('SUR_PLACE', ConsumptionMode::SurPlace->value);
    }

    public function test_a_emporter_has_correct_value(): void
    {
        $this->assertEquals('A_EMPORTER', ConsumptionMode::AEmporter->value);
    }

    public function test_can_be_created_from_string(): void
    {
        $this->assertEquals(ConsumptionMode::SurPlace, ConsumptionMode::from('SUR_PLACE'));
        $this->assertEquals(ConsumptionMode::AEmporter, ConsumptionMode::from('A_EMPORTER'));
    }

    public function test_try_from_returns_null_for_invalid(): void
    {
        $this->assertNull(ConsumptionMode::tryFrom('DELIVERY'));
    }
}
