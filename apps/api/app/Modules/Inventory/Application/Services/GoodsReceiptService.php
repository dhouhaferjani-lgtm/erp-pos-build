<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Inventory\Domain\Events\GoodsReceived;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Facades\DB;

/**
 * GoodsReceiptService - Handles goods receipt for purchase orders
 *
 * This service manages the receiving of goods from purchase orders,
 * including partial receipts, WAC updates, and stock level changes.
 */
final class GoodsReceiptService
{
    public function __construct(
        private readonly WeightedAverageCostService $wacService,
        private readonly LandedCostService $landedCostService,
        private readonly BatchStockService $batchStockService,
        private readonly ProductCostLock $costLock,
    ) {}

    /**
     * Receive goods for a purchase order.
     *
     * @param  Document  $purchaseOrder  The confirmed purchase order
     * @param  array<string, string>  $receivedQuantities  Map of line_id => paid quantity to receive
     * @param  array<string, array{batch_number: string, expiry_date: string, manufacturing_date?: string}>  $batchData  Optional batch data per line_id
     * @param  array<string, string>  $freeQuantities  Map of line_id => free quantity to receive
     * @return Document The updated purchase order
     *
     * @throws \DomainException If PO is not in valid state for receiving
     */
    public function receiveGoods(Document $purchaseOrder, array $receivedQuantities, array $batchData = [], array $freeQuantities = []): Document
    {
        if ($purchaseOrder->type !== DocumentType::PurchaseOrder) {
            throw new \DomainException('Only purchase orders can receive goods');
        }

        if ($purchaseOrder->status !== DocumentStatus::Confirmed) {
            throw new \DomainException('Purchase order must be confirmed before receiving goods');
        }

        return DB::transaction(function () use ($purchaseOrder, $receivedQuantities, $batchData, $freeQuantities): Document {
            // Get the default location for this company
            $location = $purchaseOrder->location ?? $this->getDefaultLocation($purchaseOrder);

            // Re-allocate costs if they were modified after initial confirmation
            if ($this->landedCostService->hasAllocatedCosts($purchaseOrder)) {
                $this->landedCostService->reallocateCosts($purchaseOrder);
            }

            // Canonical lock order: advisory lock(s) -> stock_level row(s) -> product row.
            // Each per-line recordPurchase() below acquires its product's advisory lock
            // internally, but accumulating those unsorted inside this single outer
            // transaction risks an AB-BA deadlock between two concurrent receipts of
            // overlapping products received in different line orders. Acquire ALL the
            // PO's product advisory locks UP FRONT in sorted order (R2-1 pattern) so the
            // nested per-line acquires are re-entrant on already-held xact locks.
            // Over-acquiring for non-physical/short lines is harmless — sorted acquisition
            // is what breaks the cycle.
            /** @var list<string> $productIds */
            $productIds = $purchaseOrder->lines
                ->pluck('product_id')
                ->filter()
                ->unique()
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();

            return $this->costLock->acquire(
                $purchaseOrder->tenant_id,
                $purchaseOrder->company_id,
                $productIds,
                function () use ($purchaseOrder, $receivedQuantities, $batchData, $freeQuantities, $location): Document {
                    return $this->processReceiptLines($purchaseOrder, $receivedQuantities, $batchData, $freeQuantities, $location);
                }
            );
        });
    }

    /**
     * Process the per-line receipt loop. Runs INSIDE the receiveGoods transaction
     * AND inside the up-front sorted ProductCostLock acquisition, so the canonical
     * lock order (advisory -> stock_level -> product) is preserved and no product
     * row lock is held before recordPurchase() is invoked.
     *
     * @param  array<string, string>  $receivedQuantities
     * @param  array<string, array{batch_number: string, expiry_date: string, manufacturing_date?: string}>  $batchData
     * @param  array<string, string>  $freeQuantities
     *
     * @throws \DomainException
     */
    private function processReceiptLines(
        Document $purchaseOrder,
        array $receivedQuantities,
        array $batchData,
        array $freeQuantities,
        Location $location
    ): Document {
        $hasReceivedItems = false;

        foreach ($purchaseOrder->lines as $line) {
            /** @var numeric-string $qtyToReceive */
            $qtyToReceive = $receivedQuantities[$line->id] ?? '0.00';
            /** @var numeric-string $freeQtyToReceive */
            $freeQtyToReceive = $freeQuantities[$line->id] ?? '0.00';

            if (bccomp($qtyToReceive, '0.00', 4) <= 0 && bccomp($freeQtyToReceive, '0.00', 4) <= 0) {
                continue;
            }

            // Validate not over-receiving paid units.
            /** @var numeric-string $alreadyReceived */
            $alreadyReceived = (string) ($line->quantity_received ?? '0.00');
            $remaining = bcsub((string) $line->quantity, $alreadyReceived, 4);

            if (bccomp($qtyToReceive, '0.00', 4) > 0 && bccomp($qtyToReceive, $remaining, 4) > 0) {
                throw new \DomainException(
                    "Cannot receive more than ordered for line {$line->id}. ".
                    "Ordered: {$line->quantity}, Already received: {$alreadyReceived}, Requested: {$qtyToReceive}"
                );
            }

            /** @var numeric-string $alreadyFreeReceived */
            $alreadyFreeReceived = (string) ($line->free_quantity_received ?? '0.00');
            $freeRemaining = bcsub((string) ($line->free_quantity ?? '0.00'), $alreadyFreeReceived, 4);

            if (bccomp($freeQtyToReceive, '0.00', 4) > 0 && bccomp($freeQtyToReceive, $freeRemaining, 4) > 0) {
                throw new \DomainException(
                    "Cannot receive more free quantity than ordered for line {$line->id}. ".
                    "Free ordered: {$line->free_quantity}, Already received: {$alreadyFreeReceived}, Requested: {$freeQtyToReceive}"
                );
            }

            // Skip non-physical products (services)
            if ($line->product_id === null) {
                continue;
            }

            // Scope by the purchase order's tenant + company so a forged
            // line.product_id (cross-tenant) cannot resolve to a foreign
            // product. Loaded UNLOCKED on purpose: recordPurchase() below
            // re-fetches and locks the product row itself in the canonical
            // order (advisory -> stock_level -> product). Holding a product
            // row lock here would invert that order and deadlock. We only
            // need the object for isPhysical()/requires_batch_tracking checks
            // and to pass into recordPurchase().
            $product = Product::query()
                ->where('tenant_id', $purchaseOrder->tenant_id)
                ->where('company_id', $purchaseOrder->company_id)
                ->find($line->product_id);
            if ($product === null || ! $product->isPhysical()) {
                continue;
            }

            if (($product->requires_batch_tracking ?? false) && ! isset($batchData[$line->id])) {
                throw new \DomainException("Batch data is required for batch-tracked product {$product->id}");
            }

            // Use landed cost from the PO line (includes allocated additional costs).
            // Keep as a numeric string — no float cast (precision contract P0-1).
            $unitCostStr = (string) ($line->landed_unit_cost ?? $line->unit_price);
            $landedUnitCost = $unitCostStr;

            // Thread variant_id from the PO line (Task 20). null for non-variant
            // products → product-level stock (backward compat). WAC stays
            // product-grain (§6.7); the variant only scopes the physical row.
            $variantId = $line->variant_id ?? null;

            $batch = null;
            if (isset($batchData[$line->id]) && ($product->requires_batch_tracking ?? false)) {
                $lineBatch = $batchData[$line->id];
                $batch = $this->batchStockService->findOrCreateBatch(
                    companyId: $purchaseOrder->company_id,
                    tenantId: $purchaseOrder->tenant_id,
                    productId: (string) $product->id,
                    batchNumber: $lineBatch['batch_number'],
                    expiryDate: $lineBatch['expiry_date'],
                    manufacturingDate: $lineBatch['manufacturing_date'] ?? null,
                );
            }

            if (bccomp($freeQtyToReceive, '0.00', 4) > 0) {
                $freeMovement = $this->wacService->recordPurchase(
                    product: $product,
                    location: $location,
                    quantity: $freeQtyToReceive,
                    landedUnitCost: '0',
                    reference: $purchaseOrder->document_number,
                    referenceType: 'Document',
                    referenceId: $purchaseOrder->id,
                    variantId: $variantId,
                );

                event(new GoodsReceived(
                    tenantId: $purchaseOrder->tenant_id,
                    companyId: $purchaseOrder->company_id,
                    productId: (string) $product->id,
                    locationId: (string) $location->id,
                    poLineId: (string) $line->id,
                    movementId: (string) $freeMovement->id,
                    receivedQty: $freeQtyToReceive,
                    unitCost: '0',
                    currency: (string) ($purchaseOrder->currency ?? 'TND'),
                ));

                if ($batch !== null) {
                    $this->batchStockService->receiveBatchStock(
                        tenantId: $purchaseOrder->tenant_id,
                        batchId: (int) $batch->id,
                        locationId: (string) $location->id,
                        quantity: $freeQtyToReceive,
                        movementId: $freeMovement->id,
                    );
                }

                $line->free_quantity_received = bcadd($alreadyFreeReceived, $freeQtyToReceive, 4);
            }

            if (bccomp($qtyToReceive, '0.00', 4) > 0) {
                // Record purchase with WAC update and audit trail.
                $movement = $this->wacService->recordPurchase(
                    product: $product,
                    location: $location,
                    quantity: $qtyToReceive,
                    landedUnitCost: $landedUnitCost,
                    reference: $purchaseOrder->document_number,
                    referenceType: 'Document',
                    referenceId: $purchaseOrder->id,
                    variantId: $variantId,
                );

                event(new GoodsReceived(
                    tenantId: $purchaseOrder->tenant_id,
                    companyId: $purchaseOrder->company_id,
                    productId: (string) $product->id,
                    locationId: (string) $location->id,
                    poLineId: (string) $line->id,
                    movementId: (string) $movement->id,
                    receivedQty: $qtyToReceive,
                    unitCost: $unitCostStr,
                    currency: (string) ($purchaseOrder->currency ?? 'TND'),
                ));

                if ($batch !== null) {
                    $this->batchStockService->receiveBatchStock(
                        tenantId: $purchaseOrder->tenant_id,
                        batchId: (int) $batch->id,
                        locationId: (string) $location->id,
                        quantity: $qtyToReceive,
                        movementId: $movement->id,
                    );
                }

                // Record the receipt-time 408 accrual basis (immutable after first paid receipt).
                // SupplierInvoicePostingService::post() asserts against this to detect
                // post-receipt landed-cost reallocations that would leave a 408 residue.
                if ($line->accrual_unit_cost === null) {
                    $line->accrual_unit_cost = $unitCostStr;
                }

                $line->quantity_received = bcadd($alreadyReceived, $qtyToReceive, 4);
            }

            if ($batch !== null) {
                $line->batch_id = $batch->id;
            }

            $line->save();

            $hasReceivedItems = true;
        }

        if (! $hasReceivedItems) {
            throw new \DomainException('No items to receive. Please specify quantities to receive.');
        }

        // Check if fully received
        $fullyReceived = $this->isFullyReceived($purchaseOrder);

        // Update PO status and metadata
        $purchaseOrder->update([
            'status' => $fullyReceived ? DocumentStatus::Received : $purchaseOrder->status,
            'payload' => array_merge($purchaseOrder->payload ?? [], [
                'last_goods_receipt_at' => now()->toDateTimeString(),
                'fully_received' => $fullyReceived,
                'goods_received_at' => $fullyReceived ? now()->toDateTimeString() : null,
            ]),
        ]);

        /** @var Document $freshOrder */
        $freshOrder = $purchaseOrder->fresh(['lines']);

        return $freshOrder;
    }

    /**
     * Receive all remaining items for a purchase order.
     *
     * @param  Document  $purchaseOrder  The confirmed purchase order
     * @return Document The updated purchase order
     */
    public function receiveAll(Document $purchaseOrder): Document
    {
        $receivedQuantities = [];

        foreach ($purchaseOrder->lines as $line) {
            $alreadyReceived = (string) ($line->quantity_received ?? '0.00');
            $remaining = bcsub((string) $line->quantity, $alreadyReceived, 4);

            if (bccomp($remaining, '0.00', 4) > 0) {
                $receivedQuantities[$line->id] = $remaining;
            }
        }

        $freeQuantities = [];

        foreach ($purchaseOrder->lines as $line) {
            $alreadyFreeReceived = (string) ($line->free_quantity_received ?? '0.00');
            $freeRemaining = bcsub((string) ($line->free_quantity ?? '0.00'), $alreadyFreeReceived, 4);

            if (bccomp($freeRemaining, '0.00', 4) > 0) {
                $freeQuantities[$line->id] = $freeRemaining;
            }
        }

        return $this->receiveGoods($purchaseOrder, $receivedQuantities, [], $freeQuantities);
    }

    /**
     * Get receipt status for a purchase order.
     *
     * @return array{status: string, total_ordered: string, total_received: string, percentage: float, lines: array<int, array<string, mixed>>}
     */
    public function getReceiptStatus(Document $purchaseOrder): array
    {
        $totalOrdered = '0.00';
        $totalReceived = '0.00';
        $lineStatus = [];

        foreach ($purchaseOrder->lines as $line) {
            $qty = (string) $line->quantity;
            $received = (string) ($line->quantity_received ?? '0.00');
            $freeQty = (string) ($line->free_quantity ?? '0.00');
            $freeReceived = (string) ($line->free_quantity_received ?? '0.00');
            $remaining = bcsub($qty, $received, 4);
            $freeRemaining = bcsub($freeQty, $freeReceived, 4);

            $totalOrdered = bcadd($totalOrdered, $qty, 4);
            $totalReceived = bcadd($totalReceived, $received, 4);

            $lineStatus[] = [
                'line_id' => $line->id,
                'product_name' => $line->product->name ?? $line->description,
                'quantity_ordered' => $qty,
                'quantity_received' => $received,
                'quantity_remaining' => $remaining,
                'free_quantity_ordered' => $freeQty,
                'free_quantity_received' => $freeReceived,
                'free_quantity_remaining' => $freeRemaining,
                'is_complete' => bccomp($remaining, '0.00', 4) <= 0 && bccomp($freeRemaining, '0.00', 4) <= 0,
            ];
        }

        $percentage = (float) $totalOrdered > 0
            ? round(((float) $totalReceived / (float) $totalOrdered) * 100, 2)
            : 0.0;

        $status = match (true) {
            bccomp($totalReceived, '0.00', 4) <= 0 => 'not_received',
            bccomp($totalReceived, $totalOrdered, 4) >= 0 => 'fully_received',
            default => 'partially_received',
        };

        return [
            'status' => $status,
            'total_ordered' => $totalOrdered,
            'total_received' => $totalReceived,
            'percentage' => $percentage,
            'lines' => $lineStatus,
        ];
    }

    /**
     * Check if a purchase order is fully received.
     */
    public function isFullyReceived(Document $purchaseOrder): bool
    {
        foreach ($purchaseOrder->lines as $line) {
            $qty = (string) $line->quantity;
            $received = (string) ($line->quantity_received ?? '0.00');
            $freeQty = (string) ($line->free_quantity ?? '0.00');
            $freeReceived = (string) ($line->free_quantity_received ?? '0.00');

            if (bccomp($received, $qty, 4) < 0 || bccomp($freeReceived, $freeQty, 4) < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if any goods have been received for this PO.
     */
    public function hasReceivedGoods(Document $purchaseOrder): bool
    {
        foreach ($purchaseOrder->lines as $line) {
            $received = (string) ($line->quantity_received ?? '0.00');
            $freeReceived = (string) ($line->free_quantity_received ?? '0.00');

            if (bccomp($received, '0.00', 4) > 0 || bccomp($freeReceived, '0.00', 4) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the default location for a company.
     *
     * @throws \RuntimeException If no default location is configured
     */
    private function getDefaultLocation(Document $document): Location
    {
        $location = $document->company->locations()
            ->where('is_default', true)
            ->first();

        if ($location === null) {
            // Fall back to any location
            $location = $document->company->locations()->first();
        }

        if ($location === null) {
            throw new \RuntimeException('No location configured for company. Please set up at least one location.');
        }

        return $location;
    }
}
