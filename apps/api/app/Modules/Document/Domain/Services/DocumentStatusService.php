<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Exceptions\DocumentTransitionException;
use App\PHPStan\Rules\DocumentStatusWriteOnlyViaStatusService;
use Illuminate\Support\Facades\Log;

/**
 * The single write path for document lifecycle-status changes (N-6, Phase 1).
 *
 * Every caller that needs to move `documents.status` asks this service; it
 * consults {@see DocumentStatusMachine} and refuses a forbidden edge with
 * {@see DocumentTransitionException} (422 `DOCUMENT_TRANSITION_REFUSED`).
 * The custom PHPStan rule {@see DocumentStatusWriteOnlyViaStatusService}
 * enforces the Phase-1 half of that at static-analysis time.
 *
 * WHY THIS EXISTS. Seven treasury writers flipped a document to `Paid` on a
 * pure TYPE test (`DocumentType::canTransitionToPaid()`), which says nothing
 * about STATE. `DocumentPostingService::post()` accepts only `Confirmed`, so a
 * `Confirmed → Paid` flip produced a document that could never be posted,
 * never sealed, with no GL entry and no VAT — and no path back. That is not a
 * status bug: it is an unrecoverable fiscal document, and it happened to the
 * first tenant (INV-2026-0003).
 *
 * WHAT IT DOES NOT DO. It does not own `fiscal_status`, the hash chain, GL, or
 * side effects — {@see DocumentPostingService} still owns posting and
 * cancellation, and calls THIS service for the status half so the edge is
 * checked once, in one place. `$extraAttributes` exists precisely so a caller
 * can keep its status write and its own columns in ONE `update()` statement:
 * the seal (`fiscal_status`, `fiscal_hash`, `chain_sequence`) must land in the
 * same statement as the status flip or the `trg_document_immutability` trigger
 * — which reads `OLD.fiscal_status` — refuses the second write.
 *
 * `$extraAttributes` may NOT carry `status`; a caller that tries is refused
 * with an `InvalidArgumentException` rather than silently overriding the edge
 * this service just validated.
 */
final readonly class DocumentStatusService
{
    public function __construct(
        private DocumentStatusMachine $machine,
    ) {}

    /**
     * Move `$document` to `$to`, refusing any edge the adjacency map forbids.
     *
     * IDEMPOTENCE IS THE CALLER'S: a self-loop is not a legal edge (see
     * {@see DocumentStatusMachine::isAllowed()}). Callers that want "already
     * there is fine" must short-circuit before calling, exactly as
     * `DocumentPostingService::post()` and `::cancel()` already do.
     *
     * @param  array<string, mixed>  $extraAttributes  written in the SAME update statement
     *
     * @throws DocumentTransitionException
     */
    public function transition(Document $document, DocumentStatus $to, array $extraAttributes = []): Document
    {
        if (array_key_exists('status', $extraAttributes)) {
            throw new \InvalidArgumentException(
                'DocumentStatusService::transition() refuses a `status` key in $extraAttributes — '
                .'it would override the very edge this service validated.'
            );
        }

        $from = $document->status;

        if (! $this->machine->isAllowed($from, $to)) {
            throw DocumentTransitionException::forbiddenEdge(
                $document->id,
                $document->document_number,
                $from,
                $to,
            );
        }

        $document->update([...$extraAttributes, 'status' => $to]);

        return $document;
    }

    /**
     * `Posted → Paid`. The ONLY way a document becomes `Paid`.
     *
     * @param  array<string, mixed>  $extraAttributes
     *
     * @throws DocumentTransitionException when the document is not Posted —
     *                                     which is the whole point: a payment collected on a CONFIRMED
     *                                     invoice is an advance (Cr 419), not a settlement, and must not
     *                                     move the lifecycle at all.
     */
    public function markPaid(Document $document, array $extraAttributes = []): Document
    {
        return $this->transition($document, DocumentStatus::Paid, $extraAttributes);
    }

    /**
     * `Paid → Posted` — the refund / instrument-cancellation re-open.
     *
     * NEVER ASSUMES THE DOCUMENT WAS POSTED. A fiscal document (invoice,
     * credit note) that was posted carries a `fiscal_hash`; one that reached
     * `Paid` through the pre-N-6 `Confirmed → Paid` hole does not. Promoting
     * the latter to `Posted` would manufacture a posted-looking invoice with
     * no seal, no chain sequence and no GL — the exact fabrication this lane
     * exists to stop. Such a document is left where it is and reported; the
     * `documents:repair-paid-never-posted` command is what moves it back to
     * `Confirmed`.
     *
     * Non-fiscal payable types (supplier invoices) have no seal to check, so
     * for them the edge is taken as-is: their `Paid` is only reachable from
     * `Posted` (`PaymentController::store()` refuses an unposted supplier
     * invoice outright).
     *
     * Returns the document unchanged when it is not `Paid` (the caller's
     * recompute may legitimately find nothing to re-open).
     *
     * @param  array<string, mixed>  $extraAttributes
     */
    public function reopenFromPaid(Document $document, array $extraAttributes = []): Document
    {
        if ($document->status !== DocumentStatus::Paid) {
            return $document;
        }

        if ($this->wasNeverSealed($document)) {
            Log::warning('Refusing to re-open a never-posted document to Posted', [
                'document_id' => $document->id,
                'document_number' => $document->document_number,
                'document_type' => $document->type->value,
                'hint' => 'Run documents:repair-paid-never-posted to move it back to Confirmed.',
            ]);

            return $document;
        }

        return $this->transition($document, DocumentStatus::Posted, $extraAttributes);
    }

    /**
     * `Paid → Posted` used by the repair path in the opposite direction is NOT
     * this method — see {@see self::repairNeverPostedToConfirmed()}.
     *
     * A fiscal document that carries no `fiscal_hash` was never sealed, and a
     * document type that requires a fiscal chain and has no hash was therefore
     * never posted. For every other type the question is unanswerable from the
     * row, so the answer is "no" and the edge proceeds.
     */
    private function wasNeverSealed(Document $document): bool
    {
        return in_array($document->type, DocumentPostingService::getFiscalDocumentTypes(), true)
            && $document->fiscal_hash === null;
    }

    /**
     * The N-6 repair edge: a fiscal document sitting at `Paid` with NO
     * `fiscal_hash` never reached `Posted` at all, so `Confirmed` is where it
     * belongs — the state the pre-N-6 code should have left it in when the
     * payment was collected.
     *
     * This edge is deliberately NOT in {@see DocumentStatusMachine}: it repairs
     * a state the machine forbids reaching in the first place, and putting
     * `paid → confirmed` in the map would legitimise a downgrade of a genuinely
     * posted, sealed invoice. The precondition below is what makes it safe, and
     * it is checked here rather than trusted from the caller.
     *
     * @param  array<string, mixed>  $extraAttributes
     *
     * @throws DocumentTransitionException when the document IS sealed (a real
     *                                     posted invoice) or is not at `Paid`.
     */
    public function repairNeverPostedToConfirmed(Document $document, array $extraAttributes = []): Document
    {
        if ($document->status !== DocumentStatus::Paid || ! $this->wasNeverSealed($document)) {
            throw DocumentTransitionException::forbiddenEdge(
                $document->id,
                $document->document_number,
                $document->status,
                DocumentStatus::Confirmed,
            );
        }

        if (array_key_exists('status', $extraAttributes)) {
            throw new \InvalidArgumentException(
                'DocumentStatusService::repairNeverPostedToConfirmed() refuses a `status` key in $extraAttributes.'
            );
        }

        $document->update([...$extraAttributes, 'status' => DocumentStatus::Confirmed]);

        return $document;
    }
}
