<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Events\ReturnNoteConfirmed;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for return note operations with fiscal hash chain compliance.
 *
 * Key differences from DeliveryNoteService:
 * - RN is hashed on CONFIRM (when stock returns), not on POST
 * - RN receives stock back (opposite of DN which issues stock)
 * - RN has its own separate hash chain per company
 * - Required for fiscal compliance (tamper-proof return documents)
 * - RN can be linked to credit notes but is independent
 *
 * Stock Returns:
 * - When RN is confirmed, receives stock back using WeightedAverageCostService
 * - Stock is received at original cost (from source document if available)
 * - WAC is recalculated based on returned goods
 *
 * Hash Chain Format: SHA256(previous_hash | document_number | date | total | currency)
 */
final class ReturnNoteService
{
    public function __construct(
        private readonly WeightedAverageCostService $wacService,
        private readonly FiscalHashService $hashService,
        private readonly TaxCalculationService $taxCalculationService,
    ) {}

    /**
     * Confirm a return note, adding it to the fiscal hash chain.
     *
     * This is the key moment when:
     * - Stock is returned (inbound from customer)
     * - The RN becomes fiscally sealed (tamper-proof)
     * - The RN is added to the company's RN hash chain
     * - WAC is updated based on returned goods
     *
     * @throws \DomainException If return note cannot be confirmed
     */
    public function confirm(Document $returnNote): Document
    {
        if ($returnNote->type !== DocumentType::ReturnNote) {
            throw new \DomainException(
                'Only return notes can be confirmed with this service.'
            );
        }

        if (! $returnNote->isDraft()) {
            throw new \DomainException(
                'Only draft return notes can be confirmed. Current status: '.$returnNote->status->value
            );
        }

        return DB::transaction(function () use ($returnNote): Document {
            $this->confirmWithFiscalChain($returnNote);

            $returnNote->refresh();

            /** @var Document */
            return $returnNote->load(['lines']);
        });
    }

    /**
     * Confirm a return note with full fiscal hash chain compliance.
     */
    private function confirmWithFiscalChain(Document $returnNote): void
    {
        // Acquire lock and get previous return note in chain
        $previousDoc = Document::where('company_id', $returnNote->company_id)
            ->where('type', DocumentType::ReturnNote)
            ->where('status', DocumentStatus::Confirmed)
            ->whereNotNull('fiscal_hash')
            ->orderByDesc('chain_sequence')
            ->lockForUpdate()
            ->first();

        // For genesis document, use company's unique seed
        $previousHash = $previousDoc?->fiscal_hash;
        $genesisSeed = $previousHash === null ? $this->getCompanyGenesisSeed($returnNote) : null;

        $chainSequence = ($previousDoc !== null ? $previousDoc->chain_sequence : 0) + 1;
        $confirmedAt = now();

        // Calculate fiscal hash using the compliance service
        $input = $this->hashService->serializeForHashing([
            'document_number' => $returnNote->document_number,
            'posted_at' => $confirmedAt->toDateString(), // Use 'posted_at' for consistency with serializer
            'total' => $returnNote->total ?? '0.00',
            'currency' => $returnNote->currency,
        ]);

        $fiscalHash = $this->hashService->calculateHash($input, $previousHash, $genesisSeed);

        // Receive stock back for each line
        $this->receiveStockBack($returnNote);

        // Update return note with fiscal chain data and seal it
        $returnNote->update([
            'status' => DocumentStatus::Confirmed,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => $fiscalHash,
            'previous_hash' => $previousHash,
            'chain_sequence' => $chainSequence,
        ]);

        // Calculate and snapshot taxes for immutable audit trail
        $taxResult = $this->taxCalculationService->calculateDocumentTaxes($returnNote);
        $returnNote->update([
            'tax_amount' => $taxResult->totalTax,
            'total' => $taxResult->total,
        ]);
        $this->taxCalculationService->snapshotTaxDetails($returnNote, $taxResult);

        // Dispatch the fiscal event for audit log
        $this->dispatchConfirmedEvent($returnNote, $confirmedAt->toIso8601String());
    }

    /**
     * Receive stock back from customer.
     *
     * When a return note is confirmed, we need to record stock receipt
     * for all lines with physical products.
     */
    private function receiveStockBack(Document $returnNote): void
    {
        foreach ($returnNote->lines as $line) {
            // Skip service lines (non-physical products)
            if ($line->product === null || ($line->product->is_service ?? false)) {
                continue;
            }

            // Skip lines with zero or negative quantity
            if (bccomp((string) $line->quantity, '0', 4) <= 0) {
                continue;
            }

            // Determine effective location using the fallback chain:
            // 1. Line's explicit location_id
            // 2. Document's location_id
            // 3. Null (error - location required for stock receipt)
            $locationId = $line->getEffectiveLocationId();

            if ($locationId === null) {
                throw new \DomainException(
                    "Cannot receive stock: no location specified for line {$line->id}. ".
                    'Please set either line.location_id or document.location_id.'
                );
            }

            $location = Location::findOrFail($locationId);

            // Get original cost (from source document if available, otherwise use current cost)
            $originalCost = $this->getOriginalCost($line);

            // Receive stock back using WAC service with audit trail
            $this->wacService->recordReturn(
                product: $line->product,
                location: $location,
                quantity: (float) $line->quantity,
                originalCost: $originalCost,
                reference: $returnNote->document_number,
                referenceType: 'Document',
                referenceId: $returnNote->id
            );
        }
    }

    /**
     * Get the original cost for a returned line.
     *
     * If return note references a source document (invoice/delivery note),
     * use the cost from that document. Otherwise, use current product cost.
     */
    private function getOriginalCost(DocumentLine $line): float
    {
        // If return note references source document, get cost from there
        if ($line->document->source_document_id !== null) {
            $sourceLine = DocumentLine::where('document_id', $line->document->source_document_id)
                ->where('product_id', $line->product_id)
                ->first();

            if ($sourceLine !== null && $sourceLine->landed_unit_cost !== null) {
                return (float) $sourceLine->landed_unit_cost;
            }
        }

        // Fallback: use current product cost
        return (float) ($line->product->cost_price ?? '0.00');
    }

    /**
     * Dispatch the ReturnNoteConfirmed event for fiscal audit trail.
     */
    private function dispatchConfirmedEvent(Document $returnNote, string $confirmedAt): void
    {
        event(new ReturnNoteConfirmed(
            returnNoteId: $returnNote->id,
            tenantId: $returnNote->tenant_id,
            companyId: $returnNote->company_id,
            documentNumber: $returnNote->document_number,
            partnerId: $returnNote->partner_id,
            total: $returnNote->total ?? '0.00',
            currency: $returnNote->currency,
            fiscalHash: $returnNote->fiscal_hash ?? '',
            chainSequence: $returnNote->chain_sequence ?? 0,
            confirmedAt: $confirmedAt,
        ));
    }

    /**
     * Get the company's unique genesis seed for hash chain initialization.
     *
     * @throws \RuntimeException If company has no genesis seed
     */
    private function getCompanyGenesisSeed(Document $returnNote): string
    {
        /** @var Company $company */
        $company = $returnNote->company;

        if ($company->fiscal_chain_seed === null) {
            throw new \RuntimeException(
                'Company is missing fiscal_chain_seed. Run migration to generate seeds.'
            );
        }

        return $company->fiscal_chain_seed;
    }
}
