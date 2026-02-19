<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Services;

use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Services\PointExpirationService;
use App\Modules\Loyalty\Domain\ValueObjects\PointsAmount;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class PointExpirationServiceTest extends TestCase
{
    private PointExpirationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PointExpirationService;
    }

    public function test_find_expiring_points_returns_transactions_with_expires_at_before_date(): void
    {
        // Arrange
        $asOfDate = Carbon::parse('2025-06-01');

        $expiredTransaction = $this->createMockTransaction(
            points: 100.0,
            expiresAt: Carbon::parse('2025-05-01')
        );

        $futureTransaction = $this->createMockTransaction(
            points: 50.0,
            expiresAt: Carbon::parse('2025-07-01')
        );

        $transactions = collect([$expiredTransaction, $futureTransaction]);

        // Act
        $result = $this->service->findExpiringPoints($transactions, $asOfDate, 0);

        // Assert
        $this->assertCount(1, $result);
        $this->assertSame($expiredTransaction, $result->first());
    }

    public function test_find_expiring_points_excludes_transactions_without_expires_at(): void
    {
        // Arrange
        $asOfDate = Carbon::parse('2025-06-01');

        $expiredTransaction = $this->createMockTransaction(
            points: 100.0,
            expiresAt: Carbon::parse('2025-05-01')
        );

        $noExpiryTransaction = $this->createMockTransaction(
            points: 50.0,
            expiresAt: null
        );

        $transactions = collect([$expiredTransaction, $noExpiryTransaction]);

        // Act
        $result = $this->service->findExpiringPoints($transactions, $asOfDate, 0);

        // Assert
        $this->assertCount(1, $result);
        $this->assertSame($expiredTransaction, $result->first());
    }

    public function test_find_expiring_points_excludes_future_expiration_dates(): void
    {
        // Arrange
        $asOfDate = Carbon::parse('2025-06-01');

        $futureTransaction1 = $this->createMockTransaction(
            points: 100.0,
            expiresAt: Carbon::parse('2025-07-01')
        );

        $futureTransaction2 = $this->createMockTransaction(
            points: 50.0,
            expiresAt: Carbon::parse('2025-12-01')
        );

        $transactions = collect([$futureTransaction1, $futureTransaction2]);

        // Act
        $result = $this->service->findExpiringPoints($transactions, $asOfDate, 0);

        // Assert
        $this->assertCount(0, $result);
    }

    public function test_find_expiring_points_includes_grace_period(): void
    {
        // Arrange
        $asOfDate = Carbon::parse('2025-06-01');
        $graceDays = 10;

        // Expires on June 5 (within 10-day grace period)
        $withinGraceTransaction = $this->createMockTransaction(
            points: 100.0,
            expiresAt: Carbon::parse('2025-06-05')
        );

        // Expires on June 15 (outside 10-day grace period)
        $outsideGraceTransaction = $this->createMockTransaction(
            points: 50.0,
            expiresAt: Carbon::parse('2025-06-15')
        );

        $transactions = collect([$withinGraceTransaction, $outsideGraceTransaction]);

        // Act
        $result = $this->service->findExpiringPoints($transactions, $asOfDate, $graceDays);

        // Assert
        $this->assertCount(1, $result);
        $this->assertSame($withinGraceTransaction, $result->first());
    }

    public function test_find_expiring_points_with_zero_grace_days(): void
    {
        // Arrange
        $asOfDate = Carbon::parse('2025-06-01');

        $exactlyExpiredTransaction = $this->createMockTransaction(
            points: 100.0,
            expiresAt: Carbon::parse('2025-06-01')
        );

        $oneDayLaterTransaction = $this->createMockTransaction(
            points: 50.0,
            expiresAt: Carbon::parse('2025-06-02')
        );

        $transactions = collect([$exactlyExpiredTransaction, $oneDayLaterTransaction]);

        // Act
        $result = $this->service->findExpiringPoints($transactions, $asOfDate, 0);

        // Assert
        $this->assertCount(1, $result);
        $this->assertTrue($result->contains($exactlyExpiredTransaction));
    }

    public function test_calculate_expiring_amount_sums_transaction_amounts(): void
    {
        // Arrange
        $transaction1 = $this->createMockTransaction(points: 100.0);
        $transaction2 = $this->createMockTransaction(points: 50.0);
        $transaction3 = $this->createMockTransaction(points: 25.5);

        $transactions = collect([$transaction1, $transaction2, $transaction3]);

        // Act
        $result = $this->service->calculateExpiringAmount($transactions);

        // Assert
        $this->assertInstanceOf(PointsAmount::class, $result);
        $this->assertEquals(175.5, $result->value);
    }

    public function test_calculate_expiring_amount_returns_zero_for_empty_collection(): void
    {
        // Arrange
        $transactions = collect([]);

        // Act
        $result = $this->service->calculateExpiringAmount($transactions);

        // Assert
        $this->assertInstanceOf(PointsAmount::class, $result);
        $this->assertEquals(0.0, $result->value);
    }

    public function test_calculate_expiring_amount_handles_mixed_positive_amounts(): void
    {
        // Arrange
        $transaction1 = $this->createMockTransaction(points: 100.0);
        $transaction2 = $this->createMockTransaction(points: 0.5);
        $transaction3 = $this->createMockTransaction(points: 1000.0);
        $transaction4 = $this->createMockTransaction(points: 0.25);

        $transactions = collect([$transaction1, $transaction2, $transaction3, $transaction4]);

        // Act
        $result = $this->service->calculateExpiringAmount($transactions);

        // Assert
        $this->assertEquals(1100.75, $result->value);
    }

    public function test_group_by_expiration_date_creates_correct_date_keys(): void
    {
        // Arrange
        $fromDate = Carbon::parse('2025-06-01');
        $toDate = Carbon::parse('2025-06-30');

        $transaction1 = $this->createMockTransaction(
            points: 100.0,
            expiresAt: Carbon::parse('2025-06-10')
        );

        $transaction2 = $this->createMockTransaction(
            points: 50.0,
            expiresAt: Carbon::parse('2025-06-20')
        );

        $transactions = collect([$transaction1, $transaction2]);

        // Act
        $result = $this->service->groupByExpirationDate($transactions, $fromDate, $toDate);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('2025-06-10', $result);
        $this->assertArrayHasKey('2025-06-20', $result);
        $this->assertEquals(100.0, $result['2025-06-10']);
        $this->assertEquals(50.0, $result['2025-06-20']);
    }

    public function test_group_by_expiration_date_sums_amounts_per_date(): void
    {
        // Arrange
        $fromDate = Carbon::parse('2025-06-01');
        $toDate = Carbon::parse('2025-06-30');

        $transaction1 = $this->createMockTransaction(
            points: 100.0,
            expiresAt: Carbon::parse('2025-06-10')
        );

        $transaction2 = $this->createMockTransaction(
            points: 50.0,
            expiresAt: Carbon::parse('2025-06-10')
        );

        $transaction3 = $this->createMockTransaction(
            points: 75.0,
            expiresAt: Carbon::parse('2025-06-10')
        );

        $transactions = collect([$transaction1, $transaction2, $transaction3]);

        // Act
        $result = $this->service->groupByExpirationDate($transactions, $fromDate, $toDate);

        // Assert
        $this->assertCount(1, $result);
        $this->assertEquals(225.0, $result['2025-06-10']);
    }

    public function test_group_by_expiration_date_filters_by_date_range(): void
    {
        // Arrange
        $fromDate = Carbon::parse('2025-06-01');
        $toDate = Carbon::parse('2025-06-30');

        $beforeRangeTransaction = $this->createMockTransaction(
            points: 100.0,
            expiresAt: Carbon::parse('2025-05-15')
        );

        $inRangeTransaction = $this->createMockTransaction(
            points: 50.0,
            expiresAt: Carbon::parse('2025-06-15')
        );

        $afterRangeTransaction = $this->createMockTransaction(
            points: 75.0,
            expiresAt: Carbon::parse('2025-07-15')
        );

        $noExpiryTransaction = $this->createMockTransaction(
            points: 25.0,
            expiresAt: null
        );

        $transactions = collect([
            $beforeRangeTransaction,
            $inRangeTransaction,
            $afterRangeTransaction,
            $noExpiryTransaction,
        ]);

        // Act
        $result = $this->service->groupByExpirationDate($transactions, $fromDate, $toDate);

        // Assert
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('2025-06-15', $result);
        $this->assertEquals(50.0, $result['2025-06-15']);
    }

    public function test_has_expired_returns_true_when_expires_at_before_as_of_date(): void
    {
        // Arrange
        $asOfDate = Carbon::parse('2025-06-01');
        $transaction = $this->createMockTransaction(
            points: 100.0,
            expiresAt: Carbon::parse('2025-05-01')
        );

        // Act
        $result = $this->service->hasExpired($transaction, $asOfDate);

        // Assert
        $this->assertTrue($result);
    }

    public function test_has_expired_returns_false_when_expires_at_after_as_of_date(): void
    {
        // Arrange
        $asOfDate = Carbon::parse('2025-06-01');
        $transaction = $this->createMockTransaction(
            points: 100.0,
            expiresAt: Carbon::parse('2025-07-01')
        );

        // Act
        $result = $this->service->hasExpired($transaction, $asOfDate);

        // Assert
        $this->assertFalse($result);
    }

    public function test_has_expired_returns_false_when_expires_at_is_null(): void
    {
        // Arrange
        $asOfDate = Carbon::parse('2025-06-01');
        $transaction = $this->createMockTransaction(
            points: 100.0,
            expiresAt: null
        );

        // Act
        $result = $this->service->hasExpired($transaction, $asOfDate);

        // Assert
        $this->assertFalse($result);
    }

    public function test_days_until_expiration_calculates_correct_days(): void
    {
        // Arrange
        $asOfDate = Carbon::parse('2025-06-01');
        $transaction = $this->createMockTransaction(
            points: 100.0,
            expiresAt: Carbon::parse('2025-06-11')
        );

        // Act
        $result = $this->service->daysUntilExpiration($transaction, $asOfDate);

        // Assert
        $this->assertEquals(10, $result);
    }

    public function test_days_until_expiration_returns_negative_for_expired_points(): void
    {
        // Arrange
        $asOfDate = Carbon::parse('2025-06-01');
        $transaction = $this->createMockTransaction(
            points: 100.0,
            expiresAt: Carbon::parse('2025-05-01')
        );

        // Act
        $result = $this->service->daysUntilExpiration($transaction, $asOfDate);

        // Assert
        $this->assertEquals(-31, $result);
    }

    public function test_days_until_expiration_returns_null_when_expires_at_is_null(): void
    {
        // Arrange
        $asOfDate = Carbon::parse('2025-06-01');
        $transaction = $this->createMockTransaction(
            points: 100.0,
            expiresAt: null
        );

        // Act
        $result = $this->service->daysUntilExpiration($transaction, $asOfDate);

        // Assert
        $this->assertNull($result);
    }

    /**
     * Helper method to create a mock transaction
     */
    private function createMockTransaction(
        float $points,
        ?Carbon $expiresAt = null
    ): Transaction {
        $transaction = $this->createMock(Transaction::class);

        $transaction->method('__get')
            ->willReturnCallback(function (string $property) use ($points, $expiresAt) {
                return match ($property) {
                    'amount' => (string) $points,
                    'expires_at' => $expiresAt,
                    default => null,
                };
            });

        return $transaction;
    }
}
