<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Application\Services;

use App\Modules\Marketplace\Application\DTOs\PriceComparisonData;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;

class PriceComparisonService
{
    /**
     * Find marketplace listings cheaper than the current price.
     *
     * Returns comparison data if cheaper alternatives exist, null otherwise.
     */
    public function findBetterPrices(
        string $countryCode,
        ?string $articleNumber,
        ?string $barcode,
        string $currentPrice,
    ): ?PriceComparisonData {
        if ($articleNumber === null && $barcode === null) {
            return null;
        }

        $query = MarketplaceListing::query()
            ->available()
            ->forCountry($countryCode)
            ->whereHas('seller', function (\Illuminate\Database\Eloquent\Builder $q): void {
                $q->whereRaw('seller_status = ?', ['active']);
            })
            ->where('price', '<', $currentPrice);

        $query->where(function ($q) use ($articleNumber, $barcode): void {
            if ($articleNumber !== null) {
                $q->orWhere('article_number', $articleNumber);
            }
            if ($barcode !== null) {
                $q->orWhere('barcode', $barcode);
            }
        });

        $listings = $query->orderBy('price')->get();

        if ($listings->isEmpty()) {
            return null;
        }

        $cheapest = $listings->first();
        /** @var numeric-string $bestPrice */
        $bestPrice = (string) $cheapest->price;
        /** @var numeric-string $currentPriceNumeric */
        $currentPriceNumeric = $currentPrice;
        $savingsAmount = bcsub($currentPriceNumeric, $bestPrice, 3);

        // Calculate savings percentage: (savings / currentPrice) * 100
        $savingsPercent = '0.00';
        if (bccomp($currentPriceNumeric, '0', 3) > 0) {
            $savingsPercent = bcmul(bcdiv($savingsAmount, $currentPrice, 5), '100', 2);
        }

        return new PriceComparisonData(
            current_price: $currentPrice,
            best_marketplace_price: $bestPrice,
            savings_amount: $savingsAmount,
            savings_percent: $savingsPercent,
            available_listings_count: $listings->count(),
            cheapest_listing_id: $cheapest->id,
        );
    }
}
