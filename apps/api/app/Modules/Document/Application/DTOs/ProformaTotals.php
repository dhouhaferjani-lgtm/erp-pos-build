<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

/**
 * The rows a PROFORMA's totals box prints below its gross line amounts — gate r2
 * §3's ruling on residual R-8.
 *
 * WHY IT EXISTS. A proforma prints tax-inclusive line amounts and one estimated
 * total. Those agree only when the document carries no document-level adjustment:
 * `total = Σ line net − discount_amount + line_tax_amount + stamp_duty_amount`
 * (`DocumentTotalsCalculator:37-56` over `TaxCalculationService:334,352-372`), so a
 * document with a TN timbre or a document-level discount left the page not adding
 * up on its own face. That is a legibility defect rather than a fiscal one — the
 * residual is `stamp − discount`, from which neither the net nor the VAT can be
 * recovered — but the customer has to pay the amount, so the page has to explain it.
 *
 * EVERY FIELD IS A DISPLAY DECISION ALREADY MADE. The blades render what is here
 * and compute nothing: `stampDuty` is the stored statutory figure, and exactly one
 * of `discount` / `surcharge` can be non-null — the DERIVED residual
 * `total − (Σ printed gross lines + stamp)`, signed. Deriving it is the ruling's
 * first condition: an assembled `stamp_duty_amount − discount_amount` matches only
 * when every line carries a persisted `tax_amount`, and the NULL-`tax_amount`
 * fallback taxes the PRE-discount net, so an assembled row would leave a silent
 * remainder on a legacy row. A derived row reconciles by construction on every
 * shape, including the ones nobody thought of.
 *
 * `null` means "print no row". All values are currency-scaled numeric strings.
 */
final class ProformaTotals
{
    public function __construct(
        /** Σ of the gross line amounts the items table prints. */
        public readonly string $grossLines,
        /** The TN timbre, when non-zero — a *droit de timbre*, not a VAT mention. */
        public readonly ?string $stampDuty,
        /** The derived residual when it REDUCES the total; always positive here. */
        public readonly ?string $discount,
        /** The derived residual when it INCREASES the total; calling that a discount would be a lie. */
        public readonly ?string $surcharge,
    ) {}
}
