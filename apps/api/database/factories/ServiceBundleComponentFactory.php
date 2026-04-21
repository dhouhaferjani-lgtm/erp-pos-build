<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceBundleComponent>
 */
class ServiceBundleComponentFactory extends Factory
{
    /**
     * @var class-string<ServiceBundleComponent>
     */
    protected $model = ServiceBundleComponent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attrs): string => (string) (ServiceBundle::find($attrs['bundle_id'])?->tenant_id
                ?? $this->faker->uuid()),
            'bundle_id' => ServiceBundle::factory(),
            'component_type' => BundleComponentType::Part,
            'product_id' => fn (array $attrs): string => Product::factory()->create([
                'tenant_id' => $attrs['tenant_id'],
                'company_id' => ServiceBundle::find($attrs['bundle_id'])?->company_id,
            ])->id,
            'service_id' => null,
            'nested_bundle_id' => null,
            'quantity' => '1.000',
            'unit_id' => Unit::factory(),
            'override_unit_price' => null,
            'is_optional' => false,
            'display_order' => 0,
            'notes' => null,
        ];
    }

    /**
     * Component represents a Product (part) line.
     */
    public function part(Product $product, string $quantity = '1.000'): static
    {
        return $this->state(fn (array $attributes): array => [
            'component_type' => BundleComponentType::Part,
            'product_id' => $product->id,
            'service_id' => null,
            'nested_bundle_id' => null,
            'quantity' => $quantity,
        ]);
    }

    /**
     * Component represents a labor (Service) line.
     */
    public function labor(Service $service, string $quantity = '1.000'): static
    {
        return $this->state(fn (array $attributes): array => [
            'component_type' => BundleComponentType::Labor,
            'product_id' => null,
            'service_id' => $service->id,
            'nested_bundle_id' => null,
            'quantity' => $quantity,
        ]);
    }

    /**
     * Component represents a nested bundle.
     */
    public function nestedBundle(ServiceBundle $nested, string $quantity = '1.000'): static
    {
        return $this->state(fn (array $attributes): array => [
            'component_type' => BundleComponentType::NestedBundle,
            'product_id' => null,
            'service_id' => null,
            'nested_bundle_id' => $nested->id,
            'quantity' => $quantity,
        ]);
    }

    /**
     * Attach the component to a specific bundle (inherits tenant).
     */
    public function forBundle(ServiceBundle $bundle): static
    {
        return $this->state(fn (array $attributes): array => [
            'bundle_id' => $bundle->id,
            'tenant_id' => $bundle->tenant_id,
        ]);
    }

    /**
     * Override the unit price for the component.
     */
    public function overridePrice(string $price): static
    {
        return $this->state(fn (array $attributes): array => [
            'override_unit_price' => $price,
        ]);
    }

    /**
     * Mark the component as optional.
     */
    public function optional(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_optional' => true,
        ]);
    }
}
