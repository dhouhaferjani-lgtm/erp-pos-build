<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Modules\Company\Domain\Company;
use App\Modules\Service\Domain\Service;
use App\Modules\Service\Domain\ServiceCategory;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for ServiceCategory model.
 */
class ServiceCategoryTest extends TestCase
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
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->assertInstanceOf(Tenant::class, $category->tenant);
        $this->assertEquals($this->tenant->id, $category->tenant->id);
    }

    #[Test]
    public function it_belongs_to_company(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->assertInstanceOf(Company::class, $category->company);
        $this->assertEquals($this->company->id, $category->company->id);
    }

    #[Test]
    public function it_can_have_parent_category(): void
    {
        $parent = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);

        $child = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Oil & Filter',
            'parent_id' => $parent->id,
        ]);

        $this->assertInstanceOf(ServiceCategory::class, $child->parent);
        $this->assertEquals($parent->id, $child->parent->id);
        $this->assertEquals('Maintenance', $child->parent->name);
    }

    #[Test]
    public function it_has_many_children(): void
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
            'name' => 'Brake Service',
            'parent_id' => $parent->id,
        ]);

        $this->assertCount(2, $parent->children);
    }

    #[Test]
    public function it_has_many_services(): void
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

        $this->assertCount(3, $category->services);
    }

    #[Test]
    public function it_can_scope_to_active_categories(): void
    {
        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);

        $activeCategories = ServiceCategory::active()->get();

        $this->assertCount(1, $activeCategories);
    }

    #[Test]
    public function it_can_scope_to_root_categories(): void
    {
        $parent = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'parent_id' => null,
        ]);

        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'parent_id' => $parent->id,
        ]);

        $rootCategories = ServiceCategory::roots()->get();

        $this->assertCount(1, $rootCategories);
        $this->assertEquals($parent->id, $rootCategories->first()->id);
    }

    #[Test]
    public function it_can_scope_to_company(): void
    {
        $otherCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $companyCategories = ServiceCategory::forCompany($this->company->id)->get();

        $this->assertCount(1, $companyCategories);
    }
}
