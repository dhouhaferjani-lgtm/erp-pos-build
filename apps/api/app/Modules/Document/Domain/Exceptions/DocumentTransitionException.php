<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Services\DocumentStatusMachine;
use DomainException;

/**
 * Raised when a requested document lifecycle-status transition is refused by
 * the adjacency map encoded in
 * {@see DocumentStatusMachine}.
 *
 * Maps to HTTP 422 `DOCUMENT_TRANSITION_REFUSED` at the API boundary
 * (`bootstrap/app.php`). Registered ABOVE the generic `DomainException`
 * handler, which Laravel would otherwise match first and render as
 * `BUSINESS_ERROR`.
 *
 * N-6: the edge this exists for is `confirmed → paid`. Seven treasury writers
 * used to perform it on a pure TYPE test (`DocumentType::canTransitionToPaid()`),
 * and `DocumentPostingService::post()` accepts only `Confirmed` — so a payment on
 * a confirmed invoice moved it to `Paid` and stranded it there forever: never
 * posted, never sealed, no GL, no VAT (campaign INV-2026-0003).
 */
final class DocumentTransitionException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $documentId,
        public readonly ?string $documentNumber,
        public readonly DocumentStatus $from,
        public readonly DocumentStatus $to,
    ) {
        parent::__construct($message);
    }

    public static function forbiddenEdge(
        string $documentId,
        ?string $documentNumber,
        DocumentStatus $from,
        DocumentStatus $to,
    ): self {
        return new self(
            "Document status transition {$from->value} → {$to->value} is not allowed.",
            $documentId,
            $documentNumber,
            $from,
            $to,
        );
    }
}
