<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
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

    /**
     * W4-3 — refuse to settle a document whose SIDE disagrees with its partner's ROLE.
     *
     * The AR-vs-AP direction of every settlement is inferred from the allocated
     * document's TYPE alone. Nothing checked that the type was plausible for the
     * partner, so a customer-typed document owned by a SUPPLIER — what the AP
     * opening batch minted before W4-3, and what every tenant migrated before that
     * fix still carries — took the AR arm in silence: `Dr bank / Cr 411`, repository
     * movement direction IN, the `401` debt untouched, the document marked `paid`.
     * The operator sent 500.000 to a supplier and the system recorded 500.000
     * arriving. Nothing warned.
     *
     * It lives HERE, beside `assertAllocatable()`, for the reason that guard gives:
     * the settlement routes are four (`PaymentController::store` /
     * `::storeMultiple`, `MultiPaymentController::createSplitPayment` /
     * `::applyDeposit`, and `PaymentAllocationService` for the smart-payment and
     * credit paths), and a guard patched onto one of them is not a guard. Gate r1
     * I-1 proved the point: with the check on `store()` alone, the legacy row was
     * still reachable through the other three.
     *
     * The question is asked of the DOCUMENT'S OWN partner, not of the payer, so no
     * call site has to plumb a partner through: every route already forces the
     * payment onto the document's partner (`ALLOCATION_PARTNER_MISMATCH`), and a
     * document whose own partner cannot own its type is wrong no matter who pays it.
     *
     * `PartnerType::Both` satisfies both sides, so a dual-role partner is never
     * refused. A document with no partner, and every type outside AR/AP (advances,
     * expenses, POS, order-stage documents), returns without an opinion — this guard
     * answers one question and must not acquire views on flows it was not written for.
     *
     * @throws HttpResponseException 422 when the type and the partner role disagree.
     */
    public function assertDirectionMatchesPartner(Document $document): void
    {
        if ($this->directionMatchesPartner($document)) {
            return;
        }

        $partner = $this->partnerOf($document);

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'PAYMENT_DIRECTION_MISMATCH',
                'message' => "This document's type does not match the partner's role, so the payment direction "
                    .'cannot be determined. A customer invoice must belong to a customer and a supplier invoice '
                    .'to a supplier. If this partner is both, set its type to Both; otherwise correct the document.',
                'details' => [
                    'document_id' => $document->id,
                    'document_number' => $document->document_number,
                    'document_type' => $document->type->value,
                    'partner_id' => $partner?->id,
                    'partner_type' => $partner?->type->value,
                ],
            ],
        ], 422));
    }

    /**
     * The same question as a PREDICATE, for the paths that must not throw.
     *
     * Gate r2 F-1 — WHO CHOSE THE DOCUMENT decides what a refusal does, and the
     * r1 hoist got that wrong: it put the throwing form into
     * `PaymentAllocationService`'s execute loop, three lines above the 30-line
     * comment in that file explaining why a refusal there must SKIP. On an auto
     * sweep the SERVER chose the document, so throwing abandons every allocatable
     * document queued behind the refused one and rolls back the whole transaction
     * — and that exact call runs inside two queued fiscal projections
     * (`TreasuryAccountPaymentBridge`, `TreasuryDepositBridge`, both `FIFO`),
     * where an `HttpResponseException` is not the `NonRetryableProjectionException`
     * the job special-cases: it is retried five times and then dead-letters a
     * SEALED device fiscal fact. Eight tests that were green on dev went red.
     *
     * So the server-chosen path calls THIS, skips, and records
     * `AllocationRefusalReason::PartnerRoleMismatch`; the HTTP entry points, where
     * an operator named the document and is waiting for an answer, call
     * `assertDirectionMatchesPartner()` and get the typed 422.
     */
    public function directionMatchesPartner(Document $document): bool
    {
        $partner = $this->partnerOf($document);

        if (! $partner instanceof Partner) {
            return true;
        }

        return match ($document->type) {
            DocumentType::Invoice, DocumentType::CreditNote => $partner->isCustomer(),
            DocumentType::SupplierInvoice, DocumentType::SupplierCreditNote => $partner->isSupplier(),
            default => true,
        };
    }

    /**
     * `documents.partner_id` is typed non-nullable in the model docblock but the
     * COLUMN is nullable, so the relation is resolved through the query builder
     * (which types it `?Partner`) rather than read as a property. The eager-loaded
     * relation is reused when a caller already fetched it, so this adds a query
     * only on the paths that did not.
     */
    private function partnerOf(Document $document): ?Partner
    {
        $partner = $document->relationLoaded('partner')
            ? $document->getRelation('partner')
            : $document->partner()->first();

        return $partner instanceof Partner ? $partner : null;
    }
}
