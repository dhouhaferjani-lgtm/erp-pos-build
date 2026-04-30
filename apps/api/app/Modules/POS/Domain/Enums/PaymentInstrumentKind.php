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
 */
enum PaymentInstrumentKind: string
{
    case StoreVoucher = 'store_voucher';
    case RestaurantVoucher = 'restaurant_voucher';
    case GiftCard = 'gift_card';
    case None = 'none';
}
