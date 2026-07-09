<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\DiscountPolicySubjectProviderInterface;
use App\Shared\Contracts\TaxConfigurationLookupInterface;
use App\Shared\DTOs\DiscountPolicyLineContext;
use App\Shared\DTOs\DiscountPolicySubject;
use App\Shared\DTOs\TaxConfigurationSummary;
use App\Shared\Exceptions\DiscountPolicySubjectNotFoundException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class DiscountPolicySubjectProvider implements DiscountPolicySubjectProviderInterface
{
    public function __construct(
        private readonly MarginResolver $marginResolver,
        private readonly TaxConfigurationLookupInterface $taxConfigurations,
    ) {}

    /**
     * @param  array<string, DiscountPolicyLineContext>  $contexts
     * @return array<string, DiscountPolicySubject>
     */
    public function resolveMany(string $companyId, array $contexts): array
    {
        $productIds = collect($contexts)
            ->map(fn (DiscountPolicyLineContext $context): string => $context->productId)
            ->unique()
            ->values()
            ->all();

        /** @var EloquentCollection<int, Product> $products */
        $products = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->with(['company', 'category'])
            ->get();

        /** @var Collection<string, Product> $productsById */
        $productsById = $products->keyBy('id');
        // Load ancestor categories ONCE and reuse the same map for margin resolution,
        // discount-cap resolution, and tax-configuration lookup — avoids a duplicate
        // identical Category whereIn query (MarginResolver would otherwise re-fetch it).
        $categoriesById = $this->loadCategoryAncestors($products);
        $marginsByProductId = $this->marginResolver->resolveMany($products, $categoriesById);
        $taxConfigurationsById = $this->loadTaxConfigurations($products, $categoriesById);
        $policyAsOf = now()->toIso8601String();

        $subjects = [];
        foreach ($contexts as $lineKey => $context) {
            $product = $productsById->get($context->productId);
            if (! $product instanceof Product) {
                throw new DiscountPolicySubjectNotFoundException($context->productId);
            }

            $company = $product->company;
            $category = $product->category;
            $categoryCaps = $this->categoryCapsNearestFirst($product, $categoriesById);
            $taxConfigurationId = $product->default_tax_configuration_id
                ?? ($category instanceof Category ? $category->default_tax_configuration_id : null)
                ?? $company->default_tax_configuration_id;
            $taxConfiguration = $taxConfigurationId !== null ? $taxConfigurationsById->get($taxConfigurationId) : null;
            $resolvedTaxRate = $taxConfiguration instanceof TaxConfigurationSummary
                ? (string) ($taxConfiguration->percentageRate ?? '0.00')
                : (string) ($product->tax_rate ?? ($category instanceof Category ? $category->default_tax_rate : null) ?? $company->default_tax_rate ?? '0.00');

            $subjects[$lineKey] = DiscountPolicySubject::make(
                companyId: $company->id,
                productId: $product->id,
                variantId: $context->variantId,
                productMaxDiscountPercent: $product->max_discount_percent !== null ? (string) $product->max_discount_percent : null,
                categoryMaxDiscountPercents: $categoryCaps,
                companyMaxDiscountPercent: $company->default_max_discount_percent !== null ? (string) $company->default_max_discount_percent : null,
                salePriceNet: $product->sale_price !== null ? (string) $product->sale_price : null,
                wacNet: (string) $product->cost_price,
                lastPurchaseCost: $product->last_purchase_cost !== null ? (string) $product->last_purchase_cost : null,
                minimumMarginPercent: $marginsByProductId[$product->id]->minimum_margin,
                currency: $company->currency,
                taxConfigurationId: $taxConfigurationId,
                taxRate: $product->tax_rate !== null ? (string) $product->tax_rate : null,
                resolvedTaxRate: $resolvedTaxRate,
                discountFloorMode: $company->discount_floor_mode->value,
                priceEntryMode: $company->price_entry_mode->value,
                policyAsOf: $policyAsOf,
                countryCode: $company->country_code,
            );
        }

        return $subjects;
    }

    public function resolve(string $companyId, string $productId, ?string $variantId = null): DiscountPolicySubject
    {
        return $this->resolveMany($companyId, [
            'single' => new DiscountPolicyLineContext(productId: $productId, variantId: $variantId),
        ])['single'];
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @return Collection<int, Category>
     */
    private function loadCategoryAncestors(EloquentCollection $products): Collection
    {
        $categoryIds = $products
            ->map(fn (Product $product): ?Category => $product->category)
            ->filter()
            ->flatMap(fn (Category $category): array => explode('/', $category->path))
            ->filter(fn (string $id): bool => $id !== '')
            ->map(fn (string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($categoryIds === []) {
            return collect();
        }

        return Category::query()
            ->whereIn('id', $categoryIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, Category>  $categoriesById
     * @return array<int, string|null>
     */
    private function categoryCapsNearestFirst(Product $product, Collection $categoriesById): array
    {
        if ($product->category === null) {
            return [];
        }

        $caps = [];
        foreach (array_reverse(explode('/', $product->category->path)) as $id) {
            if ($id === '') {
                continue;
            }

            $category = $categoriesById->get((int) $id);
            if ($category instanceof Category) {
                $caps[] = $category->max_discount_percent !== null ? (string) $category->max_discount_percent : null;
            }
        }

        return $caps;
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @param  Collection<int, Category>  $categoriesById
     * @return Collection<string, TaxConfigurationSummary>
     */
    private function loadTaxConfigurations(EloquentCollection $products, Collection $categoriesById): Collection
    {
        $taxConfigurationIds = $products
            ->flatMap(function (Product $product) use ($categoriesById): array {
                $ids = [
                    $product->default_tax_configuration_id,
                    $product->company->default_tax_configuration_id,
                ];

                if ($product->category !== null) {
                    foreach (explode('/', $product->category->path) as $categoryId) {
                        $category = $categoriesById->get((int) $categoryId);
                        if ($category instanceof Category) {
                            $ids[] = $category->default_tax_configuration_id;
                        }
                    }
                }

                return $ids;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($taxConfigurationIds === []) {
            return collect();
        }

        return collect($this->taxConfigurations->findManyById($taxConfigurationIds));
    }
}
