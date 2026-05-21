<?php

declare(strict_types=1);

namespace Tests\Unit\POS\Domain\Enums;

use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use PHPUnit\Framework\TestCase;

/**
 * Codex review B4 (2026-04-30) — single source of truth for whether a given
 * `payment_methods.code` value requires the `instrument_type` /
 * `instrument_serial` pair. Tests the static predicate that validators and
 * receipt-payment writers consume to enforce the rule.
 *
 * The set of instrument-bearing codes is derived from the enum cases — adding
 * a new instrument kind to the enum automatically extends enforcement.
 */
final class PaymentInstrumentKindTest extends TestCase
{
    public function test_enum_has_expected_cases(): void
    {
        $cases = PaymentInstrumentKind::cases();

        $this->assertCount(4, $cases);
        $this->assertContains(PaymentInstrumentKind::StoreVoucher, $cases);
        $this->assertContains(PaymentInstrumentKind::RestaurantVoucher, $cases);
        $this->assertContains(PaymentInstrumentKind::GiftCard, $cases);
        $this->assertContains(PaymentInstrumentKind::None, $cases);
    }

    public function test_requires_instrument_for_store_voucher(): void
    {
        $this->assertTrue(PaymentInstrumentKind::requiresInstrumentForMethodCode('store_voucher'));
    }

    public function test_requires_instrument_for_restaurant_voucher(): void
    {
        $this->assertTrue(PaymentInstrumentKind::requiresInstrumentForMethodCode('restaurant_voucher'));
    }

    public function test_requires_instrument_for_gift_card(): void
    {
        $this->assertTrue(PaymentInstrumentKind::requiresInstrumentForMethodCode('gift_card'));
    }

    public function test_normalizes_uppercase_method_code(): void
    {
        $this->assertTrue(PaymentInstrumentKind::requiresInstrumentForMethodCode('STORE_VOUCHER'));
        $this->assertTrue(PaymentInstrumentKind::requiresInstrumentForMethodCode('Restaurant_Voucher'));
        $this->assertTrue(PaymentInstrumentKind::requiresInstrumentForMethodCode('GIFT_CARD'));
    }

    public function test_normalizes_whitespace_in_method_code(): void
    {
        $this->assertTrue(PaymentInstrumentKind::requiresInstrumentForMethodCode('  store_voucher  '));
        $this->assertTrue(PaymentInstrumentKind::requiresInstrumentForMethodCode("\tgift_card\n"));
    }

    public function test_does_not_require_instrument_for_cash(): void
    {
        $this->assertFalse(PaymentInstrumentKind::requiresInstrumentForMethodCode('cash'));
        $this->assertFalse(PaymentInstrumentKind::requiresInstrumentForMethodCode('CASH'));
    }

    public function test_does_not_require_instrument_for_card(): void
    {
        $this->assertFalse(PaymentInstrumentKind::requiresInstrumentForMethodCode('card'));
        $this->assertFalse(PaymentInstrumentKind::requiresInstrumentForMethodCode('CB'));
    }

    public function test_does_not_require_instrument_for_empty_string(): void
    {
        $this->assertFalse(PaymentInstrumentKind::requiresInstrumentForMethodCode(''));
        $this->assertFalse(PaymentInstrumentKind::requiresInstrumentForMethodCode('   '));
    }

    public function test_does_not_require_instrument_for_none_sentinel(): void
    {
        // The `None` enum case is a sentinel for non-instrument rows. Its value
        // (`none`) must never be classified as instrument-bearing — that would
        // be circular and break the writer's null-input handling for plain
        // cash/card rows that store nothing in instrument_type.
        $this->assertFalse(PaymentInstrumentKind::requiresInstrumentForMethodCode('none'));
    }

    public function test_does_not_require_instrument_for_unknown_method_code(): void
    {
        $this->assertFalse(PaymentInstrumentKind::requiresInstrumentForMethodCode('crypto_token'));
        $this->assertFalse(PaymentInstrumentKind::requiresInstrumentForMethodCode('check'));
        $this->assertFalse(PaymentInstrumentKind::requiresInstrumentForMethodCode('transfer'));
    }
}
