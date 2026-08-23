<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Application;

use App\Modules\Loyalty\Application\DTOs\TransactionData;
use App\Modules\Loyalty\Application\Services\RedemptionProcessingService;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\RewardType;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Loyalty\Domain\Events\RewardRedeemedV2;
use App\Modules\Loyalty\Domain\Exceptions\EnrollmentNotActiveException;
use App\Modules\Loyalty\Domain\Exceptions\InsufficientPointsException;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\RewardRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use App\Modules\Loyalty\Domain\Services\RewardRedemptionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PDOException;
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

        DB::shouldReceive('afterCommit')
            ->andReturnUsing(function (callable $callback) {
                $callback();
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

        // Expectations — the enrollment is read UNDER LOCK, and the balance is
        // moved by the conditional debit, never by an absolute save().
        $this->enrollmentRepository->expects($this->once())
            ->method('findByIdForUpdate')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->enrollmentRepository->expects($this->never())->method('findById');
        $this->enrollmentRepository->expects($this->never())->method('save');

        // F-8: pin the debit arguments — the enrollment being debited, the exact
        // points string, and the points scale. A debit called with the wrong id,
        // a rounded amount or a currency-resolved scale must fail here.
        $this->enrollmentRepository->expects($this->once())
            ->method('debitForRedemption')
            ->with(
                $this->identicalTo($enrollmentId),
                $this->callback(fn (string $points): bool => bccomp($points, '100', 3) === 0),
                $this->identicalTo(3),
            )
            ->willReturn(true);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->with($rewardId)
            ->willReturn($reward);

        $this->transactionRepository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(Transaction::class))
            ->willReturn($transaction);

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

        // The reward is validated first now; the enrollment is only read (under
        // lock) once we are inside the transaction.
        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->with($rewardId)
            ->willReturn($this->createMockReward($rewardId, 100.0));

        $this->enrollmentRepository->expects($this->once())
            ->method('findByIdForUpdate')
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

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->with($rewardId)
            ->willReturn(null);

        // A missing reward is refused before any enrollment row is touched.
        $this->enrollmentRepository->expects($this->never())->method('findByIdForUpdate');

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

        $reward = $this->createMockReward($rewardId, 100.0, false); // Not active

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->willReturn($reward);

        $this->enrollmentRepository->expects($this->never())->method('findByIdForUpdate');

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

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->willReturn($reward);

        $this->enrollmentRepository->expects($this->once())
            ->method('findByIdForUpdate')
            ->willReturn($enrollment);

        $this->enrollmentRepository->expects($this->never())->method('debitForRedemption');

        // Expect
        $this->expectException(InsufficientPointsException::class);
        $this->expectExceptionMessage('Insufficient points');

        // Act
        $this->service->redeemReward($enrollmentId, $rewardId);
    }

    /** @test */
    public function it_refuses_when_the_conditional_debit_matches_no_row(): void
    {
        // The locked model said the points were there, but the committed row no
        // longer covers the cost (a concurrent redemption won). Zero affected
        // rows MUST refuse, never fall through to an absolute write.
        $enrollmentId = 'enrollment-123';
        $rewardId = 'reward-456';

        $enrollment = $this->createMockEnrollment($enrollmentId, 150.0);
        $reward = $this->createMockReward($rewardId, 100.0);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->willReturn($reward);

        $this->enrollmentRepository->expects($this->once())
            ->method('findByIdForUpdate')
            ->willReturn($enrollment);

        $this->enrollmentRepository->expects($this->once())
            ->method('debitForRedemption')
            ->with(
                $this->identicalTo($enrollmentId),
                $this->callback(fn (string $points): bool => bccomp($points, '100', 3) === 0),
                $this->identicalTo(3),
            )
            ->willReturn(false);

        // Nothing may be written to the ledger on a refusal.
        $this->transactionRepository->expects($this->never())->method('save');

        $this->expectException(InsufficientPointsException::class);

        $this->service->redeemReward($enrollmentId, $rewardId);
    }

    /** @test */
    public function it_refuses_a_non_active_enrollment(): void
    {
        $enrollmentId = 'enrollment-123';
        $rewardId = 'reward-456';

        $enrollment = $this->createMockEnrollment($enrollmentId, 150.0);
        $enrollment->status = EnrollmentStatus::OptedOut;
        $reward = $this->createMockReward($rewardId, 100.0);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->willReturn($reward);

        $this->enrollmentRepository->expects($this->once())
            ->method('findByIdForUpdate')
            ->willReturn($enrollment);

        $this->enrollmentRepository->expects($this->never())->method('debitForRedemption');
        $this->transactionRepository->expects($this->never())->method('save');

        $this->expectException(EnrollmentNotActiveException::class);

        $this->service->redeemReward($enrollmentId, $rewardId);
    }

    /** @test */
    public function it_replays_a_known_idempotency_key_without_redeeming_again(): void
    {
        $enrollmentId = 'enrollment-123';
        $rewardId = 'reward-456';
        $key = 'pos-redeem-abc';

        $reward = $this->createMockReward($rewardId, 100.0);
        $original = $this->createMockTransaction($enrollmentId, -100.0, $rewardId);

        $this->rewardRepository->expects($this->once())
            ->method('findById')
            ->willReturn($reward);

        $this->transactionRepository->expects($this->once())
            ->method('findRedeemByIdempotencyKey')
            ->with($enrollmentId, $key)
            ->willReturn($original);

        // The replay must not lock, debit or write anything.
        $this->enrollmentRepository->expects($this->never())->method('findByIdForUpdate');
        $this->enrollmentRepository->expects($this->never())->method('debitForRedemption');
        $this->transactionRepository->expects($this->never())->method('save');

        $result = $this->service->redeemReward($enrollmentId, $rewardId, null, $key);

        $this->assertSame($original->id, $result->id);
    }

    /**
     * The 23505 replay branch is unreachable in a single-connection feature
     * test (the pre-check catches every sequential replay), so the classifier
     * is unit-tested directly — same precedent as
     * EarningProcessingService::translateEarnDuplicate.
     *
     * @test
     */
    public function it_classifies_only_its_own_index_as_an_idempotent_replay(): void
    {
        $onIndex = $this->makeQueryException(
            '23505',
            'duplicate key value violates unique constraint "loyalty_txn_redeem_key_unique"',
        );
        $this->assertTrue($this->service->isRedemptionKeyDuplicate($onIndex));

        $onEarnIndex = $this->makeQueryException(
            '23505',
            'duplicate key value violates unique constraint "loyalty_txn_earn_source_unique"',
        );
        $this->assertFalse($this->service->isRedemptionKeyDuplicate($onEarnIndex));

        $foreignKey = $this->makeQueryException(
            '23503',
            'insert or update on table "loyalty_transactions" violates foreign key constraint',
        );
        $this->assertFalse($this->service->isRedemptionKeyDuplicate($foreignKey));
    }

    /**
     * Fabricate a PG-shaped QueryException carrying the constraint name, the
     * way PG surfaces it. Mirrors EarningDedupeConstraintTest's helper.
     */
    private function makeQueryException(string $sqlState, string $constraintMessage): QueryException
    {
        $previous = new PDOException("SQLSTATE[{$sqlState}]: Unique violation: 7 ERROR: {$constraintMessage}");
        $previous->errorInfo = [$sqlState, 7, $constraintMessage];

        return new QueryException(
            'tenant',
            'insert into "loyalty_transactions" (...) values (...)',
            [],
            $previous,
        );
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
            'status' => 'active',
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
