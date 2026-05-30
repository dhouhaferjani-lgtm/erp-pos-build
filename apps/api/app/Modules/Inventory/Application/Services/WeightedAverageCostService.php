<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Events\ProductCostPriceUpdated;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
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
     * Intermediate working precision for WAC blending.
     *
     * WAC multiplies quantities (scale 4) by unit costs and divides running
     * values; carrying 4 extra digits beyond the currency scale preserves the
     * exact intermediate value so the single boundary truncation is faithful.
     */
    private function workingScale(): int
    {
        return $this->scale() + 4;
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
     */
    public function recordPurchase(
        Product $product,
        Location $location,
        float $quantity,
        float $landedUnitCost,
        ?string $reference = null,
        ?string $referenceType = null,
        ?string $referenceId = null
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $landedUnitCost, $reference, $referenceType, $referenceId): StockMovement {
            // Lock stock level first to prevent concurrent modifications.
            // company_id added to the tuple (api.inventory.032) so the lock
            // cannot be satisfied by a StockLevel row from another company
            // even if product_id + location_id happen to collide cross-company.
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

            // Lock product for cost update — scope by the input product's
            // own tenant + company so the lock cannot escalate to a foreign
            // product (defense-in-depth on the upstream-trusted instance).
            $product = Product::query()
                ->where('tenant_id', $product->tenant_id)
                ->where('company_id', $product->company_id)
                ->lockForUpdate()
                ->findOrFail($product->id);

            $working = $this->workingScale();

            // Normalise every operand into a numeric string before any
            // arithmetic; quantities carry scale 4, monetary values the
            // working precision. No native float math touches WAC.
            $quantityStr = CurrencyScale::bcformat($quantity, 4);
            $landedUnitCostStr = CurrencyScale::bcformat($landedUnitCost, $working);
            $currentQty = CurrencyScale::bcformat($stockLevel->quantity, 4);
            $currentCostPrice = CurrencyScale::bcformat($product->cost_price ?? '0', $working);
            $currentValue = bcmul($currentQty, $currentCostPrice, $working);

            $newQty = bcadd($currentQty, $quantityStr, 4);
            $newValue = bcadd($currentValue, bcmul($quantityStr, $landedUnitCostStr, $working), $working);

            // Single boundary truncation to the currency scale.
            $newAvgCost = bccomp($newQty, '0', 4) > 0
                ? CurrencyScale::bcformat(bcdiv($newValue, $newQty, $working), $this->scale())
                : CurrencyScale::bcformat('0', $this->scale());

            // Record movement
            $movement = StockMovement::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'company_id' => $location->company_id,
                'movement_type' => MovementType::Receipt,
                'quantity' => $quantityStr,
                'quantity_before' => $currentQty,
                'quantity_after' => $newQty,
                'unit_cost' => CurrencyScale::bcformat($landedUnitCostStr, $this->scale()),
                'total_cost' => CurrencyScale::bcformat(bcmul($quantityStr, $landedUnitCostStr, $working), $this->scale()),
                'avg_cost_before' => CurrencyScale::bcformat($currentCostPrice, $this->scale()),
                'avg_cost_after' => $newAvgCost,
                'reference' => $reference,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            // Update stock level
            $stockLevel->quantity = $newQty;
            $stockLevel->save();

            // Update product cost
            $product->cost_price = $newAvgCost;
            $product->last_purchase_cost = CurrencyScale::bcformat($landedUnitCostStr, $this->scale());
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
            $oldCostPriceSnapshot = CurrencyScale::bcformat($currentCostPrice, $this->scale());
            $newAvgCostSnapshot = $newAvgCost;
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

            $working = $this->workingScale();

            $quantityStr = CurrencyScale::bcformat($quantity, 4);
            $costPriceStr = CurrencyScale::bcformat($product->cost_price ?? '0', $working);
            $costPriceBoundary = CurrencyScale::bcformat($costPriceStr, $this->scale());
            $currentQty = CurrencyScale::bcformat($stockLevel->quantity, 4);
            $newQty = bcsub($currentQty, $quantityStr, 4);

            // Validate sufficient stock
            if (bccomp($newQty, '0', 4) < 0) {
                throw new \DomainException(
                    "Insufficient stock for product {$product->id}. Available: {$currentQty}, Requested: {$quantityStr}"
                );
            }

            $movement = StockMovement::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'company_id' => $location->company_id,
                'movement_type' => MovementType::Issue,
                'quantity' => bcmul($quantityStr, '-1', 4),
                'quantity_before' => $currentQty,
                'quantity_after' => $newQty,
                'unit_cost' => $costPriceBoundary,
                'total_cost' => CurrencyScale::bcformat(bcmul($quantityStr, $costPriceStr, $working), $this->scale()),
                'avg_cost_before' => $costPriceBoundary,
                'avg_cost_after' => $costPriceBoundary, // WAC doesn't change on sale
                'reference' => $reference,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            // Update stock level (cost stays same)
            $stockLevel->quantity = $newQty;
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

            $working = $this->workingScale();

            // Normalise operands to numeric strings; quantity at scale 4,
            // monetary values at the working precision. No native float math.
            $quantityStr = CurrencyScale::bcformat($quantity, 4);
            $originalCostStr = CurrencyScale::bcformat($originalCost, $working);
            $currentQty = CurrencyScale::bcformat($stockLevel->quantity, 4);
            $currentCostPrice = CurrencyScale::bcformat($product->cost_price ?? '0', $working);
            $currentValue = bcmul($currentQty, $currentCostPrice, $working);

            $newQty = bcadd($currentQty, $quantityStr, 4);
            $newValue = bcadd($currentValue, bcmul($quantityStr, $originalCostStr, $working), $working);

            // Single boundary truncation to the currency scale.
            $newAvgCost = bccomp($newQty, '0', 4) > 0
                ? CurrencyScale::bcformat(bcdiv($newValue, $newQty, $working), $this->scale())
                : CurrencyScale::bcformat('0', $this->scale());

            // Record movement
            $movement = StockMovement::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'company_id' => $location->company_id,
                'movement_type' => MovementType::Receipt,
                'quantity' => $quantityStr,
                'quantity_before' => $currentQty,
                'quantity_after' => $newQty,
                'unit_cost' => CurrencyScale::bcformat($originalCostStr, $this->scale()),
                'total_cost' => CurrencyScale::bcformat(bcmul($quantityStr, $originalCostStr, $working), $this->scale()),
                'avg_cost_before' => CurrencyScale::bcformat($currentCostPrice, $this->scale()),
                'avg_cost_after' => $newAvgCost,
                'reference' => $reference,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            // Update stock level
            $stockLevel->quantity = $newQty;
            $stockLevel->save();

            // Update product cost
            $product->cost_price = $newAvgCost;
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
            $oldCostPriceSnapshot = CurrencyScale::bcformat($currentCostPrice, $this->scale());
            $newAvgCostSnapshot = $newAvgCost;
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
        $working = $this->workingScale();

        $currentQtyStr = CurrencyScale::bcformat($currentQty, 4);
        $newQtyStr = CurrencyScale::bcformat($newQty, 4);
        $currentCostStr = CurrencyScale::bcformat($currentCost, $working);
        $newCostStr = CurrencyScale::bcformat($newCost, $working);

        $totalQty = bcadd($currentQtyStr, $newQtyStr, 4);

        if (bccomp($totalQty, '0', 4) <= 0) {
            return 0.0;
        }

        $currentValue = bcmul($currentQtyStr, $currentCostStr, $working);
        $newValue = bcmul($newQtyStr, $newCostStr, $working);
        $blended = bcdiv(bcadd($currentValue, $newValue, $working), $totalQty, $working);

        // Boundary-truncate to currency scale, then surface as float to keep
        // this preview helper's published float return type stable.
        return (float) CurrencyScale::bcformat($blended, $this->scale());
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
    }
}
