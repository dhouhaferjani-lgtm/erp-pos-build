<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Enums;

use App\Modules\Loyalty\Domain\Enums\TransactionType;
use PHPUnit\Framework\TestCase;

class TransactionTypeTest extends TestCase
{
    public function test_enum_has_all_expected_cases(): void
    {
        $cases = TransactionType::cases();

        $this->assertCount(8, $cases);
        $this->assertContains(TransactionType::Earn, $cases);
        $this->assertContains(TransactionType::Redeem, $cases);
        $this->assertContains(TransactionType::Adjust, $cases);
        $this->assertContains(TransactionType::Expire, $cases);
        $this->assertContains(TransactionType::TransferIn, $cases);
        $this->assertContains(TransactionType::TransferOut, $cases);
        $this->assertContains(TransactionType::Bonus, $cases);
        $this->assertContains(TransactionType::Refund, $cases);
    }

    public function test_enum_values_are_correct(): void
    {
        $this->assertEquals('earn', TransactionType::Earn->value);
        $this->assertEquals('redeem', TransactionType::Redeem->value);
        $this->assertEquals('adjust', TransactionType::Adjust->value);
        $this->assertEquals('expire', TransactionType::Expire->value);
        $this->assertEquals('transfer_in', TransactionType::TransferIn->value);
        $this->assertEquals('transfer_out', TransactionType::TransferOut->value);
        $this->assertEquals('bonus', TransactionType::Bonus->value);
        $this->assertEquals('refund', TransactionType::Refund->value);
    }

    public function test_can_create_from_value(): void
    {
        $this->assertEquals(TransactionType::Earn, TransactionType::from('earn'));
        $this->assertEquals(TransactionType::Redeem, TransactionType::from('redeem'));
        $this->assertEquals(TransactionType::Adjust, TransactionType::from('adjust'));
        $this->assertEquals(TransactionType::Expire, TransactionType::from('expire'));
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(TransactionType::tryFrom('invalid'));
    }
}
