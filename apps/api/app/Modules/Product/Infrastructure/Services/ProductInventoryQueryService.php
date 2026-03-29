<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Services;

use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\ProductInventoryQueryInterface;
use App\Shared\DTOs\ProductInventoryDTO;
use Illuminate\Support\Collection;

final class ProductInventoryQueryService implements ProductInventoryQueryInterface
{
    /**
     * @param  array<int, string>  $platformArticleIds
     * @return Collection<string, ProductInventoryDTO>
     */
    public function findByPlatformArticleIds(string $companyId, array $platformArticleIds): Collection
    {
        return Product::query()
            ->where('company_id', $companyId)
            ->whereHas('automotiveMetadata', function ($query) use ($platformArticleIds): void {
                $query->whereIn('platform_article_id', $platformArticleIds);
            })
            ->with(['automotiveMetadata', 'stockLevels'])
            ->get()
            ->mapWithKeys(function (Product $product): array {
                $platformArticleId = (string) ($product->automotiveMetadata->platform_article_id ?? '');
                /** @var numeric-string $totalStock */
                $totalStock = (string) $product->stockLevels->sum('quantity');
                /** @var numeric-string $totalReserved */
                $totalReserved = (string) $product->stockLevels->sum('reserved');

                return [$platformArticleId => new ProductInventoryDTO(
                    productId: $product->id,
                    platformArticleId: $platformArticleId,
                    totalStock: $totalStock,
                    totalReserved: $totalReserved,
                    available: bcsub($totalStock, $totalReserved, 2),
                    salePrice: $product->sale_price !== null ? (string) $product->sale_price : null,
                )];
            });
    }
}
