<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\ProductServiceInterface;

/**
 * Application service for product operations.
 *
 * Exposes product functionality to other modules through the ProductServiceInterface.
 */
final class ProductService implements ProductServiceInterface
{
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
        $product = Product::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'sku' => $data['sku'],
            ],
            [
                'name' => $data['name'],
                'type' => isset($data['type']) ? ProductType::from($data['type']) : null,
                'description' => $this->emptyToNull($data['description'] ?? null),
                'sale_price' => $this->emptyToNull($data['sale_price'] ?? null),
                'purchase_price' => $this->emptyToNull($data['purchase_price'] ?? null),
                'barcode' => $this->emptyToNull($data['barcode'] ?? null),
            ]
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
