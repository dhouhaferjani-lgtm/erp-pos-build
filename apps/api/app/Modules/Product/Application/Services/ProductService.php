<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Services\TaxResolutionService;
use App\Shared\Contracts\ProductServiceInterface;

/**
 * Application service for product operations.
 *
 * Exposes product functionality to other modules through the ProductServiceInterface.
 */
final class ProductService implements ProductServiceInterface
{
    public function __construct(
        private readonly TaxResolutionService $taxResolution,
    ) {}

    /**
     * Find a product by SKU.
     *
     * @return string|null Product ID or null if not found
     */
    public function findIdBySku(
        string $tenantId,
        string $companyId,
        string $sku
    ): ?string {
        $product = Product::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->first();

        return $product?->id;
    }

    /**
     * Create or update a product.
     *
     * @param  array<string, mixed>  $data  Product data
     * @return string The product ID
     */
    public function upsert(
        string $tenantId,
        string $companyId,
        array $data
    ): string {
        $attributes = [
            'name' => $data['name'],
            'type' => isset($data['type']) ? ProductType::from($data['type']) : null,
            'description' => $this->emptyToNull($data['description'] ?? null),
            'sale_price' => $this->emptyToNull($data['sale_price'] ?? null),
            'purchase_price' => $this->emptyToNull($data['purchase_price'] ?? null),
            'barcode' => $this->emptyToNull($data['barcode'] ?? null),
            'tax_rate' => $this->emptyToNull($data['tax_rate'] ?? null),
            'unit' => $this->emptyToNull($data['unit'] ?? null),
        ];

        if (isset($data['is_active']) && $data['is_active'] !== '') {
            $attributes['is_active'] = in_array(
                strtolower((string) $data['is_active']),
                ['true', '1', 'yes'],
                true
            );
        }

        if (isset($data['category_name']) && $data['category_name'] !== '') {
            $category = Category::where('company_id', $companyId)
                ->where('name', $data['category_name'])
                ->first();
            if ($category !== null) {
                $attributes['category_id'] = $category->id;
            }
        }

        if (($attributes['tax_rate'] ?? null) === null) {
            $company = Company::where('tenant_id', $tenantId)
                ->where('id', $companyId)
                ->firstOrFail();

            $attributes['tax_rate'] = $this->taxResolution->getDefaultTaxForNewProduct(
                $company,
                $attributes['category_id'] ?? null,
            );
        }

        $product = Product::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'sku' => $data['sku'],
            ],
            $attributes
        );

        return $product->id;
    }

    /**
     * Convert empty strings to null.
     */
    private function emptyToNull(mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return $value;
    }
}
