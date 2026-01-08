<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Taxation\Domain\DTOs\TaxCalculationResult;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use Illuminate\Support\Facades\DB;

/**
 * LandedCostService - Allocates additional costs and non-recoverable taxes to purchase order lines
 *
 * Landed Cost = Line Total + Allocated Additional Costs + Allocated Non-Recoverable Taxes
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
     * Allocate additional costs AND non-recoverable taxes to purchase order lines
     *
     * This is the primary method for purchase orders - it handles both:
     * 1. Additional costs (freight, insurance, etc.)
     * 2. Non-recoverable document-level taxes (stamp duties, VAT for non-registered companies)
     *
     * Both are distributed proportionally by line value, and both contribute to landed unit cost.
     *
     * @param  Document  $purchaseOrder  The purchase order
     * @param  TaxCalculationResult  $taxResult  Tax calculation result from TaxCalculationService
     */
    public function allocateCostsAndTaxes(Document $purchaseOrder, TaxCalculationResult $taxResult): void
    {
        DB::transaction(function () use ($purchaseOrder, $taxResult): void {
            $lines = $purchaseOrder->lines;
            $additionalCostsTotal = (float) $purchaseOrder->additionalCosts()->sum('amount');
            $subtotal = (float) $lines->sum('line_total');

            // Separate line-level taxes from document-level taxes
            // Line-level taxes (VAT) must stay with their specific lines
            // Document-level taxes (stamps) should be distributed proportionally
            $nonRecoverableDocumentTaxTotal = 0;
            foreach ($taxResult->taxes as $tax) {
                if (! $tax->isRecoverable && $tax->appliesTo === TaxApplicationLevel::DocumentTotal) {
                    $nonRecoverableDocumentTaxTotal += (float) $tax->amount;
                }
            }

            $totalNonRecoverableTax = 0;

            // Allocate to each line
            foreach ($lines as $line) {
                // Calculate proportion for distributing document-level taxes and additional costs
                if ($subtotal > 0) {
                    $proportion = (float) $line->line_total / $subtotal;
                } else {
                    $proportion = 0;
                }

                // Allocate additional costs proportionally
                $allocatedCost = $additionalCostsTotal > 0
                    ? round($additionalCostsTotal * $proportion, 3)
                    : 0;

                // Calculate line-specific non-recoverable tax (VAT based on line's tax_rate)
                // This tax is already in line_total, but we track it separately for inventory costing
                $lineNonRecoverableTax = 0;
                if ($line->tax_rate && (float) $line->tax_rate > 0) {
                    // Check if this line's tax rate is non-recoverable
                    $lineTaxRate = (string) $line->tax_rate;
                    foreach ($taxResult->taxes as $tax) {
                        if (! $tax->isRecoverable
                            && $tax->appliesTo === TaxApplicationLevel::LineItems
                            && bccomp((string) $tax->rate, $lineTaxRate, 2) === 0) {
                            // Calculate this line's portion of the non-recoverable tax
                            $lineSubtotal = bcmul((string) $line->quantity, (string) $line->unit_price, 3);
                            $lineNonRecoverableTax = (float) bcmul(
                                $lineSubtotal,
                                bcdiv($lineTaxRate, '100', 6),
                                3
                            );
                            break;
                        }
                    }
                }

                // Allocate proportional share of document-level non-recoverable taxes
                $allocatedDocumentTax = $nonRecoverableDocumentTaxTotal > 0
                    ? round($nonRecoverableDocumentTaxTotal * $proportion, 3)
                    : 0;

                // Total non-recoverable tax for this line
                $totalLineTax = $lineNonRecoverableTax + $allocatedDocumentTax;
                $totalNonRecoverableTax += $totalLineTax;

                // Update line
                $line->allocated_costs = (string) $allocatedCost;
                $line->non_recoverable_tax = (string) round($totalLineTax, 3);

                // Calculate landed unit cost: (line_total + allocated_costs + non_recoverable_tax) / quantity
                $totalCost = (float) $line->line_total + $allocatedCost + $totalLineTax;
                $line->landed_unit_cost = (float) $line->quantity > 0
                    ? (string) round($totalCost / (float) $line->quantity, 3)
                    : $line->unit_price;

                $line->save();
            }

            // Update PO metadata
            $purchaseOrder->update([
                'payload' => array_merge($purchaseOrder->payload ?? [], [
                    'costs_allocated_at' => now()->toDateTimeString(),
                    'costs_allocated_total' => (string) $additionalCostsTotal,
                    'non_recoverable_tax_total' => (string) round($totalNonRecoverableTax, 3),
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
     * @return array<int, array{line_id: string, product_name: string, quantity: numeric-string, unit_price: numeric-string, line_total: numeric-string, allocated_costs: numeric-string, non_recoverable_tax: numeric-string, landed_unit_cost: numeric-string}>
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
                'allocated_costs' => $line->allocated_costs ?? '0.000',
                'non_recoverable_tax' => $line->non_recoverable_tax ?? '0.000',
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
     *
     * @param  float  $lineTotal  Line total (quantity × unit_price)
     * @param  float  $allocatedCost  Allocated additional costs
     * @param  float  $nonRecoverableTax  Allocated non-recoverable taxes
     * @param  float  $quantity  Quantity
     */
    public function calculateLandedUnitCost(
        float $lineTotal,
        float $allocatedCost,
        float $nonRecoverableTax,
        float $quantity
    ): float {
        if ($quantity <= 0) {
            return 0;
        }

        return round(($lineTotal + $allocatedCost + $nonRecoverableTax) / $quantity, 3);
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
