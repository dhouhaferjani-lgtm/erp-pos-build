<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Catalog\Domain\Entities\Modifier;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ModifierData extends Data
{
    public function __construct(
        public string $id,
        public string $modifier_group_id,
        public string $code,
        public string $name,
        public string $price_adjustment,
        public bool $has_inventory_impact,
        public ?string $component_type,
        public ?string $component_id,
        public ?string $component_quantity,
        public ?string $component_unit_id,
        public bool $is_default,
        public bool $is_active,
        public int $display_order,
    ) {}

    public static function fromModel(Modifier $modifier): self
    {
        return new self(
            id: $modifier->id,
            modifier_group_id: $modifier->modifier_group_id,
            code: $modifier->code,
            name: $modifier->name,
            price_adjustment: (string) $modifier->price_adjustment,
            has_inventory_impact: $modifier->hasInventoryImpact(),
            component_type: $modifier->component_type?->value,
            component_id: $modifier->component_id,
            component_quantity: $modifier->component_quantity !== null ? (string) $modifier->component_quantity : null,
            component_unit_id: $modifier->component_unit_id,
            is_default: $modifier->is_default,
            is_active: $modifier->is_active,
            display_order: $modifier->display_order,
        );
    }
}
