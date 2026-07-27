<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain\ValueObjects;

/**
 * Minimal projection of a Product used by bundle expansion.
 *
 * Bundle's Domain layer resolves parts via `ProductResolverInterface`,
 * which returns this VO rather than a full Product model to preserve
 * module boundaries (Rule #6 — sacred).
 */
final readonly class ComponentProductRef
{
    public function __construct(
        public string $product_id,
        public string $display_name,
        public ?string $sale_price,   // scaled decimal string; null => price-on-request
        public string $currency,
        public ?string $tax_rate,     // scaled decimal string (VAT %)
        public string $unit,          // e.g. "liter", "piece"
        public int $quantity_decimals,
    ) {}
}
