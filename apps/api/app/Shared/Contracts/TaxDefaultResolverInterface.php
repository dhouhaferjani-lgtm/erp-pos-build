<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Company\Domain\Company;
use App\Shared\DTOs\ProductTaxDefaultDTO;

interface TaxDefaultResolverInterface
{
    /**
     * Default tax rate (percent, numeric-string) for a new product in this company/category.
     */
    public function getDefaultTaxForNewProduct(Company $company, int|string|null $categoryId = null): string;

    /**
     * The same default, WITH the ladder level that supplied it.
     *
     * The rate alone cannot answer "where did this come from" — a category and its
     * company routinely state the same percentage — and the unified import needs the
     * provenance for the row's `_results.tax_source` breadcrumb (W2-5 gate r1 F-4).
     */
    public function resolveDefaultTaxForNewProduct(Company $company, int|string|null $categoryId = null): ProductTaxDefaultDTO;
}
