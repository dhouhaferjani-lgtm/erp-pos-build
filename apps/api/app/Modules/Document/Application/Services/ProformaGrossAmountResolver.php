<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Document\Application\DTOs\ProformaTotals;
use App\Modules\Document\Domain\Document;
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
 * these values. Intermediates run at `scale + 1` (`scale + 4` for a division) and
 * are rounded ONCE, at the boundary, with `CurrencyScale::bcround()` (half-up),
 * never `bcformat()` (which truncates and would drift the sum away from the
 * estimated total).
 *
 * The scale comes from the DOCUMENT's currency — `getScaleSafe($currency, 3)`, not
 * a bare no-arg `getScale()`. Gate r2 F-9 corrected the reason r1 gave for that:
 * this resolver does NOT run in a worker. `DocumentEmailService::send():48` and
 * `queue():106` both call `generateContent()` IN-REQUEST, before the mailable is
 * handed to the mailer, so a CompanyContext is always bound. The choice stands on
 * its own merits instead: passing the entity currency is what rule 19 asks for
 * whenever the entity is in hand, it is correct for a document denominated in
 * anything other than the company's currency (`DocumentPdfService:173` resolves
 * `$document->currency ?? $company->currency`), and it does not silently acquire a
 * dependency on request state that a future queued caller would break.
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
     *
     * @return numeric-string
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
     * ONE BASIS FOR BOTH PRINTED FIGURES (gate r2 F-7). Round 1 derived this from
     * the RATE while `lineAmount()` took the persisted `tax_amount`, and the two
     * bases cannot agree once a DOCUMENT-level discount exists: the tax engine
     * prorates that discount into every rate bucket before taxing it
     * (`TaxCalculationService:171-183`), and a rate-derived unit price knows nothing
     * about it. The gate's probe printed `Qty 1,00 · Unit Price 59,500 · Amount
     * 59,120` — the document contradicting itself on a single row, in front of the
     * customer, which is precisely the failure round 1 existed to fix, one level
     * down. `POSAccountChargeDraftService:62,164` writes that discount, so the shape
     * is production data, not a hypothetical.
     *
     * So the unit price is the gross line AMOUNT divided by the quantity. This
     * keeps the rule that mattered — the persisted `tax_amount` is never divided on
     * its own, because the figure being divided is a whole-line gross that already
     * absorbs every discount — and it makes `unit × qty == amount` an identity the
     * customer can check with a calculator.
     *
     * ROUNDING — AND ITS BOUND, WHICH IS LINEAR IN QUANTITY. The quotient is taken
     * at `scale + 4` and rounded once, so where the quantity does not divide the
     * amount exactly the printed unit price is off by up to half a currency unit —
     * and that error is then MULTIPLIED BY THE QUANTITY. The bound on
     * `|unit × qty − amount|` is therefore
     *
     *     qty × 0.5 × 10^-scale
     *
     * not the constant half-unit r2's docblock claimed (gate r3 F-11 measured it:
     * 10 000 units of a 0.333 part drifts 2.700 DT, five times that claim, and it
     * is plainly visible on the page). Pinned by
     * `ProformaOutputTest::test_a_bulk_non_dividing_row_drifts_within_the_stated_bound`.
     *
     * This is a characterised trade-off, not a defect: the printed AMOUNT is
     * authoritative, it is what the totals box sums, and the page still closes.
     * Widening the scale cannot buy `unit × qty == amount` — the quotient is
     * non-terminating for most quantities at ANY finite scale — and would print the
     * only figure on the page that is not at the currency's own scale (gate r3
     * ruling R-10: ACCEPT, do not widen). The posted invoice has no such drift
     * because its unit price is stored, not derived.
     *
     * Quantity is `decimal(N,4)`, so `scale + 4` carries every digit it can hold.
     *
     * @return numeric-string
     */
    public function unitPrice(DocumentLine $line, ?string $currency): string
    {
        $scale = $this->scaleResolver->getScaleSafe($currency, 3);
        /** @var numeric-string $quantity */
        $quantity = (string) $line->quantity;

        if (bccomp($quantity, '0', 4) === 0) { // precision-ok: document_lines.quantity is decimal(N,4) — a quantity, not currency
            return $this->lineAmount($line, $currency);
        }

        return CurrencyScale::bcround(
            bcdiv($this->lineAmount($line, $currency), $quantity, $scale + 4),
            $scale,
        );
    }

    /**
     * Σ of the gross line amounts the items table prints, and the two rows that
     * make the totals box close over them — gate r2 §3's ruling on R-8.
     *
     * The residual is DERIVED (`total − (Σ gross lines + stamp)`), never assembled
     * from `stamp_duty_amount − discount_amount`: those coincide only when every
     * line carries a persisted `tax_amount`, and the NULL fallback below taxes the
     * PRE-discount net, so an assembled row would leave a silent remainder on a
     * legacy row. Derived, the page reconciles on every shape.
     */
    public function totals(Document $document, ?string $currency): ProformaTotals
    {
        $scale = $this->scaleResolver->getScaleSafe($currency, 3);

        /** @var numeric-string $grossLines */
        $grossLines = '0';
        foreach ($document->lines as $line) {
            $grossLines = bcadd($grossLines, $this->lineAmount($line, $currency), $scale);
        }

        /** @var numeric-string $stamp */
        $stamp = CurrencyScale::bcround((string) ($document->stamp_duty_amount ?? '0'), $scale);
        /** @var numeric-string $total */
        $total = CurrencyScale::bcround((string) ($document->total ?? '0'), $scale);

        $residual = bcsub($total, bcadd($grossLines, $stamp, $scale), $scale);
        $direction = bccomp($residual, '0', $scale);

        return new ProformaTotals(
            grossLines: $grossLines,
            stampDuty: bccomp($stamp, '0', $scale) > 0 ? $stamp : null,
            discount: $direction < 0 ? bcmul($residual, '-1', $scale) : null,
            surcharge: $direction > 0 ? $residual : null,
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
