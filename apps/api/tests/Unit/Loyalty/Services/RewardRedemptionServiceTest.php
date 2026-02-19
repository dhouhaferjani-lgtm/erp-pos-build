<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Services;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Enums\RewardType;
use App\Modules\Loyalty\Domain\Services\RewardRedemptionService;
use App\Modules\Loyalty\Domain\ValueObjects\PointsAmount;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class RewardRedemptionServiceTest extends TestCase
{
    private RewardRedemptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RewardRedemptionService;
    }

    public function test_member_is_eligible_when_all_conditions_met(): void
    {
        $enrollment = $this->createEnrollment(
            currentBalance: 1000.0,
            currentTierId: 'tier-gold'
        );

        $reward = $this->createReward(
            pointsCost: 500,
            tierIds: ['tier-silver', 'tier-gold', 'tier-platinum'],
            isActive: true,
            startDate: Carbon::now()->subDay(),
            endDate: Carbon::now()->addDay(),
            quantityAvailable: 10
        );

        $this->assertTrue($this->service->isEligible($enrollment, $reward));
    }

    public function test_member_not_eligible_when_insufficient_points(): void
    {
        $enrollment = $this->createEnrollment(
            currentBalance: 400.0
        );

        $reward = $this->createReward(
            pointsCost: 500,
            isActive: true
        );

        $this->assertFalse($this->service->isEligible($enrollment, $reward));
    }

    public function test_member_not_eligible_when_wrong_tier(): void
    {
        $enrollment = $this->createEnrollment(
            currentBalance: 1000.0,
            currentTierId: 'tier-bronze'
        );

        $reward = $this->createReward(
            pointsCost: 500,
            tierIds: ['tier-gold', 'tier-platinum'],
            isActive: true
        );

        $this->assertFalse($this->service->isEligible($enrollment, $reward));
    }

    public function test_member_not_eligible_when_reward_inactive(): void
    {
        $enrollment = $this->createEnrollment(
            currentBalance: 1000.0
        );

        $reward = $this->createReward(
            pointsCost: 500,
            isActive: false
        );

        $this->assertFalse($this->service->isEligible($enrollment, $reward));
    }

    public function test_member_not_eligible_when_before_start_date(): void
    {
        $enrollment = $this->createEnrollment(
            currentBalance: 1000.0
        );

        $reward = $this->createReward(
            pointsCost: 500,
            isActive: true,
            startDate: Carbon::now()->addDay()
        );

        $this->assertFalse($this->service->isEligible($enrollment, $reward));
    }

    public function test_member_not_eligible_when_after_end_date(): void
    {
        $enrollment = $this->createEnrollment(
            currentBalance: 1000.0
        );

        $reward = $this->createReward(
            pointsCost: 500,
            isActive: true,
            startDate: Carbon::now()->subWeek(),
            endDate: Carbon::now()->subDay()
        );

        $this->assertFalse($this->service->isEligible($enrollment, $reward));
    }

    public function test_member_not_eligible_when_quantity_available_is_zero(): void
    {
        $enrollment = $this->createEnrollment(
            currentBalance: 1000.0
        );

        $reward = $this->createReward(
            pointsCost: 500,
            isActive: true,
            quantityAvailable: 0
        );

        $this->assertFalse($this->service->isEligible($enrollment, $reward));
    }

    public function test_member_not_eligible_when_exceeded_quantity_per_member(): void
    {
        $enrollment = $this->createEnrollment(
            currentBalance: 1000.0
        );

        $reward = $this->createReward(
            pointsCost: 500,
            isActive: true,
            quantityPerMember: 1
        );

        // Pass redemption count via context
        $this->assertFalse($this->service->isEligible($enrollment, $reward, 1));
    }

    public function test_calculate_cost_returns_correct_points_amount(): void
    {
        $reward = $this->createReward(pointsCost: 750);

        $cost = $this->service->calculateCost($reward);

        $this->assertInstanceOf(PointsAmount::class, $cost);
        $this->assertEquals(750.0, $cost->value);
    }

    public function test_calculate_value_for_free_item_reward(): void
    {
        $reward = $this->createReward(
            type: RewardType::FreeItem,
            rewardValue: 50.00
        );

        $value = $this->service->calculateValue($reward);

        $this->assertEquals(50.00, $value);
    }

    public function test_calculate_value_for_discount_amount_reward(): void
    {
        $reward = $this->createReward(
            type: RewardType::DiscountAmount,
            rewardValue: 25.50
        );

        $value = $this->service->calculateValue($reward);

        $this->assertEquals(25.50, $value);
    }

    public function test_calculate_value_for_discount_percent_reward(): void
    {
        $reward = $this->createReward(
            type: RewardType::DiscountPercent,
            rewardValue: 15.0,
            maxDiscount: null
        );

        $transactionContext = ['subtotal' => 200.00];
        $value = $this->service->calculateValue($reward, $transactionContext);

        $this->assertEquals(30.00, $value);
    }

    public function test_calculate_value_for_discount_percent_reward_with_max_cap(): void
    {
        $reward = $this->createReward(
            type: RewardType::DiscountPercent,
            rewardValue: 20.0,
            maxDiscount: 25.00
        );

        $transactionContext = ['subtotal' => 200.00]; // 20% would be 40.00
        $value = $this->service->calculateValue($reward, $transactionContext);

        $this->assertEquals(25.00, $value);
    }

    public function test_calculate_value_for_credit_reward(): void
    {
        $reward = $this->createReward(
            type: RewardType::Credit,
            rewardValue: 10.00
        );

        $value = $this->service->calculateValue($reward);

        $this->assertEquals(10.00, $value);
    }

    public function test_validate_qualifying_items_when_configured(): void
    {
        $reward = $this->createReward(
            qualifyingItems: [
                'product_ids' => ['prod-123', 'prod-456'],
            ]
        );

        $cartItems = [
            ['product_id' => 'prod-123', 'quantity' => 2],
            ['product_id' => 'prod-789', 'quantity' => 1],
        ];

        $this->assertTrue($this->service->validateQualifyingItems($reward, $cartItems));
    }

    public function test_validate_qualifying_items_fails_when_no_match(): void
    {
        $reward = $this->createReward(
            qualifyingItems: [
                'product_ids' => ['prod-123', 'prod-456'],
            ]
        );

        $cartItems = [
            ['product_id' => 'prod-789', 'quantity' => 1],
            ['product_id' => 'prod-999', 'quantity' => 1],
        ];

        $this->assertFalse($this->service->validateQualifyingItems($reward, $cartItems));
    }

    public function test_all_items_valid_when_no_qualifying_items_configured(): void
    {
        $reward = $this->createReward(
            qualifyingItems: null
        );

        $cartItems = [
            ['product_id' => 'prod-123', 'quantity' => 2],
            ['product_id' => 'prod-789', 'quantity' => 1],
        ];

        $this->assertTrue($this->service->validateQualifyingItems($reward, $cartItems));
    }

    /**
     * Create a mock Enrollment entity
     */
    private function createEnrollment(
        float $currentBalance = 0.0,
        ?string $currentTierId = null
    ): Enrollment {
        $enrollment = $this->createMock(Enrollment::class);

        $enrollment->method('__get')
            ->willReturnCallback(function ($property) use ($currentBalance, $currentTierId) {
                return match ($property) {
                    'current_balance' => $currentBalance,
                    'current_tier_id' => $currentTierId,
                    default => null,
                };
            });

        return $enrollment;
    }

    /**
     * Create a mock Reward entity
     *
     * @param  array<int, string>|null  $tierIds
     * @param  array<string, mixed>|null  $qualifyingItems
     */
    private function createReward(
        int $pointsCost = 100,
        ?array $tierIds = null,
        bool $isActive = true,
        RewardType $type = RewardType::DiscountAmount,
        float $rewardValue = 0.0,
        ?float $maxDiscount = null,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?int $quantityAvailable = null,
        ?int $quantityPerMember = null,
        ?array $qualifyingItems = null
    ): Reward {
        $reward = $this->createMock(Reward::class);

        $reward->method('__get')
            ->willReturnCallback(function ($property) use (
                $pointsCost,
                $tierIds,
                $isActive,
                $type,
                $rewardValue,
                $maxDiscount,
                $startDate,
                $endDate,
                $quantityAvailable,
                $quantityPerMember,
                $qualifyingItems
            ) {
                return match ($property) {
                    'points_cost' => $pointsCost,
                    'tier_ids' => $tierIds,
                    'is_active' => $isActive,
                    'reward_type' => $type,
                    'reward_value' => $rewardValue,
                    'max_discount' => $maxDiscount,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'quantity_available' => $quantityAvailable,
                    'quantity_per_member' => $quantityPerMember,
                    'qualifying_items' => $qualifyingItems,
                    default => null,
                };
            });

        return $reward;
    }
}
