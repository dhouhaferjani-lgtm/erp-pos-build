<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\SalesOrderCancelled;
use App\Modules\Document\Domain\Events\SalesOrderConfirmed;
use App\Modules\Inventory\Application\Services\StockReservationService;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for sales order lifecycle operations.
 *
 * Key responsibilities:
 * - Confirm sales orders (transition from draft)
 * - Reserve stock for confirmed orders
 * - Cancel sales orders and release reservations
 * - Manage order lifecycle with proper event sourcing
 *
 * Lifecycle:
 * 1. Draft → Confirmed (reserves stock)
 * 2. Confirmed → Cancelled (releases reservations)
 * 3. Confirmed → Delivered (handled by DeliveryNoteService)
 *
 * Stock Reservations:
 * - Created on confirm() for each line with physical products
 * - Released on cancel() or when delivery note is confirmed
 * - Expire automatically if not fulfilled within configured period
 */
final class SalesOrderService
{
    public function __construct(
        private readonly StockReservationService $stockReservationService,
        private readonly TaxCalculationService $taxCalculationService,
    ) {}

    /**
     * Confirm a sales order and reserve stock.
     *
     * This is the key moment when:
     * - Order transitions from draft to confirmed
     * - Stock is reserved for all lines with physical products
     * - Order becomes a firm commitment
     * - Confirmation event is dispatched for audit trail
     *
     * @throws \DomainException If sales order cannot be confirmed
     */
    public function confirm(Document $salesOrder): Document
    {
        if ($salesOrder->type !== DocumentType::SalesOrder) {
            throw new \DomainException(
                'Only sales orders can be confirmed with this service.'
            );
        }

        if (! $salesOrder->isDraft()) {
            throw new \DomainException(
                "Only draft sales orders can be confirmed. Current status: {$salesOrder->status->value}"
            );
        }

        return DB::transaction(function () use ($salesOrder): Document {
            $this->confirmAndReserveStock($salesOrder);

            /** @var Document */
            return $salesOrder->fresh(['lines']);
        });
    }

    /**
     * Confirm sales order and create stock reservations.
     */
    private function confirmAndReserveStock(Document $salesOrder): void
    {
        $confirmedAt = now();
        $confirmedBy = auth()->id();

        // Get company for reservation settings
        /** @var \App\Modules\Company\Domain\Company $company */
        $company = $salesOrder->company;

        // Check if auto-reservation is enabled
        $settings = $company->getReservationSettings();
        if (! $settings->autoReserveOnSalesOrder) {
            // Just update status without creating reservations
            $salesOrder->update([
                'status' => DocumentStatus::Confirmed,
                'confirmed_at' => $confirmedAt,
                'confirmed_by' => $confirmedBy,
            ]);

            // Calculate and snapshot taxes for immutable audit trail
            $taxResult = $this->taxCalculationService->calculateDocumentTaxes($salesOrder);
            $salesOrder->update([
                'tax_amount' => $taxResult->totalTax,
                'total' => $taxResult->total,
            ]);
            $this->taxCalculationService->snapshotTaxDetails($salesOrder, $taxResult);

            $this->dispatchConfirmedEvent($salesOrder, [], $confirmedAt->toIso8601String());

            return;
        }

        // Reserve stock for each line with physical products
        $reservations = [];
        foreach ($salesOrder->lines as $line) {
            // Skip service lines (non-physical products)
            if ($line->product->is_service ?? false) {
                continue;
            }

            // Skip lines with zero or negative quantity
            if (bccomp((string) $line->quantity, '0', 4) <= 0) {
                continue;
            }

            // Determine location (use line location or document default location)
            $locationId = $line->location_id ?? $salesOrder->location_id;
            if ($locationId === null) {
                throw new \DomainException(
                    "Cannot reserve stock: no location specified for line {$line->id}"
                );
            }

            // Skip lines without product_id
            if ($line->product_id === null) {
                continue;
            }

            // Reserve stock for this line
            $reservation = $this->stockReservationService->reserve(
                company: $company,
                productId: $line->product_id,
                locationId: $locationId,
                quantity: (string) $line->quantity,
                sourceType: ReservationSource::SalesOrder,
                sourceId: $salesOrder->id,
                sourceLineId: $line->id,
                priority: 0,
                notes: "Sales Order {$salesOrder->document_number}",
            );

            $reservations[] = [
                'line_id' => $line->id,
                'product_id' => $line->product_id,
                'quantity' => (string) $line->quantity,
                'location_id' => $locationId,
            ];
        }

        // Update sales order status
        $salesOrder->update([
            'status' => DocumentStatus::Confirmed,
            'confirmed_at' => $confirmedAt,
            'confirmed_by' => $confirmedBy,
        ]);

        // Calculate and snapshot taxes for immutable audit trail
        $taxResult = $this->taxCalculationService->calculateDocumentTaxes($salesOrder);
        $salesOrder->update([
            'tax_amount' => $taxResult->totalTax,
            'total' => $taxResult->total,
        ]);
        $this->taxCalculationService->snapshotTaxDetails($salesOrder, $taxResult);

        // Dispatch event for audit trail
        $this->dispatchConfirmedEvent($salesOrder, $reservations, $confirmedAt->toIso8601String());
    }

    /**
     * Cancel a sales order and release all stock reservations.
     *
     * This method:
     * - Validates the order can be cancelled
     * - Releases all stock reservations
     * - Updates order status to cancelled
     * - Dispatches cancellation event for audit trail
     *
     * @throws \DomainException If sales order cannot be cancelled
     */
    public function cancel(Document $salesOrder, string $reason, ?string $cancelledBy = null): Document
    {
        if ($salesOrder->type !== DocumentType::SalesOrder) {
            throw new \DomainException(
                'Only sales orders can be cancelled with this service.'
            );
        }

        if ($salesOrder->isCancelled()) {
            throw new \DomainException('Sales order is already cancelled');
        }

        if ($salesOrder->isPosted()) {
            throw new \DomainException('Posted sales orders cannot be cancelled. Use credit notes instead.');
        }

        return DB::transaction(function () use ($salesOrder, $reason, $cancelledBy): Document {
            $this->cancelAndReleaseStock($salesOrder, $reason, $cancelledBy);

            /** @var Document */
            return $salesOrder->fresh(['lines']);
        });
    }

    /**
     * Cancel sales order and release all stock reservations.
     */
    private function cancelAndReleaseStock(Document $salesOrder, string $reason, ?string $cancelledBy): void
    {
        $cancelledAt = now();
        $cancelledBy = $cancelledBy ?? auth()->id();

        // Release all stock reservations for this sales order
        $releasedCount = $this->stockReservationService->releaseBySource(
            sourceType: ReservationSource::SalesOrder,
            sourceId: $salesOrder->id,
            reason: ReleaseReason::Cancelled,
            releasedBy: (string) $cancelledBy,
        );

        // Update sales order status
        $salesOrder->update([
            'status' => DocumentStatus::Cancelled,
            'cancelled_at' => $cancelledAt,
            'cancelled_by' => $cancelledBy,
            'cancellation_reason' => $reason,
        ]);

        // Dispatch event for audit trail
        $this->dispatchCancelledEvent($salesOrder, $reason, $cancelledAt->toIso8601String());
    }

    /**
     * Dispatch the SalesOrderConfirmed event for audit trail.
     *
     * @param  list<array{line_id: string, product_id: string, quantity: string, location_id: string}>  $reservations
     */
    private function dispatchConfirmedEvent(Document $salesOrder, array $reservations, string $confirmedAt): void
    {
        event(new SalesOrderConfirmed(
            salesOrderId: $salesOrder->id,
            tenantId: $salesOrder->tenant_id,
            companyId: $salesOrder->company_id,
            documentNumber: $salesOrder->document_number,
            partnerId: $salesOrder->partner_id,
            total: $salesOrder->total ?? '0.00',
            currency: $salesOrder->currency,
            lines: $reservations,
            confirmedBy: (string) $salesOrder->confirmed_by,
            confirmedAt: $confirmedAt,
        ));
    }

    /**
     * Dispatch the SalesOrderCancelled event for audit trail.
     */
    private function dispatchCancelledEvent(Document $salesOrder, string $reason, string $cancelledAt): void
    {
        event(new SalesOrderCancelled(
            salesOrderId: $salesOrder->id,
            tenantId: $salesOrder->tenant_id,
            companyId: $salesOrder->company_id,
            documentNumber: $salesOrder->document_number,
            partnerId: $salesOrder->partner_id,
            cancellationReason: $reason,
            cancelledBy: (string) $salesOrder->cancelled_by,
            cancelledAt: $cancelledAt,
        ));
    }
}
