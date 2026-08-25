<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Accounting;

use App\Modules\Document\Domain\Document;

/**
 * Ask Accounting to clear a customer advance (419) into the receivable (411)
 * for a document that has just been posted (N-6).
 *
 * WHY A CONTRACT. A payment collected on a CONFIRMED invoice is booked
 * Dr Bank / Cr 419 — no receivable exists yet. Posting is what creates
 * Dr 411 / Cr Revenue + VAT, and at that instant the 419 liability must be
 * discharged against the new receivable (Dr 419 / Cr 411), or the customer
 * shows both an open advance AND an open receivable for the same money.
 *
 * That clearing has to happen INSIDE `DocumentPostingService::post()`'s
 * transaction — atomic with the seal — and `DocumentPostingService` lives in
 * the Document module, which may not import `GeneralLedgerService`
 * (CLAUDE.md rule 6). This is that seam, the same shape as
 * {@see DocumentGlPreflightInterface} and {@see DocumentGlReversalInterface}.
 */
interface CustomerAdvanceClearingInterface
{
    /**
     * Clear `$amount` of the document partner's customer advance against the
     * receivable, posting the entry SYNCHRONOUSLY inside the caller's
     * transaction so a refusal rolls the posting back with it.
     *
     * @param  numeric-string  $amount  must be > 0 and within the partner's
     *                                  available (unconsumed) 419 balance.
     * @return string The clearing journal entry's id.
     *
     * @throws \InvalidArgumentException When the amount is non-positive or
     *                                   exceeds the partner's available advance.
     */
    public function clearCustomerAdvanceForDocument(
        Document $document,
        string $amount,
        ?string $actorUserId,
    ): string;
}
