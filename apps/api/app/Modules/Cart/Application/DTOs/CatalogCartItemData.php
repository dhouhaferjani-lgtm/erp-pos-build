<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application\DTOs;

use App\Modules\Cart\Domain\Enums\CartItemSource;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CatalogCartItemData extends Data
{
    public function __construct(
        public string $id,
        public string $article_name,
        public ?string $article_number,
        public ?string $supplier_brand,
        public string $quantity,
        public ?string $unit_price,
        public ?string $currency,
        public CartItemSource $source,
        public ?string $product_id,
        public ?string $marketplace_listing_id,
        public ?string $reservation_id,
        public ?string $reservation_expires_at,
        public ?string $notes,
        public int $sort_order,
    ) {}

    public static function fromModel(CatalogCartItem $item): self
    {
        return new self(
            id: $item->id,
            article_name: $item->article_name,
            article_number: $item->article_number,
            supplier_brand: $item->supplier_brand,
            quantity: (string) $item->quantity,
            unit_price: $item->unit_price !== null ? (string) $item->unit_price : null,
            currency: $item->currency,
            source: $item->source,
            product_id: $item->product_id,
            marketplace_listing_id: $item->marketplace_listing_id,
            reservation_id: $item->reservation_id,
            reservation_expires_at: $item->reservation_expires_at?->toIso8601String(),
            notes: $item->notes,
            sort_order: $item->sort_order,
        );
    }
}
