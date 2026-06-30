<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;

/**
 * Recalculates a document's monetary totals from its lines.
 *
 * This orchestration previously lived on the Document model as
 * `Document::recalculateTotals()`, which resolved TaxCalculationService via
 * the `app()` service-locator helper — a CLAUDE.md violation (domain code must
 * use constructor injection, never the container). The orchestration moved
 * here so the dependency is injected; the model keeps responsibility for its
 * own state (this service writes through `$document->update()`).
 *
 * Behaviour is byte-identical to the former model method — see
 * Tests\Unit\Document\DocumentTotalsCalculatorTest for the before/after proof.
 */
final readonly class DocumentTotalsCalculator
{
    public function __construct(
        private TaxCalculationService $taxCalculationService,
    ) {}

    /**
     * Recalculate and persist the document's subtotal, taxes and total from
     * its lines.
     */
    public function recalculate(Document $document, int $scale = 3): void
    {
        $subtotal = '0';

        foreach ($document->lines as $line) {
            // calculateTotal() is the canonical NET line value: gross
            // (qty × unit_price) minus the line discount (percent, else flat
            // amount), before tax. Without it line discounts were silently
            // dropped from the subtotal. For a line with no discount it returns
            // bcmul(qty, unit_price, scale) — byte-identical to the old code.
            $lineSubtotal = $line->calculateTotal($scale);
            $subtotal = bcadd($subtotal, $lineSubtotal, $scale);
        }

        // Use TaxCalculationService to calculate all taxes (line taxes + stamp duties)
        $taxResult = $this->taxCalculationService->calculateDocumentTaxes($document);

        $document->update([
            'subtotal' => $subtotal,
            'line_tax_amount' => $taxResult->lineItemsTaxTotal,
            'stamp_duty_amount' => $taxResult->documentTaxTotal,
            'tax_amount' => $taxResult->totalTax,  // Total of line_tax + stamp_duty
            'total' => $taxResult->total,
        ]);
    }
}
