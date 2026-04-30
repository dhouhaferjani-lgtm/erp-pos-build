<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;

/**
 * Immutable value object carrying everything needed to redeem a voucher.
 *
 * Used as input to VoucherRedemptionService::redeem().
 *
 * All monetary amounts must be passed as bcmath-compatible numeric strings
 * at the currency scale (e.g. '20.00' for EUR, '20.000' for TND).
 */
final readonly class VoucherRedemptionRequest
{
    /**
     * @param  string  $voucherCode  User-entered code (may be lowercase; service normalises to uppercase)
     * @param  numeric-string  $appliedAmount  Amount to redeem at currency scale (e.g. '20.00')
     * @param  string  $currency  ISO 4217 currency code
     * @param  string  $receiptId  FK to pos_receipts — the sale being paid
     * @param  string  $cashierId  FK to users — the cashier performing the redemption
     * @param  string  $terminalId  FK to pos_terminals — the terminal requesting redemption
     * @param  string|null  $partnerId  Current cart customer FK to partners (required for CustomerBound vouchers)
     * @param  string|null  $authorizedByUserId  Supervisor FK to users (for supervised overrides)
     * @param  string|null  $policyTrigger  Machine-readable policy trigger key
     * @param  PaymentInstrumentKind  $instrumentKind  Must be StoreVoucher or None in Phase 1; RestaurantVoucher/GiftCard throw
     */
    public function __construct(
        public readonly string $voucherCode,
        public readonly string $appliedAmount,
        public readonly string $currency,
        public readonly string $receiptId,
        public readonly string $cashierId,
        public readonly string $terminalId,
        public readonly ?string $partnerId = null,
        public readonly ?string $authorizedByUserId = null,
        public readonly ?string $policyTrigger = null,
        public readonly PaymentInstrumentKind $instrumentKind = PaymentInstrumentKind::StoreVoucher,
    ) {}
}
