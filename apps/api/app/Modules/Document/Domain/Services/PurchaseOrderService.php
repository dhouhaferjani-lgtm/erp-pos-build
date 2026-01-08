<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\PurchaseOrderConfirmed;
use App\Modules\Inventory\Application\Services\LandedCostService;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for purchase order lifecycle operations.
 *
 * Key responsibilities:
 * - Confirm purchase orders (transition from draft)
 * - Calculate taxes with recoverability determination
 * - Allocate landed costs and non-recoverable taxes to purchase lines
 * - Manage order lifecycle with proper event sourcing
 *
 * Lifecycle:
 * 1. Draft → Confirmed (calculates taxes, allocates costs and non-recoverable taxes)
 * 2. Confirmed → Goods Receipt (handled by GoodsReceiptService)
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly LandedCostService $landedCostService,
        private readonly TaxCalculationService $taxCalculationService,
    ) {}

    /**
     * Confirm a purchase order and allocate landed costs.
     *
     * This is the key moment when:
     * - Order transitions from draft to confirmed
     * - Landed costs are allocated to all lines proportionally
     * - Order becomes a firm commitment to supplier
     * - Confirmation event is dispatched for audit trail
     *
     * @throws \DomainException If purchase order cannot be confirmed
     */
    public function confirm(Document $purchaseOrder): Document
    {
        if ($purchaseOrder->type !== DocumentType::PurchaseOrder) {
            throw new \DomainException(
                'Only purchase orders can be confirmed with this service.'
            );
        }

        if (! $purchaseOrder->isDraft()) {
            throw new \DomainException(
                "Only draft purchase orders can be confirmed. Current status: {$purchaseOrder->status->value}"
            );
        }

        return DB::transaction(function () use ($purchaseOrder): Document {
            $this->confirmAndAllocateCosts($purchaseOrder);

            $purchaseOrder->refresh();

            /** @var Document */
            return $purchaseOrder->load(['lines']);
        });
    }

    /**
     * Confirm purchase order and allocate landed costs and taxes.
     */
    private function confirmAndAllocateCosts(Document $purchaseOrder): void
    {
        $confirmedAt = now();
        $confirmedBy = auth()->id();

        // Update status first
        $purchaseOrder->update([
            'status' => DocumentStatus::Confirmed,
            'confirmed_at' => $confirmedAt,
            'confirmed_by' => $confirmedBy,
        ]);

        // Calculate taxes with recoverability determination
        $taxResult = $this->taxCalculationService->calculateDocumentTaxes($purchaseOrder);

        // Store tax details on document
        $purchaseOrder->update([
            'tax_amount' => $taxResult->totalTax,
            'total' => $taxResult->total,
        ]);

        // Snapshot tax details for immutable audit trail
        $this->taxCalculationService->snapshotTaxDetails($purchaseOrder, $taxResult);

        // Allocate landed costs AND non-recoverable taxes (has its own transaction - uses savepoints)
        $this->landedCostService->allocateCostsAndTaxes($purchaseOrder, $taxResult);

        // Dispatch event for audit trail
        $this->dispatchConfirmedEvent($purchaseOrder, $confirmedAt->toIso8601String());
    }

    /**
     * Dispatch the PurchaseOrderConfirmed event for audit trail.
     */
    private function dispatchConfirmedEvent(Document $purchaseOrder, string $confirmedAt): void
    {
        event(new PurchaseOrderConfirmed(
            purchaseOrderId: $purchaseOrder->id,
            tenantId: $purchaseOrder->tenant_id,
            companyId: $purchaseOrder->company_id,
            documentNumber: $purchaseOrder->document_number,
            partnerId: $purchaseOrder->partner_id,
            total: $purchaseOrder->total ?? '0.00',
            currency: $purchaseOrder->currency,
            confirmedBy: (string) $purchaseOrder->confirmed_by,
            confirmedAt: $confirmedAt,
        ));
    }
}
