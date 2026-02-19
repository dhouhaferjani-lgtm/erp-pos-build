<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\ValueObjects;

use App\Modules\Loyalty\Domain\ValueObjects\LoyaltyBalance;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LoyaltyBalanceTest extends TestCase
{
    public function test_creates_loyalty_balance_with_valid_values(): void
    {
        $balance = new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 200.0,
            lifetimeRedeemed: 100.0
        );

        $this->assertEquals(100.0, $balance->current);
        $this->assertEquals(200.0, $balance->lifetimeEarned);
        $this->assertEquals(100.0, $balance->lifetimeRedeemed);
    }

    public function test_throws_exception_for_negative_current_balance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Current balance cannot be negative');

        new LoyaltyBalance(
            current: -10.0,
            lifetimeEarned: 100.0,
            lifetimeRedeemed: 110.0
        );
    }

    public function test_throws_exception_for_negative_lifetime_earned(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lifetime earned cannot be negative');

        new LoyaltyBalance(
            current: 0.0,
            lifetimeEarned: -10.0,
            lifetimeRedeemed: 0.0
        );
    }

    public function test_throws_exception_for_negative_lifetime_redeemed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lifetime redeemed cannot be negative');

        new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 100.0,
            lifetimeRedeemed: -10.0
        );
    }

    public function test_throws_exception_for_invalid_balance_integrity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Balance integrity check failed');

        // Current should be 50 (200 - 150), but we're passing 100
        new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 200.0,
            lifetimeRedeemed: 150.0
        );
    }

    public function test_zero_creates_zero_balance(): void
    {
        $balance = LoyaltyBalance::zero();

        $this->assertEquals(0.0, $balance->current);
        $this->assertEquals(0.0, $balance->lifetimeEarned);
        $this->assertEquals(0.0, $balance->lifetimeRedeemed);
    }

    public function test_from_enrollment_creates_from_values(): void
    {
        $balance = LoyaltyBalance::fromEnrollment(
            current: 150.0,
            lifetimeEarned: 300.0,
            lifetimeRedeemed: 150.0
        );

        $this->assertEquals(150.0, $balance->current);
        $this->assertEquals(300.0, $balance->lifetimeEarned);
        $this->assertEquals(150.0, $balance->lifetimeRedeemed);
    }

    public function test_add_earned_increases_current_and_lifetime_earned(): void
    {
        $balance = new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 200.0,
            lifetimeRedeemed: 100.0
        );

        $newBalance = $balance->addEarned(50.0);

        $this->assertEquals(150.0, $newBalance->current);
        $this->assertEquals(250.0, $newBalance->lifetimeEarned);
        $this->assertEquals(100.0, $newBalance->lifetimeRedeemed);
        // Original unchanged
        $this->assertEquals(100.0, $balance->current);
    }

    public function test_add_earned_throws_exception_for_non_positive_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Earned amount must be positive');

        $balance = LoyaltyBalance::zero();
        $balance->addEarned(0.0);
    }

    public function test_add_redeemed_decreases_current_and_increases_lifetime_redeemed(): void
    {
        $balance = new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 200.0,
            lifetimeRedeemed: 100.0
        );

        $newBalance = $balance->addRedeemed(30.0);

        $this->assertEquals(70.0, $newBalance->current);
        $this->assertEquals(200.0, $newBalance->lifetimeEarned);
        $this->assertEquals(130.0, $newBalance->lifetimeRedeemed);
        // Original unchanged
        $this->assertEquals(100.0, $balance->current);
    }

    public function test_add_redeemed_throws_exception_for_non_positive_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redeemed amount must be positive');

        $balance = new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 100.0,
            lifetimeRedeemed: 0.0
        );

        $balance->addRedeemed(0.0);
    }

    public function test_add_redeemed_throws_exception_for_insufficient_balance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient balance for redemption');

        $balance = new LoyaltyBalance(
            current: 50.0,
            lifetimeEarned: 100.0,
            lifetimeRedeemed: 50.0
        );

        $balance->addRedeemed(75.0); // Trying to redeem more than available
    }

    public function test_has_sufficient_balance_returns_true_when_balance_sufficient(): void
    {
        $balance = new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 100.0,
            lifetimeRedeemed: 0.0
        );

        $this->assertTrue($balance->hasSufficientBalance(50.0));
        $this->assertTrue($balance->hasSufficientBalance(100.0));
    }

    public function test_has_sufficient_balance_returns_false_when_balance_insufficient(): void
    {
        $balance = new LoyaltyBalance(
            current: 50.0,
            lifetimeEarned: 100.0,
            lifetimeRedeemed: 50.0
        );

        $this->assertFalse($balance->hasSufficientBalance(75.0));
    }

    public function test_get_balance_after_redemption_calculates_hypothetical_balance(): void
    {
        $balance = new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 100.0,
            lifetimeRedeemed: 0.0
        );

        $afterBalance = $balance->getBalanceAfterRedemption(30.0);

        $this->assertEquals(70.0, $afterBalance);
        // Original unchanged
        $this->assertEquals(100.0, $balance->current);
    }

    public function test_get_balance_after_redemption_returns_zero_for_insufficient_balance(): void
    {
        $balance = new LoyaltyBalance(
            current: 50.0,
            lifetimeEarned: 100.0,
            lifetimeRedeemed: 50.0
        );

        $afterBalance = $balance->getBalanceAfterRedemption(75.0);

        $this->assertEquals(0.0, $afterBalance);
    }

    public function test_is_zero_returns_true_for_zero_balance(): void
    {
        $balance = LoyaltyBalance::zero();

        $this->assertTrue($balance->isZero());
    }

    public function test_is_zero_returns_false_for_non_zero_balance(): void
    {
        $balance = new LoyaltyBalance(
            current: 0.5,
            lifetimeEarned: 100.0,
            lifetimeRedeemed: 99.5
        );

        $this->assertFalse($balance->isZero());
    }

    public function test_format_returns_formatted_current_balance(): void
    {
        $balance = new LoyaltyBalance(
            current: 1234.56,
            lifetimeEarned: 5000.0,
            lifetimeRedeemed: 3765.44
        );

        $formatted = $balance->format(2);

        $this->assertEquals('1,234.56', $formatted);
    }

    public function test_to_array_returns_balance_components(): void
    {
        $balance = new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 200.0,
            lifetimeRedeemed: 100.0
        );

        $array = $balance->toArray();

        $this->assertEquals([
            'current' => 100.0,
            'lifetime_earned' => 200.0,
            'lifetime_redeemed' => 100.0,
        ], $array);
    }

    public function test_equals_returns_true_for_identical_balances(): void
    {
        $balance1 = new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 200.0,
            lifetimeRedeemed: 100.0
        );

        $balance2 = new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 200.0,
            lifetimeRedeemed: 100.0
        );

        $this->assertTrue($balance1->equals($balance2));
    }

    public function test_equals_returns_false_for_different_balances(): void
    {
        $balance1 = new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 200.0,
            lifetimeRedeemed: 100.0
        );

        $balance2 = new LoyaltyBalance(
            current: 50.0,
            lifetimeEarned: 200.0,
            lifetimeRedeemed: 150.0
        );

        $this->assertFalse($balance1->equals($balance2));
    }

    public function test_value_object_is_immutable(): void
    {
        $original = new LoyaltyBalance(
            current: 100.0,
            lifetimeEarned: 100.0,
            lifetimeRedeemed: 0.0
        );

        $modified = $original->addEarned(50.0);

        // Original should be unchanged
        $this->assertEquals(100.0, $original->current);
        $this->assertEquals(100.0, $original->lifetimeEarned);

        // Modified should be a new instance
        $this->assertEquals(150.0, $modified->current);
        $this->assertEquals(150.0, $modified->lifetimeEarned);
        $this->assertNotSame($original, $modified);
    }

    public function test_allows_small_floating_point_tolerance_in_integrity_check(): void
    {
        // Should NOT throw exception due to small floating point error
        $balance = new LoyaltyBalance(
            current: 100.005,
            lifetimeEarned: 200.0,
            lifetimeRedeemed: 100.0
        );

        $this->assertEquals(100.005, $balance->current);
    }
}
