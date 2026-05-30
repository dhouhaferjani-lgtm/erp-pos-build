<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty;

use App\Modules\Loyalty\Domain\ValueObjects\LoyaltyBalance;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Bcmath / numeric-string contract for LoyaltyBalance.
 *
 * Balances are stored at the canonical loyalty scale of 3 (matching
 * loyalty_enrollments.current_balance / lifetime_earned / lifetime_redeemed
 * NUMERIC(15,3)). Integrity + equality use bccomp at scale 3 — NO tolerance.
 */
final class LoyaltyBalanceBcmathTest extends TestCase
{
    public function test_constructor_stores_canonical_strings(): void
    {
        $balance = new LoyaltyBalance(
            current: '100',
            lifetimeEarned: '200',
            lifetimeRedeemed: '100',
        );

        $this->assertSame('100.000', $balance->current);
        $this->assertSame('200.000', $balance->lifetimeEarned);
        $this->assertSame('100.000', $balance->lifetimeRedeemed);
        $this->assertIsString($balance->current);
    }

    public function test_rejects_negative_current(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Current balance cannot be negative');

        new LoyaltyBalance(current: '-10', lifetimeEarned: '100', lifetimeRedeemed: '110');
    }

    public function test_integrity_check_uses_bccomp_exact(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Balance integrity check failed');

        // current should be 50 (200 - 150) but we pass 100.
        new LoyaltyBalance(current: '100', lifetimeEarned: '200', lifetimeRedeemed: '150');
    }

    public function test_integrity_check_has_no_tolerance(): void
    {
        // current=100.005 against earned-redeemed=100.000 differs at scale 3
        // and MUST be rejected (the old code tolerated a 0.01 epsilon).
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Balance integrity check failed');

        new LoyaltyBalance(current: '100.005', lifetimeEarned: '200', lifetimeRedeemed: '100');
    }

    public function test_integrity_check_passes_when_exact(): void
    {
        $balance = new LoyaltyBalance(
            current: '0.300',
            lifetimeEarned: '0.500',
            lifetimeRedeemed: '0.200',
        );

        $this->assertSame('0.300', $balance->current);
    }

    public function test_add_earned_is_exact_no_drift(): void
    {
        $balance = LoyaltyBalance::zero();

        $balance = $balance->addEarned('0.1');
        $balance = $balance->addEarned('0.2');

        $this->assertSame('0.300', $balance->current);
        $this->assertSame('0.300', $balance->lifetimeEarned);
        $this->assertSame('0.000', $balance->lifetimeRedeemed);
    }

    public function test_add_redeemed_is_exact(): void
    {
        $balance = new LoyaltyBalance(current: '100', lifetimeEarned: '200', lifetimeRedeemed: '100');

        $balance = $balance->addRedeemed('30.5');

        $this->assertSame('69.500', $balance->current);
        $this->assertSame('200.000', $balance->lifetimeEarned);
        $this->assertSame('130.500', $balance->lifetimeRedeemed);
    }

    public function test_add_redeemed_rejects_insufficient_balance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient balance for redemption');

        (new LoyaltyBalance(current: '50', lifetimeEarned: '100', lifetimeRedeemed: '50'))
            ->addRedeemed('75');
    }

    public function test_has_sufficient_balance_uses_bccomp(): void
    {
        $balance = new LoyaltyBalance(current: '100', lifetimeEarned: '100', lifetimeRedeemed: '0');

        $this->assertTrue($balance->hasSufficientBalance('100'));
        $this->assertTrue($balance->hasSufficientBalance('99.999'));
        $this->assertFalse($balance->hasSufficientBalance('100.001'));
    }

    public function test_get_balance_after_redemption_returns_canonical_string(): void
    {
        $balance = new LoyaltyBalance(current: '100', lifetimeEarned: '100', lifetimeRedeemed: '0');

        $this->assertSame('70.000', $balance->getBalanceAfterRedemption('30'));
        $this->assertSame('0.000', $balance->getBalanceAfterRedemption('150'));
    }

    public function test_is_zero_uses_bccomp(): void
    {
        $this->assertTrue(LoyaltyBalance::zero()->isZero());
        $this->assertFalse(
            (new LoyaltyBalance(current: '0.001', lifetimeEarned: '0.001', lifetimeRedeemed: '0'))->isZero()
        );
    }

    public function test_to_array_returns_strings(): void
    {
        $balance = new LoyaltyBalance(current: '100', lifetimeEarned: '200', lifetimeRedeemed: '100');

        $this->assertSame([
            'current' => '100.000',
            'lifetime_earned' => '200.000',
            'lifetime_redeemed' => '100.000',
        ], $balance->toArray());
    }

    public function test_equals_uses_bccomp_at_canonical_scale(): void
    {
        $a = new LoyaltyBalance(current: '100', lifetimeEarned: '200', lifetimeRedeemed: '100');
        $b = new LoyaltyBalance(current: '100', lifetimeEarned: '200', lifetimeRedeemed: '100');
        $c = new LoyaltyBalance(current: '50', lifetimeEarned: '200', lifetimeRedeemed: '150');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
