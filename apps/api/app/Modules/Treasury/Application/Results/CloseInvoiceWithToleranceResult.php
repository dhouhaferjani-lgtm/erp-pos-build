<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Results;

/**
 * Internal application-layer result of CloseInvoiceWithToleranceService::close().
 *
 * Not a wire-format DTO — the controller composes the JSON envelope around the
 * fresh Document model. This object simply carries the bookkeeping facts the
 * caller needs (write-off amount, GL entry id) without re-querying.
 */
final readonly class CloseInvoiceWithToleranceResult
{
    public function __construct(
        public string $invoiceId,
        public string $amountWrittenOff,
        public string $glEntryId,
    ) {}
}
