<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Exceptions\DocumentTransitionException;
use App\Modules\Workshop\WorkOrder\Domain\Services\StatusMachine;

/**
 * Pure domain service encoding the document lifecycle-status adjacency map.
 *
 * Same shape as the WorkOrder precedent
 * ({@see StatusMachine}):
 * side-effect free, answers only "is this edge legal?".
 * {@see DocumentStatusService} is the single write path — it consults this
 * machine before every mutation and raises
 * {@see DocumentTransitionException}
 * (422 `DOCUMENT_TRANSITION_REFUSED`) when the edge is forbidden.
 *
 * PHASE 1 SCOPE (N-6). `paid` is still a row in the `status` column — removing
 * it in favour of a derived `payment_status` is Phase 2 of the
 * invoice-lifecycle-dimensions program
 * (`docs/new_docs/04-PROMPTS/DOCUMENT_LIFECYCLE_AND_PAYMENT_STATUS.md` §1.2 has
 * the target model). What Phase 1 fixes is the one edge that produces
 * unrecoverable documents:
 *
 *   **`confirmed → paid` is impossible.** `Paid` is reachable ONLY from
 *   `Posted`. A payment collected on a confirmed-but-unposted invoice is an
 *   ADVANCE (Cr 419) and moves no lifecycle status at all.
 *
 * `paid → posted` exists for exactly one caller class: a refund/instrument
 * cancellation that re-opens a settled document's balance. It never promotes a
 * document that was never posted — the caller must check `fiscal_status`
 * (see {@see DocumentStatusService::reopenFromPaid()}).
 *
 * `Received` (supplier goods-receipt lifecycle) has no edges here: nothing in
 * this lane writes it, and inventing edges for it would ratify a shape no
 * caller has expressed.
 */
final class DocumentStatusMachine
{
    /**
     * Determine whether the given edge is allowed by the adjacency map.
     *
     * Self-loops are always forbidden — an idempotent re-write is the caller's
     * concern (it must short-circuit BEFORE asking for a transition), never a
     * legal edge.
     */
    public function isAllowed(DocumentStatus $from, DocumentStatus $to, ?DocumentType $type = null): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to, $this->allowedTargetsOf($from, $type), true);
    }

    /**
     * Return the allowed outgoing target states for a source state.
     *
     * @return list<DocumentStatus>
     */
    public function allowedTargetsOf(DocumentStatus $from, ?DocumentType $type = null): array
    {
        $targets = $this->salesLifecycleTargetsOf($from);

        // N-6 fix round r1 / fiscal gate F-6 — TYPE-AWARE EDGES.
        //
        // Four live production services post their document DIRECTLY from
        // `Draft`, with no `Confirmed` step at all: supplier invoices, supplier
        // credit notes, expenses and incomes. The r1 map forbade `draft → posted`
        // outright while calling itself "the document lifecycle", so routing
        // those writers through the service — which a later lane will do — would
        // have 422'd four daily flows in production. They are not a hole in the
        // model; they are a DIFFERENT lifecycle, and the map now says so.
        //
        // The sales lifecycle keeps `draft → posted` FORBIDDEN: for an invoice
        // or credit note, `Confirmed` is where the delivery-compliance gate, the
        // GL pre-flight and the numbering decision all live.
        if ($from === DocumentStatus::Draft && $type !== null && $this->postsDirectlyFromDraft($type)) {
            $targets[] = DocumentStatus::Posted;
        }

        return $targets;
    }

    /**
     * Types whose posting service takes them straight from `Draft` to `Posted`.
     *
     * R2-F4 — these are named as FQCN TEXT, never as `use` imports. A Domain
     * class that imports an Application class is a rule-6 (and deptrac
     * Domain->Application) edge even when the import exists only to satisfy a
     * `{@see}`, and deptrac's configured emitters do NOT report docblock-only
     * imports — so a green ratchet would not have caught it. Same edit F-9
     * already applied to `DocumentStatusService`.
     *
     * `App\Modules\Procurement\Application\SupplierInvoicePostingService`,
     * `App\Modules\Procurement\Application\SupplierCreditNotePostingService`,
     * `ExpenseService::post()` and `IncomeService::post()`.
     */
    private function postsDirectlyFromDraft(DocumentType $type): bool
    {
        return in_array($type, [
            DocumentType::SupplierInvoice,
            DocumentType::SupplierCreditNote,
            DocumentType::Expense,
            DocumentType::Income,
        ], true);
    }

    /**
     * The SALES document lifecycle — quotes, orders, delivery/return notes,
     * invoices, credit notes.
     *
     * @return list<DocumentStatus>
     */
    private function salesLifecycleTargetsOf(DocumentStatus $from): array
    {
        return match ($from) {
            DocumentStatus::Draft => [
                DocumentStatus::Confirmed,
                DocumentStatus::Cancelled,
            ],
            DocumentStatus::Confirmed => [
                DocumentStatus::Draft,
                DocumentStatus::Posted,
                DocumentStatus::Cancelled,
                // DELIBERATELY ABSENT: DocumentStatus::Paid. See the class docblock.
            ],
            DocumentStatus::Posted => [
                DocumentStatus::Paid,
                DocumentStatus::Cancelled,
            ],
            DocumentStatus::Paid => [
                // Refund / instrument-cancellation re-open ONLY.
                DocumentStatus::Posted,
            ],
            // Terminal.
            DocumentStatus::Cancelled => [],
            // Out of Phase-1 scope — no caller in this lane writes it.
            DocumentStatus::Received => [],
        };
    }
}
