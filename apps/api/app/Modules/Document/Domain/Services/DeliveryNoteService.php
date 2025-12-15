<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Events\DeliveryNoteConfirmed;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for delivery note operations with fiscal hash chain compliance.
 *
 * Key differences from DocumentPostingService:
 * - DN is hashed on CONFIRM (when stock moves), not on POST
 * - DN does not create GL entries (not an accounting document)
 * - DN has its own separate hash chain per company
 * - Required for Tunisia fiscal compliance (tamper-proof delivery documents)
 *
 * Hash Chain Format: SHA256(previous_hash | document_number | date | total | currency)
 */
final class DeliveryNoteService
{
    public function __construct(
        private readonly FiscalHashService $hashService,
    ) {}

    /**
     * Confirm a delivery note, adding it to the fiscal hash chain.
     *
     * This is the key moment when:
     * - Stock is moved (outbound for sales, inbound for purchases)
     * - The DN becomes fiscally sealed (tamper-proof)
     * - The DN is added to the company's DN hash chain
     *
     * @throws \DomainException If delivery note cannot be confirmed
     */
    public function confirm(Document $deliveryNote): Document
    {
        if ($deliveryNote->type !== DocumentType::DeliveryNote) {
            throw new \DomainException(
                'Only delivery notes can be confirmed with this service. Use DocumentPostingService for other document types.'
            );
        }

        if (!$deliveryNote->isDraft()) {
            throw new \DomainException(
                'Only draft delivery notes can be confirmed. Current status: '.$deliveryNote->status->value
            );
        }

        return DB::transaction(function () use ($deliveryNote): Document {
            $this->confirmWithFiscalChain($deliveryNote);

            /** @var Document */
            return $deliveryNote->fresh(['lines']);
        });
    }

    /**
     * Confirm a delivery note with full fiscal hash chain compliance.
     */
    private function confirmWithFiscalChain(Document $deliveryNote): void
    {
        // Acquire lock and get previous delivery note in chain
        $previousDoc = Document::where('company_id', $deliveryNote->company_id)
            ->where('type', DocumentType::DeliveryNote)
            ->where('status', DocumentStatus::Confirmed)
            ->whereNotNull('fiscal_hash')
            ->orderByDesc('chain_sequence')
            ->lockForUpdate()
            ->first();

        // For genesis document, use company's unique seed
        $previousHash = $previousDoc?->fiscal_hash;
        $genesisSeed = $previousHash === null ? $this->getCompanyGenesisSeed($deliveryNote) : null;

        $chainSequence = ($previousDoc?->chain_sequence ?? 0) + 1;
        $confirmedAt = now();

        // Calculate fiscal hash using the compliance service
        $input = $this->hashService->serializeForHashing([
            'document_number' => $deliveryNote->document_number,
            'posted_at' => $confirmedAt->toDateString(), // Use 'posted_at' for consistency with serializer
            'total' => $deliveryNote->total ?? '0.00',
            'currency' => $deliveryNote->currency,
        ]);

        $fiscalHash = $this->hashService->calculateHash($input, $previousHash, $genesisSeed);

        // Update delivery note with fiscal chain data and seal it
        $deliveryNote->update([
            'status' => DocumentStatus::Confirmed,
            'fiscal_category' => FiscalCategory::DeliveryNote,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => $fiscalHash,
            'previous_hash' => $previousHash,
            'chain_sequence' => $chainSequence,
        ]);

        // Dispatch the fiscal event for audit log
        $this->dispatchConfirmedEvent($deliveryNote, $confirmedAt->toIso8601String());
    }

    /**
     * Dispatch the DeliveryNoteConfirmed event for fiscal audit trail.
     */
    private function dispatchConfirmedEvent(Document $deliveryNote, string $confirmedAt): void
    {
        event(new DeliveryNoteConfirmed(
            deliveryNoteId: $deliveryNote->id,
            tenantId: $deliveryNote->tenant_id,
            companyId: $deliveryNote->company_id,
            documentNumber: $deliveryNote->document_number,
            partnerId: $deliveryNote->partner_id,
            total: $deliveryNote->total ?? '0.00',
            currency: $deliveryNote->currency,
            fiscalHash: $deliveryNote->fiscal_hash ?? '',
            chainSequence: $deliveryNote->chain_sequence ?? 0,
            confirmedAt: $confirmedAt,
        ));
    }

    /**
     * Get the company's unique genesis seed for hash chain initialization.
     *
     * @throws \RuntimeException If company has no genesis seed
     */
    private function getCompanyGenesisSeed(Document $deliveryNote): string
    {
        $company = $deliveryNote->company;

        if ($company === null) {
            throw new \RuntimeException(
                'Delivery note must have an associated company for fiscal chain'
            );
        }

        if ($company->fiscal_chain_seed === null) {
            throw new \RuntimeException(
                'Company is missing fiscal_chain_seed. Run migration to generate seeds.'
            );
        }

        return $company->fiscal_chain_seed;
    }
}
