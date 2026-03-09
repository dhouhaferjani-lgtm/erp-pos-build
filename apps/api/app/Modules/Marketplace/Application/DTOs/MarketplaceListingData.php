<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Application\DTOs;

use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MarketplaceListingData extends Data
{
    public function __construct(
        public string $listing_id,
        public string $price,
        public string $currency,
        public string $product_name,
        public ?string $supplier_brand,
        public ?string $article_number,
        public ?string $quality_tier,
        public string $min_order_quantity,
        public string $quantity_available,
        public string $country_code,
    ) {}

    public static function fromModel(MarketplaceListing $listing): self
    {
        return new self(
            listing_id: $listing->id,
            price: (string) $listing->price,
            currency: $listing->currency,
            product_name: $listing->product_name,
            supplier_brand: $listing->supplier_brand,
            article_number: $listing->article_number,
            quality_tier: $listing->quality_tier,
            min_order_quantity: (string) $listing->min_order_quantity,
            quantity_available: (string) $listing->quantity_available,
            country_code: $listing->country_code,
        );
    }
}
