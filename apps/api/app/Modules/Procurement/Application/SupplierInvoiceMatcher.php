<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\SupplierInvoiceMatchStatus;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Shared\Domain\CurrencyScale;

/**
 * C2 — Supplier Invoice 3-way Matcher
 *
 * Read-only domain service: reads Documents + DocumentLines, decides match status.
 * NO GL posting and NO DB writes (that belongs to C3).
 *
 * Two-tier rule:
 *   HARD (quantity invariant, R3-1 BLOCKER):
 *     - quantity_variance (over-clear: invoiced_qty > matchable_qty)
 *     - exception (no source_line_id / PO line not found / wrong document type /
 *                  wrong company / nothing received / zero invoice lines)
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
 *
 * Quantity check is performed at PO-line AGGREGATE level: all invoice lines sharing
 * the same source_line_id have their quantities summed before comparison against
 * matchable. This prevents multi-line over-clear where each individual line appears
 * within bounds but their combined total exceeds matchable (BLOCKER-1 fix).
 *
 * source_line_id validation: the referenced DocumentLine's parent document must be
 * of type PurchaseOrder and belong to the same company_id as the supplier invoice.
 * Any other type or company yields Exception (BLOCKER-2 fix).
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
        private readonly ReceiptLineConsumptionPlanner $receiptPlanner,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the overall match status for a supplier invoice.
     *
     * Overall = worst line status, severity order:
     *   exception > quantity_variance > price_variance > matched
     *
     * Quantity check is aggregate per PO line (all invoice lines referencing the
     * same source_line_id have their quantities summed before comparison).
     */
    public function match(Document $supplierInvoice): SupplierInvoiceMatchStatus
    {
        if ($supplierInvoice->lines->isEmpty()) {
            return SupplierInvoiceMatchStatus::Exception;
        }

        $policy = $this->resolver->forCompany($supplierInvoice->company_id);
        $worst = SupplierInvoiceMatchStatus::Matched;

        [
            'groupStatuses' => $groupStatuses,
            'poLines' => $poLines,
            'hasNullLines' => $hasNullLines,
        ] = $this->buildQtyGroupStatuses($supplierInvoice);

        if ($hasNullLines) {
            $worst = $this->worstStatus($worst, SupplierInvoiceMatchStatus::Exception);
        }

        foreach ($groupStatuses as $groupStatus) {
            $worst = $this->worstStatus($worst, $groupStatus);
        }

        // Short-circuit: Exception is the worst possible status.
        if ($worst === SupplierInvoiceMatchStatus::Exception) {
            return $worst;
        }

        // Per-line price check (only for groups that passed qty validation).
        foreach ($supplierInvoice->lines as $invoiceLine) {
            if ((bool) ($invoiceLine->is_bonus_line ?? false)) {
                continue;
            }

            if ($invoiceLine->source_line_id === null) {
                continue;
            }

            $poLine = $poLines[$invoiceLine->source_line_id] ?? null;
            if ($poLine === null) {
                continue;
            }

            // Only run price check for qty-ok groups (hard violations already accumulated above).
            if (($groupStatuses[$invoiceLine->source_line_id] ?? null) !== SupplierInvoiceMatchStatus::Matched) {
                continue;
            }

            $priceStatus = $this->computePriceStatus($invoiceLine, $poLine, $policy);
            $worst = $this->worstStatus($worst, $priceStatus);
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
        $receiptLines = GoodsReceiptLine::query()
            ->where('po_line_id', $poLine->id)
            ->get();

        if ($receiptLines->isNotEmpty()) {
            /** @var numeric-string $matchable */
            $matchable = '0.0000';
            /** @var GoodsReceiptLine $receiptLine */
            foreach ($receiptLines as $receiptLine) {
                $matchable = bcadd($matchable, $this->receiptPlanner->matchableQty($receiptLine), 4);
            }

            return CurrencyScale::bcformatStrict($matchable, 4);
        }

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
     * Quantity check is aggregate per PO line — multiple invoice lines referencing
     * the same source_line_id have their quantities summed before comparison.
     *
     * C3 calls this inside its locked transaction; C2 owns the decision.
     *
     * @throws \DomainException when posting is not permitted
     */
    public function assertPostable(Document $supplierInvoice, MatchEnforcement $enforcement): void
    {
        if ($supplierInvoice->lines->isEmpty()) {
            throw new \DomainException(sprintf(
                'Supplier invoice [%s] cannot be posted: no invoice lines are present.',
                $supplierInvoice->id,
            ));
        }

        $policy = $this->resolver->forCompany($supplierInvoice->company_id);
        $hasPriceVariance = false;

        [
            'groupStatuses' => $groupStatuses,
            'poLines' => $poLines,
            'hasNullLines' => $hasNullLines,
        ] = $this->buildQtyGroupStatuses($supplierInvoice);

        // HARD — null source_line_id (unlinked lines)
        if ($hasNullLines) {
            throw new \DomainException(sprintf(
                'Supplier invoice [%s] cannot be posted: one or more lines have no source_line_id'
                .' (unlinked). The match_enforcement setting does not apply to'
                .' quantity/exception violations.',
                $supplierInvoice->id,
            ));
        }

        // HARD — per-PO-line aggregate violations (Exception or QuantityVariance)
        foreach ($groupStatuses as $sourceLineId => $groupStatus) {
            if (
                $groupStatus === SupplierInvoiceMatchStatus::Exception
                || $groupStatus === SupplierInvoiceMatchStatus::QuantityVariance
            ) {
                throw new \DomainException(sprintf(
                    'Supplier invoice [%s] cannot be posted: PO line [%s] has a hard match'
                    .' violation (%s). The match_enforcement setting does not apply to'
                    .' quantity/exception violations.',
                    $supplierInvoice->id,
                    $sourceLineId,
                    $groupStatus->value,
                ));
            }
        }

        // ADVISORY — per-line price check (only for qty-ok groups)
        foreach ($supplierInvoice->lines as $invoiceLine) {
            if ((bool) ($invoiceLine->is_bonus_line ?? false)) {
                continue;
            }

            if ($invoiceLine->source_line_id === null) {
                continue;
            }

            $poLine = $poLines[$invoiceLine->source_line_id] ?? null;
            if ($poLine === null) {
                continue;
            }

            $priceStatus = $this->computePriceStatus($invoiceLine, $poLine, $policy);
            if ($priceStatus === SupplierInvoiceMatchStatus::PriceVariance) {
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
     * Build aggregate quantity statuses per PO line, validating each referenced line.
     *
     * Groups invoice lines by source_line_id, sums their quantities per group (bcadd,
     * scale 4), validates each referenced DocumentLine (must be a PurchaseOrder line
     * for the same company_id), then compares the aggregate quantity against matchable.
     *
     * Returns:
     *   groupStatuses — map of source_line_id → SupplierInvoiceMatchStatus
     *                   (Exception | QuantityVariance | Matched).
     *   poLines       — map of source_line_id → validated DocumentLine (PO lines only).
     *                   An entry is present only when groupStatus is Matched.
     *   hasNullLines  — true when any invoice line has source_line_id === null.
     *
     * Price check is NOT performed here; callers handle per-line price evaluation
     * against the loaded poLines after calling this method.
     *
     * @return array{
     *   groupStatuses: array<string, SupplierInvoiceMatchStatus>,
     *   poLines: array<string, DocumentLine>,
     *   hasNullLines: bool
     * }
     */
    private function buildQtyGroupStatuses(Document $supplierInvoice): array
    {
        /** @var array<string, numeric-string> $groupQtys total invoiced qty per PO line (scale 4) */
        $groupQtys = [];
        /** @var array<string, numeric-string> $bonusQtys total free invoiced qty per PO line (scale 4) */
        $bonusQtys = [];
        $hasNullLines = false;

        foreach ($supplierInvoice->lines as $invoiceLine) {
            if ((bool) ($invoiceLine->is_bonus_line ?? false)) {
                if ($invoiceLine->source_line_id === null) {
                    $hasNullLines = true;

                    continue;
                }

                $key = $invoiceLine->source_line_id;

                if (! isset($bonusQtys[$key])) {
                    $bonusQtys[$key] = '0.0000';
                }

                /** @phpstan-ignore argument.type */
                $bonusQtys[$key] = bcadd($bonusQtys[$key], $invoiceLine->quantity, 4);

                continue;
            }

            if ($invoiceLine->source_line_id === null) {
                $hasNullLines = true;

                continue;
            }

            $key = $invoiceLine->source_line_id;

            if (! isset($groupQtys[$key])) {
                $groupQtys[$key] = '0.0000';
            }

            // bcadd returns string; @phpstan-ignore narrows it to the numeric-string we declared.
            /** @phpstan-ignore argument.type */
            $groupQtys[$key] = bcadd($groupQtys[$key], $invoiceLine->quantity, 4);
        }

        /** @var array<string, SupplierInvoiceMatchStatus> $groupStatuses */
        $groupStatuses = [];
        /** @var array<string, DocumentLine> $poLines */
        $poLines = [];

        foreach ($groupQtys as $sourceLineId => $totalQty) {
            // Step 1 — PO line existence check
            $poLine = DocumentLine::find($sourceLineId);

            if ($poLine === null) {
                $groupStatuses[$sourceLineId] = SupplierInvoiceMatchStatus::Exception;

                continue;
            }

            // Step 2 — Validate: parent document must be a PurchaseOrder for the same company.
            // Tenant isolation is handled by database-per-tenant; only type + company checks needed.
            $parentDoc = $poLine->document;

            if (
                $parentDoc->type !== DocumentType::PurchaseOrder
                || $parentDoc->company_id !== $supplierInvoice->company_id
            ) {
                $groupStatuses[$sourceLineId] = SupplierInvoiceMatchStatus::Exception;

                continue;
            }

            $matchable = $this->matchableQty($poLine);

            // Step 3 — Unreceived check (matchable ≤ 0 but trying to invoice qty > 0)
            if (
                bccomp($matchable, '0', 4) <= 0
                && bccomp($totalQty, '0', 4) > 0
            ) {
                $groupStatuses[$sourceLineId] = SupplierInvoiceMatchStatus::Exception;

                continue;
            }

            // Step 4 — Aggregate over-clear check (sum of all invoice lines for this PO line)
            /** @phpstan-ignore argument.type */
            if (bccomp($totalQty, $matchable, 4) > 0) {
                $groupStatuses[$sourceLineId] = SupplierInvoiceMatchStatus::QuantityVariance;

                continue;
            }

            // Qty ok — price will be checked per line by the caller
            $groupStatuses[$sourceLineId] = SupplierInvoiceMatchStatus::Matched;
            $poLines[$sourceLineId] = $poLine;
        }

        foreach ($bonusQtys as $sourceLineId => $totalQty) {
            $poLine = DocumentLine::find($sourceLineId);

            if ($poLine === null) {
                $groupStatuses[$sourceLineId] = $this->worstStatus(
                    $groupStatuses[$sourceLineId] ?? SupplierInvoiceMatchStatus::Matched,
                    SupplierInvoiceMatchStatus::Exception,
                );

                continue;
            }

            $parentDoc = $poLine->document;

            if (
                $parentDoc->type !== DocumentType::PurchaseOrder
                || $parentDoc->company_id !== $supplierInvoice->company_id
            ) {
                $groupStatuses[$sourceLineId] = $this->worstStatus(
                    $groupStatuses[$sourceLineId] ?? SupplierInvoiceMatchStatus::Matched,
                    SupplierInvoiceMatchStatus::Exception,
                );

                continue;
            }

            $receiptLines = GoodsReceiptLine::query()
                ->where('po_line_id', $poLine->id)
                ->get();

            if ($receiptLines->isNotEmpty()) {
                /** @var numeric-string $freeMatchable */
                $freeMatchable = '0.0000';
                /** @var GoodsReceiptLine $receiptLine */
                foreach ($receiptLines as $receiptLine) {
                    $freeMatchable = bcadd($freeMatchable, $this->receiptPlanner->freeMatchableQty($receiptLine), 4);
                }
            } else {
                /** @var numeric-string $freeMatchable */
                $freeMatchable = bcsub(
                    (string) ($poLine->free_quantity_received ?? '0'),
                    (string) ($poLine->free_quantity_invoiced ?? '0'),
                    4,
                );
            }

            if (
                bccomp($freeMatchable, '0', 4) <= 0
                && bccomp($totalQty, '0', 4) > 0
            ) {
                $groupStatuses[$sourceLineId] = $this->worstStatus(
                    $groupStatuses[$sourceLineId] ?? SupplierInvoiceMatchStatus::Matched,
                    SupplierInvoiceMatchStatus::Exception,
                );

                continue;
            }

            if (bccomp($totalQty, $freeMatchable, 4) > 0) {
                $groupStatuses[$sourceLineId] = $this->worstStatus(
                    $groupStatuses[$sourceLineId] ?? SupplierInvoiceMatchStatus::Matched,
                    SupplierInvoiceMatchStatus::QuantityVariance,
                );

                continue;
            }

            $groupStatuses[$sourceLineId] = $this->worstStatus(
                $groupStatuses[$sourceLineId] ?? SupplierInvoiceMatchStatus::Matched,
                SupplierInvoiceMatchStatus::Matched,
            );
            $poLines[$sourceLineId] = $poLine;
        }

        return [
            'groupStatuses' => $groupStatuses,
            'poLines' => $poLines,
            'hasNullLines' => $hasNullLines,
        ];
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
    public function priceStatus(
        DocumentLine $invoiceLine,
        DocumentLine $poLine,
        ProcurementPolicy $policy,
    ): SupplierInvoiceMatchStatus {
        return $this->computePriceStatus($invoiceLine, $poLine, $policy);
    }

    private function computePriceStatus(
        DocumentLine $invoiceLine,
        DocumentLine $poLine,
        ProcurementPolicy $policy,
    ): SupplierInvoiceMatchStatus {
        $invoiceUnitPrice = $invoiceLine->unit_price;
        $basisUnitPrice = $this->priceBasisForInvoiceLine($invoiceLine, $poLine);
        $invoicedQty = $invoiceLine->quantity;

        // |invoice_unit_price − basis_unit_price|  (money scale 3)
        /** @phpstan-ignore argument.type */
        $priceDiff = bcsub($invoiceUnitPrice, $basisUnitPrice, 3);
        /** @phpstan-ignore argument.type */
        if (bccomp($priceDiff, '0', 3) < 0) {
            /** @phpstan-ignore argument.type */
            $priceDiff = bcmul($priceDiff, '-1', 3);
        }

        // Extended variance = |price_diff| × invoiced_qty  (money scale 3)
        /** @phpstan-ignore argument.type */
        $extendedVariance = bcmul($priceDiff, $invoicedQty, 3);

        // Basis extended = basis_unit_price × invoiced_qty (base for percent threshold)
        /** @phpstan-ignore argument.type */
        $basisExtended = bcmul($basisUnitPrice, $invoicedQty, 3);

        // Convert tolerance percent to decimal fraction (e.g. '2.00' → '0.020000')
        /** @phpstan-ignore argument.type */
        $percentFraction = bcmul($policy->variance_tolerance_percent, '0.01', 6);
        /** @phpstan-ignore argument.type */
        $percentageThreshold = bcmul($basisExtended, $percentFraction, 3);

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
     * @return numeric-string
     */
    private function priceBasisForInvoiceLine(DocumentLine $invoiceLine, DocumentLine $poLine): string
    {
        if ($invoiceLine->price_match_basis !== null) {
            return CurrencyScale::bcformatStrict((string) $invoiceLine->price_match_basis, 6);
        }

        $slices = $this->receiptPlanner->plan($poLine->id, (string) $invoiceLine->quantity);
        if ($slices === []) {
            return CurrencyScale::bcformatStrict((string) $poLine->unit_price, 6);
        }

        /** @var numeric-string $totalQty */
        $totalQty = '0.0000';
        /** @var numeric-string $totalValue */
        $totalValue = '0.0000000';
        foreach ($slices as $slice) {
            $totalQty = bcadd($totalQty, $slice['qty'], 4);
            $totalValue = bcadd($totalValue, bcmul($slice['qty'], $slice['basis'], 7), 7);
        }

        if (bccomp($totalQty, '0', 4) <= 0) {
            return CurrencyScale::bcformatStrict((string) $poLine->unit_price, 6);
        }

        /** @var numeric-string $weighted */
        $weighted = bcdiv($totalValue, $totalQty, 7);

        return CurrencyScale::bcformatStrict(CurrencyScale::bcround($weighted, 6), 6);
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
