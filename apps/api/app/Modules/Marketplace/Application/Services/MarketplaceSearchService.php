<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Application\Services;

use App\Modules\Marketplace\Application\DTOs\MarketplaceListingData;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceSearchService
{
    /**
     * Find available marketplace listings matching the given criteria.
     *
     * Returns anonymous listing data (no seller identity) sorted by price ascending.
     * Uses OR logic: matches platformArticleId OR articleNumber OR barcode.
     *
     * @return array<int, MarketplaceListingData>
     */
    public function findListings(
        string $countryCode,
        ?string $platformArticleId = null,
        ?string $articleNumber = null,
        ?string $barcode = null,
    ): array {
        if ($platformArticleId === null && $articleNumber === null && $barcode === null) {
            return [];
        }

        $listings = MarketplaceListing::query()
            ->available()
            ->forCountry($countryCode)
            ->forArticle($platformArticleId, $articleNumber, $barcode)
            ->whereHas('seller', function (Builder $query): void {
                $query->whereRaw('seller_status = ?', ['active']);
            })
            ->orderBy('price')
            ->get();

        return $listings->map(
            fn (MarketplaceListing $listing): MarketplaceListingData => MarketplaceListingData::fromModel($listing)
        )->all();
    }
}
