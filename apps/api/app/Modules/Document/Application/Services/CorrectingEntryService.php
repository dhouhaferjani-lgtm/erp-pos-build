<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\DTOs\CorrectingEntryPayload;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Shared\Contracts\Accounting\DocumentGlCorrectionInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The lifecycle of a correcting-entry DOCUMENT (R2-F4, owner ruling c4).
 *
 * Draft -> Confirmed -> Posted, created FROM the document it repairs. The link
 * (`source_document_id`) is set at creation and is never editable afterwards:
 * "a correction requires creating a correcting document LINKED to the original
 * document it refers to" is the whole ruling, so a correction that could be
 * re-pointed would defeat it.
 *
 * WHY POSTING DOES NOT GO THROUGH `DocumentPostingService::post()`. That service
 * seals the fiscal document chain for invoices/credit notes and, for every other
 * type, does nothing but flip the status — the GL is written afterwards by
 * `InvoicePostedListener` from a `DB::afterCommit()` hook. A correcting entry
 * MUST NOT be posted that way: a GL failure arriving after the commit would
 * leave the document `Posted` with no correcting entry in the ledger and no way
 * to re-drive it (the exact stranding the L1 gate's finding C-1 describes, and
 * the reason `DocumentPostingService::post()` runs its GL PRE-FLIGHT inside the
 * transaction). Here the status change and the GL write are one atomic act:
 * either both happen or neither does.
 */
final class CorrectingEntryService
{
    public function __construct(
        private readonly DocumentNumberingService $numberingService,
        private readonly DocumentGlCorrectionInterface $corrections,
    ) {}

    /**
     * Create a DRAFT correcting entry against `$target`.
     *
     * The STRUCTURAL pre-flight runs here so a correction that can never become
     * postable (unsupported target type, unknown account, malformed legs) is
     * refused immediately rather than three clicks later. The BALANCE verdict is
     * NOT applied at this point — it belongs to posting, because the target's
     * ledger can move between drafting and posting and because a draft exists
     * precisely so a correction can be saved before it is right.
     */
    public function create(
        Company $company,
        Document $target,
        CorrectingEntryPayload $payload,
    ): Document {
        return DB::transaction(function () use ($company, $target, $payload): Document {
            $correction = Document::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                // Carried from the target so reports and partner-scoped views can
                // find the correction next to the document it repairs. A
                // correcting entry never moves the partner's balance — see
                // `DocumentType::affectsReceivable()`.
                'partner_id' => $target->partner_id,
                'type' => DocumentType::CorrectingEntry,
                'fiscal_category' => FiscalCategory::NonFiscal,
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'document_number' => $this->numberingService->generateNumber(
                    $company->tenant_id,
                    $company->id,
                    DocumentType::CorrectingEntry,
                ),
                'document_date' => now(),
                'source_document_id' => $target->id,
                'location_id' => $target->location_id,
                'currency' => $target->currency,
                'reference' => $target->document_number,
                'payload' => $payload->toDocumentPayload(),
            ]);

            // Fail fast on everything that can NEVER become true later (unusable
            // target type, unknown account, malformed legs). The BALANCE verdict
            // is deliberately not applied here — see the contract's docblock: a
            // draft exists precisely so a correction can be saved before it is
            // right, and the target's ledger can move in the meantime.
            $this->corrections->assertCorrectingEntryIsWellFormed($correction);

            /** @var Document */
            return $correction->fresh();
        });
    }

    public function confirm(Document $correction): Document
    {
        $this->assertIsCorrectingEntry($correction);

        if ($correction->status === DocumentStatus::Confirmed) {
            /** @var Document */
            return $correction->fresh();
        }

        if ($correction->status !== DocumentStatus::Draft) {
            throw new DomainException(
                'Only a draft correcting entry can be confirmed. Current status: '.$correction->status->value,
            );
        }

        $correction->update([
            'status' => DocumentStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        /** @var Document */
        return $correction->fresh();
    }

    /**
     * Post the correcting entry and its GL in ONE transaction.
     *
     * IDEMPOTENCE — the same shape, and the same limit, as the reversal path
     * documents at `AccountingService:852-856`. A correcting DOCUMENT posts at
     * most once, keyed on `journal_entries(source_type = 'DocumentCorrection',
     * source_id = <this document>)`, but that pair carries NO database
     * uniqueness (see `reference_journal_entries_no_global_source_uniqueness`),
     * so the guard is a read followed by a write and, on its own, a TOCTOU
     * window. What actually closes it is the per-company
     * `pg_advisory_xact_lock` `postCorrectingEntryGl()` takes as the first
     * statement of its transaction: two concurrent posts serialise on it, and
     * the loser then SEES the winner's entry and is refused with
     * `AlreadyPosted`. The read-then-write is the mechanism; the lock is the
     * correctness argument. Neither alone is sufficient — do not remove the lock
     * on the grounds that the `exists()` check is there.
     */
    public function post(Document $correction): Document
    {
        $this->assertIsCorrectingEntry($correction);

        if ($correction->status === DocumentStatus::Posted) {
            /** @var Document */
            return $correction->fresh();
        }

        if ($correction->status !== DocumentStatus::Confirmed) {
            throw new DomainException(
                'Only a confirmed correcting entry can be posted. Current status: '.$correction->status->value,
            );
        }

        // NON-WRITING PRE-FLIGHT, outside the transaction. It answers exactly the
        // question the write will ask, so an entry that cannot post is refused
        // with its typed 422 before a write transaction is ever opened — the same
        // relationship `assertDocumentGlIsPostable()` has to the posting paths.
        //
        // It does NOT make the in-transaction re-resolve redundant, and must not
        // be read as doing so: the verdict depends on ledger rows another request
        // may be writing right now, so this answer can be stale by the time the
        // lock is taken. The authoritative verdict is the one computed inside.
        $this->corrections->assertCorrectingEntryIsPostable($correction);

        return DB::transaction(function () use ($correction): Document {
            // The GL write comes FIRST and inside this transaction: its refusal
            // must roll the status change back with it, never leave a document
            // marked Posted with nothing in the ledger.
            $this->corrections->postCorrectingEntryGl($correction);

            $correction->update([
                'status' => DocumentStatus::Posted,
                // ARMS THE TWO POSTGRES TRIGGERS. `trg_document_immutability` and
                // `trg_prevent_fiscal_deletion` both key on
                // `fiscal_status IN ('SEALED','VOIDED')`; a posted correction left
                // at DRAFT was protected by neither, so its row stayed editable
                // and deletable while its legs were sealed in the journal chain.
                //
                // SEALED here means ROW IMMUTABILITY, not a document hash-chain
                // seal — a correcting entry has no document chain and never gets
                // a `fiscal_hash` (its integrity is the JOURNAL chain, which its
                // legs joined a moment ago). That reading is what the column
                // already means: `Document::isSealed()` is documented as
                // "immutable core fields", and its only consumer is the
                // `DocumentData.is_sealed` display flag.
                //
                // ZERO SCHEMA, and deliberately so: `chk_fiscal_mandatory_core`
                // short-circuits on `fiscal_category = 'NON_FISCAL'`, so a
                // non-fiscal document may be SEALED with a NULL `fiscal_hash` and
                // NULL `chain_sequence` — the constraint permits exactly this
                // case. `chk_fiscal_status_enum` already admits 'SEALED'.
                //
                // Set in the SAME statement as the status flip on purpose: the
                // trigger reads OLD.fiscal_status, which is still DRAFT here, so
                // this update passes and every LATER one is refused.
                //
                // PARTIAL, and knowingly so. `trg_document_immutability`'s
                // immutable-field list
                // (`2025_12_11_054716_add_document_immutability_trigger.php:33-45`)
                // was written for fiscal SALES documents. It freezes identity and
                // the money columns and refuses deletion, but it does NOT cover
                // `payload` — where this document's LEGS live — nor
                // `source_document_id`, the link ruling c4 makes mandatory. So a
                // posted correction's row is protected, not immutable.
                //
                // Tolerable because the LEDGER is the authority: the legs were
                // copied into hash-chained `journal_lines` at post time, and a
                // later `payload` edit desynchronises the document from the ledger
                // without moving a single posted amount. Widening the trigger is a
                // schema migration and would forfeit this lane's zero-schema
                // property; it belongs to its own lane. Pinned — including the gap
                // — by `CorrectingEntryEndpointTest`.
                'fiscal_status' => FiscalStatus::Sealed,
            ]);

            /** @var Document */
            return $correction->fresh();
        });
    }

    /**
     * Discard a correcting entry that was never posted.
     */
    public function delete(Document $correction): void
    {
        $this->assertIsCorrectingEntry($correction);

        if ($correction->status !== DocumentStatus::Draft) {
            throw new DomainException(
                'Only a draft correcting entry can be deleted; a posted correction is part of the '
                .'ledger and is withdrawn by cancelling the document it corrects, never by deleting it. '
                .'Current status: '.$correction->status->value,
            );
        }

        $correction->delete();
    }

    private function assertIsCorrectingEntry(Document $document): void
    {
        if ($document->type !== DocumentType::CorrectingEntry) {
            throw new \InvalidArgumentException(sprintf(
                'Expected a %s document, got %s.',
                DocumentType::CorrectingEntry->value,
                $document->type->value,
            ));
        }
    }
}
