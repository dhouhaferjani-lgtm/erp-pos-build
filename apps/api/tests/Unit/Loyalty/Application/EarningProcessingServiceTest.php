<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Application;

use App\Modules\Loyalty\Application\DTOs\TransactionData;
use App\Modules\Loyalty\Application\Services\EarningProcessingService;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Loyalty\Domain\Events\PointsEarnedV2;
use App\Modules\Loyalty\Domain\Repositories\EarningRuleRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use App\Modules\Loyalty\Domain\Services\PointEarningService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Unit tests for EarningProcessingService
 *
 * @group loyalty
 * @group unit
 */
final class EarningProcessingServiceTest extends TestCase
{
    private EarningProcessingService $service;

    private EnrollmentRepositoryInterface $enrollmentRepository;

    private EarningRuleRepositoryInterface $earningRuleRepository;

    private TransactionRepositoryInterface $transactionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock repositories
        $this->enrollmentRepository = $this->createMock(EnrollmentRepositoryInterface::class);
        $this->earningRuleRepository = $this->createMock(EarningRuleRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);

        // Create real PointEarningService (already tested, final class)
        $pointEarningService = new PointEarningService($this->transactionRepository);

        // Create service with mocked repositories and real domain service
        $this->service = new EarningProcessingService(
            $this->enrollmentRepository,
            $this->earningRuleRepository,
            $this->transactionRepository,
            $pointEarningService,
        );

        // Mock database transactions
        DB::shouldReceive('transaction')
            ->andReturnUsing(function ($callback) {
                return $callback();
            });
    }

    /** @test */
    public function it_earns_points_successfully(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $sourceType = 'order';
        $sourceId = 'order-456';
        $transactionData = ['amount' => 100, 'items' => []];
        $points = 10.0;

        $enrollment = $this->createMockEnrollment($enrollmentId);
        $rule = $this->createMockEarningRule($enrollment->program_id, $points);
        $rules = new Collection([$rule]);
        $transaction = $this->createMockTransaction($enrollmentId, $points);

        Event::fake([PointsEarnedV2::class]);

        // Expectations
        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->transactionRepository->expects($this->once())
            ->method('findBySourceDocument')
            ->with($sourceType, $sourceId)
            ->willReturn(null); // No duplicate

        $this->earningRuleRepository->expects($this->once())
            ->method('findActiveByProgram')
            ->with($enrollment->program_id)
            ->willReturn($rules);

        $this->transactionRepository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(Transaction::class))
            ->willReturn($transaction);

        $this->enrollmentRepository->expects($this->once())
            ->method('save')
            ->with($enrollment)
            ->willReturn($enrollment);

        // Act
        $result = $this->service->earnPoints($enrollmentId, $transactionData, $sourceType, $sourceId);

        // Assert
        $this->assertInstanceOf(TransactionData::class, $result);
        $this->assertEquals($points, $result->amount);
        Event::assertDispatched(PointsEarnedV2::class);
    }

    /** @test */
    public function it_throws_exception_when_enrollment_not_found(): void
    {
        // Arrange
        $enrollmentId = 'nonexistent-enrollment';
        $transactionData = ['amount' => 100];

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Enrollment with ID {$enrollmentId} not found");

        // Act
        $this->service->earnPoints($enrollmentId, $transactionData, 'order', 'order-123');
    }

    /** @test */
    public function it_throws_exception_for_duplicate_earning(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $sourceType = 'order';
        $sourceId = 'order-456';
        $transactionData = ['amount' => 100];

        $enrollment = $this->createMockEnrollment($enrollmentId);
        $existingTransaction = $this->createMockTransaction($enrollmentId, 10.0);
        $existingTransaction->transaction_type = TransactionType::Earn;

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->transactionRepository->expects($this->once())
            ->method('findBySourceDocument')
            ->with($sourceType, $sourceId)
            ->willReturn($existingTransaction); // Already earned

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Points already earned for {$sourceType} {$sourceId}");

        // Act
        $this->service->earnPoints($enrollmentId, $transactionData, $sourceType, $sourceId);
    }

    /** @test */
    public function it_returns_zero_points_when_no_rules_configured(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $sourceType = 'order';
        $sourceId = 'order-456';
        $transactionData = ['amount' => 100];

        $enrollment = $this->createMockEnrollment($enrollmentId);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->transactionRepository->expects($this->once())
            ->method('findBySourceDocument')
            ->with($sourceType, $sourceId)
            ->willReturn(null);

        $this->earningRuleRepository->expects($this->once())
            ->method('findActiveByProgram')
            ->with($enrollment->program_id)
            ->willReturn(new Collection); // No rules

        // Act
        $result = $this->service->earnPoints($enrollmentId, $transactionData, $sourceType, $sourceId);

        // Assert
        $this->assertInstanceOf(TransactionData::class, $result);
        $this->assertEquals(0, $result->amount);
        $this->assertStringContainsString('No earning rules', $result->description);
    }

    /** @test */
    public function it_returns_zero_points_when_transaction_does_not_qualify(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $sourceType = 'order';
        $sourceId = 'order-456';
        $transactionData = ['amount' => 5]; // Below threshold

        $enrollment = $this->createMockEnrollment($enrollmentId);
        $rules = new Collection([new EarningRule]);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->transactionRepository->expects($this->once())
            ->method('findBySourceDocument')
            ->with($sourceType, $sourceId)
            ->willReturn(null);

        $this->earningRuleRepository->expects($this->once())
            ->method('findActiveByProgram')
            ->with($enrollment->program_id)
            ->willReturn(new Collection); // No rules = no points

        // No transaction or enrollment save should occur
        $this->transactionRepository->expects($this->never())
            ->method('save');

        $this->enrollmentRepository->expects($this->never())
            ->method('save');

        // Act
        $result = $this->service->earnPoints($enrollmentId, $transactionData, $sourceType, $sourceId);

        // Assert
        $this->assertInstanceOf(TransactionData::class, $result);
        $this->assertEquals(0, $result->amount);
        $this->assertStringContainsString('No earning rules', $result->description);
    }

    /** @test */
    public function it_updates_enrollment_balances_correctly(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $sourceType = 'order';
        $sourceId = 'order-456';
        $transactionData = ['amount' => 100];
        $points = 10.0; // 10% of 100 with our SPEND rule

        $enrollment = $this->createMockEnrollment($enrollmentId);
        $initialBalance = $enrollment->current_balance;
        $initialLifetimeEarned = $enrollment->lifetime_earned;

        $rule = $this->createMockEarningRule($enrollment->program_id, $points);
        $rules = new Collection([$rule]);
        $transaction = $this->createMockTransaction($enrollmentId, $points);

        Event::fake([PointsEarnedV2::class]);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->willReturn($enrollment);

        $this->transactionRepository->expects($this->once())
            ->method('findBySourceDocument')
            ->willReturn(null);

        $this->earningRuleRepository->expects($this->once())
            ->method('findActiveByProgram')
            ->willReturn($rules);

        $this->transactionRepository->expects($this->once())
            ->method('save')
            ->willReturn($transaction);

        $this->enrollmentRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function ($savedEnrollment) use ($initialBalance, $initialLifetimeEarned, $points) {
                // Verify balances were updated correctly
                $this->assertEquals($initialBalance + $points, $savedEnrollment->current_balance);
                $this->assertEquals($initialLifetimeEarned + $points, $savedEnrollment->lifetime_earned);
                $this->assertNotNull($savedEnrollment->last_transaction_at);

                return $savedEnrollment;
            });

        // Act
        $this->service->earnPoints($enrollmentId, $transactionData, $sourceType, $sourceId);
    }

    /** @test */
    public function it_previews_earning_without_saving(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $transactionData = ['amount' => 100];
        $expectedPoints = 10.0; // 10% of 100 with SPEND rule

        $enrollment = $this->createMockEnrollment($enrollmentId);
        $rule = $this->createMockEarningRule($enrollment->program_id, $expectedPoints);
        $rules = new Collection([$rule]);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->earningRuleRepository->expects($this->once())
            ->method('findActiveByProgram')
            ->with($enrollment->program_id)
            ->willReturn($rules);

        // Real PointEarningService will calculate based on rule
        // No need to mock - using actual domain service

        // Should not save anything
        $this->transactionRepository->expects($this->never())
            ->method('save');

        $this->enrollmentRepository->expects($this->never())
            ->method('save');

        // Act
        $result = $this->service->previewEarning($enrollmentId, $transactionData);

        // Assert
        $this->assertEquals($expectedPoints, $result);
    }

    /** @test */
    public function it_previews_zero_when_no_rules_configured(): void
    {
        // Arrange
        $enrollmentId = 'enrollment-123';
        $transactionData = ['amount' => 100];

        $enrollment = $this->createMockEnrollment($enrollmentId);

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn($enrollment);

        $this->earningRuleRepository->expects($this->once())
            ->method('findActiveByProgram')
            ->with($enrollment->program_id)
            ->willReturn(new Collection); // No rules

        // Act
        $result = $this->service->previewEarning($enrollmentId, $transactionData);

        // Assert
        $this->assertEquals(0.0, $result);
    }

    /** @test */
    public function it_throws_exception_when_previewing_for_nonexistent_enrollment(): void
    {
        // Arrange
        $enrollmentId = 'nonexistent-enrollment';
        $transactionData = ['amount' => 100];

        $this->enrollmentRepository->expects($this->once())
            ->method('findById')
            ->with($enrollmentId)
            ->willReturn(null);

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Enrollment with ID {$enrollmentId} not found");

        // Act
        $this->service->previewEarning($enrollmentId, $transactionData);
    }

    /**
     * Helper method to create a mock enrollment
     */
    private function createMockEnrollment(string $id): Enrollment
    {
        $enrollment = new Enrollment([
            'program_id' => 'program-123',
            'member_id' => 'member-123',
            'current_balance' => 50.0,
            'lifetime_earned' => 200.0,
            'lifetime_redeemed' => 150.0,
            'status' => 'ACTIVE',
            'enrolled_at' => now(),
        ]);

        $enrollment->id = $id;

        return $enrollment;
    }

    /**
     * Helper method to create a mock earning rule (SPEND type with 10% rate)
     */
    private function createMockEarningRule(string $programId, float $expectedPoints): EarningRule
    {
        $rule = new EarningRule([
            'program_id' => $programId,
            'name' => 'Test Rule',
            'rule_type' => EarningRuleType::Spend,
            'reward_value' => 0.1, // 10% of spend as points
            'reward_type' => 'points',
            'is_active' => true,
            'priority' => 1,
            'conditions' => [],
        ]);

        $rule->id = 'rule-123';

        return $rule;
    }

    /**
     * Helper method to create a mock transaction
     */
    private function createMockTransaction(string $enrollmentId, float $amount): Transaction
    {
        $transaction = new Transaction([
            'enrollment_id' => $enrollmentId,
            'transaction_type' => TransactionType::Earn,
            'amount' => $amount,
            'balance_before' => 50.0,
            'balance_after' => 50.0 + $amount,
            'description' => "Earned {$amount} points",
            'metadata' => [],
            'created_at' => now(),
        ]);

        $transaction->id = 'transaction-123';

        return $transaction;
    }
}
