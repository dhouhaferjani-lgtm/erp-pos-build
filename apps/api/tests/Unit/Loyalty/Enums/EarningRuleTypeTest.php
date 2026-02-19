<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Enums;

use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use PHPUnit\Framework\TestCase;

class EarningRuleTypeTest extends TestCase
{
    public function test_enum_has_all_expected_cases(): void
    {
        $cases = EarningRuleType::cases();

        $this->assertCount(7, $cases);
        $this->assertContains(EarningRuleType::Spend, $cases);
        $this->assertContains(EarningRuleType::Item, $cases);
        $this->assertContains(EarningRuleType::Category, $cases);
        $this->assertContains(EarningRuleType::Quantity, $cases);
        $this->assertContains(EarningRuleType::Visit, $cases);
        $this->assertContains(EarningRuleType::Threshold, $cases);
        $this->assertContains(EarningRuleType::Time, $cases);
    }

    public function test_enum_values_are_correct(): void
    {
        $this->assertEquals('spend', EarningRuleType::Spend->value);
        $this->assertEquals('item', EarningRuleType::Item->value);
        $this->assertEquals('category', EarningRuleType::Category->value);
        $this->assertEquals('quantity', EarningRuleType::Quantity->value);
        $this->assertEquals('visit', EarningRuleType::Visit->value);
        $this->assertEquals('threshold', EarningRuleType::Threshold->value);
        $this->assertEquals('time', EarningRuleType::Time->value);
    }

    public function test_can_create_from_value(): void
    {
        $this->assertEquals(EarningRuleType::Spend, EarningRuleType::from('spend'));
        $this->assertEquals(EarningRuleType::Item, EarningRuleType::from('item'));
        $this->assertEquals(EarningRuleType::Category, EarningRuleType::from('category'));
        $this->assertEquals(EarningRuleType::Quantity, EarningRuleType::from('quantity'));
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(EarningRuleType::tryFrom('invalid'));
    }
}
