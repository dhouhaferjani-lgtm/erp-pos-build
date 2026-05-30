<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Taxation\Domain\DTOs\TaxCalculationResult;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LandedCostService - Allocates additional costs and non-recoverable taxes to purchase order lines
 *
 * Landed Cost = Line Total + Allocated Additional Costs + Allocated Non-Recoverable Taxes
 *
 * IMPORTANT: Cost allocation is wrapped in a transaction to ensure
 * all-or-nothing allocation. If any line fails, the entire allocation
 * is rolled back to prevent partial/inconsistent cost assignments.
 *
 * Precision: all arithmetic is bcmath. Intermediate values carry
 * {@see self::workingScale()} (currency scale + 4) extra digits and are
 * truncated to the currency scale exactly once at the boundary. Proportional
 * allocations use a largest-remainder reconciliation: the running remainder is
 * assigned to the absorber line — the last line with a positive base (see
 * {@see self::absorberIndex()}) — so the sum of allocated columns equals the
 * input total to the last unit, the residue never lands on a zero-base line,
 * and line order/keying cannot drop it.
 */
class LandedCostService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Intermediate working precision for proportional cost allocation.
     */
    private function workingScale(): int
    {
        return $this->scale() + 4;
    }

    /**
     * Allocate additional costs to purchase order lines proportionally by value
     *
     * Uses a database transaction to ensure atomic allocation.
     * If any line fails to save, all allocations are rolled back.
     */
    public function allocateCosts(Document $purchaseOrder): void
    {
        DB::transaction(function () use ($purchaseOrder): void {
            $scale = $this->scale();
            $working = $this->workingScale();

            // Re-key to a contiguous 0-based sequence so the largest-remainder
            // "is this the last line" check is robust even if the relation was
            // filtered/keyed by a caller (otherwise the remainder could be dropped).
            $lines = $purchaseOrder->lines->values();
            $additionalCostsTotal = CurrencyScale::bcformat(
                (string) $purchaseOrder->additionalCosts()->sum('amount'),
                $scale,
            );
            $subtotal = $this->sumLineTotals($lines);

            $remaining = $additionalCostsTotal;
            $absorberIndex = $this->absorberIndex($lines);

            foreach ($lines as $index => $line) {
                $lineTotal = CurrencyScale::bcformat((string) $line->line_total, $working);

                if (bccomp($subtotal, '0', $working) > 0 && bccomp($additionalCostsTotal, '0', $scale) > 0) {
                    $allocatedCost = $this->allocateShare(
                        index: $index,
                        absorberIndex: $absorberIndex,
                        lineTotal: $lineTotal,
                        subtotal: $subtotal,
                        total: $additionalCostsTotal,
                        remaining: $remaining,
                    );
                } else {
                    $allocatedCost = CurrencyScale::bcformat('0', $scale);
                }

                $remaining = bcsub($remaining, $allocatedCost, $scale);

                $line->allocated_costs = $allocatedCost;
                $line->landed_unit_cost = $this->landedUnitCost($lineTotal, $allocatedCost, '0', $line);
                $line->save();
            }

            // Mark PO with timestamp of cost allocation for audit trail
            $purchaseOrder->update([
                'payload' => array_merge($purchaseOrder->payload ?? [], [
                    'costs_allocated_at' => now()->toDateTimeString(),
                    'costs_allocated_total' => $additionalCostsTotal,
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
            $scale = $this->scale();
            $working = $this->workingScale();

            // Re-key to a contiguous 0-based sequence (see allocateCosts()).
            $lines = $purchaseOrder->lines->values();
            $additionalCostsTotal = CurrencyScale::bcformat(
                (string) $purchaseOrder->additionalCosts()->sum('amount'),
                $scale,
            );
            $subtotal = $this->sumLineTotals($lines);

            // Separate line-level taxes from document-level taxes.
            // Line-level taxes (VAT) must stay with their specific lines.
            // Document-level taxes (stamps) should be distributed proportionally.
            $nonRecoverableDocumentTaxTotal = CurrencyScale::bcformat('0', $scale);
            foreach ($taxResult->taxes as $tax) {
                if (! $tax->isRecoverable && $tax->appliesTo === TaxApplicationLevel::DocumentTotal) {
                    $nonRecoverableDocumentTaxTotal = bcadd(
                        $nonRecoverableDocumentTaxTotal,
                        CurrencyScale::bcformat((string) $tax->amount, $scale),
                        $scale,
                    );
                }
            }

            $totalNonRecoverableTax = CurrencyScale::bcformat('0', $scale);

            $remainingCost = $additionalCostsTotal;
            $remainingDocTax = $nonRecoverableDocumentTaxTotal;
            $absorberIndex = $this->absorberIndex($lines);

            // Allocate to each line
            foreach ($lines as $index => $line) {
                $lineTotal = CurrencyScale::bcformat((string) $line->line_total, $working);

                $hasSubtotal = bccomp($subtotal, '0', $working) > 0;

                // Allocate additional costs proportionally (largest-remainder).
                $allocatedCost = ($hasSubtotal && bccomp($additionalCostsTotal, '0', $scale) > 0)
                    ? $this->allocateShare($index, $absorberIndex, $lineTotal, $subtotal, $additionalCostsTotal, $remainingCost)
                    : CurrencyScale::bcformat('0', $scale);
                $remainingCost = bcsub($remainingCost, $allocatedCost, $scale);

                // Calculate line-specific non-recoverable tax (VAT based on line's tax_rate).
                // This tax is already in line_total, but we track it separately for inventory costing.
                $lineNonRecoverableTax = CurrencyScale::bcformat('0', $scale);
                if ($line->tax_rate !== null && bccomp($line->tax_rate, '0', 6) > 0) {
                    $lineTaxRate = CurrencyScale::bcformat($line->tax_rate, $working);
                    foreach ($taxResult->taxes as $tax) {
                        if ($tax->rate === null) {
                            continue;
                        }
                        $taxRate = CurrencyScale::bcformat($tax->rate, $working);
                        if (! $tax->isRecoverable
                            && $tax->appliesTo === TaxApplicationLevel::LineItems
                            && bccomp($taxRate, $lineTaxRate, 2) === 0) {
                            // This line's portion of the non-recoverable tax.
                            $lineSubtotal = bcmul((string) $line->quantity, (string) $line->unit_price, $working);
                            $lineNonRecoverableTax = CurrencyScale::bcformat(
                                bcmul($lineSubtotal, bcdiv($lineTaxRate, '100', $working), $working),
                                $scale,
                            );
                            break;
                        }
                    }
                }

                // Allocate proportional share of document-level non-recoverable taxes.
                $allocatedDocumentTax = ($hasSubtotal && bccomp($nonRecoverableDocumentTaxTotal, '0', $scale) > 0)
                    ? $this->allocateShare($index, $absorberIndex, $lineTotal, $subtotal, $nonRecoverableDocumentTaxTotal, $remainingDocTax)
                    : CurrencyScale::bcformat('0', $scale);
                $remainingDocTax = bcsub($remainingDocTax, $allocatedDocumentTax, $scale);

                // Total non-recoverable tax for this line.
                $totalLineTax = bcadd($lineNonRecoverableTax, $allocatedDocumentTax, $scale);
                $totalNonRecoverableTax = bcadd($totalNonRecoverableTax, $totalLineTax, $scale);

                // Update line.
                $line->allocated_costs = $allocatedCost;
                $line->non_recoverable_tax = $totalLineTax;
                $line->landed_unit_cost = $this->landedUnitCost($lineTotal, $allocatedCost, $totalLineTax, $line);

                $line->save();
            }

            // Update PO metadata
            $purchaseOrder->update([
                'payload' => array_merge($purchaseOrder->payload ?? [], [
                    'costs_allocated_at' => now()->toDateTimeString(),
                    'costs_allocated_total' => $additionalCostsTotal,
                    'non_recoverable_tax_total' => $totalNonRecoverableTax,
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
            $scale = $this->scale();
            $working = $this->workingScale();

            // Re-key to a contiguous 0-based sequence (see allocateCosts()).
            $lines = $purchaseOrder->lines->values();
            $additionalCostsTotal = CurrencyScale::bcformat(
                (string) $purchaseOrder->additionalCosts()->sum('amount'),
                $scale,
            );
            $subtotal = $this->sumLineTotals($lines);

            $remaining = $additionalCostsTotal;
            $absorberIndex = $this->absorberIndex($lines);

            foreach ($lines as $index => $line) {
                $lineTotal = CurrencyScale::bcformat((string) $line->line_total, $working);

                if (bccomp($subtotal, '0', $working) > 0 && bccomp($additionalCostsTotal, '0', $scale) > 0) {
                    $allocatedCost = $this->allocateShare(
                        index: $index,
                        absorberIndex: $absorberIndex,
                        lineTotal: $lineTotal,
                        subtotal: $subtotal,
                        total: $additionalCostsTotal,
                        remaining: $remaining,
                    );
                } else {
                    $allocatedCost = CurrencyScale::bcformat('0', $scale);
                }

                $remaining = bcsub($remaining, $allocatedCost, $scale);

                $line->allocated_costs = $allocatedCost;
                $line->landed_unit_cost = $this->landedUnitCost($lineTotal, $allocatedCost, '0', $line);
                $line->save();
            }

            // Update reallocation timestamp
            $purchaseOrder->update([
                'payload' => array_merge($purchaseOrder->payload ?? [], [
                    'costs_reallocated_at' => now()->toDateTimeString(),
                    'costs_allocated_total' => $additionalCostsTotal,
                ]),
            ]);
        });
    }

    /**
     * Sum the line_total of a collection at the working precision.
     *
     * @param  iterable<int, DocumentLine>  $lines
     * @return numeric-string
     */
    private function sumLineTotals(iterable $lines): string
    {
        $working = $this->workingScale();
        $subtotal = CurrencyScale::bcformat('0', $working);

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, CurrencyScale::bcformat((string) $line->line_total, $working), $working);
        }

        return $subtotal;
    }

    /**
     * Index of the line that absorbs the largest-remainder residue.
     *
     * The residue must land on a line that actually participates in the
     * proportional split, i.e. one with a positive line_total. A zero-base line
     * gets a zero proportional share, so dumping the whole rounding residue on it
     * would inflate its landed unit cost from a base of nothing (e.g. a free /
     * promotional line). We therefore pick the LAST line with line_total > 0.
     *
     * Lines are assumed to be 0-indexed contiguously (callers pass ->values()).
     * If every line has a zero base the proportional branch is never entered
     * (subtotal must be > 0 to allocate), so the fallback to the last index is
     * inert; we return it only to keep the absorber well-defined.
     *
     * @param  Collection<int, DocumentLine>  $lines
     */
    private function absorberIndex(Collection $lines): int
    {
        $working = $this->workingScale();
        $absorber = $lines->count() - 1;

        foreach ($lines as $index => $line) {
            $lineTotal = CurrencyScale::bcformat((string) $line->line_total, $working);
            if (bccomp($lineTotal, '0', $working) > 0) {
                $absorber = $index;
            }
        }

        return $absorber;
    }

    /**
     * Compute one line's proportional share of a total using largest-remainder
     * reconciliation: every line except the absorber is rounded proportionally,
     * and the absorber line receives whatever remains so the allocation sums
     * exactly. The absorber is the last line with a positive base (see
     * {@see absorberIndex()}), so the rounding residue never lands on a
     * zero-base line.
     *
     * @param  int  $absorberIndex  Index of the line that absorbs the residue
     * @param  numeric-string  $lineTotal  Line total at working precision
     * @param  numeric-string  $subtotal  Sum of all line totals at working precision
     * @param  numeric-string  $total  Amount being distributed at currency scale
     * @param  numeric-string  $remaining  Amount still unallocated at currency scale
     * @return numeric-string Allocated amount at currency scale
     */
    private function allocateShare(
        int $index,
        int $absorberIndex,
        string $lineTotal,
        string $subtotal,
        string $total,
        string $remaining,
    ): string {
        $scale = $this->scale();

        // Absorber line takes the running remainder — exact reconciliation.
        if ($index === $absorberIndex) {
            return CurrencyScale::bcformat($remaining, $scale);
        }

        $working = $this->workingScale();
        $proportion = bcdiv($lineTotal, $subtotal, $working);

        return CurrencyScale::bcformat(bcmul($total, $proportion, $working), $scale);
    }

    /**
     * Compute a line's landed unit cost, falling back to unit_price when the
     * line has no positive quantity.
     *
     * @param  numeric-string  $lineTotal  Line total at working precision
     * @param  numeric-string  $allocatedCost  Allocated costs at currency scale
     * @param  numeric-string  $nonRecoverableTax  Non-recoverable tax at currency scale
     * @return numeric-string
     */
    private function landedUnitCost(
        string $lineTotal,
        string $allocatedCost,
        string $nonRecoverableTax,
        DocumentLine $line,
    ): string {
        $scale = $this->scale();
        $working = $this->workingScale();

        $quantity = CurrencyScale::bcformat((string) $line->quantity, 4);

        if (bccomp($quantity, '0', 4) <= 0) {
            return CurrencyScale::bcformat((string) $line->unit_price, $scale);
        }

        $totalCost = bcadd(
            bcadd($lineTotal, CurrencyScale::bcformat($allocatedCost, $working), $working),
            CurrencyScale::bcformat($nonRecoverableTax, $working),
            $working,
        );

        return CurrencyScale::bcformat(bcdiv($totalCost, $quantity, $working), $scale);
    }

    /**
     * Get breakdown of cost allocation for display
     *
     * @return array<int, array{line_id: string, product_name: string, quantity: numeric-string, unit_price: numeric-string, line_total: numeric-string, allocated_costs: numeric-string, non_recoverable_tax: numeric-string|float, landed_unit_cost: numeric-string}>
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
     * Calculate what the allocated cost would be for a line without saving.
     *
     * Note: this preview helper computes a single line's proportional share in
     * isolation; it does NOT perform the largest-remainder reconciliation used
     * by {@see allocateCosts()} (which needs the whole line set). The published
     * float return type is preserved for backward compatibility.
     */
    public function calculateAllocatedCost(float $lineTotal, float $subtotal, float $additionalCostsTotal): float
    {
        $scale = $this->scale();
        $working = $this->workingScale();

        $subtotalStr = CurrencyScale::bcformat($subtotal, $working);
        $totalStr = CurrencyScale::bcformat($additionalCostsTotal, $working);

        if (bccomp($subtotalStr, '0', $working) <= 0 || bccomp($totalStr, '0', $working) <= 0) {
            return 0.0;
        }

        $proportion = bcdiv(CurrencyScale::bcformat($lineTotal, $working), $subtotalStr, $working);

        return (float) CurrencyScale::bcformat(bcmul($totalStr, $proportion, $working), $scale);
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
        $scale = $this->scale();
        $working = $this->workingScale();

        $quantityStr = CurrencyScale::bcformat($quantity, 4);

        if (bccomp($quantityStr, '0', 4) <= 0) {
            return 0.0;
        }

        $totalCost = bcadd(
            bcadd(CurrencyScale::bcformat($lineTotal, $working), CurrencyScale::bcformat($allocatedCost, $working), $working),
            CurrencyScale::bcformat($nonRecoverableTax, $working),
            $working,
        );

        return (float) CurrencyScale::bcformat(bcdiv($totalCost, $quantityStr, $working), $scale);
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
