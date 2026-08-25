<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

/**
 * WHY a document was refused a payment allocation (C-0a0, SPEC §2.1).
 *
 * `DOCUMENT_NOT_ALLOCATABLE` on its own tells an operator nothing actionable —
 * "post the invoice first", "this is a supplier opening balance", "credit notes
 * are refunded, not collected", "purchase-order prepayments are not supported"
 * and "pay this one through the supplier flow" are five different remedies
 * behind one code. The reason is the machine-
 * readable half of the refusal; `translationKey()` is the human half, resolved
 * against `lang/<locale>/treasury.php` so nothing user-facing is hardcoded
 * (rule 11).
 *
 * Rule 9 is a DEFAULT-REFUSE, so a new `DocumentType` lands here rather than in
 * an admitted branch — which is the point: an unruled type must be visibly
 * unruled, never silently payable.
 */
enum AllocationRefusalReason: string
{
    /**
     * Rule 1 — the document is not in a live, committed state: `draft` (nothing
     * has been committed to the counterparty), `cancelled` (withdrawn), or one
     * of the retired lifecycle values `paid` / `received` (F-88), against which
     * no NEW money may be admitted.
     */
    case DocumentNotLive = 'document_not_live';

    /**
     * F-107 — a historical AR/AP opening minted by `ArApOpeningService`.
     * Rules 2–4 (which side it settles) need the persisted provenance columns
     * that lane C-PROV0 adds; until then the counterparty direction is a guess,
     * and guessing wrong books Dr bank / Cr 411 for a PAYABLE. Fail closed.
     */
    case HistoricalOpeningProvenance = 'historical_opening_provenance';

    /**
     * F-89 / F-107 — an invoice minted from a POS account-charge receipt. The
     * 411 receivable already exists (it came from the sealed POS fiscal event),
     * so such a payment is a clearing and NEVER a 419 advance. Rule 5 admits it
     * once provenance is persisted; until then, fail closed.
     */
    case PosDerivedProvenance = 'pos_derived_provenance';

    /**
     * The type IS allocatable, but not in this lifecycle status — a supplier
     * invoice that is not yet `posted` (no Cr 401 to clear), a sales order that
     * is no longer `confirmed`.
     */
    case StatusNotAllocatableForType = 'status_not_allocatable_for_type';

    /**
     * Credit notes and supplier credit notes carry money owed BY us. They are
     * settled OUTWARD — by application to another document or by a refund —
     * never by an inbound allocation.
     */
    case OutwardDocumentType = 'outward_document_type';

    /**
     * F-153 / LEDGER OQ-3 — a purchase-order allocation used to book a NEGATIVE
     * customer receivable (Cr 411) against a SUPPLIER partner: the wrong
     * direction, the wrong account and the wrong partner class. A genuine
     * supplier prepayment belongs in a supplier-advance account (409) and is a
     * future program. Refused throughout this one.
     */
    case PurchaseOrderWrongDirection = 'purchase_order_wrong_direction';

    /**
     * Rule 9's tail: quotes, RFQs, delivery notes, return notes and correcting
     * entries carry no partner balance an allocation could settle; expenses and
     * income are settled through their own metadata flags
     * (`expense_metadata.is_paid` / `income_metadata.is_received`), not through
     * `payment_allocations`.
     */
    case TypeNeverAllocatable = 'type_never_allocatable';

    /**
     * C-0a0 gate r1 / F-4 — the document IS payable, but not on THIS path.
     *
     * A posted supplier invoice is settled Dr 401 / Cr bank through the
     * supplier-aware `PaymentController::store()` branch. Every other writer
     * posts the customer direction (Dr bank / Cr 411-or-419) and increments a
     * repository, so `classifyReceivableSide()` refuses a `PayableSettlement`
     * there. r1 reused `OutwardDocumentType` for this, which was wrong twice
     * over: a payable is INWARD money, not outward, and its operator copy talked
     * about credit notes. Both halves of a refusal have to be true, or the enum
     * is just `DOCUMENT_NOT_ALLOCATABLE` with extra steps.
     */
    case PayableNotSettleableHere = 'payable_not_settleable_here';

    /**
     * The i18n key that renders this reason to an operator. Namespaced so the
     * whole family can be located and translated as one block.
     */
    public function translationKey(): string
    {
        return 'treasury.allocation_refused.'.$this->value;
    }
}
