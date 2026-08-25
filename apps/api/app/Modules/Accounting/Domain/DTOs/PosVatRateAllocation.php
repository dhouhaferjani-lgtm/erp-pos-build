<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\DTOs;

/**
 * One sealed VAT rate's share of ONE tender leg (W4-9).
 *
 * `$taxRate` is carried verbatim from `pos_receipt_vat_details.tax_rate` so the
 * GL line description names the same rate the DGI declaration groups by. It is
 * a LABEL here, never an input to arithmetic: `$vatAmount` comes from the
 * sealed row, it is never `net * rate`.
 */
final readonly class PosVatRateAllocation
{
    /**
     * @param  numeric-string  $taxRate  e.g. '19.00'
     * @param  numeric-string  $vatAmount  this leg's share of the sealed VAT for the rate
     */
    public function __construct(
        public string $taxRate,
        public string $vatAmount,
    ) {}
}
