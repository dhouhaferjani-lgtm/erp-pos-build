<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application\DTOs;

use App\Modules\Cart\Domain\Enums\CartStatus;
use App\Modules\Cart\Domain\Models\CatalogCart;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CatalogCartData extends Data
{
    /**
     * @param  array<int, CatalogCartItemData>  $items
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $vehicle_id,
        public CartStatus $status,
        public bool $is_shared,
        public array $items,
        public ?string $notes,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(CatalogCart $cart): self
    {
        $cart->loadMissing('items');

        return new self(
            id: $cart->id,
            name: $cart->name,
            vehicle_id: $cart->vehicle_id,
            status: $cart->status,
            is_shared: (bool) $cart->is_shared,
            items: $cart->items->map(
                fn ($item): CatalogCartItemData => CatalogCartItemData::fromModel($item)
            )->all(),
            notes: $cart->notes,
            created_at: $cart->created_at?->toIso8601String() ?? '',
            updated_at: $cart->updated_at?->toIso8601String(),
        );
    }
}
