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
            current: '100',
            lifetimeEarned: '200',
            lifetimeRedeemed: '100'
        );

        $this->assertSame('100.000', $balance->current);
        $this->assertSame('200.000', $balance->lifetimeEarned);
        $this->assertSame('100.000', $balance->lifetimeRedeemed);
    }

    public function test_throws_exception_for_negative_current_balance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Current balance cannot be negative');

        new LoyaltyBalance(current: '-10', lifetimeEarned: '100', lifetimeRedeemed: '110');
    }

    public function test_throws_exception_for_negative_lifetime_earned(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lifetime earned cannot be negative');

        new LoyaltyBalance(current: '0', lifetimeEarned: '-10', lifetimeRedeemed: '0');
    }

    public function test_throws_exception_for_negative_lifetime_redeemed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lifetime redeemed cannot be negative');

        new LoyaltyBalance(current: '100', lifetimeEarned: '100', lifetimeRedeemed: '-10');
    }

    public function test_throws_exception_for_invalid_balance_integrity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Balance integrity check failed');

        // Current should be 50 (200 - 150), but we're passing 100
        new LoyaltyBalance(current: '100', lifetimeEarned: '200', lifetimeRedeemed: '150');
    }

    public function test_zero_creates_zero_balance(): void
    {
        $balance = LoyaltyBalance::zero();

        $this->assertSame('0.000', $balance->current);
        $this->assertSame('0.000', $balance->lifetimeEarned);
        $this->assertSame('0.000', $balance->lifetimeRedeemed);
    }

    public function test_from_enrollment_creates_from_values(): void
    {
        $balance = LoyaltyBalance::fromEnrollment(
            current: '150',
            lifetimeEarned: '300',
            lifetimeRedeemed: '150'
        );

        $this->assertSame('150.000', $balance->current);
        $this->assertSame('300.000', $balance->lifetimeEarned);
        $this->assertSame('150.000', $balance->lifetimeRedeemed);
    }

    public function test_add_earned_increases_current_and_lifetime_earned(): void
    {
        $balance = new LoyaltyBalance(current: '100', lifetimeEarned: '200', lifetimeRedeemed: '100');

        $newBalance = $balance->addEarned('50');

        $this->assertSame('150.000', $newBalance->current);
        $this->assertSame('250.000', $newBalance->lifetimeEarned);
        $this->assertSame('100.000', $newBalance->lifetimeRedeemed);
        // Original unchanged
        $this->assertSame('100.000', $balance->current);
    }

    public function test_add_earned_throws_exception_for_non_positive_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Earned amount must be positive');

        LoyaltyBalance::zero()->addEarned('0');
    }

    public function test_add_redeemed_decreases_current_and_increases_lifetime_redeemed(): void
    {
        $balance = new LoyaltyBalance(current: '100', lifetimeEarned: '200', lifetimeRedeemed: '100');

        $newBalance = $balance->addRedeemed('30');

        $this->assertSame('70.000', $newBalance->current);
        $this->assertSame('200.000', $newBalance->lifetimeEarned);
        $this->assertSame('130.000', $newBalance->lifetimeRedeemed);
        // Original unchanged
        $this->assertSame('100.000', $balance->current);
    }

    public function test_add_redeemed_throws_exception_for_non_positive_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redeemed amount must be positive');

        (new LoyaltyBalance(current: '100', lifetimeEarned: '100', lifetimeRedeemed: '0'))
            ->addRedeemed('0');
    }

    public function test_add_redeemed_throws_exception_for_insufficient_balance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient balance for redemption');

        (new LoyaltyBalance(current: '50', lifetimeEarned: '100', lifetimeRedeemed: '50'))
            ->addRedeemed('75'); // Trying to redeem more than available
    }

    public function test_has_sufficient_balance_returns_true_when_balance_sufficient(): void
    {
        $balance = new LoyaltyBalance(current: '100', lifetimeEarned: '100', lifetimeRedeemed: '0');

        $this->assertTrue($balance->hasSufficientBalance('50'));
        $this->assertTrue($balance->hasSufficientBalance('100'));
    }

    public function test_has_sufficient_balance_returns_false_when_balance_insufficient(): void
    {
        $balance = new LoyaltyBalance(current: '50', lifetimeEarned: '100', lifetimeRedeemed: '50');

        $this->assertFalse($balance->hasSufficientBalance('75'));
    }

    public function test_get_balance_after_redemption_calculates_hypothetical_balance(): void
    {
        $balance = new LoyaltyBalance(current: '100', lifetimeEarned: '100', lifetimeRedeemed: '0');

        $afterBalance = $balance->getBalanceAfterRedemption('30');

        $this->assertSame('70.000', $afterBalance);
        // Original unchanged
        $this->assertSame('100.000', $balance->current);
    }

    public function test_get_balance_after_redemption_returns_zero_for_insufficient_balance(): void
    {
        $balance = new LoyaltyBalance(current: '50', lifetimeEarned: '100', lifetimeRedeemed: '50');

        $this->assertSame('0.000', $balance->getBalanceAfterRedemption('75'));
    }

    public function test_is_zero_returns_true_for_zero_balance(): void
    {
        $this->assertTrue(LoyaltyBalance::zero()->isZero());
    }

    public function test_is_zero_returns_false_for_non_zero_balance(): void
    {
        $balance = new LoyaltyBalance(current: '0.5', lifetimeEarned: '100', lifetimeRedeemed: '99.5');

        $this->assertFalse($balance->isZero());
    }

    public function test_format_returns_formatted_current_balance(): void
    {
        $balance = new LoyaltyBalance(current: '1234.56', lifetimeEarned: '5000', lifetimeRedeemed: '3765.44');

        $this->assertEquals('1,234.56', $balance->format(2));
    }

    public function test_to_array_returns_balance_components_as_strings(): void
    {
        $balance = new LoyaltyBalance(current: '100', lifetimeEarned: '200', lifetimeRedeemed: '100');

        $this->assertSame([
            'current' => '100.000',
            'lifetime_earned' => '200.000',
            'lifetime_redeemed' => '100.000',
        ], $balance->toArray());
    }

    public function test_equals_returns_true_for_identical_balances(): void
    {
        $balance1 = new LoyaltyBalance(current: '100', lifetimeEarned: '200', lifetimeRedeemed: '100');
        $balance2 = new LoyaltyBalance(current: '100', lifetimeEarned: '200', lifetimeRedeemed: '100');

        $this->assertTrue($balance1->equals($balance2));
    }

    public function test_equals_returns_false_for_different_balances(): void
    {
        $balance1 = new LoyaltyBalance(current: '100', lifetimeEarned: '200', lifetimeRedeemed: '100');
        $balance2 = new LoyaltyBalance(current: '50', lifetimeEarned: '200', lifetimeRedeemed: '150');

        $this->assertFalse($balance1->equals($balance2));
    }

    public function test_value_object_is_immutable(): void
    {
        $original = new LoyaltyBalance(current: '100', lifetimeEarned: '100', lifetimeRedeemed: '0');

        $modified = $original->addEarned('50');

        // Original should be unchanged
        $this->assertSame('100.000', $original->current);
        $this->assertSame('100.000', $original->lifetimeEarned);

        // Modified should be a new instance
        $this->assertSame('150.000', $modified->current);
        $this->assertSame('150.000', $modified->lifetimeEarned);
        $this->assertNotSame($original, $modified);
    }

    public function test_integrity_check_has_no_tolerance(): void
    {
        // The old VO tolerated a 0.01 epsilon in the integrity check; the bcmath
        // contract rejects any mismatch at the canonical scale.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Balance integrity check failed');

        new LoyaltyBalance(current: '100.005', lifetimeEarned: '200', lifetimeRedeemed: '100');
    }
}
