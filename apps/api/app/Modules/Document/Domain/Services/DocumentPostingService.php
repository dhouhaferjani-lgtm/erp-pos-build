<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\CreditNoteAllocation;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DeliveryComplianceCode;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\PostingContext;
use App\Modules\Document\Domain\Events\InvoiceCancelled;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Document\Domain\Events\SalesOrderCancelled;
use App\Modules\Document\Domain\Exceptions\DeliveryRequiredBeforeInvoiceException;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Shared\Contracts\Accounting\CustomerAdvanceClearingInterface;
use App\Shared\Contracts\Accounting\DocumentGlPreflightInterface;
use App\Shared\Contracts\Accounting\DocumentGlReversalInterface;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
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
        private readonly DeliveryComplianceGate $deliveryComplianceGate,
        private readonly CustomerAdvanceClearingInterface $advanceClearing,
        private readonly DocumentStatusService $documentStatus,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Post a document, adding it to the fiscal hash chain if required.
     *
     * This method is idempotent: calling it on an already-posted document
     * will return the document without error.
     *
     * @param  PostingContext  $context  WHO is posting. Defaults to
     *                                   {@see PostingContext::Standard}, so every existing caller keeps the full
     *                                   gate; only a caller that names a different context can claim the narrow
     *                                   pre-delivery exemption (fiscal F-1 — see {@see PostingContext}).
     *
     * @throws \DomainException If document cannot be posted (wrong status)
     */
    public function post(Document $document, PostingContext $context = PostingContext::Standard): Document
    {
        // Idempotent: if already posted, return success.
        //
        // N-6 — `Paid` counts as already posted here when the document carries a
        // seal. Posting a fully-prepaid invoice now settles it in the same
        // transaction (`settleIfFullyPrepaid()`), so `Posted` is a state it
        // passes THROUGH; a re-post that landed in the `! isConfirmed()` refusal
        // below would report "cannot post a paid document" about a document this
        // very method had just posted.
        if ($document->isPosted() || $this->isSealedAndSettled($document)) {
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
            $this->validateDeliveryCompliance($document, $context);
        }

        $requiresFiscalChain = $this->requiresFiscalChain($document->type);

        return DB::transaction(function () use ($document, $requiresFiscalChain, $context): Document {
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
                // T25e — record the pre-delivery invoicing policy in force and
                // the delivery state that satisfied it, BEFORE the seal. The
                // invoice fiscal hash covers only document_number / posted_at /
                // total / currency, so this cannot move the sealed bytes; writing
                // it pre-seal makes the stamp and the seal one atomic act, and
                // keeps it clear of the SEALED-only immutability trigger.
                if ($document->type === DocumentType::Invoice) {
                    $this->deliveryComplianceGate->stampDeliveryPolicyDecision(
                        $document,
                        $context,
                        $this->isExemptFromDeliveryRequirement($document, $context),
                    );
                }

                $this->glPreflight->assertDocumentGlIsPostable($document);

                $this->postWithFiscalChain($document);
            } else {
                $this->documentStatus->transition($document, DocumentStatus::Posted);
            }

            // N-6 — posting is the moment a receivable comes into existence, so
            // it is also the moment any advance collected against this document
            // must be discharged. Runs INSIDE the posting transaction: if the
            // clearing entry cannot be written the whole posting is refused
            // rather than leaving a sealed invoice next to a stranded 419.
            if ($document->type === DocumentType::Invoice) {
                $this->clearAdvancesAllocatedToInvoice($document);
                $this->settleIfFullyPrepaid($document);
            }

            /** @var Document */
            return $document->fresh(['lines']);
        });
    }

    /**
     * A document that is `Paid` AND sealed reached `Paid` through `Posted` — it
     * has been posted, and a second `post()` must be a no-op rather than a
     * refusal. A `Paid` document with NO seal is the legacy pre-N-6 dead end and
     * is deliberately NOT covered: it must not be treated as posted by anything.
     */
    private function isSealedAndSettled(Document $document): bool
    {
        return $document->status === DocumentStatus::Paid && $document->fiscal_hash !== null;
    }

    /**
     * Clear every OPEN customer advance allocated to this invoice, once.
     *
     * OPEN means `booked_as_advance = true AND advance_cleared_at IS NULL`. The
     * second half is what stops a DOUBLE CLEAR: an order-originated prepayment
     * is already cleared at CONVERSION by
     * `SalesOrderToInvoiceConverter::transferPrepayments()`, which re-points the
     * allocation row onto the new invoice — without the marker this method would
     * clear the same 419 a second time, draining another advance of the same
     * partner (the clearing ceiling is partner-pool-level by construction) or
     * refusing outright.
     *
     * ONE entry for the whole sum, not one per allocation: the clearing is a
     * single accounting fact about this invoice, and
     * `clearCustomerAdvanceToReceivable()` keys its entry on the INVOICE id.
     */
    private function clearAdvancesAllocatedToInvoice(Document $invoice): void
    {
        $openAdvances = PaymentAllocation::query()
            ->where('document_id', $invoice->id)
            ->where('booked_as_advance', true)
            ->whereNull('advance_cleared_at')
            ->lockForUpdate()
            ->get();

        if ($openAdvances->isEmpty()) {
            return;
        }

        $scale = $this->scaleResolver->getScaleSafe((string) $invoice->currency, 3);

        /** @var numeric-string $total */
        $total = '0';
        foreach ($openAdvances as $advance) {
            /** @var numeric-string $amount */
            $amount = (string) $advance->amount;
            $total = bcadd($total, $amount, $scale);
        }

        if (bccomp($total, '0', $scale) <= 0) {
            return;
        }

        $actorId = auth()->id();
        $journalEntryId = $this->advanceClearing->clearCustomerAdvanceForDocument(
            $invoice,
            $total,
            is_string($actorId) ? $actorId : null,
        );

        // `advance_journal_entry_id` is deliberately NOT overwritten: it names
        // the entry that BOOKED the advance, and this one discharges it. The
        // clearing entry is keyed on the invoice id in `journal_entries` and is
        // found from there.
        unset($journalEntryId);

        PaymentAllocation::query()
            ->whereIn('id', $openAdvances->pluck('id')->all())
            ->update(['advance_cleared_at' => now()]);
    }

    /**
     * An invoice whose balance is already zero when it is posted was PAID IN
     * ADVANCE: the money arrived while it was still Confirmed, was booked to
     * 419, and the clearing above has just discharged it against the fresh
     * receivable. `Posted -> Paid` is now a legal edge and the lifecycle should
     * say so.
     *
     * This is the ONLY place `confirmed-then-prepaid` reaches `Paid`, and it
     * reaches it THROUGH `Posted` — which is the whole point of the lane.
     */
    private function settleIfFullyPrepaid(Document $invoice): void
    {
        if (! $invoice->type->canTransitionToPaid()) {
            return;
        }

        $scale = $this->scaleResolver->getScaleSafe((string) $invoice->currency, 3);
        $outstanding = $invoice->outstandingBalance($scale);

        if (bccomp($outstanding, '0', $scale) > 0) {
            return;
        }

        // Nothing was ever allocated: a zero-balance read on a document with no
        // allocations at all is the empty case, not a settlement.
        $hasAllocations = PaymentAllocation::query()
            ->where('document_id', $invoice->id)
            ->exists();

        if (! $hasAllocations) {
            return;
        }

        $this->documentStatus->markPaid($invoice);
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

            // Update status and fiscal_status if it's a fiscal document.
            // N-6 — `status` is no longer in this array: the single write path
            // takes it as the transition target and merges the rest.
            $updateData = [
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
                $this->documentStatus->transition($document, DocumentStatus::Cancelled, $updateData);
                DB::afterCommit(function () use ($document): void {
                    $this->dispatchCancellationEvent($document);
                });
            } else {
                $this->documentStatus->transition($document, DocumentStatus::Cancelled, $updateData);
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

            $this->documentStatus->transition($salesOrder, DocumentStatus::Cancelled, [
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

            $this->documentStatus->transition($document, DocumentStatus::Draft, [
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

            $this->documentStatus->transition($salesOrder, DocumentStatus::Draft, [
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

            $this->documentStatus->transition($purchaseOrder, DocumentStatus::Draft, [
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

        // Update document with fiscal chain data and seal it.
        //
        // N-6 — routed through the single write path, with the seal columns
        // passed as `$extraAttributes` so they land in the SAME statement as the
        // status flip. That is not cosmetic: `trg_document_immutability` reads
        // `OLD.fiscal_status`, so splitting the seal into a second update would
        // be refused by the trigger.
        $this->documentStatus->transition($document, DocumentStatus::Posted, [
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
     * Enforce the delivery requirement for invoices carrying physical products.
     *
     * Enforced at POSTING time (not creation time) so delivery notes can be
     * auto-created and confirmed as part of a guided flow.
     *
     * 🔁 Wave 3 T25f: the traversal that used to live here — walking
     * `sourceOrder.payload['delivery_note_ids']` by hand — has moved to
     * {@see DeliveryComplianceGate}, which reads BOTH linkage shapes through
     * `DeliveredQuantityResolver`. `InvoiceController::checkDeliveryNotesDelivered()`
     * was the second copy of the same logic and is gone; the controller now asks
     * the same gate, so the guided 422 and the posting refusal can no longer
     * disagree about whether an invoice was delivered.
     *
     * Behaviour is UNCHANGED for every invoice that posted before T25f. What is
     * new is that DN → invoice-converted invoices are now *visible* to the gate
     * (they pass it: their delivery notes are Confirmed by construction) instead
     * of being waved through by a "no source order" early return.
     *
     * @throws \DomainException If physical products haven't been delivered
     */
    private function validateDeliveryCompliance(Document $invoice, PostingContext $context): void
    {
        $status = $this->deliveryComplianceGate->evaluate($invoice);

        if ($status->isCompliant()) {
            return;
        }

        // 🆕 Fix round 1 / fiscal F-1 — ORCHESTRATOR RULING (b): the NARROW,
        // recorded exemption. See {@see PostingContext} for why this is a caller
        // context and not a document-shape test, and
        // {@see isExemptFromDeliveryRequirement()} for the two conditions.
        if ($status->code === DeliveryComplianceCode::DeliveryRequiredBeforeInvoice
            && $this->isExemptFromDeliveryRequirement($invoice, $context)) {
            return;
        }

        // 🚨 T25b — the compliance refusal is TYPED and carries the resolved
        // policy. Flattening it into the generic `\DomainException` below would
        // make the control depend on the entry point: every non-controller
        // caller of post() would surface it as an opaque POSTING_FAILED with no
        // policy, no source and no compliant alternative to offer.
        if ($status->code === DeliveryComplianceCode::DeliveryRequiredBeforeInvoice
            && $status->resolvedPolicy !== null) {
            throw new DeliveryRequiredBeforeInvoiceException(
                policy: $status->resolvedPolicy->policy->value,
                policySource: $status->resolvedPolicy->source,
                draftDeliveryNotes: $status->draftDeliveryNotes,
                canAutoConfirm: $status->canAutoConfirm,
                blockedReason: $status->blockedReason,
            );
        }

        throw new \DomainException($status->message);
    }

    /**
     * The ONLY way past the pre-delivery refusal (fix round 1, fiscal F-1).
     *
     * TWO conditions, deliberately, and neither is sufficient alone:
     *
     *   1. the CALLER named {@see PostingContext::WorkOrderGeneratedInvoice} — an
     *      explicit, typed claim written at one call site, so adding a second
     *      exempt path is an edit to the enum and to this method, never an
     *      emergent consequence of how a document happens to be shaped;
     *   2. the DOCUMENT really is work-order-generated (`work_order_id`), so a
     *      caller that passes the context for an unrelated invoice — by mistake
     *      or otherwise — gets the refusal anyway.
     *
     * It exempts ONE verdict: `DeliveryRequiredBeforeInvoice`. Draft delivery
     * notes, an incomplete delivery and an order with no notes all still refuse,
     * because those describe a delivery lane that EXISTS and is in the wrong
     * state — a different fact from "this module has no delivery lane", which is
     * the whole basis of the exemption.
     *
     * Scope of the underlying gap, and why this is not a licence: WO parts move
     * NO stock today (no issuance path exists anywhere in the Workshop module),
     * so a WO invoice recognises revenue with no movement and, post-cutover, no
     * COGS. That is a real defect and it is ticketed —
     * `docs/superpowers/tickets/2026-08-10-workshop-parts-goods-lane-gap.md` —
     * for the DPA program backlog. When the WO goods lane lands, this exemption
     * is expected to be DELETED, and the stamp's `delivery_requirement_exempted`
     * flag is how the population posted under it is found.
     */
    private function isExemptFromDeliveryRequirement(Document $document, PostingContext $context): bool
    {
        return $context->claimsPreDeliveryExemption()
            && $document->work_order_id !== null;
    }
}
