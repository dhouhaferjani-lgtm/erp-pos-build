<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\Enums\AllocationRefusalReason;
use DomainException;

/**
 * A document cannot receive a payment allocation in its current
 * (type, status, provenance) state — N-6, made exhaustive by C-0a0.
 *
 * Rendered as 422 `DOCUMENT_NOT_ALLOCATABLE` in `bootstrap/app.php`, above the
 * generic `DomainException` handler.
 *
 * WHY A TYPED DOMAIN EXCEPTION AND NOT `HttpResponseException` (gate r1 I-6).
 * The classifier is reached from QUEUED PROJECTIONS as well as from HTTP:
 * `TreasuryAccountPaymentBridge` and `TreasuryDepositBridge` drive
 * `applyAllocationFromCommand()` with `AllocationMethod::FIFO`. An
 * `HttpResponseException` raised in a worker is an HTTP response object with no
 * request behind it — the worker cannot render it, log it usefully, or retry on
 * it. A domain refusal must be a domain type; the HTTP shape belongs at the
 * boundary.
 *
 * C-0a0 — the refusal now carries its REASON. One code behind several different
 * remedies ("post the invoice first", "this is an opening balance", "credit
 * notes are refunded, not collected", "purchase-order prepayments are not
 * supported") is an operator dead end; `$reason->translationKey()` is what the
 * boundary renders, so nothing user-facing is hardcoded (rule 11).
 */
final class DocumentNotAllocatableException extends DomainException
{
    public function __construct(
        public readonly string $documentId,
        public readonly ?string $documentNumber,
        public readonly DocumentType $documentType,
        public readonly DocumentStatus $documentStatus,
        public readonly AllocationRefusalReason $reason,
    ) {
        parent::__construct(sprintf(
            'Document %s (%s, %s) cannot receive a payment allocation in its current state (%s).',
            $documentNumber ?? $documentId,
            $documentType->value,
            $documentStatus->value,
            $reason->value,
        ));
    }
}
