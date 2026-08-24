<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Services\TaxResolutionService;
use App\Shared\Contracts\ProductServiceInterface;
use App\Shared\DTOs\CategoryResolutionDTO;
use Illuminate\Support\Str;

/**
 * Application service for product operations.
 *
 * Exposes product functionality to other modules through the ProductServiceInterface.
 */
final class ProductService implements ProductServiceInterface
{
    public function __construct(
        private readonly TaxResolutionService $taxResolution,
        private readonly BrandResolutionService $brandResolution,
        private readonly CategoryResolutionService $categoryResolution,
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
        $fileSku = $this->emptyToNull($data['sku'] ?? null);
        $barcode = $this->emptyToNull($data['barcode'] ?? null);
        $nameSku = $this->skuFromName((string) $data['name']);
        $existing = $this->findExistingProduct($tenantId, $companyId, $fileSku, $barcode, $nameSku);
        $createSku = $fileSku ?? ($barcode ?? $nameSku);

        $attributes = [
            'name' => $data['name'],
            'type' => ProductType::from((string) ($this->emptyToNull($data['type'] ?? null) ?? ProductType::Part->value)),
            'description' => $this->emptyToNull($data['description'] ?? null),
            'sale_price' => $this->emptyToNull($data['sale_price'] ?? null),
            'purchase_price' => $this->emptyToNull($data['purchase_price'] ?? null),
            'barcode' => $barcode,
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

        // W2-3: create on miss, exactly as the brand block below does. Lookup-only
        // meant a day-one tenant (empty `categories`, and no categories import
        // exists) lost every category_name with no warning anywhere.
        if (isset($data['category_name']) && trim((string) $data['category_name']) !== '') {
            $attributes['category_id'] = $this->categoryResolution
                ->resolve($companyId, (string) $data['category_name'])
                ->categoryId;
        }

        if (isset($data['brand']) && trim((string) $data['brand']) !== '') {
            $brand = $this->brandResolution->resolve($tenantId, trim((string) $data['brand']), null, null)->brand;
            $attributes['brand_id'] = $brand->id;
            $attributes['brand_source'] = BrandSource::User;
        }

        if (($attributes['tax_rate'] ?? null) === null) {
            if ($existing !== null && $existing->tax_rate !== null) {
                // Re-import without a rate column must not clobber an existing explicit rate.
                $attributes['tax_rate'] = $existing->tax_rate;
            } else {
                $company = Company::where('tenant_id', $tenantId)
                    ->where('id', $companyId)
                    ->firstOrFail();

                $attributes['tax_rate'] = $this->taxResolution->getDefaultTaxForNewProduct(
                    $company,
                    $attributes['category_id'] ?? null,
                );
            }
        }

        if ($existing !== null) {
            $existing->fill($attributes);
            $existing->save();

            return $existing->id;
        }

        $product = Product::create(
            array_merge([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'sku' => $createSku,
            ], $attributes)
        );

        return $product->id;
    }

    public function resolveCategoryByName(string $companyId, string $name): CategoryResolutionDTO
    {
        return $this->categoryResolution->resolve($companyId, $name);
    }

    private function skuFromName(string $name): string
    {
        $sku = strtoupper(substr(Str::slug($name, '-'), 0, 100));

        return $sku !== '' ? $sku : 'PRODUCT';
    }

    private function findExistingProduct(
        string $tenantId,
        string $companyId,
        ?string $fileSku,
        ?string $barcode,
        string $nameSku
    ): ?Product {
        $query = Product::where('tenant_id', $tenantId)
            ->where('company_id', $companyId);

        if ($fileSku !== null) {
            return $query->where('sku', $fileSku)->first();
        }

        if ($barcode !== null) {
            return $query->where('barcode', $barcode)->first();
        }

        return $query->where('sku', $nameSku)->first();
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
