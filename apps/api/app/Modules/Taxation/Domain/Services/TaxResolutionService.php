<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;

class TaxResolutionService
{
    /**
     * Resolve the applicable tax rate for a product in context
     *
     * Priority hierarchy (highest to lowest):
     * 1. Line-level override (passed as parameter)
     * 2. Product-level tax rate
     * 3. Category-level default tax rate
     * 4. Company-level default tax rate
     *
     * @param  string|null  $lineOverrideTaxRate  Tax rate explicitly set on document line
     * @param  Product  $product  Product being sold
     * @param  Company  $company  Company context
     */
    public function resolveProductTax(
        ?string $lineOverrideTaxRate,
        Product $product,
        Company $company
    ): TaxResolutionResult {
        // 1. Line-level override (highest priority)
        if ($lineOverrideTaxRate !== null) {
            return new TaxResolutionResult(
                taxRate: $lineOverrideTaxRate,
                source: TaxSource::Line
            );
        }

        // 2. Product-level tax
        if ($product->tax_rate !== null) {
            return new TaxResolutionResult(
                taxRate: $product->tax_rate,
                source: TaxSource::Product
            );
        }

        // 3. Category-level default (if product has a category)
        $category = $product->category()->first();
        if ($category && $category->default_tax_rate !== null) {
            return new TaxResolutionResult(
                taxRate: $category->default_tax_rate,
                source: TaxSource::Category
            );
        }

        // 4. Company-level default (fallback)
        return new TaxResolutionResult(
            taxRate: $company->default_tax_rate ?? '0.00',
            source: TaxSource::Company
        );
    }

    /**
     * Get default tax rate for a new product
     *
     * When creating a new product, inherit from category or company
     */
    public function getDefaultTaxForNewProduct(Company $company, ?int $categoryId = null): string
    {
        // If category specified, try to get its default tax rate
        if ($categoryId !== null) {
            $category = \DB::table('categories')->find($categoryId);
            if ($category && $category->default_tax_rate !== null) {
                return $category->default_tax_rate;
            }
        }

        // Fallback to company default
        return $company->default_tax_rate ?? '0.00';
    }
}

/**
 * Value object for tax resolution result
 */
readonly class TaxResolutionResult
{
    public function __construct(
        public string $taxRate,
        public TaxSource $source
    ) {}
}

/**
 * Enum representing where the tax rate came from
 */
enum TaxSource: string
{
    case Line = 'line';
    case Product = 'product';
    case Category = 'category';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Line => 'Line Override',
            self::Product => 'Product',
            self::Category => 'Category Default',
            self::Company => 'Company Default',
        };
    }
}
