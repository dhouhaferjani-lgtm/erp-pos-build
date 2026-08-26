<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

use App\Shared\Contracts\TaxDefaultResolverInterface;
use App\Shared\Enums\ProductTaxDefaultSource;

/**
 * The default tax rate for a new product, WITH the ladder level that supplied it.
 *
 * Crosses the module boundary via {@see TaxDefaultResolverInterface}: the value alone
 * cannot answer "where did this come from", because a category and its company
 * routinely state the SAME percentage. Callers that only need the number keep using
 * `getDefaultTaxForNewProduct()`.
 */
final readonly class ProductTaxDefaultDTO
{
    /**
     * @param  numeric-string  $taxRate  percent, two decimals
     */
    public function __construct(
        public string $taxRate,
        public ProductTaxDefaultSource $source,
    ) {}
}
