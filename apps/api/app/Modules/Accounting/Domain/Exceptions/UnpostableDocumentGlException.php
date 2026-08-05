<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Exceptions;

use App\Modules\Accounting\Domain\Enums\GlResidualRefusal;
use DomainException;

/**
 * Thrown by the GL PRE-FLIGHT, before a document is sealed.
 *
 * W-6 D1a, gate finding C-1: the first cut of the guard threw from inside the
 * post-commit `InvoicePostedListener`, i.e. after `DocumentPostingService` had
 * already written `status = Posted`, `fiscal_hash` and `chain_sequence`. Because
 * `post()` returns early on an already-posted document, `InvoicePosted` never
 * re-fired, so the refusal left a sealed invoice with NO GL at all — AR, revenue,
 * VAT and the partner balance all missing while the trial balance reported
 * "balanced". That is worse than the unbalanced entry it was meant to prevent.
 *
 * The assertion now runs inside `DocumentPostingService::post()`'s transaction,
 * BEFORE the seal, so a refusal rolls back cleanly and nothing is stranded.
 * Extending `DomainException` makes it a 422 `BUSINESS_ERROR` via
 * `bootstrap/app.php` — the document really is unpostable as authored, and the
 * user can fix its totals and retry.
 *
 * Contrast {@see UnbalancedJournalEntryException}, which stays a 500: it can only
 * fire from the listener, after the seal, and by then a 422 would be a lie.
 *
 * docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md (D1a)
 * docs/superpowers/reviews/2026-08-05-l1-fiscal-gate.md (C-1, C-2)
 */
final class UnpostableDocumentGlException extends DomainException
{
    public function __construct(
        public readonly GlResidualRefusal $refusal,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  numeric-string  $residual
     */
    public static function forDocument(
        GlResidualRefusal $refusal,
        string $documentNumber,
        string $residual,
    ): self {
        return new self($refusal, sprintf(
            '%s [%s] Document %s, residual %s.',
            $refusal->message(),
            $refusal->value,
            $documentNumber,
            $residual,
        ));
    }
}
