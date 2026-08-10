<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\CreditNoteAllocation;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Events\InvoiceCancelled;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Document\Domain\Events\SalesOrderCancelled;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\PhysicalLinePredicate;
use App\Modules\Product\Domain\Product;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Shared\Contracts\Accounting\DocumentGlPreflightInterface;
use App\Shared\Contracts\Accounting\DocumentGlReversalInterface;
use App\Shared\Contracts\Inventory\ReceiptLineGuardInterface;
use App\Shared\Contracts\Inventory\ReservationReleaserInterface;
use App\Shared\Contracts\Taxation\DocumentPeriodLockInterface;
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
        private readonly ReservationReleaserInterface $reservationReleaser,
        private readonly ReceiptLineGuardInterface $receiptLineGuard,
        private readonly DocumentGlPreflightInterface $glPreflight,
        private readonly DocumentGlReversalInterface $glReversal,
        private readonly DocumentPeriodLockInterface $periodLock,
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
                // W-6 D1a / gate C-1 — ask Accounting whether this document's GL
                // entry can balance BEFORE sealing it. The GL itself is written by
                // InvoicePostedListener, which dispatchPostedEvent() defers to
                // DB::afterCommit(...) so a listener failure cannot roll back the
                // fiscal chain. That same ordering means a refusal raised in the
                // listener arrives when the document is already Posted + hash-chained,
                // and post() returns early on an already-posted document so the event
                // never re-fires: the document would be stranded with no GL at all.
                // Refusing here is a clean 422 on an UNSEALED document, inside this
                // transaction. The listener keeps its own assertion as defence in depth.
                $this->glPreflight->assertDocumentGlIsPostable($document);

                $this->postWithFiscalChain($document);
            } else {
                $document->update(['status' => DocumentStatus::Posted]);
            }

            /** @var Document */
            return $document->fresh(['lines']);
        });
    }

    /**
     * Cancel a document, recording posted fiscal cancellations in the fiscal chain.
     *
     * This method is idempotent: calling it on an already-cancelled document
     * will return the document without error.
     *
     * @throws \DomainException If document cannot be cancelled (wrong status)
     */
    public function cancel(Document $document, ?string $reason = null, ?string $actorId = null): Document
    {
        // Idempotent: if already cancelled, return success
        if ($document->status === DocumentStatus::Cancelled) {
            /** @var Document */
            return $document->fresh(['lines']);
        }

        if ($document->type === DocumentType::SalesOrder) {
            return $this->cancelSalesOrder($document, $reason, $actorId);
        }

        if (! $document->isPosted()) {
            throw new \DomainException(
                'Only posted documents can be cancelled. Current status: '.$document->status->value
            );
        }

        if ($this->requiresFiscalChain($document->type) && $this->hasBlockingAllocations($document)) {
            throw new \DomainException('DOCUMENT_HAS_PAYMENTS');
        }

        $requiresFiscalChain = $this->requiresFiscalChain($document->type);

        return DB::transaction(function () use ($document, $requiresFiscalChain, $reason, $actorId): Document {
            // Double-check inside transaction (another request may have cancelled
            // it). GL gate I-1 / treasury gate finding 3: a plain `refresh()` is a
            // SELECT with no lock, so two concurrent cancels can both pass this
            // check and both seal a GL reversal — the ledger's revenue/AR/VAT get
            // credited-then-debited TWICE, and both entries race for the same
            // `chain_sequence`. `lockForUpdate()` re-reads the row and holds it for
            // the rest of the transaction, so the second concurrent cancel blocks
            // here until the first commits, then observes the now-cancelled status
            // and takes the idempotent early return below.
            /** @var Document $document */
            $document = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->status === DocumentStatus::Cancelled) {
                /** @var Document */
                return $document->fresh(['lines']);
            }

            if ($requiresFiscalChain && $this->hasBlockingAllocations($document)) {
                throw new \DomainException('DOCUMENT_HAS_PAYMENTS');
            }

            // R2-F1 / GL gate ruling 6a, second condition. The reversal below is
            // dated `now()` on purpose — back-dating it to the document's own date
            // would retroactively rewrite a trial balance or VAT-payable balance
            // under a declaration that may already be filed. That dating is only
            // SAFE while the document's own period is still OPEN: cancelling a
            // January invoice in March silently leaves January's declared VAT
            // untouched while the GL moves in March.
            //
            // So once the covering `vat_periods` row is CLOSED or FILED the
            // withdrawal is refused outright and the accountant is pointed at a
            // credit note (avoir), which is what FR/TN practice requires anyway.
            //
            // Placed BEFORE the GL reversal and inside this transaction: the
            // refusal rolls the cancel back with it, so no reversal is sealed and
            // no document is left half-withdrawn. It applies to BOTH branches —
            // non-fiscal document types (supplier invoices, expenses) post AP and
            // deductible input VAT into the same declaration.
            //
            // The purchase-document arm is the DEFAULT pending ruling R-c c2 and
            // is explicitly reversible — the single seam is
            // `VatPeriodCancellationGuard::refusalAppliesTo()`.
            $this->periodLock->assertCancellationPeriodIsOpen($document);

            // Update status and fiscal_status if it's a fiscal document
            $updateData = [
                'status' => DocumentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
                'cancellation_reason' => $reason,
            ];

            if ($requiresFiscalChain) {
                // W-7 F-6 (c): cancelling a POSTED invoice used to make no GL call
                // at all, leaving its AR debit, revenue credits and VAT credit
                // standing in the ledger forever. The reversal is a NEW
                // hash-chained entry mirroring the SEALED legs — never a mutation
                // or deletion of the immutable original.
                //
                // It runs INSIDE this transaction on purpose. The L1 lane's gate
                // finding C-1 is the counter-example: a GL verdict raised after the
                // document is sealed cannot be re-driven, and strands the document.
                // Here the reversal and the void are one atomic act — if the
                // reversal cannot be written the cancel is refused with it, and the
                // document stays posted with its GL intact.
                $this->glReversal->reverseDocumentGl($document);

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

    private function cancelSalesOrder(Document $salesOrder, ?string $reason, ?string $actorId): Document
    {
        // v1 scope: deposit/payment allocation guards apply only to fiscal documents.
        if ($salesOrder->isPosted()) {
            throw new \DomainException('Posted sales orders cannot be cancelled. Use credit notes instead.');
        }

        return DB::transaction(function () use ($salesOrder, $reason, $actorId): Document {
            $salesOrder->refresh();
            if ($salesOrder->status === DocumentStatus::Cancelled) {
                /** @var Document */
                return $salesOrder->fresh(['lines']);
            }

            $cancelledAt = now();
            $cancelledBy = $actorId ?? (auth()->id() !== null ? (string) auth()->id() : null);

            $this->reservationReleaser->releaseReservationsForSource(
                sourceType: ReservationSource::SalesOrder->value,
                sourceId: $salesOrder->id,
                reason: ReleaseReason::Cancelled->value,
                releasedBy: $cancelledBy,
                expectedTenantId: $salesOrder->tenant_id,
                expectedCompanyId: $salesOrder->company_id,
            );

            $salesOrder->update([
                'status' => DocumentStatus::Cancelled,
                'cancelled_at' => $cancelledAt,
                'cancelled_by' => $cancelledBy,
                'cancellation_reason' => $reason,
            ]);

            DB::afterCommit(function () use ($salesOrder, $reason, $cancelledAt): void {
                $this->dispatchSalesOrderCancelledEvent(
                    $salesOrder,
                    $reason ?? '',
                    $salesOrder->cancelled_by ?? '',
                    $cancelledAt->toIso8601String(),
                );
            });

            /** @var Document */
            return $salesOrder->fresh(['lines']);
        });
    }

    public function hasBlockingAllocations(Document $document): bool
    {
        $hasPaymentAllocations = PaymentAllocation::query()
            ->where('document_id', $document->id)
            ->where('amount', '>', 0)
            ->exists();

        if ($hasPaymentAllocations) {
            return true;
        }

        return CreditNoteAllocation::query()
            ->where('invoice_id', $document->id)
            ->where('amount', '>', 0)
            ->exists();
    }

    /**
     * Revert a v1-supported confirmed document back to draft.
     *
     * Revert is a status transition only, plus release of auto-created sales-order
     * reservations where applicable. Past fiscal/domain events are immutable: this
     * method emits no compensating event mutations and does not alter prior events.
     *
     * @throws \DomainException If document cannot be reverted
     */
    public function revert(Document $document, ?string $actorId = null): Document
    {
        if ($document->status === DocumentStatus::Draft) {
            /** @var Document */
            return $document->fresh(['lines']);
        }

        if (! $document->isConfirmed()) {
            throw new \DomainException(
                'Only confirmed documents can be reverted. Current status: '.$document->status->value
            );
        }

        return match ($document->type) {
            DocumentType::Quote => $this->revertStatusOnly($document),
            DocumentType::SalesOrder => $this->revertSalesOrder($document, $actorId),
            DocumentType::PurchaseOrder => $this->revertPurchaseOrder($document),
            default => throw new \DomainException('DOCUMENT_REVERT_NOT_SUPPORTED'),
        };
    }

    private function revertStatusOnly(Document $document): Document
    {
        return DB::transaction(function () use ($document): Document {
            $document->refresh();
            if (! $document->isConfirmed()) {
                throw new \DomainException(
                    'Only confirmed documents can be reverted. Current status: '.$document->status->value
                );
            }

            $document->update([
                'status' => DocumentStatus::Draft,
                'confirmed_at' => null,
                'confirmed_by' => null,
            ]);

            /** @var Document */
            return $document->fresh(['lines']);
        });
    }

    private function revertSalesOrder(Document $salesOrder, ?string $actorId): Document
    {
        return DB::transaction(function () use ($salesOrder, $actorId): Document {
            $salesOrder->refresh();
            if (! $salesOrder->isConfirmed()) {
                throw new \DomainException(
                    'Only confirmed documents can be reverted. Current status: '.$salesOrder->status->value
                );
            }

            $this->reservationReleaser->releaseReservationsForSource(
                sourceType: ReservationSource::SalesOrder->value,
                sourceId: $salesOrder->id,
                reason: ReleaseReason::OrderModified->value,
                releasedBy: $actorId,
                expectedTenantId: $salesOrder->tenant_id,
                expectedCompanyId: $salesOrder->company_id,
            );

            $salesOrder->update([
                'status' => DocumentStatus::Draft,
                'confirmed_at' => null,
                'confirmed_by' => null,
            ]);

            /** @var Document */
            return $salesOrder->fresh(['lines']);
        });
    }

    private function revertPurchaseOrder(Document $purchaseOrder): Document
    {
        return DB::transaction(function () use ($purchaseOrder): Document {
            $purchaseOrder->refresh();
            if (! $purchaseOrder->isConfirmed()) {
                throw new \DomainException(
                    'Only confirmed documents can be reverted. Current status: '.$purchaseOrder->status->value
                );
            }

            $poLineIds = [];
            foreach ($purchaseOrder->lines()->pluck('id') as $lineId) {
                if (is_string($lineId)) {
                    $poLineIds[] = $lineId;
                }
            }

            if ($this->receiptLineGuard->poLineIdsWithReceipts($poLineIds) !== []) {
                throw new \DomainException('PURCHASE_ORDER_HAS_RECEIPTS');
            }

            if ($purchaseOrder->source_document_id !== null) {
                $source = Document::query()
                    ->where('tenant_id', $purchaseOrder->tenant_id)
                    ->where('company_id', $purchaseOrder->company_id)
                    ->find($purchaseOrder->source_document_id);

                if ($source?->type === DocumentType::PurchaseQuoteRequest) {
                    throw new \DomainException('PURCHASE_ORDER_FROM_RFQ');
                }
            }

            $hasSupplierInvoices = Document::query()
                ->where('tenant_id', $purchaseOrder->tenant_id)
                ->where('company_id', $purchaseOrder->company_id)
                ->where('type', DocumentType::SupplierInvoice)
                ->where(function ($query) use ($purchaseOrder): void {
                    $query
                        ->where('source_document_id', $purchaseOrder->id)
                        ->orWhereJsonContains('payload->supplier_invoice->source_document_ids', $purchaseOrder->id);
                })
                ->exists();

            if ($hasSupplierInvoices) {
                throw new \DomainException('PURCHASE_ORDER_HAS_SUPPLIER_INVOICES');
            }

            $purchaseOrder->update([
                'status' => DocumentStatus::Draft,
                'confirmed_at' => null,
                'confirmed_by' => null,
            ]);

            /** @var Document */
            return $purchaseOrder->fresh(['lines']);
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

    private function dispatchSalesOrderCancelledEvent(Document $salesOrder, string $reason, string $cancelledBy, string $cancelledAt): void
    {
        event(new SalesOrderCancelled(
            salesOrderId: $salesOrder->id,
            tenantId: $salesOrder->tenant_id,
            companyId: $salesOrder->company_id,
            documentNumber: $salesOrder->document_number ?? '',
            partnerId: $salesOrder->partner_id,
            cancellationReason: $reason,
            cancelledBy: $cancelledBy,
            cancelledAt: $cancelledAt,
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
        /** @var Company $company */
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
            // D-19 / T4: ONE physical predicate, carrying the api.document.010
            // scope (a forged cross-tenant line.product_id must not resolve to a
            // foreign product) rather than restating it here.
            if (PhysicalLinePredicate::forLine($line, $invoice->tenant_id, $invoice->company_id)) {
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
