<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;

/**
 * Immutable result returned by VoucherVoidService::void().
 *
 * `alreadyVoided` is the idempotent short-circuit: the voucher was already in
 * VoucherStatus::Voided when the row lock was taken, so no ledger row and no GL
 * entry were written by this call. Callers that need an operator-facing refusal
 * (the back-office endpoint) render it as a 422; callers that must not fail on a
 * re-entry (cascade, fraud auto-void) simply continue.
 */
final readonly class VoucherVoidResult
{
    /**
     * @param  Voucher  $voucher  The voucher projection after the void
     * @param  VoucherLedger|null  $ledgerRow  The appended Voided row; null on an idempotent re-entry
     * @param  string|null  $glJournalEntryId  The GL reversal id; null when the balance was zero
     *                                         or on an idempotent re-entry
     * @param  bool  $alreadyVoided  True when this call was a no-op
     */
    public function __construct(
        public Voucher $voucher,
        public ?VoucherLedger $ledgerRow,
        public ?string $glJournalEntryId,
        public bool $alreadyVoided,
    ) {}
}
