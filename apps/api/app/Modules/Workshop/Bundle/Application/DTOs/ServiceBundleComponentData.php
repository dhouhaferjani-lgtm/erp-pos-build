<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\DTOs;

use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use App\Shared\Domain\CurrencyScale;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ServiceBundleComponentData extends Data
{
    public function __construct(
        public string $id,
        public string $bundle_id,
        public BundleComponentType $component_type,
        public string $component_id,
        public string $component_display_name,
        public string $quantity,
        public string $unit,
        public ?string $override_unit_price,
        public bool $is_optional,
        public int $display_order,
        public ?string $notes,
    ) {}

    public static function fromModel(ServiceBundleComponent $component, int $scale = 3): self
    {
        $componentId = match ($component->component_type) {
            BundleComponentType::Part => $component->product_id,
            BundleComponentType::Labor => $component->service_id,
            BundleComponentType::NestedBundle => $component->nested_bundle_id,
        };

        $displayName = $component->relationLoaded('product') && $component->product !== null
            ? $component->product->name
            : ($component->relationLoaded('service') && $component->service !== null
                ? $component->service->name
                : ($component->relationLoaded('nestedBundle') && $component->nestedBundle !== null
                    ? $component->nestedBundle->name
                    : ''));

        $unit = $component->relationLoaded('unit')
            ? $component->unit->symbol
            : '';

        return new self(
            id: $component->id,
            bundle_id: $component->bundle_id,
            component_type: $component->component_type,
            component_id: (string) ($componentId ?? ''),
            component_display_name: $displayName,
            quantity: CurrencyScale::bcformat($component->quantity, $scale),
            unit: $unit,
            override_unit_price: $component->override_unit_price !== null
                ? CurrencyScale::bcformat($component->override_unit_price, $scale)
                : null,
            is_optional: $component->is_optional,
            display_order: $component->display_order,
            notes: $component->notes,
        );
    }
}
