<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Document\Domain\Document;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * W-7 F-6 — refuse to allocate money to a WITHDRAWN document.
 *
 * The campaign reproduced this with no race at all: post an invoice for 200.000,
 * cancel it (200, `status: cancelled`, `cancelled_at` set), then record a payment
 * against it from a stale page — 201, and the document came back `status: paid`
 * with `cancelled_at` still populated, indistinguishable from a legitimately
 * settled invoice. A cancelled sale reappeared as revenue that was collected.
 *
 * Nothing on that path ever asked what STATE the document was in:
 * - the `allocations.*.document_id` rule is a bare `ScopedExists` (tenant +
 *   company, no status predicate);
 * - the one status guard that existed was scoped inside the SupplierInvoice
 *   branch, and the AR branch was a bare `else` that checked nothing;
 * - the FIVE writes that flip a document to `Paid` are each gated only on
 *   `DocumentType::canTransitionToPaid()`, a pure TYPE match;
 * - the DB immutability trigger returns early unless `fiscal_status = 'SEALED'`,
 *   and a cancelled document is `VOIDED`, so the database did not stop it either.
 *
 * The fix is this ONE guard, called on the shared per-allocation paths rather than
 * patched onto the five writers, so a status write can never be reached with a
 * withdrawn document in hand.
 */
final class DocumentAllocationStateGuard
{
    /**
     * @throws HttpResponseException 422 when the document has been withdrawn.
     */
    public function assertAllocatable(Document $document): void
    {
        if (! $document->isWithdrawn()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'DOCUMENT_NOT_ALLOCATABLE',
                'message' => 'This document has been cancelled and can no longer receive payments.',
                'details' => [
                    'document_id' => $document->id,
                    'document_number' => $document->document_number,
                    'status' => $document->status->value,
                    'fiscal_status' => $document->fiscal_status->value,
                ],
            ],
        ], 422));
    }
}
