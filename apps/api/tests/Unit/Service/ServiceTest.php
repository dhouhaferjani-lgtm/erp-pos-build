<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Modules\Company\Domain\Company;
use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Service\Domain\Service;
use App\Modules\Service\Domain\ServiceCategory;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for Service model.
 */
class ServiceTest extends TestCase
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
    public function it_belongs_to_tenant(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->assertInstanceOf(Tenant::class, $service->tenant);
        $this->assertEquals($this->tenant->id, $service->tenant->id);
    }

    #[Test]
    public function it_belongs_to_company(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->assertInstanceOf(Company::class, $service->company);
        $this->assertEquals($this->company->id, $service->company->id);
    }

    #[Test]
    public function it_belongs_to_category(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'category_id' => $category->id,
        ]);

        $this->assertInstanceOf(ServiceCategory::class, $service->category);
        $this->assertEquals($category->id, $service->category->id);
    }

    #[Test]
    public function it_can_have_null_category(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'category_id' => null,
        ]);

        $this->assertNull($service->category);
    }

    #[Test]
    public function it_casts_pricing_type_to_enum(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'pricing_type' => 'flat_rate',
        ]);

        $this->assertInstanceOf(PricingType::class, $service->pricing_type);
        $this->assertEquals(PricingType::FlatRate, $service->pricing_type);
    }

    #[Test]
    public function it_can_scope_to_active_services(): void
    {
        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);

        $activeServices = Service::active()->get();

        $this->assertCount(1, $activeServices);
    }

    #[Test]
    public function it_can_scope_to_company(): void
    {
        $otherCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $companyServices = Service::forCompany($this->company->id)->get();

        $this->assertCount(1, $companyServices);
    }

    #[Test]
    public function it_can_scope_by_pricing_type(): void
    {
        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'pricing_type' => PricingType::FlatRate,
        ]);

        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'pricing_type' => PricingType::Hourly,
        ]);

        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'pricing_type' => PricingType::Hourly,
        ]);

        $hourlyServices = Service::byPricingType(PricingType::Hourly)->get();

        $this->assertCount(2, $hourlyServices);
    }

    #[Test]
    public function it_supports_soft_deletes(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $service->delete();

        $this->assertSoftDeleted('services', ['id' => $service->id]);
        $this->assertNull(Service::find($service->id));
        $this->assertNotNull(Service::withTrashed()->find($service->id));
    }

    #[Test]
    public function it_has_flat_rate_helper(): void
    {
        $flatRate = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'pricing_type' => PricingType::FlatRate,
        ]);

        $hourly = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'pricing_type' => PricingType::Hourly,
        ]);

        $this->assertTrue($flatRate->isFlatRate());
        $this->assertFalse($hourly->isFlatRate());
    }

    #[Test]
    public function it_has_hourly_helper(): void
    {
        $flatRate = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'pricing_type' => PricingType::FlatRate,
        ]);

        $hourly = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'pricing_type' => PricingType::Hourly,
        ]);

        $this->assertFalse($flatRate->isHourly());
        $this->assertTrue($hourly->isHourly());
    }

    #[Test]
    public function it_has_percentage_helper(): void
    {
        $flatRate = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'pricing_type' => PricingType::FlatRate,
        ]);

        $percentage = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'pricing_type' => PricingType::Percentage,
        ]);

        $this->assertFalse($flatRate->isPercentage());
        $this->assertTrue($percentage->isPercentage());
    }
}
