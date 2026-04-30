<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Events;

/**
 * Dispatched when a redemption fully exhausts a voucher's balance
 * (status = FullyRedeemed). This includes cases where a RoundingAdjustment
 * was required to write off a sub-minor-unit residual.
 *
 * This event is immutable — never rename, restructure, or delete it.
 * Create a VoucherFullyRedeemedV2 if the contract must change.
 */
final class VoucherFullyRedeemed
{
    /**
     * @param  numeric-string  $appliedAmount  Amount applied in the final redemption
     * @param  bool  $hadRoundingAdjustment  True if a RoundingAdjustment ledger event was also fired
     */
    public function __construct(
        public readonly string $voucherId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $code,
        public readonly string $appliedAmount,
        public readonly string $currency,
        public readonly string $receiptId,
        public readonly string $cashierId,
        public readonly string $terminalId,
        public readonly string $glJournalEntryId,
        public readonly bool $hadRoundingAdjustment,
        public readonly string $occurredAt,
    ) {}
}
