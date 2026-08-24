<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Application\Services\DocumentAllocationStateGuard;
use App\Modules\Treasury\Domain\Enums\AllocationTreatment;
use App\Modules\Treasury\Domain\Exceptions\DocumentNotAllocatableException;

/**
 * N-6 — decide how an AR allocation must be booked, by POSTED-NESS.
 *
 * The pre-N-6 rule was `DocumentType::SalesOrder ⇒ advance, else Cr 411`
 * (`PaymentAllocationService`'s JE branch). It never asked whether a receivable
 * existed. A confirmed invoice is numbered and payable in the product but has
 * NO GL footprint: posting is what creates 411 / revenue / VAT. Crediting 411
 * against it produced a dangling credit — the partner's receivable went
 * negative, no VAT was ever declared, and the invoice was flipped to `Paid`, a
 * state `DocumentPostingService::post()` refuses, so it could never be posted
 * afterwards.
 *
 * THE RULE (Phase 1) — EVERY (type, status) PAIR IS ENUMERATED. There is no
 * `default => ReceivableClearing` fall-through, and gate r1 I-5 is why: the
 * first version defaulted unruled pairs to Cr 411, which silently FLIPPED
 * `SalesOrder + Posted` from the pre-N-6 Cr 419 (a pure TYPE test — ANY sales
 * order booked an advance) to Cr 411, reproducing the exact N-6 defect on a
 * different pair, inside the fix for it. A rule written as `default =>` also
 * cannot be told apart from an oversight by the next reader.
 *
 *   Invoice + Posted/Paid          → ReceivableClearing (Cr 411)
 *   Invoice + Confirmed            → Prepayment (Cr 419)   ← THE N-6 EDGE
 *   SalesOrder, any live status    → Prepayment (Cr 419)
 *   PurchaseOrder, any live status → ReceivableClearing — EXPLICITLY WRONG, and
 *     kept only because it is live today (pinned by
 *     `DocumentPaymentStatusTransitionTest::test_fully_paid_purchase_order_retains_confirmed_status`).
 *     A supplier prepayment belongs in a SUPPLIER advance account; this books a
 *     negative CUSTOMER receivable against a supplier partner. That is an AP
 *     defect, not the N-6 defect, and refusing it here would break a live flow to
 *     fix a different bug. Named residual R-1 in the N-6 handback; owned by the
 *     purchases lane.
 *   everything else                → `DocumentNotAllocatableException` (422)
 *
 * A DRAFT has committed nothing to the customer; a CANCELLED document is
 * withdrawn (W-7 F-6 — {@see DocumentAllocationStateGuard} covers the same
 * ground from the fiscal side and keeps its own refusal); a CREDIT NOTE is money
 * owed BY us and is never settled by a customer receipt on this path; a SUPPLIER
 * INVOICE is payable only through the supplier-aware `PaymentController::store()`
 * branch, which posts Dr 401 / Cr Bank and runs its own posted-ness + Cr-401
 * evidence guard.
 *
 * LIVES IN Domain (gate r1 I-1). It is a pure, side-effect-free policy object
 * with no infrastructure dependency, and `MultiPaymentService` — itself a Domain
 * service — must consult it. Sitting in Application made that a deptrac
 * Domain→Application BLOCKER.
 */
final class DocumentAllocationClassifier
{
    /**
     * @throws DocumentNotAllocatableException rendered as 422 `DOCUMENT_NOT_ALLOCATABLE`
     */
    public function classify(Document $document): AllocationTreatment
    {
        $treatment = $this->classifyOrNull($document);

        if ($treatment === null) {
            throw new DocumentNotAllocatableException(
                $document->id,
                $document->document_number,
                $document->type,
                $document->status,
            );
        }

        return $treatment;
    }

    /**
     * The non-throwing counterpart, for read models that must decide whether to
     * OFFER a document as payable without provoking an exception (the
     * open-documents lookup, the allocation preview).
     */
    public function classifyOrNull(Document $document): ?AllocationTreatment
    {
        return match (true) {
            // ── Refusals first: unconditional, nothing below may shadow them ──
            in_array($document->status, [DocumentStatus::Draft, DocumentStatus::Cancelled], true) => null,
            $document->type === DocumentType::CreditNote => null,
            $document->type === DocumentType::SupplierInvoice => null,

            // ── Invoice ──
            $document->type === DocumentType::Invoice
                && in_array($document->status, [DocumentStatus::Posted, DocumentStatus::Paid], true) => AllocationTreatment::ReceivableClearing,

            // THE N-6 EDGE: a confirmed invoice has no receivable yet.
            $document->type === DocumentType::Invoice
                && $document->status === DocumentStatus::Confirmed => AllocationTreatment::Prepayment,

            // ── Sales order: ALWAYS an advance, at every reachable status ──
            // `Posted` IS reachable (`DocumentPostingService::cancelSalesOrder()`
            // guards for exactly that state), and the pre-N-6 rule was a pure type
            // test — anything narrower here is a regression, not a fix.
            $document->type === DocumentType::SalesOrder => AllocationTreatment::Prepayment,

            // ── Purchase order: the explicitly-wrong legacy row, see docblock ──
            $document->type === DocumentType::PurchaseOrder => AllocationTreatment::ReceivableClearing,

            // Everything else is refused. NO `default => ReceivableClearing`.
            default => null,
        };
    }

    public function isAllocatable(Document $document): bool
    {
        return $this->classifyOrNull($document) !== null;
    }
}
