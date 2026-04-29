<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherKind;
use Illuminate\Support\Carbon;

/**
 * Immutable value object carrying everything needed to issue a voucher.
 *
 * Used as input to VoucherIssuanceService::issueFromRefund(),
 * issueFromExchangeSurplus(), and issueGoodwill().
 *
 * All monetary amounts are stored as numeric-string with full precision.
 * The service does NOT accept float — callers must pass a bcmath-compatible string.
 */
final readonly class VoucherIssuanceRequest
{
    /**
     * @param  numeric-string  $amount  Face value of the voucher (positive)
     * @param  string  $currency  ISO 4217 currency code
     * @param  string  $tenantId  Owning tenant
     * @param  string  $companyId  Owning company
     * @param  string  $issuedByUserId  FK to users — the cashier / back-office agent
     * @param  string|null  $sourceReceiptId  FK to pos_receipts (null for goodwill back-office)
     * @param  string|null  $issuedToPartnerId  Identified recipient partner at issuance time
     * @param  string|null  $issuedAtTerminalId  POS terminal (null for back-office)
     * @param  Carbon|null  $expiresAt  Null = no expiry (or tenant default applied upstream)
     * @param  string|null  $notes  Free-text note
     * @param  string|null  $authorizedByUserId  Supervisor for four-eyes approval
     * @param  string|null  $overrideReason  Reason text when overriding a control
     * @param  string|null  $policyTrigger  Machine-readable trigger key (e.g. 'refund_v1')
     * @param  RedemptionMode  $redemptionMode  Bearer (default) or CustomerBound
     * @param  VoucherKind  $voucherKind  MPV (default); SPV is rejected at issuance
     * @param  PaymentInstrumentKind  $instrumentKind  Must be StoreVoucher or None in Phase 1; RestaurantVoucher/GiftCard throw
     */
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $issuedByUserId,
        public readonly ?string $sourceReceiptId = null,
        public readonly ?string $issuedToPartnerId = null,
        public readonly ?string $issuedAtTerminalId = null,
        public readonly ?Carbon $expiresAt = null,
        public readonly ?string $notes = null,
        public readonly ?string $authorizedByUserId = null,
        public readonly ?string $overrideReason = null,
        public readonly ?string $policyTrigger = null,
        public readonly RedemptionMode $redemptionMode = RedemptionMode::Bearer,
        public readonly VoucherKind $voucherKind = VoucherKind::MPV,
        public readonly PaymentInstrumentKind $instrumentKind = PaymentInstrumentKind::StoreVoucher,
    ) {}
}
