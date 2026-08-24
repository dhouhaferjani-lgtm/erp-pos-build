<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Shared\Contracts\TaxDefaultResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\DB;

class TaxResolutionService implements TaxDefaultResolverInterface
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
                taxRate: (string) $category->default_tax_rate,
                source: TaxSource::Category
            );
        }

        // 4. Company-level default (fallback)
        return new TaxResolutionResult(
            taxRate: (string) ($company->default_tax_rate ?? '0.00'),
            source: TaxSource::Company
        );
    }

    /**
     * Get default tax rate for a new product.
     *
     * When creating a new product, inherit from category or company:
     * 1. Category default_tax_rate (if category id given and rate is set)
     * 2. Company default_tax_rate
     * 3. '0.00' (hard fallback when neither is configured)
     *
     * @param  int|string|null  $categoryId  Category primary-key value. The product `categories`
     *                                       table uses an integer auto-increment PK, but callers
     *                                       may pass it as a string from HTTP request payloads, so
     *                                       both are accepted; the query coerces either.
     */
    public function getDefaultTaxForNewProduct(Company $company, int|string|null $categoryId = null): string
    {
        if ($categoryId !== null) {
            $rate = DB::table('categories')
                ->where('id', $categoryId)
                ->where('company_id', $company->id)
                ->value('default_tax_rate');

            if ($rate !== null) {
                return (string) $rate;
            }
        }

        return (string) ($company->default_tax_rate ?? '0.00');
    }

    /**
     * Derive a product's denormalised `tax_rate` from the tax configuration the
     * operator actually chose (campaign defect N-1).
     *
     * `products.tax_rate` is not decoration — it is the number
     * `ReceiptCreationService` reads unconditionally and seals into the POS
     * hash chain, and the number `DocumentLineTaxResolver` falls back to. Until
     * this method existed, nothing in the product write path ever consulted
     * `tax_configurations.percentage_rate`, so choosing "TVA 7 %" stored the
     * configuration id next to the company's 19 % default and every sale of
     * that product over-charged VAT.
     *
     * THE SCOPE FILTERS MIRROR `DocumentLineTaxResolver` (Document module;
     * named, not imported — a Taxation domain service must not take a use
     * statement on a Document application service just to cite it)
     * EXACTLY — `country_code` + `LINE_ITEMS` + percentage-typed — and they must
     * stay mirrored. `tax_configurations` is a country-scoped reference table
     * (no `tenant_id` / `company_id` columns), so the company's country IS the
     * ownership boundary; a foreign-country id is separately refused with a 422
     * by `TaxConfigurationCountryCoherent` on the Create/Update requests before
     * this is ever reached. If the two resolvers ever disagreed, the same
     * configuration would price a POS line and a document line differently —
     * which is the class of defect N-1 was.
     *
     * RETURNS NULL, DELIBERATELY, rather than falling back to a default. A
     * configuration that cannot yield a line-item percentage (a fixed-amount
     * stamp duty, a `DOCUMENT_TOTAL` config, a row deleted between validation
     * and write) is a caller error, not a licence to invent a rate — inventing
     * one is precisely how the wrong number got sealed in the first place. The
     * caller turns null into a refusal.
     *
     * @param  string  $taxConfigurationId  A `tax_configurations.id` UUID.
     * @return numeric-string|null Two-decimal percentage, or null when this
     *                             configuration cannot state one.
     */
    public function resolveRateFromTaxConfiguration(Company $company, string $taxConfigurationId): ?string
    {
        if (trim($taxConfigurationId) === '') {
            return null;
        }

        $configuration = TaxConfiguration::query()
            ->where('country_code', $company->country_code)
            ->where('applies_to', TaxApplicationLevel::LineItems->value)
            ->find($taxConfigurationId);

        if (! $configuration instanceof TaxConfiguration || ! $configuration->isPercentage()) {
            return null;
        }

        // bcformatStrict, never number_format on a float (agent rule 19). The
        // column is decimal(5,2) and `percentage_rate` is cast `decimal:2`, so
        // the value arrives as a string and stays one all the way to the DB.
        return CurrencyScale::bcformatStrict($configuration->percentage_rate ?? '0', 2);
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
