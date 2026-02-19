<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Services;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\Tier;
use App\Modules\Loyalty\Domain\Enums\QualificationType;
use App\Modules\Loyalty\Domain\Services\TierEvaluationService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class TierEvaluationServiceTest extends TestCase
{
    private TierEvaluationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TierEvaluationService;
    }

    public function test_calculate_tier_for_spend_qualification_type(): void
    {
        $enrollment = $this->createMockEnrollment();
        $tiers = $this->createMockTiers(QualificationType::Spend);
        $memberStats = [
            'lifetime_spend' => 5000.0,
            'points_earned' => 0,
            'visit_count' => 0,
        ];

        $result = $this->service->calculateTier($enrollment, $tiers, $memberStats);

        $this->assertNotNull($result);
        $this->assertEquals('Silver', $result->name);
        $this->assertEquals(2, $result->level);
    }

    public function test_calculate_tier_for_points_earned_qualification_type(): void
    {
        $enrollment = $this->createMockEnrollment();
        $tiers = $this->createMockTiers(QualificationType::PointsEarned);
        $memberStats = [
            'lifetime_spend' => 0,
            'points_earned' => 15000,
            'visit_count' => 0,
        ];

        $result = $this->service->calculateTier($enrollment, $tiers, $memberStats);

        $this->assertNotNull($result);
        $this->assertEquals('Gold', $result->name);
        $this->assertEquals(3, $result->level);
    }

    public function test_calculate_tier_for_visits_qualification_type(): void
    {
        $enrollment = $this->createMockEnrollment();
        $tiers = $this->createMockTiers(QualificationType::Visits);
        $memberStats = [
            'lifetime_spend' => 0,
            'points_earned' => 0,
            'visit_count' => 8,
        ];

        $result = $this->service->calculateTier($enrollment, $tiers, $memberStats);

        $this->assertNotNull($result);
        $this->assertEquals('Silver', $result->name);
        $this->assertEquals(2, $result->level);
    }

    public function test_calculate_tier_returns_null_when_no_tiers_match(): void
    {
        $enrollment = $this->createMockEnrollment();
        $tiers = $this->createMockTiers(QualificationType::Spend);
        $memberStats = [
            'lifetime_spend' => 50.0,
            'points_earned' => 0,
            'visit_count' => 0,
        ];

        $result = $this->service->calculateTier($enrollment, $tiers, $memberStats);

        $this->assertNull($result);
    }

    public function test_calculate_tier_returns_highest_qualifying_tier(): void
    {
        $enrollment = $this->createMockEnrollment();
        $tiers = $this->createMockTiers(QualificationType::Spend);
        $memberStats = [
            'lifetime_spend' => 25000.0, // Qualifies for all tiers
            'points_earned' => 0,
            'visit_count' => 0,
        ];

        $result = $this->service->calculateTier($enrollment, $tiers, $memberStats);

        $this->assertNotNull($result);
        $this->assertEquals('Platinum', $result->name);
        $this->assertEquals(4, $result->level);
    }

    public function test_qualifies_for_tier_when_threshold_met(): void
    {
        $tier = $this->createMockTier(
            'Gold',
            3,
            QualificationType::Spend,
            10000.0
        );
        $memberStats = [
            'lifetime_spend' => 10000.0,
            'points_earned' => 0,
            'visit_count' => 0,
        ];

        $result = $this->service->qualifiesForTier($tier, $memberStats);

        $this->assertTrue($result);
    }

    public function test_does_not_qualify_when_threshold_not_met(): void
    {
        $tier = $this->createMockTier(
            'Gold',
            3,
            QualificationType::Spend,
            10000.0
        );
        $memberStats = [
            'lifetime_spend' => 9999.99,
            'points_earned' => 0,
            'visit_count' => 0,
        ];

        $result = $this->service->qualifiesForTier($tier, $memberStats);

        $this->assertFalse($result);
    }

    public function test_should_change_tier_when_levels_differ(): void
    {
        $currentTier = $this->createMockTier('Silver', 2, QualificationType::Spend, 5000.0);
        $calculatedTier = $this->createMockTier('Gold', 3, QualificationType::Spend, 10000.0);

        $result = $this->service->shouldChangeTier($currentTier, $calculatedTier);

        $this->assertTrue($result);
    }

    public function test_should_not_change_tier_when_levels_same(): void
    {
        $currentTier = $this->createMockTier('Gold', 3, QualificationType::Spend, 10000.0);
        $calculatedTier = $this->createMockTier('Gold', 3, QualificationType::Spend, 10000.0);

        $result = $this->service->shouldChangeTier($currentTier, $calculatedTier);

        $this->assertFalse($result);
    }

    public function test_is_upgrade_when_new_tier_level_higher(): void
    {
        $currentTier = $this->createMockTier('Silver', 2, QualificationType::Spend, 5000.0);
        $newTier = $this->createMockTier('Gold', 3, QualificationType::Spend, 10000.0);

        $result = $this->service->isUpgrade($currentTier, $newTier);

        $this->assertTrue($result);
    }

    public function test_is_not_upgrade_when_new_tier_level_lower(): void
    {
        $currentTier = $this->createMockTier('Gold', 3, QualificationType::Spend, 10000.0);
        $newTier = $this->createMockTier('Silver', 2, QualificationType::Spend, 5000.0);

        $result = $this->service->isUpgrade($currentTier, $newTier);

        $this->assertFalse($result);
    }

    public function test_is_not_upgrade_when_tiers_are_same(): void
    {
        $currentTier = $this->createMockTier('Gold', 3, QualificationType::Spend, 10000.0);
        $newTier = $this->createMockTier('Gold', 3, QualificationType::Spend, 10000.0);

        $result = $this->service->isUpgrade($currentTier, $newTier);

        $this->assertFalse($result);
    }

    public function test_apply_tier_multiplier_multiplies_points_correctly(): void
    {
        $tier = $this->createMockTier('Gold', 3, QualificationType::Spend, 10000.0, 1.5);
        $basePoints = 100.0;

        $result = $this->service->applyTierMultiplier($basePoints, $tier);

        $this->assertEquals(150.0, $result);
    }

    public function test_apply_tier_multiplier_with_one_point_zero_returns_same_points(): void
    {
        $tier = $this->createMockTier('Bronze', 1, QualificationType::Spend, 1000.0, 1.0);
        $basePoints = 100.0;

        $result = $this->service->applyTierMultiplier($basePoints, $tier);

        $this->assertEquals(100.0, $result);
    }

    public function test_tier_evaluation_with_no_current_tier_new_member(): void
    {
        $currentTier = null;
        $calculatedTier = $this->createMockTier('Bronze', 1, QualificationType::Spend, 1000.0);

        $shouldChange = $this->service->shouldChangeTier($currentTier, $calculatedTier);
        $isUpgrade = $this->service->isUpgrade($currentTier, $calculatedTier);

        $this->assertTrue($shouldChange);
        $this->assertTrue($isUpgrade);
    }

    public function test_calculate_tier_with_manual_qualification_type_returns_null(): void
    {
        $enrollment = $this->createMockEnrollment();
        $tiers = $this->createMockTiers(QualificationType::Manual);
        $memberStats = [
            'lifetime_spend' => 25000.0,
            'points_earned' => 0,
            'visit_count' => 0,
        ];

        $result = $this->service->calculateTier($enrollment, $tiers, $memberStats);

        // Manual tiers cannot be calculated automatically
        $this->assertNull($result);
    }

    public function test_qualifies_for_tier_with_points_earned_threshold(): void
    {
        $tier = $this->createMockTier(
            'Platinum',
            4,
            QualificationType::PointsEarned,
            20000.0
        );
        $memberStats = [
            'lifetime_spend' => 0,
            'points_earned' => 25000,
            'visit_count' => 0,
        ];

        $result = $this->service->qualifiesForTier($tier, $memberStats);

        $this->assertTrue($result);
    }

    public function test_qualifies_for_tier_with_visits_threshold(): void
    {
        $tier = $this->createMockTier(
            'Gold',
            3,
            QualificationType::Visits,
            15.0
        );
        $memberStats = [
            'lifetime_spend' => 0,
            'points_earned' => 0,
            'visit_count' => 20,
        ];

        $result = $this->service->qualifiesForTier($tier, $memberStats);

        $this->assertTrue($result);
    }

    /**
     * Helper method to create a mock Enrollment
     */
    private function createMockEnrollment(): Enrollment
    {
        return $this->createStub(Enrollment::class);
    }

    /**
     * Helper method to create a mock Tier
     */
    private function createMockTier(
        string $name,
        int $level,
        QualificationType $qualificationType,
        float $threshold,
        float $multiplier = 1.0
    ): Tier {
        $tier = new class($name, $level, $qualificationType, $threshold, $multiplier) extends Tier
        {
            public function __construct(
                string $name,
                int $level,
                QualificationType $qualificationType,
                float $threshold,
                float $multiplier
            ) {
                // Don't call parent constructor to avoid database requirements
                $this->name = $name;
                $this->level = $level;
                $this->qualification_type = $qualificationType;
                $this->qualification_threshold = $threshold;
                $this->earning_multiplier = $multiplier;
            }
        };

        return $tier;
    }

    /**
     * Helper method to create a collection of mock tiers
     *
     * @return Collection<int, Tier>
     */
    private function createMockTiers(QualificationType $qualificationType): Collection
    {
        // Use different thresholds based on qualification type
        if ($qualificationType === QualificationType::Visits) {
            return new Collection([
                $this->createMockTier('Bronze', 1, $qualificationType, 3.0, 1.0),
                $this->createMockTier('Silver', 2, $qualificationType, 5.0, 1.25),
                $this->createMockTier('Gold', 3, $qualificationType, 10.0, 1.5),
                $this->createMockTier('Platinum', 4, $qualificationType, 20.0, 2.0),
            ]);
        }

        // For SPEND and POINTS_EARNED use larger thresholds
        return new Collection([
            $this->createMockTier('Bronze', 1, $qualificationType, 1000.0, 1.0),
            $this->createMockTier('Silver', 2, $qualificationType, 5000.0, 1.25),
            $this->createMockTier('Gold', 3, $qualificationType, 10000.0, 1.5),
            $this->createMockTier('Platinum', 4, $qualificationType, 20000.0, 2.0),
        ]);
    }
}
