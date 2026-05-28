<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Services;

use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\ProductInventoryQueryInterface;
use App\Shared\DTOs\ProductInventoryDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ProductInventoryQueryService implements ProductInventoryQueryInterface
{
    /**
     * @param  array<int, string>  $platformArticleIds
     * @return Collection<string, ProductInventoryDTO>
     */
    public function findByPlatformArticleIds(string $companyId, array $platformArticleIds): Collection
    {
        // platform_article_id is a uuid column. External platform article ids are
        // not always uuids (e.g. an unlinked marketplace ref); a non-uuid value can
        // never match a local product and on PostgreSQL raises 22P02 (invalid uuid
        // syntax), which would 500 the whole catalog-browse enrichment. Drop
        // non-uuid ids up front — they are simply unmatched, not an error.
        $validArticleIds = array_values(array_filter(
            $platformArticleIds,
            static fn (string $id): bool => Str::isUuid($id),
        ));

        if ($validArticleIds === []) {
            /** @var Collection<string, ProductInventoryDTO> $empty */
            $empty = collect();

            return $empty;
        }

        return Product::query()
            ->where('company_id', $companyId)
            ->whereHas('automotiveMetadata', function ($query) use ($validArticleIds): void {
                $query->whereIn('platform_article_id', $validArticleIds);
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
