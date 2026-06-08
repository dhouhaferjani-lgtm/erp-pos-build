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

    public static function fromModel(ServiceBundleComponent $component, int $scale = 4): self
    {
        $componentId = match ($component->component_type) {
            BundleComponentType::Part => $component->product_id,
            BundleComponentType::Labor => $component->service_id,
            BundleComponentType::NestedBundle => $component->nested_bundle_id,
        };

        $displayName = match ($component->component_type) {
            BundleComponentType::Part => self::resolvePartName($component),
            BundleComponentType::Labor => self::resolveLaborName($component),
            BundleComponentType::NestedBundle => self::resolveNestedBundleName($component),
        };

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

    private static function resolvePartName(ServiceBundleComponent $component): string
    {
        // Use the eager-loaded relation when present; fall back to a
        // lazy load so that upstream call sites that only loaded
        // `components` (without `components.product`) still produce a
        // non-empty display name.
        $product = $component->relationLoaded('product')
            ? $component->product
            : $component->product()->first();

        return $product === null ? '' : $product->name;
    }

    private static function resolveLaborName(ServiceBundleComponent $component): string
    {
        $service = $component->relationLoaded('service')
            ? $component->service
            : $component->service()->first();

        return $service === null ? '' : $service->name;
    }

    private static function resolveNestedBundleName(ServiceBundleComponent $component): string
    {
        $nested = $component->relationLoaded('nestedBundle')
            ? $component->nestedBundle
            : $component->nestedBundle()->first();

        return $nested === null ? '' : $nested->name;
    }
}
