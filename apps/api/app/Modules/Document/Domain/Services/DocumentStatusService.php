<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Exceptions\DocumentDatesInconsistentException;
use App\Modules\Document\Domain\Exceptions\DocumentRenumberingException;
use App\Modules\Document\Domain\Exceptions\DocumentTransitionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single write path for document lifecycle-status changes (N-6, Phase 1).
 *
 * WHAT IS AND IS NOT ROUTED THROUGH IT TODAY — the r1 docblock claimed more
 * than the code delivered (fiscal gate F-6), and the r2 correction of it still
 * over-claimed (R2-F3). This list is measured, not remembered:
 * `grep -rn "'status' => DocumentStatus::" app/` with each context read.
 *
 * ROUTED (every edge below goes through `transition()`):
 *   - `DocumentPostingService` — the seal, the non-fiscal post, cancel,
 *     sales-order cancel, and the three reverts.
 *   - all seven treasury writers (`PaymentAllocationService`,
 *     `PaymentController` ×4, `MultiPaymentService` ×2,
 *     `CloseInvoiceWithToleranceService`) plus the two re-open writers
 *     (`PaymentRefundService`, `OutboundInstrumentService`).
 *   - the four `Draft -> Posted` posting services: supplier invoice, supplier
 *     credit note, expense, income.
 *   - all standard document confirms: quote, invoice, credit note, purchase
 *     order, sales order, delivery note, return note, and correcting entry.
 *
 * NOT ROUTED — real, live, non-birth transitions that still write `status`
 * directly. Phase 2 owns them; naming them is the point, so the next reader
 * does not have to re-derive the list and does not mistake this class for a
 * complete write path:
 *   - `RefundService:140`, `:289`, `:864` — `-> Cancelled` on an UNPOSTED
 *     invoice or credit note. This is the writer that produces the
 *     cancelled-but-never-sealed document the print marker has to describe
 *     honestly (R2-F1); its `Posted` arm delegates to
 *     `DocumentPostingService::cancel()`, so only the unsealed edge is loose.
 *   - every `create([... 'status' => ...])` BIRTH state, which is not a
 *     transition and is legitimately exempt (`ArApOpeningService` is the
 *     load-bearing example — its rows are exactly what `wasNeverSealed()`
 *     exempts).
 *   - `CorrectingEntryService` — `Confirmed -> Posted`; the preceding
 *     `Draft -> Confirmed` edge is routed here so it receives its number.
 *   - `repairNeverPostedToConfirmed()` below — a deliberately exceptional
 *     repair edge that is not legal in the normal adjacency map.
 *
 * NEITHER GUARD COVERS THAT REMAINDER, and the r1 claim that they did was
 * wrong: the PHPStan rule scopes to `Paid` anywhere and `Posted` under
 * `App\Modules\Treasury\`, and `chk_documents_status_enum` is a VALUE
 * backstop, never an edge one.
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
 *
 * IT DOES OWN ONE COLUMN BESIDES `status`: `document_number` (R-2 / LEDGER
 * D-T9-1). Drafts are now born unnumbered and the number is allocated on the
 * first transition out of `Draft` — see {@see self::allocateNumberAndTransition()} for
 * the rule and {@see self::assignNumberIfMissing()} for the two confirm shapes
 * that must allocate before their own status write. A caller MAY still name an
 * unnumbered document itself (the expense and income posters do, from their own
 * `EXP-`/`INC-` sequences). Once non-null, the number is immutable at this
 * service boundary regardless of lifecycle or fiscal-seal state.
 *
 * Caller-side `lockForUpdate()` remains useful for serialising the rest of a
 * confirm flow, but it is NOT load-bearing for number immutability. The first
 * numbered Draft exit is claimed here by one conditional UPDATE over the id,
 * expected status, and NULL number; a stale unlocked model therefore cannot
 * replace the identity already established by another request.
 */
final readonly class DocumentStatusService
{
    private const STAGED_ALLOCATION_RELATION = '__document_status_staged_allocation';

    public function __construct(
        private DocumentStatusMachine $machine,
        private DocumentNumberingService $numbering,
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
     * @throws DocumentDatesInconsistentException
     * @throws DocumentRenumberingException
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

        // R-2 / LEDGER D-T9-1 — `document_number` in `$extraAttributes` is LEGAL, and
        // deliberately so. Two live writers number their document from a sequence this
        // service knows nothing about and hand the result in: `ExpenseService::post()`
        // (`EXP-` via `generateExpenseNumber()`) and `IncomeService::post()` (`INC-`).
        // Both are `draft → posted` writers for types the machine lets post directly
        // (`DocumentStatusMachine::postsDirectlyFromDraft()`), and both predate this
        // lane. A caller-supplied number therefore WINS only while the row is still
        // unnumbered. Once a number exists, changing it would rewrite fiscal identity;
        // refuse that at the service boundary even when the row is not sealed yet.
        $hasCallerNumberAttribute = array_key_exists('document_number', $extraAttributes);
        $persistedNumber = $document->getRawOriginal('document_number');
        $attemptedNumber = $hasCallerNumberAttribute
            ? $extraAttributes['document_number']
            : $document->document_number;

        if ($persistedNumber !== null && $attemptedNumber !== $persistedNumber) {
            throw new DocumentRenumberingException(
                documentId: $document->id,
                existingNumber: (string) $persistedNumber,
                attemptedNumber: is_scalar($attemptedNumber) || $attemptedNumber === null
                    ? $attemptedNumber
                    : get_debug_type($attemptedNumber),
            );
        }

        // NULL does not name an unnumbered draft. Remove it so the allocator
        // below can supply the identity on the first real Draft exit.
        if ($hasCallerNumberAttribute && $extraAttributes['document_number'] === null) {
            unset($extraAttributes['document_number']);
        }

        $callerSuppliedNumber = array_key_exists('document_number', $extraAttributes);

        $from = $document->status;

        if (! $this->machine->isAllowed($from, $to, $document->type)) {
            throw DocumentTransitionException::forbiddenEdge(
                $document->id,
                $document->document_number,
                $from,
                $to,
            );
        }

        // DEV-QA-008/057, gate r2 N-1 — defence in depth for the ONE edge on
        // which a draft becomes a document somebody else is shown. See
        // {@see DocumentDatesInconsistentException} for why the FormRequest
        // guards are not enough: no `confirm()` action re-validates dates.
        if ($from === DocumentStatus::Draft && $to === DocumentStatus::Confirmed) {
            $this->assertDatesAreConsistent($document);
        }

        if ($from === DocumentStatus::Draft
            && $persistedNumber === null
            && $to !== DocumentStatus::Draft
            && $to !== DocumentStatus::Cancelled) {
            return $this->allocateNumberAndTransition(
                document: $document,
                to: $to,
                extraAttributes: $extraAttributes,
                callerSuppliedNumber: $callerSuppliedNumber,
            );
        }

        $document->update([...$extraAttributes, 'status' => $to]);

        return $document;
    }

    /**
     * Refuse a `Draft -> Confirmed` edge on a row whose `due_date` /
     * `valid_until` precedes its own `document_date`.
     *
     * Compared at DAY granularity on the model's own `date` casts
     * (`Document.php:183-184`), so a time component cannot decide the outcome
     * and EQUALITY PASSES — the same `>=` semantics the FormRequest rule
     * `DueDateNotBeforeDocumentDate` applies at the write boundary, and the
     * same two message keys, so an operator meets one sentence rather than two
     * different ones for one mistake.
     *
     * `document_date` is NOT NULL in the schema
     * (`2025_11_30_080000_create_documents_table.php:21`); the two compared
     * columns are nullable and a null one is simply nothing to compare.
     *
     * @throws DocumentDatesInconsistentException
     */
    private function assertDatesAreConsistent(Document $document): void
    {
        $documentDate = $document->document_date->toDateString();

        $dueDate = $document->due_date;

        if ($dueDate !== null && $dueDate->toDateString() < $documentDate) {
            throw DocumentDatesInconsistentException::dueDateBeforeDocumentDate(
                $document->id,
                $documentDate,
                $dueDate->toDateString(),
            );
        }

        $validUntil = $document->valid_until;

        if ($validUntil !== null && $validUntil->toDateString() < $documentDate) {
            throw DocumentDatesInconsistentException::validUntilBeforeDocumentDate(
                $document->id,
                $documentDate,
                $validUntil->toDateString(),
            );
        }
    }

    /**
     * R-2 / LEDGER D-T9-1 — THE SINGLE DOCUMENT-NUMBER ALLOCATION POINT.
     *
     * A `Draft` carries NO `document_number`. It is spent HERE, on the first
     * transition that turns the draft into a document somebody else can be
     * shown — and nowhere else. The allocation and lifecycle flip are persisted
     * by ONE conditional UPDATE carrying `id = ?`, the expected `Draft` status,
     * and `document_number IS NULL`. Before this lane, a number was spent when a
     * row was created, so an abandoned one-line form held that number forever:
     * the campaign found `PO-2026-0001 … PO-2026-0009` sitting as orphan drafts
     * ahead of the operator's real `PO-2026-0010` (N-14's remaining half).
     *
     * DOES NOT GENERATE when the caller supplied its own `document_number` — the
     * expense and income posters number from `EXP-`/`INC-` sequences this service
     * does not own, and that number wins the same conditional update.
     *
     * NOT ON THE WAY TO `Cancelled`. A draft that dies never became a document,
     * and numbering it would re-create the very defect this method exists to
     * remove — an abandoned form holding a number out of the fiscal sequence.
     * A cancelled draft therefore stays unnumbered, and that is the only state
     * pair in which a `documents` row legitimately has no number.
     *
     * NEVER RENUMBERS. Every document born numbered — every conversion, every
     * credit note off an invoice, every opening-balance row, and every draft
     * that predates this lane — bypasses allocation. A stale model whose row was
     * numbered after it loaded loses the conditional update and reloads the
     * winner. The number a row already holds is never taken back or replaced.
     *
     * WHAT GUARANTEES A ROLLBACK RETURNS THE NUMBER.
     * `DocumentNumberingService::generateForKeyOnce()` takes `lockForUpdate()`
     * on the `document_sequences` counter row and increments it in the same
     * transaction, so two concurrent confirms serialise on that row rather than
     * racing. It opens a NESTED `DB::transaction()`, which Laravel implements as
     * a SAVEPOINT when a transaction is already open — so when the caller's
     * confirm transaction rolls back, the counter increment rolls back with it
     * and the number is handed to the next confirm instead of leaving a gap.
     * This allocator also opens its own transaction, so direct callers receive
     * the same allocation/status atomicity. When a larger confirm transaction
     * exists, the nested transaction is a savepoint and an outer rollback still
     * returns the number.
     *
     * A zero-row result means the passed model lost a race or the database state
     * is otherwise inconsistent. A number generated inside this transaction is
     * rolled back with it. {@see self::assignNumberIfMissing()} opens an earlier
     * savepoint, so staged allocation and every intervening confirm side effect
     * roll back together when this claim loses. The row is then reloaded: the
     * already-completed same transition wins with its established number; every
     * other state is refused.
     *
     * @param  array<string, mixed>  $extraAttributes
     */
    private function allocateNumberAndTransition(
        Document $document,
        DocumentStatus $to,
        array $extraAttributes,
        bool $callerSuppliedNumber,
    ): Document {
        $lostClaim = new \RuntimeException('The conditional document-number claim affected no rows.');
        $stagedSavepointLevel = $document->relationLoaded(self::STAGED_ALLOCATION_RELATION)
            ? $document->getRelation(self::STAGED_ALLOCATION_RELATION)
            : null;
        $stagedSavepointLevel = is_int($stagedSavepointLevel) ? $stagedSavepointLevel : null;

        try {
            $transitioned = DB::transaction(function () use (
                $document,
                $to,
                $extraAttributes,
                $callerSuppliedNumber,
                $lostClaim,
            ): Document {
                $number = $callerSuppliedNumber
                    ? (string) $extraAttributes['document_number']
                    : $this->numberFor($document);

                $candidate = clone $document;
                $candidate->fill([
                    ...$extraAttributes,
                    'document_number' => $number,
                    'status' => $to,
                ]);

                $affectedRows = $document->newModelQuery()
                    ->where($document->getKeyName(), $document->getKey())
                    ->where('status', DocumentStatus::Draft->value)
                    ->whereNull('document_number')
                    ->update($candidate->getDirty());

                if ($affectedRows === 0) {
                    // Throw through the transaction boundary so this request's
                    // sequence increment is rolled back before the winner is read.
                    throw $lostClaim;
                }

                $document->unsetRelation(self::STAGED_ALLOCATION_RELATION);

                return $document->refresh();
            });
        } catch (\Throwable $exception) {
            if ($exception !== $lostClaim) {
                $this->closeStagedAllocationSavepoint($document, $stagedSavepointLevel, false);

                throw $exception;
            }

            $this->closeStagedAllocationSavepoint($document, $stagedSavepointLevel, false);

            $transitioned = null;
        }

        if ($transitioned !== null) {
            $this->closeStagedAllocationSavepoint($document, $stagedSavepointLevel, true);

            return $transitioned;
        }

        /** @var Document|null $current */
        $current = $document->newModelQuery()->find($document->getKey());

        if ($current !== null
            && $current->document_number !== null
            && $current->status === $to) {
            $document->setRawAttributes($current->getAttributes(), true);

            return $document;
        }

        $currentStatus = $current === null ? DocumentStatus::Draft : $current->status;

        if ($current !== null) {
            $document->setRawAttributes($current->getAttributes(), true);
        }

        throw new DocumentTransitionException(
            message: sprintf(
                'Document %s changed while its number was being allocated; refusing an inconsistent %s → %s transition.',
                $document->id,
                $currentStatus->value,
                $to->value,
            ),
            documentId: $document->id,
            documentNumber: $current?->document_number,
            from: $currentStatus,
            to: $to,
        );
    }

    private function closeStagedAllocationSavepoint(
        Document $document,
        ?int $expectedLevel,
        bool $commit,
    ): void {
        if ($expectedLevel === null) {
            return;
        }

        if (DB::transactionLevel() !== $expectedLevel) {
            throw new \LogicException(
                'The staged document-number savepoint is no longer the active transaction boundary.',
            );
        }

        $document->unsetRelation(self::STAGED_ALLOCATION_RELATION);

        if ($commit) {
            DB::commit();

            return;
        }

        DB::rollBack();
    }

    private function numberFor(Document $document): string
    {
        if ($document->document_number !== null) {
            return $document->document_number;
        }

        return $this->numbering->generateNumber(
            tenantId: $document->tenant_id,
            companyId: $document->company_id,
            type: $document->type,
        );
    }

    /**
     * Stage the number a `Draft` does not yet hold, for confirm paths that need
     * it BEFORE the status write persists it.
     *
     * Two shapes of caller need this rather than {@see self::transition()}:
     *
     *  - the FISCAL sealers ({@see DeliveryNoteService}, {@see ReturnNoteService})
     *    hash `document_number` into the chain input and write the seal columns
     *    together with the status. The number must exist before the hash is
     *    computed, not in the same statement as it — a NULL in a sealed hash
     *    input is unrecoverable.
     *  - confirm flows that USE the number before flipping the status
     *    ({@see SalesOrderService} stamps it into each stock reservation's
     *    notes).
     *
     * It is the same allocation, from the same place, under the same rollback
     * guarantee described on {@see self::allocateNumberAndTransition()} — callers must
     * be inside their confirm transaction because they use the staged number
     * before transition. The number is assigned to the model in memory only;
     * the caller MUST then use {@see self::transition()}, whose conditional
     * UPDATE persists this dirty attribute with the status flip. A document
     * that already has a number is returned untouched. This method opens a nested
     * savepoint and tags its level in memory; transition commits that savepoint
     * after a successful claim or rolls it back after a lost claim, undoing both
     * the sequence increment and every side effect written with the staged number.
     */
    public function assignNumberIfMissing(Document $document): Document
    {
        if ($document->document_number !== null) {
            return $document;
        }

        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'assignNumberIfMissing() must run inside the caller\'s confirm transaction.',
            );
        }

        DB::beginTransaction();

        try {
            $number = $this->numberFor($document);
            $document->setAttribute('document_number', $number);
            $document->setRelation(self::STAGED_ALLOCATION_RELATION, DB::transactionLevel());
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

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
        // HISTORICAL documents are the deliberate exception, and they are real
        // production data: `ArApOpeningService` creates opening-balance AR/AP
        // invoices `Posted` with `fiscal_category = NON_FISCAL`,
        // `fiscal_status = DRAFT` and NO hash — they were posted in the
        // customer's PREVIOUS system, and this one records them as already
        // standing. Such an invoice can legitimately be paid, refunded, and must
        // re-open to `Posted`. `is_historical` is the flag that says so, set at
        // exactly that one creation site.
        if ($document->isHistorical()) {
            return false;
        }

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
