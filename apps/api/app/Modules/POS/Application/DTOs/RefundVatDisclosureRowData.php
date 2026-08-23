<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One tax rate's worth of REFUND VAT on an X/Z report (B-6(ii), Option A2).
 *
 * DERIVED FOR DISPLAY, never signed. Every amount is a POSITIVE MAGNITUDE — the
 * deduction it represents. The declaration arm books the same figure with the
 * opposite sign (`EloquentVatDataRepository.php:112-113`, `-ABS(...)`), so
 * `declared_output(rate) = sales_vat(rate) - vat_amount` holds by construction.
 */
#[TypeScript]
final class RefundVatDisclosureRowData extends Data
{
    public function __construct(
        public readonly string $tax_rate,
        public readonly string $net_amount,
        public readonly string $vat_amount,
        public readonly string $gross_amount,
    ) {}
}
