<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Application;

use App\Modules\Loyalty\Application\Services\TierManagementService;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\Tier;
use App\Modules\Loyalty\Domain\Enums\QualificationType;
use App\Modules\Loyalty\Domain\Events\TierDowngradedV2;
use App\Modules\Loyalty\Domain\Events\TierUpgradedV2;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TierRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use App\Modules\Loyalty\Domain\Services\TierEvaluationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Unit tests for TierManagementService
 *
 * @group loyalty
 * @group unit
 */
final class TierManagementServiceTest extends TestCase
{
    private TierManagementService $service;

    private EnrollmentRepositoryInterface $enrollmentRepository;

    private TierRepositoryInterface $tierRepository;

    private TransactionRepositoryInterface $transactionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock repositories
        $this->enrollmentRepository = $this->createMock(EnrollmentRepositoryInterface::class);
        $this->tierRepository = $this->createMock(TierRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);

        // Create real TierEvaluationService (already tested, final class)
        $tierEvaluationService = new TierEvaluationService;

        // Create service with mocked repositories and real domain service
        $this->service = new TierManagementService(
            $this->enrollmentRepository,
            $this->tierRepository,
            $this->transactionRepository,
            $tierEvaluationService,
        );

        // Mock database transactions
        DB::shouldReceive('transaction')
            ->andReturnUsing(function ($callback) {
                return $callback();
            });
    }

    /** @test */
    public function it_upgrades_tier_successfully(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $memberStats = [
            'lifetime_spend' => 1000.0,
            'points_earned' => 500,
            'visit_count' => 10,
        ];

        $enrollment = $this->createMockEnrollment($enrollmentId, null); // No current tier
        $silverTier = $this->createMockTier('tier-silver', 'Silver', 1, 500.0);
        $goldTier = $this->createMockTier('tier-gold', 'Gold', 2, 1000.0);
        $tiers = new Collection([$silverTier, $goldTier]);

        Event::fake([TierUpgradedV2::class]);

        // Expectations
        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->tierRepository->expects($this->once())
            ->method('findByProgram')
            ->with($enrollment->program_id)
            ->willReturn($tiers);

        $this->enrollmentRepository->expects($this->once())
            ->method('save')
            ->with($enrollment)
            ->willReturn($enrollment);

        // Act
        $result = $this->service->evaluateAndApplyTierChange($enrollmentId, $memberStats);

        // Assert
        $this->assertTrue($result['tier_changed']);
        $this->assertNull($result['previous_tier_id']);
        $this->assertEquals('tier-gold', $result['new_tier_id']);
        $this->assertTrue($result['is_upgrade']);
        Event::assertDispatched(TierUpgradedV2::class);
    }

    /** @test */
    public function it_downgrades_tier_successfully(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $memberStats = [
            'lifetime_spend' => 300.0, // Below gold threshold
            'points_earned' => 150,
            'visit_count' => 5,
        ];

        $enrollment = $this->createMockEnrollment($enrollmentId, 'tier-gold'); // Currently Gold
        $silverTier = $this->createMockTier('tier-silver', 'Silver', 1, 500.0);
        $goldTier = $this->createMockTier('tier-gold', 'Gold', 2, 1000.0);
        $bronzeTier = $this->createMockTier('tier-bronze', 'Bronze', 0, 0.0);
        $tiers = new Collection([$bronzeTier, $silverTier, $goldTier]);

        Event::fake([TierDowngradedV2::class]);

        // Expectations
        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->tierRepository->expects($this->atLeastOnce())
            ->method('findByProgram')
            ->willReturn($tiers);

        $this->tierRepository->expects($this->once())
            ->method('findById')
            ->with('tier-gold')
            ->willReturn($goldTier);

        $this->enrollmentRepository->expects($this->once())
            ->method('save')
            ->willReturn($enrollment);

        // Act
        $result = $this->service->evaluateAndApplyTierChange($enrollmentId, $memberStats);

        // Assert
        $this->assertTrue($result['tier_changed']);
        $this->assertEquals('tier-gold', $result['previous_tier_id']);
        $this->assertEquals('tier-bronze', $result['new_tier_id']);
        $this->assertFalse($result['is_upgrade']);
        Event::assertDispatched(TierDowngradedV2::class);
    }

    /** @test */
    public function it_does_not_change_tier_when_member_still_qualifies(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $memberStats = [
            'lifetime_spend' => 1200.0, // Still qualifies for gold
            'points_earned' => 600,
            'visit_count' => 12,
        ];

        $enrollment = $this->createMockEnrollment($enrollmentId, 'tier-gold');
        $silverTier = $this->createMockTier('tier-silver', 'Silver', 1, 500.0);
        $goldTier = $this->createMockTier('tier-gold', 'Gold', 2, 1000.0);
        $tiers = new Collection([$silverTier, $goldTier]);

        Event::fake();

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->tierRepository->expects($this->atLeastOnce())
            ->method('findByProgram')
            ->willReturn($tiers);

        $this->tierRepository->expects($this->once())
            ->method('findById')
            ->with('tier-gold')
            ->willReturn($goldTier);

        // Should not save
        $this->enrollmentRepository->expects($this->never())
            ->method('save');

        // Act
        $result = $this->service->evaluateAndApplyTierChange($enrollmentId, $memberStats);

        // Assert
        $this->assertFalse($result['tier_changed']);
        $this->assertEquals('tier-gold', $result['previous_tier_id']);
        $this->assertEquals('tier-gold', $result['new_tier_id']);
        Event::assertNotDispatched(TierUpgradedV2::class);
        Event::assertNotDispatched(TierDowngradedV2::class);
    }

    /** @test */
    public function it_returns_no_change_when_no_tiers_configured(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $memberStats = ['lifetime_spend' => 1000.0];

        $enrollment = $this->createMockEnrollment($enrollmentId, null);
        $tiers = new Collection; // Empty

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->tierRepository->expects($this->once())
            ->method('findByProgram')
            ->willReturn($tiers);

        // Act
        $result = $this->service->evaluateAndApplyTierChange($enrollmentId, $memberStats);

        // Assert
        $this->assertFalse($result['tier_changed']);
        $this->assertNull($result['new_tier_id']);
    }

    /** @test */
    public function it_throws_exception_when_enrollment_not_found(): void
    {
        // Arrange
        $enrollmentId = 'nonexistent';
        $memberStats = ['lifetime_spend' => 1000.0];

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Enrollment with ID {$enrollmentId} not found");

        // Act
        $this->service->evaluateAndApplyTierChange($enrollmentId, $memberStats);
    }

    /** @test */
    public function it_checks_tier_qualification_without_applying(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $memberStats = [
            'lifetime_spend' => 1000.0,
            'points_earned' => 500,
        ];

        $enrollment = $this->createMockEnrollment($enrollmentId, 'tier-silver');
        $silverTier = $this->createMockTier('tier-silver', 'Silver', 1, 500.0);
        $goldTier = $this->createMockTier('tier-gold', 'Gold', 2, 1000.0);
        $tiers = new Collection([$silverTier, $goldTier]);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->tierRepository->expects($this->atLeastOnce())
            ->method('findByProgram')
            ->willReturn($tiers);

        $this->tierRepository->expects($this->once())
            ->method('findById')
            ->with('tier-silver')
            ->willReturn($silverTier);

        // Should not save
        $this->enrollmentRepository->expects($this->never())
            ->method('save');

        // Act
        $result = $this->service->checkTierQualification($enrollmentId, $memberStats);

        // Assert
        $this->assertTrue($result['qualifies_for_tier']);
        $this->assertEquals('tier-gold', $result['tier_id']);
        $this->assertEquals('Gold', $result['tier_name']);
        $this->assertTrue($result['is_upgrade']);
    }

    /** @test */
    public function it_returns_false_when_checking_with_no_tiers(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $memberStats = ['lifetime_spend' => 1000.0];

        $enrollment = $this->createMockEnrollment($enrollmentId, null);
        $tiers = new Collection;

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->tierRepository->expects($this->once())
            ->method('findByProgram')
            ->willReturn($tiers);

        // Act
        $result = $this->service->checkTierQualification($enrollmentId, $memberStats);

        // Assert
        $this->assertFalse($result['qualifies_for_tier']);
        $this->assertNull($result['tier_id']);
    }

    /** @test */
    public function it_assigns_tier_manually(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $tierId = 'tier-gold';

        $enrollment = $this->createMockEnrollment($enrollmentId, 'tier-silver');
        $silverTier = $this->createMockTier('tier-silver', 'Silver', 1, 500.0);
        $goldTier = $this->createMockTier('tier-gold', 'Gold', 2, 1000.0);

        Event::fake([TierUpgradedV2::class]);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        // findById will be called twice: once for tier-gold, once for tier-silver
        $this->tierRepository->expects($this->exactly(2))
            ->method('findById')
            ->willReturnCallback(function ($id) use ($goldTier, $silverTier) {
                return match ($id) {
                    'tier-gold' => $goldTier,
                    'tier-silver' => $silverTier,
                    default => null,
                };
            });

        $this->enrollmentRepository->expects($this->once())
            ->method('save')
            ->willReturn($enrollment);

        // Act
        $result = $this->service->assignTierManually($enrollmentId, $tierId);

        // Assert
        $this->assertTrue($result['tier_changed']);
        $this->assertEquals('tier-silver', $result['previous_tier_id']);
        $this->assertEquals('tier-gold', $result['new_tier_id']);
        $this->assertTrue($result['is_upgrade']);
        Event::assertDispatched(TierUpgradedV2::class);
    }

    /** @test */
    public function it_throws_exception_when_manually_assigning_nonexistent_tier(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $tierId = 'nonexistent-tier';

        $enrollment = $this->createMockEnrollment($enrollmentId, null);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->tierRepository->expects($this->once())
            ->method('findById')
            ->with($tierId)
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Tier with ID {$tierId} not found");

        // Act
        $this->service->assignTierManually($enrollmentId, $tierId);
    }

    /** @test */
    public function it_throws_exception_when_manually_assigning_to_wrong_program(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $tierId = 'tier-gold';

        $enrollment = $this->createMockEnrollment($enrollmentId, null);
        $goldTier = $this->createMockTier('tier-gold', 'Gold', 2, 1000.0);
        $goldTier->program_id = 'different-program'; // Wrong program

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->tierRepository->expects($this->once())
            ->method('findById')
            ->with($tierId)
            ->willReturn($goldTier);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Tier does not belong to the enrollment's program");

        // Act
        $this->service->assignTierManually($enrollmentId, $tierId);
    }

    /** @test */
    public function it_returns_no_change_when_manually_assigning_same_tier(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $tierId = 'tier-gold';

        $enrollment = $this->createMockEnrollment($enrollmentId, 'tier-gold');
        $goldTier = $this->createMockTier('tier-gold', 'Gold', 2, 1000.0);

        Event::fake();

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        // findById will be called twice: once for the tier being assigned, once for current tier
        $this->tierRepository->expects($this->exactly(2))
            ->method('findById')
            ->with('tier-gold')
            ->willReturn($goldTier);

        // Should not save
        $this->enrollmentRepository->expects($this->never())
            ->method('save');

        // Act
        $result = $this->service->assignTierManually($enrollmentId, $tierId);

        // Assert
        $this->assertFalse($result['tier_changed']);
        $this->assertEquals('tier-gold', $result['previous_tier_id']);
        Event::assertNotDispatched(TierUpgradedV2::class);
        Event::assertNotDispatched(TierDowngradedV2::class);
    }

    /**
     * Helper method to create a mock enrollment
     */
    private function createMockEnrollment(string $id, ?string $currentTierId): Enrollment
    {
        $enrollment = new Enrollment([
            'program_id' => 'program-123',
            'member_id' => 'member-123',
            'current_tier_id' => $currentTierId,
            'current_balance' => 100.0,
            'lifetime_earned' => 500.0,
            'lifetime_redeemed' => 50.0,
            'status' => 'ACTIVE',
            'enrolled_at' => now(),
        ]);

        $enrollment->id = $id;

        return $enrollment;
    }

    /**
     * Helper method to create a mock tier
     */
    private function createMockTier(string $id, string $name, int $level, float $threshold): Tier
    {
        $tier = new Tier([
            'program_id' => 'program-123',
            'name' => $name,
            'level' => $level,
            'qualification_type' => QualificationType::Spend,
            'qualification_threshold' => $threshold,
            'earning_multiplier' => 1.0 + ($level * 0.1),
            'benefits' => [],
        ]);

        $tier->id = $id;

        return $tier;
    }
}
