<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\Enums\AllocationRefusalReason;
use App\Modules\Treasury\Domain\Enums\AllocationTreatment;
use App\Modules\Treasury\Domain\Exceptions\DocumentNotAllocatableException;

/**
 * The ONE payment-applicability policy: may money be allocated to this
 * document, and if so, how must the ledger book it.
 *
 * ── N-6 (Phase 1) established the object ──────────────────────────────────
 * The pre-N-6 rule was `DocumentType::SalesOrder ⇒ advance, else Cr 411`
 * (`PaymentAllocationService`'s JE branch). It never asked whether a receivable
 * existed. A confirmed invoice is numbered and payable in the product but has
 * NO GL footprint: posting is what creates 411 / revenue / VAT. Crediting 411
 * against it produced a dangling credit — the partner's receivable went
 * negative, no VAT was ever declared, and the invoice was flipped to `Paid`, a
 * state `DocumentPostingService::post()` refuses, so it could never be posted
 * afterwards. N-6 replaced the type test with a posted-ness test and enumerated
 * every pair (gate r1 I-5: a `default =>` arm cannot be told apart from an
 * oversight by the next reader).
 *
 * ── C-0a0 makes it EXHAUSTIVE and DEFAULT-REFUSE ──────────────────────────
 * SPEC-document-lifecycle-dimensions r11 §2.1, rules 1 and 6–9. N-6 enumerated
 * the pairs it had opinions about and let the rest fall to `null`; the admitted
 * set was still wider than the ledger can justify, and three writers never
 * consulted the object at all. What changed:
 *
 *   ADMITTED — and nothing else:
 *     Invoice      + posted    (native)  → ReceivableClearing (Cr 411)
 *     Invoice      + confirmed (native)  → Prepayment         (Cr 419)
 *     SalesOrder   + confirmed           → Prepayment         (Cr 419)
 *     SupplierInvoice + posted           → PayableSettlement  (Dr 401)
 *
 *   NARROWED from N-6:
 *     Invoice + `paid` was ReceivableClearing. `paid` is a RETIRED lifecycle
 *       value (F-88); a settled document admits no new money.
 *     SalesOrder at ANY live status was Prepayment. Spec rule 7 admits it only
 *       while `confirmed` — a sales order's lifecycle is
 *       draft→confirmed→cancelled (§1.3), so any other status is a legacy row
 *       whose GL treatment nobody has ruled. Refuse rather than guess.
 *     PurchaseOrder was ReceivableClearing — N-6's own named residual R-1,
 *       kept only because it was live. It books a NEGATIVE CUSTOMER receivable
 *       against a SUPPLIER partner. F-153 / LEDGER OQ-3 refuse it throughout
 *       this program; a real supplier prepayment (Dr 409) is a future one.
 *
 *   WIDENED from N-6:
 *     SupplierInvoice + posted was refused HERE and handled by a bypass in
 *       `PaymentController::store()`. Behaviour is unchanged; the decision
 *       simply moved INTO the policy object so no writer needs a bypass. The
 *       AR-only writers call `classifyReceivableSide()` and refuse it.
 *
 * ── Provenance: fail closed (F-107) ───────────────────────────────────────
 * Spec rules 2–5 decide historical openings and POS account-charge invoices
 * from PERSISTED provenance columns (`opening_side`, `document_provenance`)
 * that do not exist yet — lane C-PROV0 adds them and C-0a1 admits those rules.
 * Until then this lane must not guess: a historical AP opening is minted as an
 * `Invoice` exactly like an AR one, and admitting it would book Dr bank /
 * Cr 411 for a PAYABLE — money moving the wrong way against the wrong partner
 * class. So Invoice/CreditNote rows that carry EITHER marker are REFUSED, and
 * the two markers are read from the columns the minting services already write
 * (see `ArApOpeningService::postBatch()` and `POSAccountChargeDraftService::createDraft()`).
 * This is a deliberate temporary NARROWING: historical AR openings are payable
 * today and stop being payable until C-0a1. Refusing a legitimate collection is
 * recoverable; a wrong-direction GL entry against a supplier is not.
 *
 * ── Reversals are the inverse operation ───────────────────────────────────
 * `assertReversalAdmitted()` is what the refund / reversal writers call. It is
 * TOTAL BY DESIGN — see its docblock. Admission rules govern money coming IN;
 * a rule that could refuse the unwinding of money already recorded would strand
 * cash on a document this lane has just stopped admitting.
 *
 * LIVES IN Domain (N-6 gate r1 I-1). A pure, side-effect-free policy object
 * with no infrastructure dependency — `MultiPaymentService`, itself a Domain
 * service, must consult it, and sitting in Application made that a deptrac
 * Domain→Application BLOCKER. `App\Modules\Treasury\Application\Services\DocumentAllocationStateGuard`
 * (N-6 R2-F4: FQCN in prose, not a Domain→Application `use`) covers the
 * cancelled-document ground from the fiscal side and keeps its own refusal.
 */
final class DocumentAllocationClassifier
{
    /**
     * The `documents.reference` prefix `ArApOpeningService::postBatch()` writes
     * on every historical AR/AP opening it mints
     * ("Opening Balance Batch: {$batch->name}").
     */
    public const HISTORICAL_OPENING_REFERENCE_PREFIX = 'Opening Balance Batch: ';

    /**
     * The `documents.reference` prefix `POSAccountChargeDraftService::createDraft()`
     * writes on every POS account-charge invoice ('POS-ACCOUNT-CHARGE:'.$fiscalEventId).
     * It is also that service's idempotency key, so it is durable, not cosmetic.
     */
    public const POS_ACCOUNT_CHARGE_REFERENCE_PREFIX = 'POS-ACCOUNT-CHARGE:';

    /**
     * The only two lifecycle states in which a document may receive money
     * (spec rule 1, read as its contrapositive).
     */
    private const LIVE_STATUSES = [DocumentStatus::Confirmed, DocumentStatus::Posted];

    /**
     * @throws DocumentNotAllocatableException rendered as 422 `DOCUMENT_NOT_ALLOCATABLE`
     */
    public function classify(Document $document): AllocationTreatment
    {
        $reason = $this->refusalReasonFor($document);

        if ($reason !== null) {
            throw new DocumentNotAllocatableException(
                $document->id,
                $document->document_number,
                $document->type,
                $document->status,
                $reason,
            );
        }

        return $this->treatmentFor($document);
    }

    /**
     * For the AR-only entry points (smart allocation, multi-line, excess
     * allocation, split payment, deposit application). They post Dr bank /
     * Cr 411-or-419 and increment a repository; a payable settlement reaching
     * them would move cash the wrong way against the wrong account, so it is
     * refused with the same typed 422 rather than silently mis-booked.
     *
     * @throws DocumentNotAllocatableException
     */
    public function classifyReceivableSide(Document $document): AllocationTreatment
    {
        $treatment = $this->classify($document);

        if (! $treatment->isReceivableSide()) {
            throw new DocumentNotAllocatableException(
                $document->id,
                $document->document_number,
                $document->type,
                $document->status,
                AllocationRefusalReason::OutwardDocumentType,
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
        return $this->refusalReasonFor($document) === null
            ? $this->treatmentFor($document)
            : null;
    }

    public function isAllocatable(Document $document): bool
    {
        return $this->refusalReasonFor($document) === null;
    }

    /**
     * WHY this document is refused, or `null` if it is admitted.
     *
     * Rules are evaluated in spec order and are mutually exclusive — FIRST
     * MATCH WINS. Rule 1 outranks provenance (a cancelled POS invoice is
     * refused as not-live, not as POS-derived), and provenance outranks the
     * per-type rules (a historical opening is refused as an opening, not as an
     * ordinary invoice that happens to be allocatable).
     */
    public function refusalReasonFor(Document $document): ?AllocationRefusalReason
    {
        // ── Rule 1 ────────────────────────────────────────────────────────
        if (! in_array($document->status, self::LIVE_STATUSES, true)) {
            return AllocationRefusalReason::DocumentNotLive;
        }

        // ── Rules 2–5, fail-closed until C-PROV0 persists provenance (F-107) ──
        // Only Invoice and CreditNote are ever minted by the opening importer
        // or the POS account-charge bridge, so only they can carry a marker.
        if ($document->type === DocumentType::Invoice || $document->type === DocumentType::CreditNote) {
            if ($this->isHistoricalOpening($document)) {
                return AllocationRefusalReason::HistoricalOpeningProvenance;
            }

            if ($this->isPosAccountCharge($document)) {
                return AllocationRefusalReason::PosDerivedProvenance;
            }
        }

        // ── Rules 6–9. EXHAUSTIVE over `DocumentType`: no `default` arm, so a
        // new type is a PHPStan/`UnhandledMatchError` failure, never a silent
        // admission. ──────────────────────────────────────────────────────
        return match ($document->type) {
            // Rule 6 — native invoice: posted clears the receivable, confirmed
            // takes an advance. Both statuses are live by rule 1 above.
            DocumentType::Invoice => null,

            // Rule 7.
            DocumentType::SalesOrder => $document->status === DocumentStatus::Confirmed
                ? null
                : AllocationRefusalReason::StatusNotAllocatableForType,

            // Rule 8 — the existing guarded supplier path. `PaymentController`
            // keeps its own posted-ness + Cr-401-evidence guard on top; this is
            // the policy half of the same rule.
            DocumentType::SupplierInvoice => $document->status === DocumentStatus::Posted
                ? null
                : AllocationRefusalReason::StatusNotAllocatableForType,

            // Rule 9 — outward money.
            DocumentType::CreditNote,
            DocumentType::SupplierCreditNote => AllocationRefusalReason::OutwardDocumentType,

            // Rule 9 — F-153 / LEDGER OQ-3.
            DocumentType::PurchaseOrder => AllocationRefusalReason::PurchaseOrderWrongDirection,

            // Rule 9 — no partner balance an allocation could settle.
            DocumentType::Quote,
            DocumentType::PurchaseQuoteRequest,
            DocumentType::DeliveryNote,
            DocumentType::ReturnNote,
            DocumentType::CorrectingEntry,
            DocumentType::Expense,
            DocumentType::Income => AllocationRefusalReason::TypeNeverAllocatable,
        };
    }

    /**
     * A REVERSAL — a negative mirror that unwinds allocations already recorded
     * (`PaymentRefundService`'s full refund, pro-rata unwind and payment
     * reversal; `VendorRefundService`'s prepayment refund).
     *
     * TOTAL BY DESIGN, and that is the ruling, not an oversight. This lane
     * narrows what money may come IN; it must not narrow what may come back
     * OUT. Every document that carries a live allocation today was admitted by
     * SOME rule at the time — including rules this lane has just withdrawn
     * (purchase-order prepayments, historical openings, sales orders past
     * `confirmed`). If reversal admission reused the inbound table, the first
     * consequence of shipping C-0a0 would be that those documents' money could
     * no longer be refunded: cash stranded on a document nobody can unwind,
     * which is strictly worse than the defect being fixed.
     *
     * It exists as an explicit call rather than as an absence so that (a) the
     * census can prove EVERY writer of `payment_allocations` passes through the
     * classifier, and (b) when a reversal rule is finally ruled (C-0a1, once
     * provenance distinguishes the families), there is exactly one place to put
     * it. Residual R-C0a0-1 in the handback.
     */
    public function assertReversalAdmitted(Document $document): void
    {
        unset($document);
    }

    /**
     * The GL treatment for an ADMITTED document. Exhaustive over `DocumentType`
     * for the same reason `refusalReasonFor()` is: every refused type resolves
     * to `null` HERE too, so the two tables can never disagree about which
     * cells are admitted.
     */
    private function treatmentFor(Document $document): AllocationTreatment
    {
        return match ($document->type) {
            DocumentType::Invoice => $document->status === DocumentStatus::Posted
                ? AllocationTreatment::ReceivableClearing
                : AllocationTreatment::Prepayment,
            DocumentType::SalesOrder => AllocationTreatment::Prepayment,
            DocumentType::SupplierInvoice => AllocationTreatment::PayableSettlement,
            DocumentType::Quote,
            DocumentType::PurchaseQuoteRequest,
            DocumentType::PurchaseOrder,
            DocumentType::CreditNote,
            DocumentType::SupplierCreditNote,
            DocumentType::DeliveryNote,
            DocumentType::ReturnNote,
            DocumentType::CorrectingEntry,
            DocumentType::Expense,
            DocumentType::Income => throw new \LogicException(
                "DocumentAllocationClassifier: {$document->type->value} is never admitted; "
                .'treatmentFor() must only be reached for a document refusalReasonFor() admitted.'
            ),
        };
    }

    /**
     * A historical AR/AP opening minted by `ArApOpeningService::postBatch()`.
     *
     * BOTH markers are checked and EITHER is enough — this is a fail-closed
     * detector, so a false positive costs a refusal an operator can escalate,
     * while a false negative costs a wrong-direction GL entry. `is_historical`
     * is the durable column the importer sets; the reference prefix is the
     * batch marker the brief names, and it survives a row whose flag some other
     * importer never set.
     */
    private function isHistoricalOpening(Document $document): bool
    {
        if ($document->is_historical === true) {
            return true;
        }

        return str_starts_with((string) $document->reference, self::HISTORICAL_OPENING_REFERENCE_PREFIX);
    }

    /**
     * An invoice minted from a sealed POS account-charge fiscal event by
     * `POSAccountChargeDraftService::createDraft()`. The reference IS that
     * service's idempotency key, so it is present on every such row.
     */
    private function isPosAccountCharge(Document $document): bool
    {
        return str_starts_with((string) $document->reference, self::POS_ACCOUNT_CHARGE_REFERENCE_PREFIX);
    }
}
