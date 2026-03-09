<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Application\Services;

use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Marketplace\Domain\Enums\ListingStatus;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Product\Domain\Product;

class ListingSyncService
{
    /**
     * Sync a product's data to a marketplace listing.
     *
     * Creates or updates a listing based on the product's current state.
     * Delists products that are inactive, non-physical, or have no sale price.
     */
    public function syncProduct(MarketplaceSeller $seller, Product $product): void
    {
        $listing = MarketplaceListing::where('seller_id', $seller->id)
            ->where('source_product_id', $product->id)
            ->first();

        // Delist if product is not listable
        if (! $product->is_active || ! $product->is_physical || $product->sale_price === null) {
            if ($listing !== null) {
                $listing->update([
                    'listing_status' => ListingStatus::Delisted,
                ]);
            }

            return;
        }

        $availableQuantity = $this->getAvailableQuantity($product);
        /** @var numeric-string $availableQuantity */
        $listingStatus = bccomp($availableQuantity, '0', 2) > 0
            ? ListingStatus::Active
            : ListingStatus::OutOfStock;

        $automotiveMetadata = $product->automotiveMetadata;

        $attributes = [
            'country_code' => $seller->country_code,
            'platform_article_id' => $automotiveMetadata?->platform_article_id,
            'article_number' => $automotiveMetadata !== null ? ($automotiveMetadata->article_number ?? $product->sku) : $product->sku,
            'barcode' => $product->barcode,
            'product_name' => $product->name,
            'supplier_brand' => $automotiveMetadata?->supplier_brand,
            'quality_tier' => $automotiveMetadata?->brand_quality_tier?->value,
            'price' => $product->sale_price,
            'currency' => $seller->currency,
            'quantity_available' => $availableQuantity,
            'min_order_quantity' => '1.00',
            'listing_status' => $listingStatus,
            'price_updated_at' => now(),
            'stock_updated_at' => now(),
        ];

        if ($listing !== null) {
            $listing->update($attributes);
        } else {
            MarketplaceListing::create(array_merge($attributes, [
                'seller_id' => $seller->id,
                'source_product_id' => $product->id,
            ]));
        }
    }

    /**
     * Get available (unreserved) quantity for a product across all locations.
     */
    public function getAvailableQuantity(Product $product): string
    {
        $stockLevels = StockLevel::where('product_id', $product->id)
            ->where('company_id', $product->company_id)
            ->get();

        $totalAvailable = '0.00';
        foreach ($stockLevels as $stockLevel) {
            /** @var numeric-string $available */
            $available = $stockLevel->getAvailableQuantity();
            $totalAvailable = bcadd($totalAvailable, $available, 2);
        }

        return $totalAvailable;
    }
}
