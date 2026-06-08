<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Repositories;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\Repositories\ProductVariantRepository;
use Illuminate\Support\Collection;

final readonly class EloquentProductVariantRepository implements ProductVariantRepository
{
    public function findById(string $id): ?ProductVariant
    {
        return ProductVariant::find($id);
    }

    public function findByBarcode(string $barcode, string $companyId): ?ProductVariant
    {
        return ProductVariant::where('barcode', $barcode)
            ->where('company_id', $companyId)
            ->first();
    }

    public function findBySku(string $sku, string $companyId): ?ProductVariant
    {
        return ProductVariant::where('sku', $sku)
            ->where('company_id', $companyId)
            ->first();
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    public function listForProduct(string $productId, bool $onlyActive = true): Collection
    {
        $query = ProductVariant::where('product_id', $productId);

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        return $query->orderBy('display_order')->get();
    }

    public function save(ProductVariant $variant): void
    {
        $variant->save();
    }

    public function softDelete(string $id): void
    {
        $variant = $this->findById($id);

        if ($variant !== null) {
            $variant->delete();
        }
    }
}
