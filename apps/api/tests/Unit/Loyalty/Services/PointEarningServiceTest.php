<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\Tier;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Services\PointEarningService;
use App\Modules\Loyalty\Domain\ValueObjects\PointsAmount;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Infrastructure\CurrencyScaleResolver;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PointEarningServiceTest extends TestCase
{
    private PointEarningService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub resolver returning EUR scale=2 for unit tests (no DB required).
        $resolver = new class implements CurrencyScaleResolverInterface
        {
            public function getScale(?string $currencyCode = null): int
            {
                return 2;
            }

            public function getScaleSafe(?string $currencyCode = null, int $fallback = 3): int
            {
                return 2;
            }
        };

        $this->service = new PointEarningService($resolver);
    }

    /**
     * Regression for P1-1: EarnPointsOnReceiptCompleted implements ShouldQueue and
     * runs in a queue worker where no CompanyContext is bound. Before the fix the
     * service called the resolver's no-arg getScale(), which threw
     * UnboundCompanyContextException — the listener caught it and silently dropped
     * the points. With the real resolver and an UNBOUND CompanyContext, the earning
     * path must NOT throw and must award the correct points.
     *
     * @test
     */
    public function it_awards_points_without_a_bound_company_context_using_transaction_currency(): void
    {
        // Real resolver, no company bound (simulates the queue worker context).
        $unboundContext = new CompanyContext;
        $resolver = new CurrencyScaleResolver(
            companyContext: $unboundContext,
            countryFinder: fn (string $code) => null,
        );
        $service = new PointEarningService($resolver);

        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '1.5',
            isActive: true,
        );

        // Currency carried on the transaction → resolves via the static ISO map (EUR=2),
        // no CompanyContext required.
        $transactionData = [
            'amount' => 100.00,
            'currency' => 'EUR',
            'items' => [],
        ];

        $points = $service->calculatePoints($enrollment, $transactionData, $rule);

        $this->assertEqualsWithDelta(150.0, $points->value, 0.0001); // 100 * 1.5
    }

    /**
     * Regression for P1-1: even when NO currency is carried on the transaction (so
     * the resolver cannot resolve from CompanyContext or an explicit code), the
     * service must fall back via getScaleSafe() to the TND canonical floor (3) and
     * still award points rather than throwing.
     *
     * @test
     */
    public function it_awards_points_without_company_context_and_without_currency_via_safe_fallback(): void
    {
        $unboundContext = new CompanyContext;
        $resolver = new CurrencyScaleResolver(
            companyContext: $unboundContext,
            countryFinder: fn (string $code) => null,
        );
        $service = new PointEarningService($resolver);

        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '1.5',
            isActive: true,
        );

        $transactionData = [
            'amount' => 100.00,
            'items' => [],
            // no 'currency' key — must not throw
        ];

        $points = $service->calculatePoints($enrollment, $transactionData, $rule);

        $this->assertEqualsWithDelta(150.0, $points->value, 0.0001);
    }

    /** @test */
    public function it_calculates_points_for_spend_rule_type(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '1.5', // 1.5 points per currency unit
            isActive: true
        );

        $transactionData = [
            'amount' => 100.00, // Spent 100
            'items' => [],
        ];

        // Act
        $points = $this->service->calculatePoints($enrollment, $transactionData, $rule);

        // Assert
        $this->assertEquals(150.0, $points->value); // 100 * 1.5 = 150
    }

    /** @test */
    public function it_calculates_points_for_item_rule_type(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Item,
            rewardValue: '10.0', // 10 points per matching item
            isActive: true,
            conditions: ['product_ids' => ['product-123', 'product-456']]
        );

        $transactionData = [
            'amount' => 200.00,
            'items' => [
                ['product_id' => 'product-123', 'quantity' => 2],
                ['product_id' => 'product-456', 'quantity' => 1],
                ['product_id' => 'product-999', 'quantity' => 1], // Not in rule
            ],
        ];

        // Act
        $points = $this->service->calculatePoints($enrollment, $transactionData, $rule);

        // Assert
        $this->assertEquals(30.0, $points->value); // (2 + 1) * 10 = 30
    }

    /** @test */
    public function it_calculates_points_for_category_rule_type(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Category,
            rewardValue: '5.0', // 5 points per item in category
            isActive: true,
            conditions: ['category_ids' => ['cat-1', 'cat-2']]
        );

        $transactionData = [
            'amount' => 150.00,
            'items' => [
                ['product_id' => 'p1', 'category_id' => 'cat-1', 'quantity' => 3],
                ['product_id' => 'p2', 'category_id' => 'cat-2', 'quantity' => 2],
                ['product_id' => 'p3', 'category_id' => 'cat-999', 'quantity' => 1],
            ],
        ];

        // Act
        $points = $this->service->calculatePoints($enrollment, $transactionData, $rule);

        // Assert
        $this->assertEquals(25.0, $points->value); // (3 + 2) * 5 = 25
    }

    /** @test */
    public function it_calculates_points_for_quantity_rule_type(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Quantity,
            rewardValue: '2.0', // 2 points per item purchased
            isActive: true
        );

        $transactionData = [
            'amount' => 250.00,
            'items' => [
                ['product_id' => 'p1', 'quantity' => 5],
                ['product_id' => 'p2', 'quantity' => 3],
            ],
        ];

        // Act
        $points = $this->service->calculatePoints($enrollment, $transactionData, $rule);

        // Assert
        $this->assertEquals(16.0, $points->value); // (5 + 3) * 2 = 16
    }

    /** @test */
    public function it_calculates_points_for_visit_rule_type(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Visit,
            rewardValue: '50.0', // 50 points per visit
            isActive: true
        );

        $transactionData = [
            'amount' => 10.00,
            'items' => [],
        ];

        // Act
        $points = $this->service->calculatePoints($enrollment, $transactionData, $rule);

        // Assert
        $this->assertEquals(50.0, $points->value);
    }

    /** @test */
    public function it_calculates_points_for_threshold_rule_type(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Threshold,
            rewardValue: '100.0', // 100 points if threshold met
            isActive: true,
            conditions: ['min_purchase_amount' => '50.00']
        );

        $transactionData = [
            'amount' => 75.00, // Above threshold
            'items' => [],
        ];

        // Act
        $points = $this->service->calculatePoints($enrollment, $transactionData, $rule);

        // Assert
        $this->assertEquals(100.0, $points->value);
    }

    /** @test */
    public function it_calculates_zero_points_for_threshold_not_met(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Threshold,
            rewardValue: '100.0',
            isActive: true,
            conditions: ['min_purchase_amount' => '50.00']
        );

        $transactionData = [
            'amount' => 25.00, // Below threshold
            'items' => [],
        ];

        // Act
        $points = $this->service->calculatePoints($enrollment, $transactionData, $rule);

        // Assert
        $this->assertEquals(0.0, $points->value);
    }

    /** @test */
    public function it_applies_tier_multiplier_from_enrollment(): void
    {
        // Arrange
        $tier = $this->createTier(earningMultiplier: '2.0'); // 2x multiplier
        $enrollment = $this->createEnrollment(tier: $tier);
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '1.0',
            isActive: true
        );

        $transactionData = [
            'amount' => 100.00,
            'items' => [],
        ];

        // Act
        $points = $this->service->calculatePoints($enrollment, $transactionData, $rule);

        // Assert
        $this->assertEquals(200.0, $points->value); // 100 * 1.0 * 2.0 = 200
    }

    /** @test */
    public function it_respects_max_earn_per_transaction_cap(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '2.0',
            isActive: true,
            maxEarnPerTransaction: '100.00' // Cap at 100 points
        );

        $transactionData = [
            'amount' => 200.00, // Would earn 400 points
            'items' => [],
        ];

        // Act
        $points = $this->service->calculatePoints($enrollment, $transactionData, $rule);

        // Assert
        $this->assertEquals(100.0, $points->value); // Capped at 100
    }

    /** @test */
    public function it_respects_max_earn_per_day_cap(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '1.0',
            isActive: true,
            maxEarnPerDay: '500.00'
        );

        $calculatedPoints = PointsAmount::fromNumeric(300.0);
        $alreadyEarnedToday = 400.0; // Already earned 400 today

        // Act
        $points = $this->service->applyDailyCap($calculatedPoints, $rule, $alreadyEarnedToday);

        // Assert
        $this->assertEquals(100.0, $points->value); // 500 - 400 = 100 remaining
    }

    /** @test */
    public function it_returns_zero_when_daily_cap_already_reached(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '1.0',
            isActive: true,
            maxEarnPerDay: '500.00'
        );

        $calculatedPoints = PointsAmount::fromNumeric(100.0);
        $alreadyEarnedToday = 500.0; // Already at limit

        // Act
        $points = $this->service->applyDailyCap($calculatedPoints, $rule, $alreadyEarnedToday);

        // Assert
        $this->assertEquals(0.0, $points->value);
    }

    /** @test */
    public function it_detects_rule_applies_when_all_conditions_match(): void
    {
        // Arrange
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '1.0',
            isActive: true,
            conditions: [
                'min_purchase_amount' => '50.00',
                'product_ids' => ['p1', 'p2'],
            ]
        );

        $transactionData = [
            'amount' => 75.00,
            'items' => [
                ['product_id' => 'p1', 'quantity' => 1],
            ],
        ];

        // Act
        $applies = $this->service->ruleApplies($rule, $transactionData);

        // Assert
        $this->assertTrue($applies);
    }

    /** @test */
    public function it_detects_rule_does_not_apply_when_conditions_fail(): void
    {
        // Arrange
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '1.0',
            isActive: true,
            conditions: [
                'min_purchase_amount' => '100.00',
            ]
        );

        $transactionData = [
            'amount' => 50.00, // Below minimum
            'items' => [],
        ];

        // Act
        $applies = $this->service->ruleApplies($rule, $transactionData);

        // Assert
        $this->assertFalse($applies);
    }

    /** @test */
    public function it_detects_rule_applies_when_no_conditions_specified(): void
    {
        // Arrange
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '1.0',
            isActive: true,
            conditions: []
        );

        $transactionData = [
            'amount' => 50.00,
            'items' => [],
        ];

        // Act
        $applies = $this->service->ruleApplies($rule, $transactionData);

        // Assert
        $this->assertTrue($applies);
    }

    /** @test */
    public function it_returns_zero_points_when_rule_inactive(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '1.0',
            isActive: false // Inactive
        );

        $transactionData = [
            'amount' => 100.00,
            'items' => [],
        ];

        // Act
        $points = $this->service->calculatePoints($enrollment, $transactionData, $rule);

        // Assert
        $this->assertEquals(0.0, $points->value);
    }

    /** @test */
    public function it_returns_zero_points_when_outside_date_range(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '1.0',
            isActive: true,
            startDate: Carbon::now()->addDays(1), // Starts tomorrow
            endDate: Carbon::now()->addDays(10)
        );

        $transactionData = [
            'amount' => 100.00,
            'items' => [],
        ];

        // Act
        $points = $this->service->calculatePoints($enrollment, $transactionData, $rule);

        // Assert
        $this->assertEquals(0.0, $points->value);
    }

    /** @test */
    public function it_handles_time_based_conditions(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Time,
            rewardValue: '50.0',
            isActive: true,
            conditions: [
                'time_start' => '14:00',
                'time_end' => '18:00',
                'day_of_week' => [1, 2, 3, 4, 5], // Weekdays
            ]
        );

        $transactionData = [
            'amount' => 100.00,
            'items' => [],
            'timestamp' => Carbon::parse('2024-01-15 15:30:00'), // Monday 3:30 PM
        ];

        // Act
        $applies = $this->service->ruleApplies($rule, $transactionData);

        // Assert
        $this->assertTrue($applies);
    }

    /** @test */
    public function it_returns_zero_for_time_rule_outside_hours(): void
    {
        // Arrange
        $enrollment = $this->createEnrollment();
        $rule = $this->createRule(
            ruleType: EarningRuleType::Time,
            rewardValue: '50.0',
            isActive: true,
            conditions: [
                'time_start' => '14:00',
                'time_end' => '18:00',
            ]
        );

        $transactionData = [
            'amount' => 100.00,
            'items' => [],
            'timestamp' => Carbon::parse('2024-01-15 10:00:00'), // 10 AM - outside range
        ];

        // Act
        $applies = $this->service->ruleApplies($rule, $transactionData);

        // Assert
        $this->assertFalse($applies);
    }

    /** @test */
    public function it_applies_both_tier_multiplier_and_transaction_cap(): void
    {
        // Arrange
        $tier = $this->createTier(earningMultiplier: '3.0');
        $enrollment = $this->createEnrollment(tier: $tier);
        $rule = $this->createRule(
            ruleType: EarningRuleType::Spend,
            rewardValue: '1.0',
            isActive: true,
            maxEarnPerTransaction: '150.00'
        );

        $transactionData = [
            'amount' => 100.00, // Would earn 100 * 1 * 3 = 300, but capped at 150
            'items' => [],
        ];

        // Act
        $points = $this->service->calculatePoints($enrollment, $transactionData, $rule);

        // Assert
        $this->assertEquals(150.0, $points->value);
    }

    // Helper methods

    /**
     * @param  array<string, mixed>  $conditions
     */
    private function createRule(
        EarningRuleType $ruleType,
        string $rewardValue,
        bool $isActive,
        array $conditions = [],
        ?string $maxEarnPerTransaction = null,
        ?string $maxEarnPerDay = null,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): EarningRule {
        $rule = new EarningRule;
        $rule->id = 'rule-'.uniqid();
        $rule->program_id = 'program-123';
        $rule->name = 'Test Rule';
        $rule->rule_type = $ruleType;
        $rule->priority = 1;
        $rule->is_active = $isActive;
        $rule->conditions = $conditions;
        $rule->reward_value = $rewardValue;
        $rule->reward_type = 'points';
        $rule->max_earn_per_transaction = $maxEarnPerTransaction;
        $rule->max_earn_per_day = $maxEarnPerDay;
        $rule->start_date = $startDate;
        $rule->end_date = $endDate;

        return $rule;
    }

    private function createEnrollment(?Tier $tier = null): Enrollment
    {
        $enrollment = new Enrollment;
        $enrollment->id = 'enrollment-'.uniqid();
        $enrollment->program_id = 'program-123';
        $enrollment->member_id = 'member-456';
        $enrollment->current_balance = '100.00';
        $enrollment->lifetime_earned = '500.00';
        $enrollment->lifetime_redeemed = '400.00';
        $enrollment->current_tier_id = $tier?->id;
        $enrollment->status = 'active';
        $enrollment->enrolled_at = Carbon::now();

        if ($tier) {
            $enrollment->setRelation('currentTier', $tier);
        }

        return $enrollment;
    }

    private function createTier(string $earningMultiplier = '1.0'): Tier
    {
        $tier = new Tier;
        $tier->id = 'tier-'.uniqid();
        $tier->program_id = 'program-123';
        $tier->name = 'Gold Tier';
        $tier->level = 2;
        $tier->earning_multiplier = $earningMultiplier;

        return $tier;
    }
}
