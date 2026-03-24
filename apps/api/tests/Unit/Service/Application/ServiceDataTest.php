<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Application;

use App\Modules\Company\Domain\Company;
use App\Modules\Service\Application\DTOs\ServiceCategoryData;
use App\Modules\Service\Application\DTOs\ServiceData;
use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Service\Domain\Service;
use App\Modules\Service\Domain\ServiceCategory;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for Service DTOs.
 */
class ServiceDataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    #[Test]
    public function it_creates_service_data_from_model(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SRV-001',
            'name' => 'Oil Change',
            'description' => 'Engine oil replacement',
            'pricing_type' => PricingType::FlatRate,
            'base_price' => '45.00',
            'currency' => 'TND',
            'default_duration_minutes' => 30,
            'tax_rate' => '19.00',
            'is_active' => true,
        ]);

        $dto = ServiceData::fromModel($service);

        $this->assertEquals($service->id, $dto->id);
        $this->assertEquals('SRV-001', $dto->code);
        $this->assertEquals('Oil Change', $dto->name);
        $this->assertEquals('Engine oil replacement', $dto->description);
        $this->assertEquals(PricingType::FlatRate, $dto->pricing_type);
        $this->assertEquals('45.000', $dto->base_price);
        $this->assertEquals('TND', $dto->currency);
        $this->assertEquals(30, $dto->default_duration_minutes);
        $this->assertEquals('19.00', $dto->tax_rate);
        $this->assertTrue($dto->is_active);
        $this->assertNull($dto->category_id);
    }

    #[Test]
    public function it_includes_category_data_when_present(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);

        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'category_id' => $category->id,
        ]);

        // Load the category relation
        $service->load('category');

        $dto = ServiceData::fromModel($service);

        $this->assertEquals($category->id, $dto->category_id);
        $this->assertNotNull($dto->category);
        $this->assertInstanceOf(ServiceCategoryData::class, $dto->category);
        $this->assertEquals('Maintenance', $dto->category->name);
    }

    #[Test]
    public function it_creates_service_category_data_from_model(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Repair',
            'description' => 'All repair services',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $dto = ServiceCategoryData::fromModel($category);

        $this->assertEquals($category->id, $dto->id);
        $this->assertEquals('Repair', $dto->name);
        $this->assertEquals('All repair services', $dto->description);
        $this->assertEquals(10, $dto->sort_order);
        $this->assertTrue($dto->is_active);
        $this->assertNull($dto->parent_id);
    }

    #[Test]
    public function it_includes_parent_data_when_present(): void
    {
        $parent = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);

        $child = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Oil Change',
            'parent_id' => $parent->id,
        ]);

        $dto = ServiceCategoryData::fromModel($child);

        $this->assertEquals($parent->id, $dto->parent_id);
    }

    #[Test]
    public function it_includes_services_count_when_loaded(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        Service::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'category_id' => $category->id,
        ]);

        // Reload with count
        $category->loadCount('services');

        $dto = ServiceCategoryData::fromModel($category);

        $this->assertEquals(3, $dto->services_count);
    }

    #[Test]
    public function it_handles_hourly_pricing_type(): void
    {
        $service = Service::factory()->hourly('75.00')->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $dto = ServiceData::fromModel($service);

        $this->assertEquals(PricingType::Hourly, $dto->pricing_type);
        $this->assertEquals('75.000', $dto->hourly_rate);
    }

    #[Test]
    public function it_handles_percentage_pricing_type(): void
    {
        $service = Service::factory()->percentage('15.00')->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $dto = ServiceData::fromModel($service);

        $this->assertEquals(PricingType::Percentage, $dto->pricing_type);
        $this->assertEquals('15.000', $dto->base_price);
    }
}
