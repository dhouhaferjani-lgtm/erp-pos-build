<?php

declare(strict_types=1);

namespace Tests\Unit\Voucher\Domain\Enums;

use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherKind;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use PHPUnit\Framework\TestCase;

/**
 * Verifies all Voucher enum values match the spec exactly.
 */
final class VoucherEnumsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // VoucherStatus
    // -------------------------------------------------------------------------

    public function test_voucher_status_values(): void
    {
        $this->assertSame('issued', VoucherStatus::Issued->value);
        $this->assertSame('partially_redeemed', VoucherStatus::PartiallyRedeemed->value);
        $this->assertSame('fully_redeemed', VoucherStatus::FullyRedeemed->value);
        $this->assertSame('expired', VoucherStatus::Expired->value);
        $this->assertSame('voided', VoucherStatus::Voided->value);
    }

    public function test_voucher_status_has_exactly_five_cases(): void
    {
        $this->assertCount(5, VoucherStatus::cases());
    }

    public function test_voucher_status_from_string(): void
    {
        $this->assertSame(VoucherStatus::Issued, VoucherStatus::from('issued'));
        $this->assertSame(VoucherStatus::FullyRedeemed, VoucherStatus::from('fully_redeemed'));
    }

    // -------------------------------------------------------------------------
    // VoucherEvent
    // -------------------------------------------------------------------------

    public function test_voucher_event_values(): void
    {
        $this->assertSame('issued', VoucherEvent::Issued->value);
        $this->assertSame('redeemed', VoucherEvent::Redeemed->value);
        $this->assertSame('partially_redeemed', VoucherEvent::PartiallyRedeemed->value);
        $this->assertSame('expired', VoucherEvent::Expired->value);
        $this->assertSame('voided', VoucherEvent::Voided->value);
        $this->assertSame('reversed', VoucherEvent::Reversed->value);
        $this->assertSame('transferred', VoucherEvent::Transferred->value);
        $this->assertSame('rounding_adjustment', VoucherEvent::RoundingAdjustment->value);
    }

    public function test_voucher_event_has_exactly_eight_cases(): void
    {
        $this->assertCount(8, VoucherEvent::cases());
    }

    public function test_voucher_event_from_string(): void
    {
        $this->assertSame(VoucherEvent::Reversed, VoucherEvent::from('reversed'));
        $this->assertSame(VoucherEvent::RoundingAdjustment, VoucherEvent::from('rounding_adjustment'));
    }

    // -------------------------------------------------------------------------
    // RedemptionMode
    // -------------------------------------------------------------------------

    public function test_redemption_mode_values(): void
    {
        $this->assertSame('bearer', RedemptionMode::Bearer->value);
        $this->assertSame('customer_bound', RedemptionMode::CustomerBound->value);
    }

    public function test_redemption_mode_has_exactly_two_cases(): void
    {
        $this->assertCount(2, RedemptionMode::cases());
    }

    public function test_redemption_mode_from_string(): void
    {
        $this->assertSame(RedemptionMode::Bearer, RedemptionMode::from('bearer'));
        $this->assertSame(RedemptionMode::CustomerBound, RedemptionMode::from('customer_bound'));
    }

    // -------------------------------------------------------------------------
    // VoucherKind
    // -------------------------------------------------------------------------

    public function test_voucher_kind_values(): void
    {
        $this->assertSame('MPV', VoucherKind::MPV->value);
        $this->assertSame('SPV', VoucherKind::SPV->value);
    }

    public function test_voucher_kind_has_exactly_two_cases(): void
    {
        $this->assertCount(2, VoucherKind::cases());
    }

    public function test_voucher_kind_from_string(): void
    {
        $this->assertSame(VoucherKind::MPV, VoucherKind::from('MPV'));
        $this->assertSame(VoucherKind::SPV, VoucherKind::from('SPV'));
    }

    // -------------------------------------------------------------------------
    // VoucherSource
    // -------------------------------------------------------------------------

    public function test_voucher_source_values(): void
    {
        $this->assertSame('refund', VoucherSource::Refund->value);
        $this->assertSame('exchange_surplus', VoucherSource::ExchangeSurplus->value);
        $this->assertSame('goodwill', VoucherSource::Goodwill->value);
        $this->assertSame('loyalty_credit', VoucherSource::LoyaltyCredit->value);
        $this->assertSame('gift_card_purchase', VoucherSource::GiftCardPurchase->value);
        $this->assertSame('promotional', VoucherSource::Promotional->value);
    }

    public function test_voucher_source_has_exactly_six_cases(): void
    {
        $this->assertCount(6, VoucherSource::cases());
    }

    public function test_voucher_source_from_string(): void
    {
        $this->assertSame(VoucherSource::Refund, VoucherSource::from('refund'));
        $this->assertSame(VoucherSource::ExchangeSurplus, VoucherSource::from('exchange_surplus'));
        $this->assertSame(VoucherSource::GiftCardPurchase, VoucherSource::from('gift_card_purchase'));
    }
}
