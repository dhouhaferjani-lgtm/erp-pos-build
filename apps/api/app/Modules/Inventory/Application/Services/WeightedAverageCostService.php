<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
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
    /**
     * Record a purchase and update weighted average cost
     *
     * Uses pessimistic locking to prevent race conditions when multiple
     * purchase receipts happen concurrently for the same product.
     */
    public function recordPurchase(
        Product $product,
        Location $location,
        float $quantity,
        float $landedUnitCost,
        ?string $reference = null
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $landedUnitCost, $reference): StockMovement {
            // Lock stock level first to prevent concurrent modifications
            $stockLevel = StockLevel::where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->where('tenant_id', $product->tenant_id)
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

            // Lock product for cost update
            $product = Product::lockForUpdate()->findOrFail($product->id);

            $currentQty = (float) $stockLevel->quantity;
            $currentCostPrice = (float) ($product->cost_price ?? 0);
            $currentValue = $currentQty * $currentCostPrice;

            $newQty = $currentQty + $quantity;
            $newValue = $currentValue + ($quantity * $landedUnitCost);

            $newAvgCost = $newQty > 0 ? round($newValue / $newQty, 2) : 0;

            // Record movement
            $movement = StockMovement::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'company_id' => $location->company_id,
                'movement_type' => 'purchase',
                'quantity' => $quantity,
                'quantity_before' => $currentQty,
                'quantity_after' => $newQty,
                'unit_cost' => (string) $landedUnitCost,
                'total_cost' => (string) ($quantity * $landedUnitCost),
                'avg_cost_before' => (string) $currentCostPrice,
                'avg_cost_after' => (string) $newAvgCost,
                'reference' => $reference,
            ]);

            // Update stock level
            $stockLevel->quantity = (string) $newQty;
            $stockLevel->save();

            // Update product cost
            $product->cost_price = (string) $newAvgCost;
            $product->last_purchase_cost = (string) $landedUnitCost;
            $product->cost_updated_at = now();
            $product->save();

            return $movement;
        });
    }

    /**
     * Record a sale (cost comes out at current average)
     *
     * Uses pessimistic locking to prevent race conditions when multiple
     * sales happen concurrently for the same product.
     */
    public function recordSale(
        Product $product,
        Location $location,
        float $quantity,
        ?string $reference = null
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $reference): StockMovement {
            // Lock stock level to prevent concurrent modifications
            $stockLevel = StockLevel::where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->where('tenant_id', $product->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Lock product to get consistent cost price
            $product = Product::lockForUpdate()->findOrFail($product->id);

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
                'movement_type' => 'sale',
                'quantity' => -$quantity,
                'quantity_before' => $currentQty,
                'quantity_after' => $newQty,
                'unit_cost' => (string) $costPrice,
                'total_cost' => (string) ($quantity * $costPrice),
                'avg_cost_before' => (string) $costPrice,
                'avg_cost_after' => (string) $costPrice, // WAC doesn't change on sale
                'reference' => $reference,
            ]);

            // Update stock level (cost stays same)
            $stockLevel->quantity = (string) $newQty;
            $stockLevel->save();

            return $movement;
        });
    }

    /**
     * Record a return (stock comes back at original cost)
     *
     * Uses pessimistic locking to prevent race conditions.
     */
    public function recordReturn(
        Product $product,
        Location $location,
        float $quantity,
        float $originalCost,
        ?string $reference = null
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $originalCost, $reference): StockMovement {
            // Lock stock level first
            $stockLevel = StockLevel::where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->where('tenant_id', $product->tenant_id)
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

            // Lock product for cost update
            $product = Product::lockForUpdate()->findOrFail($product->id);

            $currentQty = (float) $stockLevel->quantity;
            $currentCostPrice = (float) ($product->cost_price ?? 0);
            $currentValue = $currentQty * $currentCostPrice;

            $newQty = $currentQty + $quantity;
            $newValue = $currentValue + ($quantity * $originalCost);

            $newAvgCost = $newQty > 0 ? round($newValue / $newQty, 2) : 0;

            // Record movement
            $movement = StockMovement::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'company_id' => $location->company_id,
                'movement_type' => 'return',
                'quantity' => $quantity,
                'quantity_before' => $currentQty,
                'quantity_after' => $newQty,
                'unit_cost' => (string) $originalCost,
                'total_cost' => (string) ($quantity * $originalCost),
                'avg_cost_before' => (string) $currentCostPrice,
                'avg_cost_after' => (string) $newAvgCost,
                'reference' => $reference,
            ]);

            // Update stock level
            $stockLevel->quantity = (string) $newQty;
            $stockLevel->save();

            // Update product cost
            $product->cost_price = (string) $newAvgCost;
            $product->cost_updated_at = now();
            $product->save();

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

        return round(($currentValue + $newValue) / $totalQty, 2);
    }
}
