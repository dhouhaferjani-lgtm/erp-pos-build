<?php

declare(strict_types=1);

namespace App\Shared\Enums;

use App\Shared\Contracts\TaxDefaultResolverInterface;

/**
 * WHICH level of the new-product tax ladder supplied a product's default rate.
 *
 * Crosses the module boundary via {@see TaxDefaultResolverInterface}
 * so the unified import can record it on the row (`_results.product.tax_source`) — the only
 * breadcrumb an imported row keeps about where its rate came from. It used to say
 * `default` for every inherited rate, which stopped being true the moment the
 * category became a real source (W2-5).
 *
 * `File` is the import's own case: the row carried an explicit `tax_rate` column, so
 * no ladder ran at all.
 */
enum ProductTaxDefaultSource: string
{
    case File = 'file';
    case CategoryDefault = 'category_default';
    case CompanyDefault = 'company_default';

    public function label(): string
    {
        return match ($this) {
            self::File => 'From the imported file',
            self::CategoryDefault => 'Category default',
            self::CompanyDefault => 'Company default',
        };
    }
}
