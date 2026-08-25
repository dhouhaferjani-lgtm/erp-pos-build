<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Document\Application\DTOs\ProformaLineAmounts;
use App\Modules\Document\Application\DTOs\ProformaPresentationData;
use App\Modules\Document\Domain\Document;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;

/**
 * Assembles the proforma projection a JSON document payload carries — C-F0w.
 *
 * This is {@see DocumentPdfService::viewDataFor()}'s proforma block, minus the
 * blade: the same policy, the same {@see ProformaGrossAmountResolver}, the same
 * `totals()` call, so the web detail page and the PDF cannot print different
 * numbers for the same document. Adding a second derivation for the web is exactly
 * the defect class the gate called F-C1/F-C2 one level up.
 *
 * Returns `null` for anything that is not a proforma. A definitive document's
 * payload keeps `subtotal` / `tax_amount` and carries no proforma block at all, so
 * the two branches of the front end cannot be confused for one another.
 */
final class ProformaPresenter
{
    public function __construct(
        private readonly ProformaOutputPolicy $policy,
        private readonly ProformaGrossAmountResolver $grossAmounts,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function present(Document $document): ?ProformaPresentationData
    {
        if (! $this->policy->isProforma($document)) {
            return null;
        }

        $currency = $document->currency;
        $scale = $this->scaleResolver->getScaleSafe($currency, 3);
        $totals = $this->grossAmounts->totals($document, $currency);

        if (! $document->relationLoaded('lines')) {
            $document->load('lines');
        }

        $lines = [];
        foreach ($document->lines as $line) {
            $lines[] = new ProformaLineAmounts(
                line_id: $line->id,
                unit_price: $this->grossAmounts->unitPrice($line, $currency),
                line_total: $this->grossAmounts->lineAmount($line, $currency),
            );
        }

        return new ProformaPresentationData(
            estimated_total: CurrencyScale::bcformatStrict((string) ($document->total ?? '0'), $scale),
            gross_lines: $totals->grossLines,
            stamp_duty: $totals->stampDuty,
            discount: $totals->discount,
            adjustment: $totals->surcharge,
            lines: $lines,
        );
    }
}
