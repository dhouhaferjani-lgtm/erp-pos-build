<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Service\Application\DTOs\ServiceCategoryData;
use App\Modules\Service\Application\DTOs\ServiceData;
use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Service\Domain\Service;
use App\Modules\Service\Domain\ServiceCategory;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for ServiceCatalogService.
 */
class ServiceCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCatalogService $service;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // ServiceCatalogService now requires CompanyContext to be set.
        // Pin the active company before resolving the service.
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->service = app(ServiceCatalogService::class);
    }

    // ============================================
    // Service CRUD Tests
    // ============================================

    #[Test]
    public function it_creates_a_service(): void
    {
        $data = [
            'code' => 'SRV-001',
            'name' => 'Oil Change',
            'description' => 'Full synthetic oil change',
            'pricing_type' => 'flat_rate',
            'base_price' => '45.00',
            'currency' => 'TND',
            'default_duration_minutes' => 30,
            'tax_rate' => '19.00',
        ];

        $result = $this->service->createService($this->company->id, $data);

        $this->assertInstanceOf(ServiceData::class, $result);
        $this->assertEquals('SRV-001', $result->code);
        $this->assertEquals('Oil Change', $result->name);
        $this->assertEquals(PricingType::FlatRate, $result->pricing_type);
        $this->assertEquals('45.000', $result->base_price);

        $this->assertDatabaseHas('services', [
            'company_id' => $this->company->id,
            'code' => 'SRV-001',
            'name' => 'Oil Change',
        ]);
    }

    #[Test]
    public function it_prevents_duplicate_service_codes(): void
    {
        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SRV-001',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service code already exists');

        $this->service->createService($this->company->id, [
            'code' => 'SRV-001',
            'name' => 'Different Service',
            'pricing_type' => 'flat_rate',
            'base_price' => '50.00',
        ]);
    }

    #[Test]
    public function it_updates_a_service(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SRV-001',
            'name' => 'Oil Change',
            'base_price' => '45.00',
        ]);

        $result = $this->service->updateService($service->id, [
            'name' => 'Premium Oil Change',
            'base_price' => '65.00',
        ]);

        $this->assertEquals('Premium Oil Change', $result->name);
        $this->assertEquals('65.000', $result->base_price);
        $this->assertEquals('SRV-001', $result->code); // Code unchanged
    }

    #[Test]
    public function it_deletes_a_service(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $result = $this->service->deleteService($service->id);

        $this->assertTrue($result);
        $this->assertSoftDeleted('services', ['id' => $service->id]);
    }

    #[Test]
    public function it_lists_services_for_company(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        Service::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        Service::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $result = $this->service->listServices($this->company->id);

        $this->assertCount(3, $result);
        $this->assertContainsOnlyInstancesOf(ServiceData::class, $result);
    }

    #[Test]
    public function it_filters_services_by_active_status(): void
    {
        Service::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        Service::factory()->inactive()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $activeOnly = $this->service->listServices($this->company->id, ['active_only' => true]);
        $all = $this->service->listServices($this->company->id);

        $this->assertCount(2, $activeOnly);
        $this->assertCount(3, $all);
    }

    #[Test]
    public function it_filters_services_by_category(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        Service::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'category_id' => $category->id,
        ]);

        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'category_id' => null,
        ]);

        $filtered = $this->service->listServices($this->company->id, ['category_id' => $category->id]);

        $this->assertCount(2, $filtered);
    }

    #[Test]
    public function it_filters_services_by_pricing_type(): void
    {
        Service::factory()->flatRate()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        Service::factory()->hourly()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $hourly = $this->service->listServices($this->company->id, ['pricing_type' => 'hourly']);

        $this->assertCount(2, $hourly);
    }

    #[Test]
    public function it_searches_services_by_name(): void
    {
        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Oil Change',
        ]);

        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Brake Service',
        ]);

        $results = $this->service->listServices($this->company->id, ['search' => 'oil']);

        $this->assertCount(1, $results);
        $this->assertEquals('Oil Change', $results->first()->name);
    }

    // ============================================
    // Category CRUD Tests
    // ============================================

    #[Test]
    public function it_creates_a_category(): void
    {
        $data = [
            'name' => 'Maintenance',
            'description' => 'Regular maintenance services',
            'sort_order' => 10,
        ];

        $result = $this->service->createCategory($this->company->id, $data);

        $this->assertInstanceOf(ServiceCategoryData::class, $result);
        $this->assertEquals('Maintenance', $result->name);
        $this->assertEquals(10, $result->sort_order);

        $this->assertDatabaseHas('service_categories', [
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);
    }

    #[Test]
    public function it_creates_nested_categories(): void
    {
        $parent = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);

        $result = $this->service->createCategory($this->company->id, [
            'name' => 'Oil & Filters',
            'parent_id' => $parent->id,
        ]);

        $this->assertEquals($parent->id, $result->parent_id);
    }

    #[Test]
    public function it_prevents_duplicate_category_names_in_company(): void
    {
        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name already exists');

        $this->service->createCategory($this->company->id, [
            'name' => 'Maintenance',
        ]);
    }

    #[Test]
    public function it_updates_a_category(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);

        $result = $this->service->updateCategory($category->id, [
            'name' => 'Scheduled Maintenance',
            'description' => 'Updated description',
        ]);

        $this->assertEquals('Scheduled Maintenance', $result->name);
        $this->assertEquals('Updated description', $result->description);
    }

    #[Test]
    public function it_deletes_a_category_without_services(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $result = $this->service->deleteCategory($category->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('service_categories', ['id' => $category->id]);
    }

    #[Test]
    public function it_prevents_deleting_category_with_services(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'category_id' => $category->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot delete category with existing services');

        $this->service->deleteCategory($category->id);
    }

    #[Test]
    public function it_lists_categories_for_company(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        ServiceCategory::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        ServiceCategory::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $result = $this->service->listCategories($this->company->id);

        $this->assertCount(3, $result);
        $this->assertContainsOnlyInstancesOf(ServiceCategoryData::class, $result);
    }

    #[Test]
    public function it_lists_root_categories_only(): void
    {
        $parent = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'parent_id' => $parent->id,
        ]);

        $result = $this->service->listCategories($this->company->id, ['root_only' => true]);

        $this->assertCount(1, $result);
        $this->assertNull($result->first()->parent_id);
    }

    #[Test]
    public function it_gets_category_tree(): void
    {
        $parent = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);

        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Oil Changes',
            'parent_id' => $parent->id,
        ]);

        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Filters',
            'parent_id' => $parent->id,
        ]);

        $tree = $this->service->getCategoryTree($this->company->id);

        $this->assertCount(1, $tree); // Only root category
        $this->assertEquals('Maintenance', $tree[0]['name']);
        $this->assertCount(2, $tree[0]['children']);
    }
}
