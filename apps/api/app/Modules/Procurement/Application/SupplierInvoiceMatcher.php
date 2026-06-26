<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\SupplierInvoiceMatchStatus;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\ProcurementPolicy;

/**
 * C2 — Supplier Invoice 3-way Matcher
 *
 * Read-only domain service: reads Documents + DocumentLines, decides match status.
 * NO GL posting and NO DB writes (that belongs to C3).
 *
 * Two-tier rule:
 *   HARD (quantity invariant, R3-1 BLOCKER):
 *     - quantity_variance (over-clear: invoiced_qty > matchable_qty)
 *     - exception (no source_line_id / PO line not found / nothing received)
 *     These block posting regardless of match_enforcement. Warn CANNOT bypass them.
 *
 *   ADVISORY (price variance):
 *     - price_variance: unit price outside dual-threshold tolerance
 *     Governed by match_enforcement: postable under Warn, blocked under Block.
 *
 * Tolerance comparison mirrors PaymentToleranceService::check() (dual-threshold):
 *   percentageThreshold = poExtended × (variance_tolerance_percent / 100)
 *   withinTolerance     = (variance ≤ percentageThreshold) AND (variance ≤ max_amount)
 *
 * Arithmetic: bcmath on numeric strings only. Qty scale 4, money scale 3. Never float.
 */
final class SupplierInvoiceMatcher
{
    /**
     * Severity order for determining worst-of-N line statuses.
     * Higher value = more severe.
     *
     * @var array<string, int>
     */
    private const SEVERITY = [
        SupplierInvoiceMatchStatus::Matched->value => 0,
        SupplierInvoiceMatchStatus::Unmatched->value => 0,
        SupplierInvoiceMatchStatus::PriceVariance->value => 1,
        SupplierInvoiceMatchStatus::QuantityVariance->value => 2,
        SupplierInvoiceMatchStatus::Exception->value => 3,
    ];

    public function __construct(
        private readonly ProcurementPolicyResolver $resolver,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the overall match status for a supplier invoice.
     *
     * Overall = worst line status, severity order:
     *   exception > quantity_variance > price_variance > matched
     */
    public function match(Document $supplierInvoice): SupplierInvoiceMatchStatus
    {
        $policy = $this->resolver->forCompany($supplierInvoice->company_id);
        $worst = SupplierInvoiceMatchStatus::Matched;

        foreach ($supplierInvoice->lines as $invoiceLine) {
            $lineStatus = $this->computeLineStatus($invoiceLine, $policy);
            $worst = $this->worstStatus($worst, $lineStatus);
        }

        return $worst;
    }

    /**
     * Compute the matchable quantity remaining on a PO line.
     *
     * matchable = quantity_received − quantity_invoiced  (qty scale 4, bcmath)
     *
     * This is the quantity window available for the next supplier invoice against
     * this PO line. C3 will atomically increment quantity_invoiced by the billed
     * qty after assertPostable passes.
     *
     * @return numeric-string
     */
    public function matchableQty(DocumentLine $poLine): string
    {
        /** @phpstan-ignore argument.type */
        return bcsub(
            $poLine->quantity_received,
            $poLine->quantity_invoiced,
            4,
        );
    }

    /**
     * Assert the supplier invoice is postable under the given enforcement mode.
     *
     * HARD violations (exception, quantity_variance) throw regardless of $enforcement.
     * ADVISORY violations (price_variance) throw only under Block; pass under Warn.
     *
     * C3 calls this inside its locked transaction; C2 owns the decision.
     *
     * @throws \DomainException when posting is not permitted
     */
    public function assertPostable(Document $supplierInvoice, MatchEnforcement $enforcement): void
    {
        $policy = $this->resolver->forCompany($supplierInvoice->company_id);
        $hasPriceVariance = false;

        foreach ($supplierInvoice->lines as $invoiceLine) {
            $lineStatus = $this->computeLineStatus($invoiceLine, $policy);

            // HARD invariant — NEVER bypassable regardless of match_enforcement.
            // Warn governs ONLY price variance; exception and quantity_variance always block.
            if (
                $lineStatus === SupplierInvoiceMatchStatus::Exception
                || $lineStatus === SupplierInvoiceMatchStatus::QuantityVariance
            ) {
                throw new \DomainException(sprintf(
                    'Supplier invoice [%s] cannot be posted: line [%s] has a hard match violation'
                    .' (%s). The match_enforcement setting does not apply to'
                    .' quantity/exception violations.',
                    $supplierInvoice->id,
                    $invoiceLine->id,
                    $lineStatus->value,
                ));
            }

            if ($lineStatus === SupplierInvoiceMatchStatus::PriceVariance) {
                $hasPriceVariance = true;
            }
        }

        // ADVISORY — governed by enforcement (Warn passes, Block throws)
        if ($hasPriceVariance && $enforcement === MatchEnforcement::Block) {
            throw new \DomainException(sprintf(
                'Supplier invoice [%s] cannot be posted: price variance detected'
                .' and match_enforcement is block.',
                $supplierInvoice->id,
            ));
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Compute the match status for a single supplier invoice line.
     *
     * Steps (per spec C2 §Per-line matching logic):
     *   1. source_line_id null / PO line missing → exception
     *   2. matchable ≤ 0 and invoiced qty > 0 → exception (nothing received)
     *   3. invoiced qty > matchable → quantity_variance (HARD)
     *   4. price variance check via dual-threshold tolerance → matched | price_variance
     */
    private function computeLineStatus(
        DocumentLine $invoiceLine,
        ProcurementPolicy $policy,
    ): SupplierInvoiceMatchStatus {
        // Step 1 — source line linkage
        if ($invoiceLine->source_line_id === null) {
            return SupplierInvoiceMatchStatus::Exception;
        }

        $poLine = DocumentLine::find($invoiceLine->source_line_id);
        if ($poLine === null) {
            return SupplierInvoiceMatchStatus::Exception;
        }

        // Step 2 — unreceived check
        $matchable = $this->matchableQty($poLine);
        $invoicedQty = $invoiceLine->quantity;

        if (
            bccomp($matchable, '0', 4) <= 0
            && bccomp($invoicedQty, '0', 4) > 0
        ) {
            return SupplierInvoiceMatchStatus::Exception;
        }

        // Step 3 — over-clear check (HARD quantity invariant)
        if (bccomp($invoicedQty, $matchable, 4) > 0) {
            return SupplierInvoiceMatchStatus::QuantityVariance;
        }

        // Step 4 — price variance (dual-threshold, mirrors PaymentToleranceService::check())
        return $this->computePriceStatus($invoiceLine, $poLine, $policy);
    }

    /**
     * Dual-threshold price variance check.
     *
     * Mirrors the bccomp idiom from PaymentToleranceService::check() (lines ~74-145):
     *   percentageThreshold = poExtended × (variance_tolerance_percent / 100)
     *   withinPercentage    = variance ≤ percentageThreshold
     *   withinMaxAmount     = variance ≤ variance_tolerance_max_amount
     *   withinTolerance     = withinPercentage AND withinMaxAmount
     *
     * variance_tolerance_percent is stored as '2.00' (i.e. 2%), so divide by 100
     * before multiplying (bcmul by '0.01' at scale 6).
     *
     * Scale convention: qty scale 4, money scale 3. Intermediate percent fraction
     * computed at scale 6 to avoid precision loss before the final multiply.
     */
    private function computePriceStatus(
        DocumentLine $invoiceLine,
        DocumentLine $poLine,
        ProcurementPolicy $policy,
    ): SupplierInvoiceMatchStatus {
        $invoiceUnitPrice = $invoiceLine->unit_price;
        $poUnitPrice = $poLine->unit_price;
        $invoicedQty = $invoiceLine->quantity;

        // |invoice_unit_price − po_unit_price|  (money scale 3)
        /** @phpstan-ignore argument.type */
        $priceDiff = bcsub($invoiceUnitPrice, $poUnitPrice, 3);
        /** @phpstan-ignore argument.type */
        if (bccomp($priceDiff, '0', 3) < 0) {
            /** @phpstan-ignore argument.type */
            $priceDiff = bcmul($priceDiff, '-1', 3);
        }

        // Extended variance = |price_diff| × invoiced_qty  (money scale 3)
        /** @phpstan-ignore argument.type */
        $extendedVariance = bcmul($priceDiff, $invoicedQty, 3);

        // PO extended = po_unit_price × invoiced_qty (base for percent threshold)
        /** @phpstan-ignore argument.type */
        $poExtended = bcmul($poUnitPrice, $invoicedQty, 3);

        // Convert tolerance percent to decimal fraction (e.g. '2.00' → '0.020000')
        /** @phpstan-ignore argument.type */
        $percentFraction = bcmul($policy->variance_tolerance_percent, '0.01', 6);
        /** @phpstan-ignore argument.type */
        $percentageThreshold = bcmul($poExtended, $percentFraction, 3);

        /** @phpstan-ignore argument.type */
        $withinPercentage = bccomp($extendedVariance, $percentageThreshold, 3) <= 0;
        /** @phpstan-ignore argument.type */
        $withinMaxAmount = bccomp($extendedVariance, $policy->variance_tolerance_max_amount, 3) <= 0;

        if ($withinPercentage && $withinMaxAmount) {
            return SupplierInvoiceMatchStatus::Matched;
        }

        return SupplierInvoiceMatchStatus::PriceVariance;
    }

    /**
     * Return whichever status has higher severity.
     *
     * Severity: exception(3) > quantity_variance(2) > price_variance(1) > matched(0)
     */
    private function worstStatus(
        SupplierInvoiceMatchStatus $a,
        SupplierInvoiceMatchStatus $b,
    ): SupplierInvoiceMatchStatus {
        return self::SEVERITY[$a->value] >= self::SEVERITY[$b->value] ? $a : $b;
    }
}
