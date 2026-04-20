<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\Bundle;

use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleVehicleApplicability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ServiceBundleModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_bundle_with_defaults(): void
    {
        $bundle = ServiceBundle::factory()->create();

        $this->assertSame(BundlePricingMode::Standard, $bundle->pricing_mode);
        $this->assertTrue($bundle->is_active);
        $this->assertNull($bundle->base_price);
        $this->assertSame('TND', $bundle->currency);
    }

    public function test_factory_creates_fixed_bundle(): void
    {
        $bundle = ServiceBundle::factory()->fixedBundle('130.000')->create();

        $this->assertSame(BundlePricingMode::FixedBundle, $bundle->pricing_mode);
        $this->assertSame('130.000', $bundle->base_price);
    }

    public function test_components_relation_filters_bundle_components(): void
    {
        $bundle = ServiceBundle::factory()->create();
        ServiceBundleComponent::factory()->forBundle($bundle)->count(3)->create();

        $this->assertCount(3, $bundle->components);
    }

    public function test_vehicle_applicability_universal_detection(): void
    {
        $bundle = ServiceBundle::factory()->create();
        $universal = ServiceBundleVehicleApplicability::factory()->forBundle($bundle)->universal()->create();

        $this->assertTrue($universal->isUniversal());
    }

    public function test_component_type_enum_cast(): void
    {
        $bundle = ServiceBundle::factory()->create();
        $component = ServiceBundleComponent::factory()->forBundle($bundle)->create();

        $this->assertInstanceOf(BundleComponentType::class, $component->component_type);
    }
}
