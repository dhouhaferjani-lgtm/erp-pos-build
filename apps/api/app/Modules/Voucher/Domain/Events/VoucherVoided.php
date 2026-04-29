<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Events;

/**
 * Dispatched after a voucher is successfully voided.
 *
 * Voiding paths in Phase 1:
 *   - Auto-void via fraud detection (5 failed lookup attempts).
 *   - Cascade void when the source credit note is voided (VoucherCascadeService).
 *
 * This event is immutable — never rename, restructure, or delete it.
 * Create a VoucherVoidedV2 if the contract must change.
 */
final class VoucherVoided
{
    /**
     * @param  numeric-string  $voidedBalance  The balance that was zeroed out
     */
    public function __construct(
        public readonly string $voucherId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $code,
        public readonly string $voidedBalance,
        public readonly string $voidReason,
        public readonly string $glJournalEntryId,
        public readonly string $occurredAt,
    ) {}
}
