<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Document\Domain\DocumentLine;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;

/**
 * The tax-inclusive figures a PROFORMA line prints — SPEC §2.4 r11.2 (gate r1 F-2,
 * orchestrator ruling under owner OQ-75's default).
 *
 * WHY THIS EXISTS. Round 1 printed NET line amounts under a GROSS estimated total.
 * Nothing on the page said `VAT`, so Art. 18 was not engaged — but the page did not
 * reconcile on its own face, and the difference between the two figures a reader
 * could see was, to the millime, the VAT the lane had just removed. One subtraction
 * undid the whole invariant. So the line figures go gross: the unit price and the
 * line amount are tax-inclusive, they sum to the estimated total, and the net basis
 * never appears, which is what makes `total − net` unformable rather than merely
 * unlabelled.
 *
 * HOW THE TAX PER LINE IS KNOWN, in order:
 *   1. `document_lines.tax_amount` — the persisted, authoritative figure the tax
 *      engine wrote (`TaxCalculationService::calculateDocumentTaxes()`), already
 *      carrying its share of any document-level discount.
 *   2. `document_lines.tax_rate` × the net line value — for rows where the column
 *      is NULL, which is every row written before the tax engine existed and every
 *      row a fixture builds by hand.
 *   3. Neither ⇒ gross == net. A line with no rate and no amount is not a taxed
 *      line, and inventing tax for it would put a number on the page that no
 *      ledger will ever agree with.
 *
 * PRECISION (rule 19). Every step is bcmath on strings — no float ever touches
 * these values. Intermediates run at `scale + 1` and are rounded ONCE, at the
 * boundary, with `CurrencyScale::bcround()` (half-up), never `bcformat()` (which
 * truncates and would drift the sum away from the estimated total). The scale comes
 * from the DOCUMENT's currency via `getScaleSafe()`, not from a bare no-arg
 * `getScale()`: this runs inside `DocumentEmailService::queue()` too, where there
 * is no CompanyContext to resolve a default from.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: it never touches `documents.total`. The
 * estimated total stays the stored figure. When a document carries a document-level
 * charge (the TN timbre in `stamp_duty_amount`) or a document-level discount, the
 * gross lines therefore do NOT sum to it — the residual is that charge or discount,
 * and it is NOT the VAT, so the security property holds either way. See the lane
 * handback residual R-8.
 */
final class ProformaGrossAmountResolver
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * The line's tax-inclusive amount — what the `Amount` column prints.
     */
    public function lineAmount(DocumentLine $line, ?string $currency): string
    {
        $scale = $this->scaleResolver->getScaleSafe($currency, 3);
        /** @var numeric-string $net */
        $net = (string) $line->line_total;

        /** @var numeric-string $tax */
        $tax = $line->tax_amount !== null
            ? (string) $line->tax_amount
            : bcmul($net, $this->rateFraction($line, $scale), $scale + 1);

        return CurrencyScale::bcround(bcadd($net, $tax, $scale + 1), $scale);
    }

    /**
     * The line's tax-inclusive unit price — what the `Unit Price` column prints.
     *
     * Derived from the RATE, never from `tax_amount`: the persisted line tax is a
     * whole-line figure that already absorbs the line discount, so dividing it by
     * the quantity would print a unit price the customer cannot multiply back.
     * `unit_price × qty == line amount` is not an identity on a discounted line —
     * it is not one today either — and this resolver does not pretend otherwise.
     */
    public function unitPrice(DocumentLine $line, ?string $currency): string
    {
        $scale = $this->scaleResolver->getScaleSafe($currency, 3);
        /** @var numeric-string $net */
        $net = (string) $line->unit_price;

        return CurrencyScale::bcround(
            bcadd($net, bcmul($net, $this->rateFraction($line, $scale), $scale + 1), $scale + 1),
            $scale,
        );
    }

    /**
     * `tax_rate` is a PERCENT (`19.00`), cast at scale 2. The fraction is kept at
     * `scale + 4` so a rate like 7.50% does not lose a digit before it multiplies.
     *
     * @return numeric-string
     */
    private function rateFraction(DocumentLine $line, int $scale): string
    {
        /** @var numeric-string|null $rate */
        $rate = $line->tax_rate;

        if ($rate === null || bccomp($rate, '0', 2) === 0) { // precision-ok: document_lines.tax_rate is decimal(5,2), a percentage — not currency-scaled
            return '0';
        }

        return bcdiv($rate, '100', $scale + 4);
    }
}
