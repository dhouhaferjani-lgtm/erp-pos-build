<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\Enums\AllocationRefusalReason;
use App\Modules\Treasury\Domain\Enums\AllocationTreatment;
use App\Modules\Treasury\Domain\Exceptions\DocumentNotAllocatableException;
use App\Shared\Contracts\Accounting\HistoricalOpeningSide;
use App\Shared\Contracts\Accounting\HistoricalOpeningSideReaderInterface;

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
 * ── Provenance: fail closed WHERE THE EVIDENCE IS ABSENT (F-107, F-2) ─────
 * Spec rules 2–5 decide historical openings and POS account-charge invoices
 * from PERSISTED provenance columns (`opening_side`, `document_provenance`)
 * that do not exist yet — lane C-PROV0 adds them and C-0a1 admits those rules.
 * The markers themselves are read from what the minting services already write
 * (`ArApOpeningService::postBatch()` sets `is_historical` + the batch reference
 * prefix; `POSAccountChargeDraftService::createDraft()` sets the receipt
 * reference).
 *
 * POS-derived Invoice/CreditNote ⇒ REFUSED: the 411 already exists (it came from
 * the sealed POS fiscal event), so a payment on it is always a clearing and
 * never a 419 advance — but nothing here can yet prove the adopted receivable is
 * the one being settled, so it waits for C-0a1.
 *
 * Historical openings ⇒ side-resolved, see `refusalForHistoricalOpening()`. Fail
 * closed is the rule for the AP side and for an unprovable side, NOT a blanket
 * over both: gate r1 F-2 established that the AR/AP discriminator exists today
 * (`opening_balance_import_rows.row_type` + `mapped_entity_id`), and refusing a
 * side you can prove is safe is not caution, it is an outage.
 *
 * ── Reversals are the inverse operation ───────────────────────────────────
 * `assertReversalAdmitted()` is what the refund / reversal writers call. It is
 * TOTAL BY DESIGN — see its docblock. Admission rules govern money coming IN;
 * a rule that could refuse the unwinding of money already recorded would strand
 * cash on a document this lane has just stopped admitting.
 *
 * LIVES IN Domain (N-6 gate r1 I-1) — `MultiPaymentService`, itself a Domain
 * service, must consult it, and sitting in Application made that a deptrac
 * Domain→Application BLOCKER. It is no longer strictly side-effect-free: F-2
 * gives it one constructor-injected READER, consulted only for documents that
 * already carry an opening marker. It still writes nothing and decides nothing
 * from ambient state. `App\Modules\Treasury\Application\Services\DocumentAllocationStateGuard`
 * (N-6 R2-F4: FQCN in prose, not a Domain→Application `use`) covers the
 * cancelled-document ground from the fiscal side and keeps its own refusal.
 */
final class DocumentAllocationClassifier
{
    public function __construct(
        // C-0a0 gate r1 / F-2 — the AR/AP side of a historical opening, read
        // through a Shared contract (rule 6). It is consulted ONLY for a
        // document that already carries an opening marker, so the ordinary
        // native path stays query-free.
        private readonly HistoricalOpeningSideReaderInterface $openingSideReader,
    ) {}

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
            // F-4: a posted supplier invoice is INWARD money on the wrong path,
            // not an outward document. Its remedy is the supplier payment flow,
            // and the reason has to say so.
            throw new DocumentNotAllocatableException(
                $document->id,
                $document->document_number,
                $document->type,
                $document->status,
                AllocationRefusalReason::PayableNotSettleableHere,
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

        // ── Rules 2–5 (F-107 sequencing) ──────────────────────────────────
        // Only Invoice and CreditNote are ever minted by the opening importer
        // or the POS account-charge bridge, so only they can carry a marker.
        if ($document->type === DocumentType::Invoice || $document->type === DocumentType::CreditNote) {
            if ($this->isHistoricalOpening($document)) {
                return $this->refusalForHistoricalOpening($document);
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

            // Rule 7, as reconciled with the N-6 gate in r1 (F-9). Spec r11
            // says "SalesOrder + confirmed"; the N-6 gate ruled that `Posted` is
            // REACHABLE (`DocumentPostingService::cancelSalesOrder()` guards for
            // exactly that state, and `DocumentStatusMachine` allows
            // Confirmed → Posted for every sales-lifecycle type) and that
            // narrowing below the pre-N-6 rule is a regression, not a fix. Rule 1
            // has already excluded every non-live status, so admitting BOTH live
            // statuses honours the prior ruling without widening past it. Either
            // way the money is an ADVANCE: a sales order never carries a 411.
            DocumentType::SalesOrder => null,

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
     * SEAM PRESENT, PREDICATE DEFERRED TO C-0a1 — say so plainly rather than let
     * a census imply otherwise. It exists as an explicit call rather than as an
     * absence so that (a) the writer census can prove EVERY writer of
     * `payment_allocations` passes through this object, and (b) when a reversal
     * rule is finally ruled (C-0a1, once provenance distinguishes the families),
     * there is exactly one place to put it.
     *
     * It takes an ID, not a `Document`, deliberately (gate r1 / F-5). r1 loaded
     * the document at three call sites purely to satisfy this signature — three
     * discarded `Document::find()` round-trips inside the refund transaction, to
     * feed a method that does nothing with them. The seam should cost nothing
     * until it decides something. Residual R-C0a0-1 in the handback.
     */
    public function assertReversalAdmitted(string $documentId): void
    {
        unset($documentId);
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
     * C-0a0 gate r1 / F-2 — a historical opening is refused on the side that
     * actually books wrong-direction GL, and admitted on the side that does not.
     *
     * r1 refused BOTH sides on the stated grounds that no column could tell them
     * apart. That was not true: `opening_balance_import_rows.row_type` has
     * carried `AR`/`AP` since the importer was written, and `mapped_entity_id`
     * names the document each row minted. Blanket refusal therefore bought no
     * safety on the AR side and cost a first-client cutover its entire open
     * receivables ledger — those invoices have no other settlement route,
     * because the AP branch of `PaymentController::store()` requires
     * `type === SupplierInvoice` and an opening is minted as `Invoice`.
     *
     * Owner-sheet OQ-74 records this as the default:
     * - AR + posted ⇒ ADMITTED (`ReceivableClearing`). Collecting it is an
     *   ordinary collection, and booking it as a 419 advance would invent a
     *   liability the company does not owe.
     *
     *   CUTOVER ORDERING (gate r2 / R-R2-1) — an AR opening DOCUMENT creates no
     *   journal entry of its own: `ArApOpeningService` says so three times
     *   ("No GL entry created (GL was handled by accounting opening)", `:37`,
     *   `:257`, `:427`). It writes `is_historical`, `balance_due` and a `posted`
     *   status, and nothing else. So the 411 this collection CREDITS exists only
     *   if the tenant also posted an `AccountingOpeningService` opening batch.
     *   Collect against AR open items only AFTER the accounting opening batch is
     *   posted, or the credit lands against a debit that was never made and the
     *   partner's receivable goes negative. This is inherited, not introduced —
     *   base admitted the same collections — but C-0a0 is what re-enables the
     *   path, so the runbook ordering is stated where the admission is made.
     * - AP ⇒ REFUSED. Settling it is Dr 401 / Cr bank; C-0a1 routes it once
     *   `opening_side` is a persisted column.
     * - side UNPROVEN ⇒ REFUSED. Fail closed belongs where the evidence is
     *   genuinely absent, not where nobody looked.
     *
     * CreditNote openings stay refused on BOTH sides (spec rule 4 / OQ-37).
     */
    private function refusalForHistoricalOpening(Document $document): ?AllocationRefusalReason
    {
        if ($document->type === DocumentType::CreditNote) {
            return AllocationRefusalReason::HistoricalOpeningProvenance;
        }

        if ($this->openingSideReader->sideFor($document->id) !== HistoricalOpeningSide::AccountsReceivable) {
            return AllocationRefusalReason::HistoricalOpeningProvenance;
        }

        // An opening is minted `posted` (`ArApOpeningService::postBatch()`), and
        // `posted` is what proves the 411 exists. Anything else is a row nobody
        // has ruled on — refuse rather than invent a treatment for it.
        return $document->status === DocumentStatus::Posted
            ? null
            : AllocationRefusalReason::StatusNotAllocatableForType;
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
