<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Application;

use App\Modules\Loyalty\Application\DTOs\TransactionData;
use App\Modules\Loyalty\Application\Services\RedemptionProcessingService;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\RewardType;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Loyalty\Domain\Events\RewardRedeemedV2;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\RewardRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use App\Modules\Loyalty\Domain\Services\RewardRedemptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Unit tests for RedemptionProcessingService
 *
 * @group loyalty
 * @group unit
 */
final class RedemptionProcessingServiceTest extends TestCase
{
    private RedemptionProcessingService $service;

    private EnrollmentRepositoryInterface $enrollmentRepository;

    private RewardRepositoryInterface $rewardRepository;

    private TransactionRepositoryInterface $transactionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock repositories
        $this->enrollmentRepository = $this->createMock(EnrollmentRepositoryInterface::class);
        $this->rewardRepository = $this->createMock(RewardRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);

        // Create real RewardRedemptionService (already tested, final class)
        $rewardRedemptionService = new RewardRedemptionService($this->transactionRepository);

        // Create service with mocked repositories and real domain service
        $this->service = new RedemptionProcessingService(
            $this->enrollmentRepository,
            $this->rewardRepository,
            $this->transactionRepository,
            $rewardRedemptionService,
        );

        // Mock database transactions
        DB::shouldReceive('transaction')
            ->andReturnUsing(function ($callback) {
                return $callback();
            });
    }

    /** @test */
    public function it_redeems_reward_successfully(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $rewardId = 'reward-456';
        $pointsCost = 100.0;

        $enrollment = $this->createMockEnrollment($enrollmentId, 150.0); // Has enough points
        $reward = $this->createMockReward($rewardId, $pointsCost);
        $transaction = $this->createMockTransaction($enrollmentId, -$pointsCost, $rewardId);

        Event::fake([RewardRedeemedV2::class]);

        // Expectations
        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->with($rewardId)
            ->willReturn($reward);

        $this->transactionRepository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(Transaction::class))
            ->willReturn($transaction);

        $this->enrollmentRepository->expects($this->once())
            ->method('save')
            ->with($enrollment)
            ->willReturn($enrollment);

        // Act
        $result = $this->service->redeemReward($enrollmentId, $rewardId);

        // Assert
        $this->assertInstanceOf(TransactionData::class, $result);
        $this->assertEquals(-$pointsCost, $result->amount);
        $this->assertEquals($rewardId, $result->reward_id);
        Event::assertDispatched(RewardRedeemedV2::class);
    }

    /** @test */
    public function it_throws_exception_when_enrollment_not_found(): void
    {
        // Arrange
        $enrollmentId = 'nonexistent-enrollment';
        $rewardId = 'reward-456';

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Enrollment with ID {$enrollmentId} not found");

        // Act
        $this->service->redeemReward($enrollmentId, $rewardId);
    }

    /** @test */
    public function it_throws_exception_when_reward_not_found(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $rewardId = 'nonexistent-reward';

        $enrollment = $this->createMockEnrollment($enrollmentId, 150.0);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->with($rewardId)
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Reward with ID {$rewardId} not found");

        // Act
        $this->service->redeemReward($enrollmentId, $rewardId);
    }

    /** @test */
    public function it_throws_exception_when_reward_not_active(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $rewardId = 'reward-456';

        $enrollment = $this->createMockEnrollment($enrollmentId, 150.0);
        $reward = $this->createMockReward($rewardId, 100.0, false); // Not active

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->willReturn($reward);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Reward {$rewardId} is not active");

        // Act
        $this->service->redeemReward($enrollmentId, $rewardId);
    }

    /** @test */
    public function it_throws_exception_when_insufficient_points(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $rewardId = 'reward-456';
        $pointsCost = 100.0;

        $enrollment = $this->createMockEnrollment($enrollmentId, 50.0); // Not enough points
        $reward = $this->createMockReward($rewardId, $pointsCost);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->willReturn($reward);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient points');

        // Act
        $this->service->redeemReward($enrollmentId, $rewardId);
    }

    /** @test */
    public function it_updates_enrollment_balances_correctly(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $rewardId = 'reward-456';
        $pointsCost = 100.0;

        $enrollment = $this->createMockEnrollment($enrollmentId, 150.0);
        $initialBalance = $enrollment->current_balance;
        $initialLifetimeRedeemed = $enrollment->lifetime_redeemed;

        $reward = $this->createMockReward($rewardId, $pointsCost);
        $transaction = $this->createMockTransaction($enrollmentId, -$pointsCost, $rewardId);

        Event::fake([RewardRedeemedV2::class]);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->willReturn($reward);

        $this->transactionRepository->expects($this->once())
            ->method('save')
            ->willReturn($transaction);

        $this->enrollmentRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function ($savedEnrollment) use ($initialBalance, $initialLifetimeRedeemed, $pointsCost) {
                // Verify balances were updated correctly
                $this->assertEquals($initialBalance - $pointsCost, $savedEnrollment->current_balance);
                $this->assertEquals($initialLifetimeRedeemed + $pointsCost, $savedEnrollment->lifetime_redeemed);
                $this->assertNotNull($savedEnrollment->last_transaction_at);

                return $savedEnrollment;
            });

        // Act
        $this->service->redeemReward($enrollmentId, $rewardId);
    }

    /** @test */
    public function it_checks_redemption_eligibility_successfully(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $rewardId = 'reward-456';
        $pointsCost = 100.0;

        $enrollment = $this->createMockEnrollment($enrollmentId, 150.0);
        $reward = $this->createMockReward($rewardId, $pointsCost);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->with($rewardId)
            ->willReturn($reward);

        // Act
        $result = $this->service->canRedeem($enrollmentId, $rewardId);

        // Assert
        $this->assertTrue($result['can_redeem']);
        $this->assertEquals($pointsCost, $result['points_required']);
        $this->assertNull($result['reason']);
    }

    /** @test */
    public function it_returns_false_when_checking_nonexistent_enrollment(): void
    {
        // Arrange
        $enrollmentId = 'nonexistent';
        $rewardId = 'reward-456';

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        // Act
        $result = $this->service->canRedeem($enrollmentId, $rewardId);

        // Assert
        $this->assertFalse($result['can_redeem']);
        $this->assertEquals(0.0, $result['points_required']);
        $this->assertEquals('Enrollment not found', $result['reason']);
    }

    /** @test */
    public function it_returns_false_when_checking_nonexistent_reward(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $rewardId = 'nonexistent';

        $enrollment = $this->createMockEnrollment($enrollmentId, 150.0);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        // Act
        $result = $this->service->canRedeem($enrollmentId, $rewardId);

        // Assert
        $this->assertFalse($result['can_redeem']);
        $this->assertEquals(0.0, $result['points_required']);
        $this->assertEquals('Reward not found', $result['reason']);
    }

    /** @test */
    public function it_returns_false_when_checking_inactive_reward(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $rewardId = 'reward-456';
        $pointsCost = 100.0;

        $enrollment = $this->createMockEnrollment($enrollmentId, 150.0);
        $reward = $this->createMockReward($rewardId, $pointsCost, false); // Not active

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->willReturn($reward);

        // Act
        $result = $this->service->canRedeem($enrollmentId, $rewardId);

        // Assert
        $this->assertFalse($result['can_redeem']);
        $this->assertEquals($pointsCost, $result['points_required']);
        $this->assertEquals('Reward is not active', $result['reason']);
    }

    /** @test */
    public function it_returns_false_when_checking_insufficient_points(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $rewardId = 'reward-456';
        $pointsCost = 100.0;

        $enrollment = $this->createMockEnrollment($enrollmentId, 50.0); // Not enough
        $reward = $this->createMockReward($rewardId, $pointsCost);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->willReturn($reward);

        // Act
        $result = $this->service->canRedeem($enrollmentId, $rewardId);

        // Assert
        $this->assertFalse($result['can_redeem']);
        $this->assertEquals($pointsCost, $result['points_required']);
        $this->assertStringContainsString('Insufficient points', $result['reason']);
    }

    /**
     * Helper method to create a mock enrollment
     */
    private function createMockEnrollment(string $id, float $balance): Enrollment
    {
        $enrollment = new Enrollment([
            'program_id' => 'program-123',
            'member_id' => 'member-123',
            'current_balance' => $balance,
            'lifetime_earned' => 200.0,
            'lifetime_redeemed' => 50.0,
            'status' => 'ACTIVE',
            'enrolled_at' => now(),
        ]);

        $enrollment->id = $id;

        return $enrollment;
    }

    /**
     * Helper method to create a mock reward
     */
    private function createMockReward(string $id, float $pointsCost, bool $isActive = true): Reward
    {
        $reward = new Reward([
            'program_id' => 'program-123',
            'name' => 'Test Reward',
            'reward_type' => RewardType::FreeItem,
            'points_cost' => $pointsCost,
            'is_active' => $isActive,
        ]);

        $reward->id = $id;

        return $reward;
    }

    /**
     * Helper method to create a mock transaction
     */
    private function createMockTransaction(string $enrollmentId, float $amount, string $rewardId): Transaction
    {
        $transaction = new Transaction([
            'enrollment_id' => $enrollmentId,
            'transaction_type' => TransactionType::Redeem,
            'amount' => $amount,
            'balance_before' => 150.0,
            'balance_after' => 150.0 + $amount,
            'reward_id' => $rewardId,
            'description' => 'Redeemed reward',
            'metadata' => [],
            'created_at' => now(),
        ]);

        $transaction->id = 'transaction-123';

        return $transaction;
    }
}
