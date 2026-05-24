<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Enums;

/**
 * Discriminator for the payment instrument recorded on a receipt-payment row.
 *
 * History:
 *   Originally, pos_receipt_payments had a `voucher_serial` column whose only
 *   meaning was "restaurant voucher serial" (French ticket-restaurant tender).
 *   Task 21 (spec §3.3) renames that column to `instrument_serial` and adds
 *   this `instrument_type` discriminator so the application knows what kind
 *   of instrument the serial belongs to.
 *
 * Phase 1 support:
 *   - StoreVoucher  — internal store-credit voucher (issued by VoucherIssuanceService)
 *   - RestaurantVoucher — legacy path; writing the serial is still allowed, but
 *       VoucherIssuanceService / VoucherRedemptionService will throw
 *       RestaurantVoucherNotYetSupportedException if called with this kind.
 *       The full restaurant-ticket tender ships in Phase 2 (spec §3.2.1).
 *   - GiftCard — reserved for Phase 2; same Phase 1 rejection applies.
 *   - None — explicit sentinel for non-instrument payment rows.
 *
 * Codex review B4 (2026-04-30):
 *   The instrument-bearing payment-method codes are exactly the non-`None`
 *   case values of this enum. {@see self::requiresInstrumentForMethodCode()}
 *   is the single source of truth that validators (StoreReceiptPaymentsRequest)
 *   and writers (ReceiptPaymentService, POS receipt projections)
 *   consult to enforce that a `payment_methods.code` such as `store_voucher`
 *   never lands without its `instrument_type` + `instrument_serial` pair —
 *   leaving those fields null is a v3 fiscal-hash integrity violation.
 *   The TS counterpart at `apps/pos/src/lib/payment/paymentMethodKind.ts`
 *   mirrors the same code list; both must stay in sync.
 */
enum PaymentInstrumentKind: string
{
    case StoreVoucher = 'store_voucher';
    case RestaurantVoucher = 'restaurant_voucher';
    case GiftCard = 'gift_card';
    case None = 'none';

    /**
     * Returns true when the given `payment_methods.code` requires the
     * `instrument_type` + `instrument_serial` pair to be present and non-empty.
     *
     * The set is derived from the enum cases: every case whose value matches
     * the (normalized) input is treated as instrument-bearing — except the
     * `None` sentinel, which is reserved for plain non-instrument rows and
     * must never be classified as requiring an instrument (that would be
     * circular).
     *
     * Normalization: input is lowercased and trimmed so callers can pass the
     * raw client-supplied `method_code` (online: `payment_methods.code` looked
     * up by FK; sync: client snapshot directly from the wire) without having
     * to worry about case or whitespace drift.
     *
     * Codex review B4 (2026-04-30): this is the single source of truth.
     * Adding a new instrument-bearing payment method = add a case to this
     * enum, and the validators/writers automatically enforce the rule.
     */
    public static function requiresInstrumentForMethodCode(string $methodCode): bool
    {
        $normalized = strtolower(trim($methodCode));

        if ($normalized === '' || $normalized === self::None->value) {
            return false;
        }

        foreach (self::cases() as $case) {
            if ($case === self::None) {
                continue;
            }
            if ($case->value === $normalized) {
                return true;
            }
        }

        return false;
    }
}
