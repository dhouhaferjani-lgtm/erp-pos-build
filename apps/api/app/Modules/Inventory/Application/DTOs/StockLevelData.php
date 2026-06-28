<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class StockLevelData extends Data
{
    public function __construct(
        public string $id,
        public string $product_id,
        public ?string $product_name,
        public string $location_id,
        public ?string $location_name,
        public string $quantity,
        public string $reserved,
        public string $available,
        public string $incoming,
        public string $projected_available,
        public ?string $min_quantity,
        public ?string $max_quantity,
        public bool $is_below_minimum,
        public int $quantity_decimals,
    ) {}

    public static function fromModel(StockLevel $stockLevel, string $incoming = '0.00'): self
    {
        /** @var numeric-string $available */
        $available = $stockLevel->getAvailableQuantity();
        /** @var numeric-string $incoming */
        $projectedAvailable = bcadd($available, $incoming, 4);

        return new self(
            id: $stockLevel->id,
            product_id: $stockLevel->product_id,
            product_name: $stockLevel->product->name ?? null,
            location_id: $stockLevel->location_id,
            location_name: $stockLevel->location->name ?? null,
            quantity: (string) $stockLevel->quantity,
            reserved: (string) $stockLevel->reserved,
            available: $available,
            incoming: $incoming,
            projected_available: $projectedAvailable,
            min_quantity: $stockLevel->min_quantity !== null ? (string) $stockLevel->min_quantity : null,
            max_quantity: $stockLevel->max_quantity !== null ? (string) $stockLevel->max_quantity : null,
            is_below_minimum: $stockLevel->isBelowMinimum(),
            quantity_decimals: self::resolveQuantityDecimals($stockLevel),
        );
    }

    /**
     * Derive the product's unit precision (decimal_places) so the UI steps the
     * quantity by the unit. Falls back to the canonical storage scale (4) when
     * the product.unitOfMeasure chain is not eager-loaded.
     */
    private static function resolveQuantityDecimals(StockLevel $stockLevel): int
    {
        if (! $stockLevel->relationLoaded('product')) {
            return 4;
        }

        // getRelation() (not the typed `->product` accessor) so the null case is
        // visible to PHPStan: Product uses SoftDeletes, so the loaded relation is
        // null when the product was archived — guarding here avoids a 500.
        $product = $stockLevel->getRelation('product');

        if (! $product instanceof Product
            || ! $product->relationLoaded('unitOfMeasure')
            || $product->unitOfMeasure === null) {
            return 4;
        }

        return $product->unitOfMeasure->decimal_places;
    }
}
