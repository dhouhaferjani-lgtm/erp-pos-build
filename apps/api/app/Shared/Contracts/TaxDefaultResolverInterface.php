<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Company\Domain\Company;

interface TaxDefaultResolverInterface
{
    /**
     * Default tax rate (percent, numeric-string) for a new product in this company/category.
     */
    public function getDefaultTaxForNewProduct(Company $company, int|string|null $categoryId = null): string;
}
