<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Repositories;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use Illuminate\Support\Collection;

interface ProductVariantRepository
{
    public function findById(string $id): ?ProductVariant;

    public function findByBarcode(string $barcode, string $companyId): ?ProductVariant;

    public function findBySku(string $sku, string $companyId): ?ProductVariant;

    /**
     * @return Collection<int, ProductVariant>
     */
    public function listForProduct(string $productId, bool $onlyActive = true): Collection;

    public function save(ProductVariant $variant): void;

    public function softDelete(string $id): void;
}
