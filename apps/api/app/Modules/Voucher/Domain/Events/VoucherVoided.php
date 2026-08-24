<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Events;

/**
 * Dispatched after a voucher is successfully voided.
 *
 * Every void path dispatches this event, because every void path is the same
 * write: VoucherVoidService (lane Q-5). The originating path is carried in
 * $voidReason, which is the ledger row's policy_trigger:
 *   - 'manual_void'               — back-office operator void
 *   - 'cascade_credit_note_void'  — the source credit note was voided
 *   - 'auto_fraud_void'           — fraud detection (5 failed lookup attempts)
 *
 * $glJournalEntryId is the empty string when the voided balance was zero and no
 * GL reversal was required.
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
