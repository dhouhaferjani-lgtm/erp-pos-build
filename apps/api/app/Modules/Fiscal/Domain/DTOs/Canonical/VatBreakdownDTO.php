<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

/**
 * One row in `fiscal_events.payload.vat_breakdown[]` — 5 properties per
 * Candidate C-v3 §3.
 *
 * Each row corresponds to the partition of `line_items` by
 * `(vat_rate, tax_category_code)` per synthesis v5 §6.C algorithm:
 *   - `net_amount`   = SUM(line_items[i].line_subtotal where group matches)
 *   - `vat_amount`   = SUM(line_items[i].line_vat where group matches)
 *   - `gross_amount` = bcadd(net_amount, vat_amount, currency_scale)
 *
 * `tax_category_code` empty string means standard taxable; unified
 * KSA BT-151 / IT Natura axis.
 */
final readonly class VatBreakdownDTO
{
    public function __construct(
        public string $grossAmount,
        public string $netAmount,
        public string $rate,
        public string $taxCategoryCode,
        public string $vatAmount,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            grossAmount: FiscalPayloadArrayGuards::requireString($data, 'gross_amount'),
            netAmount: FiscalPayloadArrayGuards::requireString($data, 'net_amount'),
            rate: FiscalPayloadArrayGuards::requireString($data, 'rate'),
            taxCategoryCode: FiscalPayloadArrayGuards::requireString($data, 'tax_category_code'),
            vatAmount: FiscalPayloadArrayGuards::requireString($data, 'vat_amount'),
        );
    }
}
