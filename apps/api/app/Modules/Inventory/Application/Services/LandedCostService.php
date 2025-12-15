<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Document;
use Illuminate\Support\Facades\DB;

/**
 * LandedCostService - Allocates additional costs to purchase order lines
 *
 * IMPORTANT: Cost allocation is wrapped in a transaction to ensure
 * all-or-nothing allocation. If any line fails, the entire allocation
 * is rolled back to prevent partial/inconsistent cost assignments.
 */
class LandedCostService
{
    /**
     * Allocate additional costs to purchase order lines proportionally by value
     *
     * Uses a database transaction to ensure atomic allocation.
     * If any line fails to save, all allocations are rolled back.
     */
    public function allocateCosts(Document $purchaseOrder): void
    {
        DB::transaction(function () use ($purchaseOrder): void {
            $lines = $purchaseOrder->lines;
            $additionalCostsTotal = (float) $purchaseOrder->additionalCosts()->sum('amount');
            $subtotal = (float) $lines->sum('line_total');

            foreach ($lines as $line) {
                if ($subtotal > 0 && $additionalCostsTotal > 0) {
                    $proportion = (float) $line->line_total / $subtotal;
                    $allocatedCost = round($additionalCostsTotal * $proportion, 2);
                } else {
                    $allocatedCost = 0;
                }

                $line->allocated_costs = (string) $allocatedCost;
                $line->landed_unit_cost = (float) $line->quantity > 0
                    ? (string) round(((float) $line->line_total + $allocatedCost) / (float) $line->quantity, 2)
                    : $line->unit_price;
                $line->save();
            }

            // Mark PO with timestamp of cost allocation for audit trail
            $purchaseOrder->update([
                'payload' => array_merge($purchaseOrder->payload ?? [], [
                    'costs_allocated_at' => now()->toDateTimeString(),
                    'costs_allocated_total' => (string) $additionalCostsTotal,
                ]),
            ]);
        });
    }

    /**
     * Re-allocate costs when additional costs are modified after initial allocation
     *
     * This can be called when costs are added/modified before goods receipt.
     */
    public function reallocateCosts(Document $purchaseOrder): void
    {
        DB::transaction(function () use ($purchaseOrder): void {
            $lines = $purchaseOrder->lines;
            $additionalCostsTotal = (float) $purchaseOrder->additionalCosts()->sum('amount');
            $subtotal = (float) $lines->sum('line_total');

            foreach ($lines as $line) {
                if ($subtotal > 0 && $additionalCostsTotal > 0) {
                    $proportion = (float) $line->line_total / $subtotal;
                    $allocatedCost = round($additionalCostsTotal * $proportion, 2);
                } else {
                    $allocatedCost = 0;
                }

                $line->allocated_costs = (string) $allocatedCost;
                $line->landed_unit_cost = (float) $line->quantity > 0
                    ? (string) round(((float) $line->line_total + $allocatedCost) / (float) $line->quantity, 2)
                    : $line->unit_price;
                $line->save();
            }

            // Update reallocation timestamp
            $purchaseOrder->update([
                'payload' => array_merge($purchaseOrder->payload ?? [], [
                    'costs_reallocated_at' => now()->toDateTimeString(),
                    'costs_allocated_total' => (string) $additionalCostsTotal,
                ]),
            ]);
        });
    }

    /**
     * Get breakdown of cost allocation for display
     *
     * @return array<int, array{line_id: string, product_name: string, quantity: numeric-string, unit_price: numeric-string, line_total: numeric-string, allocated_costs: numeric-string, landed_unit_cost: numeric-string}>
     */
    public function getAllocationBreakdown(Document $purchaseOrder): array
    {
        $result = [];

        foreach ($purchaseOrder->lines as $line) {
            $result[] = [
                'line_id' => $line->id,
                'product_name' => $line->product->name ?? $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
                'allocated_costs' => $line->allocated_costs ?? '0.00',
                'landed_unit_cost' => $line->landed_unit_cost ?? $line->unit_price,
            ];
        }

        return $result;
    }

    /**
     * Calculate what the allocated cost would be for a line without saving
     */
    public function calculateAllocatedCost(float $lineTotal, float $subtotal, float $additionalCostsTotal): float
    {
        if ($subtotal <= 0 || $additionalCostsTotal <= 0) {
            return 0;
        }

        $proportion = $lineTotal / $subtotal;

        return round($additionalCostsTotal * $proportion, 2);
    }

    /**
     * Calculate landed unit cost for a line
     */
    public function calculateLandedUnitCost(float $lineTotal, float $allocatedCost, float $quantity): float
    {
        if ($quantity <= 0) {
            return 0;
        }

        return round(($lineTotal + $allocatedCost) / $quantity, 2);
    }

    /**
     * Check if costs have been allocated for this PO
     */
    public function hasAllocatedCosts(Document $purchaseOrder): bool
    {
        $payload = $purchaseOrder->payload ?? [];

        return isset($payload['costs_allocated_at']);
    }

    /**
     * Check if goods have been received (costs should not be reallocated after)
     */
    public function canModifyCosts(Document $purchaseOrder): bool
    {
        $payload = $purchaseOrder->payload ?? [];

        // Cannot modify costs after goods are received
        return ! isset($payload['goods_received_at']);
    }
}
