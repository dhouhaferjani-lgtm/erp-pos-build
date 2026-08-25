<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

use App\Modules\Document\Domain\Document;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Everything a web detail page needs to render a PROFORMA — C-F0w, the web half of
 * SPEC §2.4. Present on the payload if and only if
 * {@see Document::isProformaOutput()} is true.
 *
 * THE FIELDS ARE THE BLADE'S FIELDS. `gross_lines`, `stamp_duty`, `discount` and
 * `adjustment` are {@see ProformaTotals} — the rows gate r2 §3 (residual R-8)
 * ruled make the totals box close over tax-inclusive line amounts — plus the
 * estimated total the box is headed by, and the per-line figures the items table
 * prints. Nothing here is derivable into a VAT figure: `estimated_total` is the
 * stored document total, `gross_lines` is Σ of the printed gross amounts, and the
 * residual between them is the document-level charge or discount, never the tax.
 *
 * WHY THE ESTIMATED TOTAL IS SHIPPED even though `DocumentData::$total` carries the
 * same number: so that a page rendering a proforma reads ONE object and never has
 * to decide which of two totals is safe to print. `documents.tax_amount` and
 * `documents.subtotal` stay on the payload for the definitive-document branch;
 * a proforma branch that touched either would be the bug.
 *
 * `null` means "print no row". All values are currency-scaled numeric strings.
 */
#[TypeScript]
final class ProformaPresentationData extends Data
{
    /**
     * @param  list<ProformaLineAmounts>  $lines
     */
    public function __construct(
        /** The stored document total, headed `documents.proforma.estimated_total`. */
        public readonly string $estimated_total,
        /** Σ of the gross line amounts in `lines`. */
        public readonly string $gross_lines,
        /** The TN timbre when non-zero — a *droit de timbre*, not a VAT mention. */
        public readonly ?string $stamp_duty,
        /** The derived residual when it REDUCES the total; always positive here. */
        public readonly ?string $discount,
        /** The derived residual when it INCREASES the total; not a discount. */
        public readonly ?string $adjustment,
        public readonly array $lines,
    ) {}
}
