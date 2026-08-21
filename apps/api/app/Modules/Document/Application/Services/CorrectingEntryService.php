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

        return DB::transaction(function () use ($correction): Document {
            // The GL write comes FIRST and inside this transaction: its refusal
            // must roll the status change back with it, never leave a document
            // marked Posted with nothing in the ledger.
            $this->corrections->postCorrectingEntryGl($correction);

            $correction->update(['status' => DocumentStatus::Posted]);

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
