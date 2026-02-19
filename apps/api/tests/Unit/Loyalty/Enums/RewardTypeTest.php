<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Enums;

use App\Modules\Loyalty\Domain\Enums\RewardType;
use PHPUnit\Framework\TestCase;

class RewardTypeTest extends TestCase
{
    public function test_enum_has_all_expected_cases(): void
    {
        $cases = RewardType::cases();

        $this->assertCount(6, $cases);
        $this->assertContains(RewardType::FreeItem, $cases);
        $this->assertContains(RewardType::DiscountAmount, $cases);
        $this->assertContains(RewardType::DiscountPercent, $cases);
        $this->assertContains(RewardType::Choice, $cases);
        $this->assertContains(RewardType::Credit, $cases);
        $this->assertContains(RewardType::External, $cases);
    }

    public function test_enum_values_are_correct(): void
    {
        $this->assertEquals('free_item', RewardType::FreeItem->value);
        $this->assertEquals('discount_amount', RewardType::DiscountAmount->value);
        $this->assertEquals('discount_percent', RewardType::DiscountPercent->value);
        $this->assertEquals('choice', RewardType::Choice->value);
        $this->assertEquals('credit', RewardType::Credit->value);
        $this->assertEquals('external', RewardType::External->value);
    }

    public function test_can_create_from_value(): void
    {
        $this->assertEquals(RewardType::FreeItem, RewardType::from('free_item'));
        $this->assertEquals(RewardType::DiscountAmount, RewardType::from('discount_amount'));
        $this->assertEquals(RewardType::DiscountPercent, RewardType::from('discount_percent'));
        $this->assertEquals(RewardType::Choice, RewardType::from('choice'));
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(RewardType::tryFrom('invalid'));
    }
}
