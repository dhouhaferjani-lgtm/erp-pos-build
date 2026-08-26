<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The two tax-INCLUSIVE figures one line of a proforma prints — C-F0w, the web
 * half of SPEC §2.4 r11.2 (gate r1 F-2).
 *
 * WHY THE LINE FIGURES CANNOT COME FROM `DocumentLineData`. That DTO carries
 * `unit_price` and `line_total` NET of tax. A page that prints net line amounts
 * under a gross estimated total does not mention VAT — and hands the reader the
 * subtraction that recovers it exactly. The blades were fixed by printing gross
 * (`ProformaGrossAmountResolver`); the web detail pages have to print the SAME
 * figures, so they are shipped alongside the payload rather than re-derived in
 * TypeScript, where a rounding rule and a bcmath scale would have to be
 * reimplemented and would drift.
 *
 * `line_id` matches `DocumentLineData::$id`, so the table joins them by key and a
 * line with no entry here prints no amount at all — never a net one.
 *
 * All values are currency-scaled numeric strings (rule 19): the front end formats,
 * it never computes.
 */
#[TypeScript]
final class ProformaLineAmounts extends Data
{
    public function __construct(
        public readonly string $line_id,
        /** Tax-inclusive unit price — `line_total ÷ quantity`, see the resolver. */
        public readonly string $unit_price,
        /** Tax-inclusive line amount. */
        public readonly string $line_total,
    ) {}
}
