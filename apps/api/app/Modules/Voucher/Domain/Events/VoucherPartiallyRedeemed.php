<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Events;

/**
 * Dispatched when a partial redemption reduces a voucher's balance but it
 * remains above the minimum currency unit (status = PartiallyRedeemed).
 *
 * This event is immutable — never rename, restructure, or delete it.
 * Create a VoucherPartiallyRedeemedV2 if the contract must change.
 */
final class VoucherPartiallyRedeemed
{
    /**
     * @param  numeric-string  $appliedAmount  Amount that was redeemed
     * @param  numeric-string  $newBalance  Remaining balance at internal precision
     */
    public function __construct(
        public readonly string $voucherId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $code,
        public readonly string $appliedAmount,
        public readonly string $newBalance,
        public readonly string $currency,
        public readonly string $receiptId,
        public readonly string $cashierId,
        public readonly string $terminalId,
        public readonly string $glJournalEntryId,
        public readonly string $occurredAt,
    ) {}
}
