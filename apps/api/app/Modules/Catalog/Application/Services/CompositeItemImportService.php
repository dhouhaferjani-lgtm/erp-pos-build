<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Enums\PricingMode;
use App\Modules\Catalog\Domain\Enums\ProductionType;
use App\Modules\Catalog\Domain\Enums\VerticalType;
use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Category;
use App\Modules\Taxation\Domain\Services\TaxResolutionService;
use App\Shared\Contracts\CompositeItemServiceInterface;

final class CompositeItemImportService implements CompositeItemServiceInterface
{
    public function __construct(
        private readonly TaxResolutionService $taxResolutionService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(string $tenantId, string $companyId, array $data): string
    {
        /** @var string $code */
        $code = $data['code'];

        $attributes = [
            'name' => $data['name'],
            'base_price' => $data['base_price'],
            'vertical_type' => $this->resolveVerticalType($data),
            'production_type' => $this->resolveProductionType($data),
            'pricing_mode' => $this->resolvePricingMode($data),
        ];

        // Resolve the tax_rate from the import row.
        // An empty/absent value in the data means "no explicit rate supplied".
        $explicitTaxRate = (isset($data['tax_rate']) && $data['tax_rate'] !== '')
            ? (string) $data['tax_rate']
            : null;

        if (isset($data['manual_cost']) && $data['manual_cost'] !== '') {
            $attributes['manual_cost'] = $data['manual_cost'];
        }

        if (isset($data['is_active']) && $data['is_active'] !== '') {
            $attributes['is_active'] = in_array(strtolower((string) $data['is_active']), ['true', '1', 'yes'], true);
        }

        $resolvedCategoryId = null;
        if (isset($data['category_name']) && $data['category_name'] !== '') {
            $category = Category::where('company_id', $companyId)
                ->where('name', $data['category_name'])
                ->first();
            if ($category !== null) {
                $attributes['category_id'] = $category->id;
                $resolvedCategoryId = $category->id;
            }
        }

        $item = CompositeItem::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();

        if ($item !== null) {
            // Re-import: if the caller supplied an explicit rate, use it.
            // If no rate was supplied but the composite already has one, preserve it (no clobber).
            // Only resolve the company default when the existing record has no rate at all.
            if ($explicitTaxRate !== null) {
                $attributes['tax_rate'] = $explicitTaxRate;
            } elseif ($item->tax_rate === null) {
                $company = Company::where('id', $companyId)->firstOrFail();
                $attributes['tax_rate'] = $this->taxResolutionService->getDefaultTaxForNewProduct(
                    $company,
                    $resolvedCategoryId,
                );
            }
            // else: existing non-null rate is preserved — tax_rate not included in $attributes

            $item->update($attributes);

            return $item->id;
        }

        // New composite: resolve tax rate.
        if ($explicitTaxRate !== null) {
            $attributes['tax_rate'] = $explicitTaxRate;
        } else {
            $company = Company::where('id', $companyId)->firstOrFail();
            $attributes['tax_rate'] = $this->taxResolutionService->getDefaultTaxForNewProduct(
                $company,
                $resolvedCategoryId,
            );
        }

        $item = CompositeItem::create([
            ...$attributes,
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'code' => $code,
        ]);

        return $item->id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveVerticalType(array $data): VerticalType
    {
        if (isset($data['vertical_type']) && $data['vertical_type'] !== '') {
            return VerticalType::from((string) $data['vertical_type']);
        }

        return VerticalType::Fnb;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveProductionType(array $data): ProductionType
    {
        if (isset($data['production_type']) && $data['production_type'] !== '') {
            return ProductionType::from((string) $data['production_type']);
        }

        return ProductionType::MadeToOrder;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePricingMode(array $data): PricingMode
    {
        if (isset($data['pricing_mode']) && $data['pricing_mode'] !== '') {
            return PricingMode::from((string) $data['pricing_mode']);
        }

        return PricingMode::Standard;
    }
}
