<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Events\StockMovementRecordedV2;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Events\ProductCostPriceUpdated;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WeightedAverageCostService - Manages weighted average cost calculations for inventory
 *
 * IMPORTANT: All methods use database transactions with pessimistic locking
 * to prevent race conditions that could corrupt stock quantities and cost prices.
 */
class WeightedAverageCostService
{
    public function __construct(
        private readonly MarginService $marginService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Record a purchase and update weighted average cost
     *
     * Uses pessimistic locking to prevent race conditions when multiple
     * purchase receipts happen concurrently for the same product.
     *
     * @param  Product  $product  The product being purchased
     * @param  Location  $location  The location receiving the stock
     * @param  float  $quantity  Quantity being purchased
     * @param  float  $landedUnitCost  Unit cost including landed costs
     * @param  string|null  $reference  Human-readable reference (e.g., "PO-2025-001")
     * @param  string|null  $referenceType  Type of source document (e.g., "Document")
     * @param  string|null  $referenceId  UUID of source document for audit trail
     * @param  string|null  $variantId  Variant UUID when the line is for a specific variant; null = product-level stock
     */
    public function recordPurchase(
        Product $product,
        Location $location,
        float $quantity,
        float $landedUnitCost,
        ?string $reference = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $variantId = null,
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $landedUnitCost, $reference, $referenceType, $referenceId, $variantId): StockMovement {
            // Lock stock level first to prevent concurrent modifications.
            // company_id added to the tuple (api.inventory.032) so the lock
            // cannot be satisfied by a StockLevel row from another company
            // even if product_id + location_id happen to collide cross-company.
            // variant_id (Task 20) scopes the lock to the correct row: when a
            // line addresses a specific variant we must not land on the
            // product-level row (which has variant_id IS NULL).
            $stockLevel = StockLevel::where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->where('tenant_id', $product->tenant_id)
                ->where('company_id', $product->company_id)
                ->when(
                    $variantId !== null,
                    fn ($q) => $q->where('variant_id', $variantId),
                    fn ($q) => $q->whereNull('variant_id'),
                )
                ->lockForUpdate()
                ->first();

            if ($stockLevel === null) {
                $stockLevel = StockLevel::create([
                    'id' => Str::uuid()->toString(),
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'location_id' => $location->id,
                    'tenant_id' => $product->tenant_id,
                    'company_id' => $location->company_id,
                    'quantity' => '0',
                    'reserved' => '0',
                ]);
            }

            // Lock product for cost update — scope by the input product's
            // own tenant + company so the lock cannot escalate to a foreign
            // product (defense-in-depth on the upstream-trusted instance).
            $product = Product::query()
                ->where('tenant_id', $product->tenant_id)
                ->where('company_id', $product->company_id)
                ->lockForUpdate()
                ->findOrFail($product->id);

            $currentQty = (float) $stockLevel->quantity;
            $currentCostPrice = (float) ($product->cost_price ?? 0);
            $currentValue = $currentQty * $currentCostPrice;

            $newQty = $currentQty + $quantity;
            $newValue = $currentValue + ($quantity * $landedUnitCost);

            $newAvgCost = $newQty > 0 ? round($newValue / $newQty, $this->scale()) : 0;

            // Record movement (variant_id threaded from receipt line — Task 20)
            $movement = StockMovement::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'location_id' => $location->id,
                'company_id' => $location->company_id,
                'movement_type' => MovementType::Receipt,
                'quantity' => $quantity,
                'quantity_before' => $currentQty,
                'quantity_after' => $newQty,
                'unit_cost' => (string) $landedUnitCost,
                'total_cost' => (string) ($quantity * $landedUnitCost),
                'avg_cost_before' => (string) $currentCostPrice,
                'avg_cost_after' => (string) $newAvgCost,
                'reference' => $reference,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            // Update stock level
            $stockLevel->quantity = (string) $newQty;
            $stockLevel->save();

            // Update product cost
            $product->cost_price = (string) $newAvgCost;
            $product->last_purchase_cost = (string) $landedUnitCost;
            $product->cost_updated_at = now();
            $product->save();

            // Auto-update sale price based on new weighted average cost
            $oldSalePrice = (string) ($product->sale_price ?? '0.00');
            $priceUpdated = $this->marginService->updateSalePrice($product);

            // Capture data for events BEFORE afterCommit
            $movementSnapshot = $movement;
            /** @var Product $productSnapshot */
            $productSnapshot = $product->fresh();
            $locationSnapshot = $location;
            $newStockLevelSnapshot = $stockLevel->quantity;
            $priceUpdatedSnapshot = $priceUpdated;
            $oldCostPriceSnapshot = (string) $currentCostPrice;
            $newAvgCostSnapshot = (string) $newAvgCost;
            $oldSalePriceSnapshot = $oldSalePrice;
            $referenceSnapshot = $reference;

            // Emit audit event if prices changed - AFTER transaction commits
            if ($priceUpdatedSnapshot) {
                DB::afterCommit(function () use (
                    $productSnapshot,
                    $oldCostPriceSnapshot,
                    $newAvgCostSnapshot,
                    $oldSalePriceSnapshot,
                    $referenceSnapshot
                ): void {
                    event(new ProductCostPriceUpdated(
                        productId: $productSnapshot->id,
                        tenantId: $productSnapshot->tenant_id,
                        companyId: $productSnapshot->company_id,
                        productSku: $productSnapshot->sku,
                        oldCostPrice: $oldCostPriceSnapshot,
                        newCostPrice: $newAvgCostSnapshot,
                        oldSalePrice: $oldSalePriceSnapshot,
                        newSalePrice: (string) $productSnapshot->sale_price,
                        reason: 'purchase_receipt',
                        referenceDocument: $referenceSnapshot,
                    ));
                });
            }

            // Dispatch StockMovementRecorded event for audit trail - AFTER transaction commits
            DB::afterCommit(function () use (
                $movementSnapshot,
                $productSnapshot,
                $locationSnapshot,
                $newStockLevelSnapshot
            ): void {
                $this->dispatchStockMovementEvent(
                    movement: $movementSnapshot,
                    product: $productSnapshot,
                    location: $locationSnapshot,
                    movementType: 'purchase',
                    newStockLevel: $newStockLevelSnapshot
                );
            });

            return $movement;
        });
    }

    /**
     * Record a sale (cost comes out at current average)
     *
     * Uses pessimistic locking to prevent race conditions when multiple
     * sales happen concurrently for the same product.
     *
     * @param  Product  $product  The product being sold
     * @param  Location  $location  The location issuing the stock
     * @param  float  $quantity  Quantity being sold
     * @param  string|null  $reference  Human-readable reference (e.g., "DN-2025-001")
     * @param  string|null  $referenceType  Type of source document (e.g., "Document")
     * @param  string|null  $referenceId  UUID of source document for audit trail
     */
    public function recordSale(
        Product $product,
        Location $location,
        float $quantity,
        ?string $reference = null,
        ?string $referenceType = null,
        ?string $referenceId = null
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $reference, $referenceType, $referenceId): StockMovement {
            // Lock stock level to prevent concurrent modifications.
            // company_id added to the tuple (api.inventory.032).
            $stockLevel = StockLevel::where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->where('tenant_id', $product->tenant_id)
                ->where('company_id', $product->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Lock product to get consistent cost price — scoped to the
            // input product's own tenant + company.
            $product = Product::query()
                ->where('tenant_id', $product->tenant_id)
                ->where('company_id', $product->company_id)
                ->lockForUpdate()
                ->findOrFail($product->id);

            $costPrice = (float) ($product->cost_price ?? 0);
            $currentQty = (float) $stockLevel->quantity;
            $newQty = $currentQty - $quantity;

            // Validate sufficient stock
            if ($newQty < 0) {
                throw new \DomainException(
                    "Insufficient stock for product {$product->id}. Available: {$currentQty}, Requested: {$quantity}"
                );
            }

            $movement = StockMovement::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'company_id' => $location->company_id,
                'movement_type' => MovementType::Issue,
                'quantity' => -$quantity,
                'quantity_before' => $currentQty,
                'quantity_after' => $newQty,
                'unit_cost' => (string) $costPrice,
                'total_cost' => (string) ($quantity * $costPrice),
                'avg_cost_before' => (string) $costPrice,
                'avg_cost_after' => (string) $costPrice, // WAC doesn't change on sale
                'reference' => $reference,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            // Update stock level (cost stays same)
            $stockLevel->quantity = (string) $newQty;
            $stockLevel->save();

            // Capture data for events BEFORE afterCommit
            $movementSnapshot = $movement;
            $productSnapshot = $product;
            $locationSnapshot = $location;
            $newStockLevelSnapshot = $stockLevel->quantity;

            // Dispatch StockMovementRecorded event for audit trail - AFTER transaction commits
            DB::afterCommit(function () use (
                $movementSnapshot,
                $productSnapshot,
                $locationSnapshot,
                $newStockLevelSnapshot
            ): void {
                $this->dispatchStockMovementEvent(
                    movement: $movementSnapshot,
                    product: $productSnapshot,
                    location: $locationSnapshot,
                    movementType: 'sale',
                    newStockLevel: $newStockLevelSnapshot
                );
            });

            return $movement;
        });
    }

    /**
     * Record a return (stock comes back at original cost)
     *
     * Uses pessimistic locking to prevent race conditions.
     *
     * @param  Product  $product  The product being returned
     * @param  Location  $location  The location receiving the return
     * @param  float  $quantity  Quantity being returned
     * @param  float  $originalCost  Original cost of the returned items
     * @param  string|null  $reference  Human-readable reference (e.g., "RN-2025-001")
     * @param  string|null  $referenceType  Type of source document (e.g., "Document")
     * @param  string|null  $referenceId  UUID of source document for audit trail
     */
    public function recordReturn(
        Product $product,
        Location $location,
        float $quantity,
        float $originalCost,
        ?string $reference = null,
        ?string $referenceType = null,
        ?string $referenceId = null
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $originalCost, $reference, $referenceType, $referenceId): StockMovement {
            // Lock stock level first. company_id added to the tuple (api.inventory.032).
            $stockLevel = StockLevel::where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->where('tenant_id', $product->tenant_id)
                ->where('company_id', $product->company_id)
                ->lockForUpdate()
                ->first();

            if ($stockLevel === null) {
                $stockLevel = StockLevel::create([
                    'id' => Str::uuid()->toString(),
                    'product_id' => $product->id,
                    'location_id' => $location->id,
                    'tenant_id' => $product->tenant_id,
                    'company_id' => $location->company_id,
                    'quantity' => '0',
                    'reserved' => '0',
                ]);
            }

            // Lock product for cost update — scoped to the input product's
            // own tenant + company.
            $product = Product::query()
                ->where('tenant_id', $product->tenant_id)
                ->where('company_id', $product->company_id)
                ->lockForUpdate()
                ->findOrFail($product->id);

            $currentQty = (float) $stockLevel->quantity;
            $currentCostPrice = (float) ($product->cost_price ?? 0);
            $currentValue = $currentQty * $currentCostPrice;

            $newQty = $currentQty + $quantity;
            $newValue = $currentValue + ($quantity * $originalCost);

            $newAvgCost = $newQty > 0 ? round($newValue / $newQty, $this->scale()) : 0;

            // Record movement
            $movement = StockMovement::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'company_id' => $location->company_id,
                'movement_type' => MovementType::Receipt,
                'quantity' => $quantity,
                'quantity_before' => $currentQty,
                'quantity_after' => $newQty,
                'unit_cost' => (string) $originalCost,
                'total_cost' => (string) ($quantity * $originalCost),
                'avg_cost_before' => (string) $currentCostPrice,
                'avg_cost_after' => (string) $newAvgCost,
                'reference' => $reference,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            // Update stock level
            $stockLevel->quantity = (string) $newQty;
            $stockLevel->save();

            // Update product cost
            $product->cost_price = (string) $newAvgCost;
            $product->cost_updated_at = now();
            $product->save();

            // Auto-update sale price based on new weighted average cost
            $oldSalePrice = (string) ($product->sale_price ?? '0.00');
            $priceUpdated = $this->marginService->updateSalePrice($product);

            // Capture data for events BEFORE afterCommit
            $movementSnapshot = $movement;
            /** @var Product $productSnapshot */
            $productSnapshot = $product->fresh();
            $locationSnapshot = $location;
            $newStockLevelSnapshot = $stockLevel->quantity;
            $priceUpdatedSnapshot = $priceUpdated;
            $oldCostPriceSnapshot = (string) $currentCostPrice;
            $newAvgCostSnapshot = (string) $newAvgCost;
            $oldSalePriceSnapshot = $oldSalePrice;
            $referenceSnapshot = $reference;

            // Emit audit event if prices changed - AFTER transaction commits
            if ($priceUpdatedSnapshot) {
                DB::afterCommit(function () use (
                    $productSnapshot,
                    $oldCostPriceSnapshot,
                    $newAvgCostSnapshot,
                    $oldSalePriceSnapshot,
                    $referenceSnapshot
                ): void {
                    event(new ProductCostPriceUpdated(
                        productId: $productSnapshot->id,
                        tenantId: $productSnapshot->tenant_id,
                        companyId: $productSnapshot->company_id,
                        productSku: $productSnapshot->sku,
                        oldCostPrice: $oldCostPriceSnapshot,
                        newCostPrice: $newAvgCostSnapshot,
                        oldSalePrice: $oldSalePriceSnapshot,
                        newSalePrice: (string) $productSnapshot->sale_price,
                        reason: 'product_return',
                        referenceDocument: $referenceSnapshot,
                    ));
                });
            }

            // Dispatch StockMovementRecorded event for audit trail - AFTER transaction commits
            DB::afterCommit(function () use (
                $movementSnapshot,
                $productSnapshot,
                $locationSnapshot,
                $newStockLevelSnapshot
            ): void {
                $this->dispatchStockMovementEvent(
                    movement: $movementSnapshot,
                    product: $productSnapshot,
                    location: $locationSnapshot,
                    movementType: 'return',
                    newStockLevel: $newStockLevelSnapshot
                );
            });

            return $movement;
        });
    }

    /**
     * Calculate what the new weighted average cost would be without recording
     */
    public function calculateNewWAC(
        float $currentQty,
        float $currentCost,
        float $newQty,
        float $newCost
    ): float {
        $currentValue = $currentQty * $currentCost;
        $newValue = $newQty * $newCost;
        $totalQty = $currentQty + $newQty;

        if ($totalQty <= 0) {
            return 0;
        }

        return round(($currentValue + $newValue) / $totalQty, $this->scale());
    }

    /**
     * Dispatch StockMovementRecorded event for audit trail.
     *
     * @param  StockMovement  $movement  The stock movement record
     * @param  Product  $product  The product being moved
     * @param  Location  $location  The location of the movement
     * @param  string  $movementType  Type of movement (purchase, sale, return)
     * @param  string  $newStockLevel  The new stock level after movement
     */
    private function dispatchStockMovementEvent(
        StockMovement $movement,
        Product $product,
        Location $location,
        string $movementType,
        string $newStockLevel
    ): void {
        event(new StockMovementRecorded(
            movementId: $movement->id,
            tenantId: $product->tenant_id,
            companyId: $product->company_id,
            productId: $product->id,
            locationId: $location->id,
            movementType: $movementType,
            quantity: (string) $movement->quantity,
            unitCost: (string) $movement->unit_cost,
            totalCost: (string) $movement->total_cost,
            newStockLevel: $newStockLevel,
            reference: $movement->reference,
            referenceType: $movement->reference_type,
            referenceId: $movement->reference_id,
            occurredAt: now()->toIso8601String(),
        ));

        // V2 dual-dispatch (variant-aware). The variant is read from the
        // persisted movement row (variant_id is written by Task 15); it may be
        // null for product-level movements, which is expected.
        event(new StockMovementRecordedV2(
            movementId: $movement->id,
            tenantId: $product->tenant_id,
            companyId: $product->company_id,
            productId: $product->id,
            locationId: $location->id,
            movementType: $movementType,
            quantity: (string) $movement->quantity,
            unitCost: (string) $movement->unit_cost,
            totalCost: (string) $movement->total_cost,
            newStockLevel: $newStockLevel,
            variantId: $movement->variant_id,
            reference: $movement->reference,
            referenceType: $movement->reference_type,
            referenceId: $movement->reference_id,
            occurredAt: now()->toIso8601String(),
        ));
    }
}
