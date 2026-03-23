<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Events\InvoiceCancelled;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for posting and cancelling documents with NF525 compliance.
 *
 * This service implements the "Event-first, state-second" pattern required for
 * fiscal compliance. All fiscal documents (invoices, credit notes) are added
 * to a SHA-256 hash chain that ensures tamper-proof audit trails.
 *
 * Hash Chain Format: SHA256(previous_hash | document_number | date | total | currency)
 */
final class DocumentPostingService
{
    /**
     * Document types that require fiscal hash chain compliance.
     *
     * @var list<DocumentType>
     */
    private const FISCAL_DOCUMENT_TYPES = [
        DocumentType::Invoice,
        DocumentType::CreditNote,
    ];

    public function __construct(
        private readonly FiscalHashService $hashService,
    ) {}

    /**
     * Post a document, adding it to the fiscal hash chain if required.
     *
     * This method is idempotent: calling it on an already-posted document
     * will return the document without error.
     *
     * @throws \DomainException If document cannot be posted (wrong status)
     */
    public function post(Document $document): Document
    {
        // Idempotent: if already posted, return success
        if ($document->isPosted()) {
            /** @var Document */
            return $document->fresh(['lines']);
        }

        if (! $document->isConfirmed()) {
            throw new \DomainException(
                'Only confirmed documents can be posted. Current status: '.$document->status->value
            );
        }

        // Tunisia fiscal compliance: Check if physical products have been delivered (for invoices)
        if ($document->type === DocumentType::Invoice) {
            $this->validateDeliveryCompliance($document);
        }

        $requiresFiscalChain = $this->requiresFiscalChain($document->type);

        return DB::transaction(function () use ($document, $requiresFiscalChain): Document {
            // Double-check inside transaction (another request may have posted it)
            $document->refresh();
            if ($document->isPosted()) {
                /** @var Document */
                return $document->fresh(['lines']);
            }

            if ($requiresFiscalChain) {
                $this->postWithFiscalChain($document);
            } else {
                $document->update(['status' => DocumentStatus::Posted]);
            }

            /** @var Document */
            return $document->fresh(['lines']);
        });
    }

    /**
     * Cancel a posted document, recording the cancellation in the fiscal chain.
     *
     * This method is idempotent: calling it on an already-cancelled document
     * will return the document without error.
     *
     * @throws \DomainException If document cannot be cancelled (wrong status)
     */
    public function cancel(Document $document): Document
    {
        // Idempotent: if already cancelled, return success
        if ($document->status === DocumentStatus::Cancelled) {
            /** @var Document */
            return $document->fresh(['lines']);
        }

        if (! $document->isPosted()) {
            throw new \DomainException(
                'Only posted documents can be cancelled. Current status: '.$document->status->value
            );
        }

        $requiresFiscalChain = $this->requiresFiscalChain($document->type);

        return DB::transaction(function () use ($document, $requiresFiscalChain): Document {
            // Double-check inside transaction (another request may have cancelled it)
            $document->refresh();
            if ($document->status === DocumentStatus::Cancelled) {
                /** @var Document */
                return $document->fresh(['lines']);
            }

            // Update status and fiscal_status if it's a fiscal document
            $updateData = ['status' => DocumentStatus::Cancelled];

            if ($requiresFiscalChain) {
                $updateData['fiscal_status'] = FiscalStatus::Voided;
                $document->update($updateData);
                DB::afterCommit(function () use ($document): void {
                    $this->dispatchCancellationEvent($document);
                });
            } else {
                $document->update($updateData);
            }

            /** @var Document */
            return $document->fresh(['lines']);
        });
    }

    /**
     * Post a document with full fiscal hash chain compliance.
     */
    private function postWithFiscalChain(Document $document): void
    {
        // Acquire lock and get previous document in chain
        $previousDoc = Document::where('company_id', $document->company_id)
            ->where('type', $document->type)
            ->where('status', DocumentStatus::Posted)
            ->whereNotNull('fiscal_hash')
            ->orderByDesc('chain_sequence')
            ->lockForUpdate()
            ->first();

        // For genesis document, use company's unique seed instead of empty string
        // This adds per-tenant entropy, making chain forgery harder than ZATCA's fixed "0"
        $previousHash = $previousDoc?->fiscal_hash;
        $genesisSeed = $previousHash === null ? $this->getCompanyGenesisSeed($document) : null;

        $chainSequence = ($previousDoc !== null ? $previousDoc->chain_sequence : 0) + 1;
        $postedAt = now();

        // Calculate fiscal hash using the compliance service
        $input = $this->hashService->serializeForHashing([
            'document_number' => $document->document_number,
            'posted_at' => $postedAt->toDateString(),
            'total' => $document->total ?? '0.00',
            'currency' => $document->currency,
        ]);

        $fiscalHash = $this->hashService->calculateHash($input, $previousHash, $genesisSeed);

        // Determine fiscal category based on document type
        $fiscalCategory = match ($document->type) {
            DocumentType::Invoice => FiscalCategory::TaxInvoice,
            DocumentType::CreditNote => FiscalCategory::CreditNote,
            default => FiscalCategory::NonFiscal,
        };

        // Update document with fiscal chain data and seal it
        $document->update([
            'status' => DocumentStatus::Posted,
            'fiscal_category' => $fiscalCategory,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => $fiscalHash,
            'previous_hash' => $previousHash,
            'chain_sequence' => $chainSequence,
        ]);

        // Dispatch the fiscal event for audit log (after transaction commits
        // to prevent listener failures from rolling back the fiscal chain)
        DB::afterCommit(function () use ($document, $postedAt): void {
            $this->dispatchPostedEvent($document, $postedAt->toIso8601String());
        });
    }

    /**
     * Dispatch the InvoicePosted event for fiscal audit trail.
     */
    private function dispatchPostedEvent(Document $document, string $postedAt): void
    {
        event(new InvoicePosted(
            invoiceId: $document->id,
            tenantId: $document->tenant_id,
            companyId: $document->company_id,
            documentNumber: $document->document_number,
            documentType: $document->type->value,
            partnerId: $document->partner_id,
            total: $document->total ?? '0.00',
            currency: $document->currency,
            fiscalHash: $document->fiscal_hash ?? '',
            chainSequence: $document->chain_sequence ?? 0,
            postedAt: $postedAt,
        ));
    }

    /**
     * Dispatch the InvoiceCancelled event for fiscal audit trail.
     */
    private function dispatchCancellationEvent(Document $document): void
    {
        event(new InvoiceCancelled(
            invoiceId: $document->id,
            tenantId: $document->tenant_id,
            companyId: $document->company_id,
            documentNumber: $document->document_number,
            documentType: $document->type->value,
            originalFiscalHash: $document->fiscal_hash ?? '',
            cancelledAt: now()->toIso8601String(),
        ));
    }

    /**
     * Check if a document type requires fiscal hash chain.
     */
    private function requiresFiscalChain(DocumentType $type): bool
    {
        return in_array($type, self::FISCAL_DOCUMENT_TYPES, true);
    }

    /**
     * Get the list of document types that require fiscal compliance.
     *
     * @return list<DocumentType>
     */
    public static function getFiscalDocumentTypes(): array
    {
        return self::FISCAL_DOCUMENT_TYPES;
    }

    /**
     * Get the company's unique genesis seed for hash chain initialization.
     *
     * Each company has a cryptographically random 256-bit seed that is used
     * as the "previous hash" for the first document in each hash chain.
     * This is more secure than ZATCA's fixed SHA256("0") approach.
     *
     * @throws \RuntimeException If company has no genesis seed
     */
    private function getCompanyGenesisSeed(Document $document): string
    {
        /** @var \App\Modules\Company\Domain\Company $company */
        $company = $document->company;

        if ($company->fiscal_chain_seed === null) {
            throw new \RuntimeException(
                'Company is missing fiscal_chain_seed. Run migration to generate seeds.'
            );
        }

        return $company->fiscal_chain_seed;
    }

    /**
     * Validate Tunisia fiscal compliance for invoices with physical products.
     *
     * Ensures that all physical products have been delivered before posting
     * the invoice. This is enforced at posting time (not creation time) to
     * allow for a better UX where delivery notes can be auto-created.
     *
     * @throws \DomainException If physical products haven't been delivered
     */
    private function validateDeliveryCompliance(Document $invoice): void
    {
        // Check if invoice has any physical products
        $hasPhysicalProducts = false;
        foreach ($invoice->lines as $line) {
            if ($line->product_id === null) {
                continue;
            }

            $product = Product::find($line->product_id);
            if ($product !== null && $product->is_physical) {
                $hasPhysicalProducts = true;
                break;
            }
        }

        if (! $hasPhysicalProducts) {
            return; // No physical products - no delivery requirement
        }

        // Get source order if invoice was created from order
        $sourceOrder = $invoice->sourceDocument;
        if ($sourceOrder === null || $sourceOrder->type !== DocumentType::SalesOrder) {
            // No source order - this is a standalone invoice, no delivery check needed
            return;
        }

        // Check if order has delivery notes
        $orderPayload = $sourceOrder->payload ?? [];
        $deliveryNoteIds = $orderPayload['delivery_note_ids'] ?? [];

        if (empty($deliveryNoteIds)) {
            throw new \DomainException(
                'Physical products must be delivered before posting invoice. No delivery notes found for the source order.'
            );
        }

        // Get all delivery notes and check if they're fully delivered
        $deliveryNotes = Document::whereIn('id', $deliveryNoteIds)
            ->where('type', DocumentType::DeliveryNote)
            ->with('lines')
            ->get();

        foreach ($deliveryNotes as $dn) {
            // Check if all lines have been fully delivered
            $fullyDelivered = true;
            foreach ($dn->lines as $line) {
                $qtyDelivered = $line->quantity_delivered ?? '0.00';
                $qty = $line->quantity;

                // If any line hasn't been fully delivered, mark as not complete
                if (bccomp((string) $qtyDelivered, (string) $qty, 4) < 0) {
                    $fullyDelivered = false;
                    break;
                }
            }

            if (! $fullyDelivered) {
                throw new \DomainException(
                    sprintf(
                        'Delivery note %s must be marked as fully delivered before posting invoice. Please update the delivery quantities.',
                        $dn->document_number
                    )
                );
            }
        }
    }
}
