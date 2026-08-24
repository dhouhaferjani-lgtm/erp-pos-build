<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Enums\DocumentStatus;

/**
 * Pure domain service encoding the document lifecycle-status adjacency map.
 *
 * Same shape as the WorkOrder precedent
 * ({@see \App\Modules\Workshop\WorkOrder\Domain\Services\StatusMachine}):
 * side-effect free, answers only "is this edge legal?".
 * {@see DocumentStatusService} is the single write path — it consults this
 * machine before every mutation and raises
 * {@see \App\Modules\Document\Domain\Exceptions\DocumentTransitionException}
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
    public function isAllowed(DocumentStatus $from, DocumentStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to, $this->allowedTargetsOf($from), true);
    }

    /**
     * Return the allowed outgoing target states for a source state.
     *
     * @return list<DocumentStatus>
     */
    public function allowedTargetsOf(DocumentStatus $from): array
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
