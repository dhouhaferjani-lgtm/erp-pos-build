<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for service module database migrations.
 */
class ServiceMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function service_categories_table_exists_with_correct_columns(): void
    {
        $this->assertTrue(Schema::hasTable('service_categories'));

        $this->assertTrue(Schema::hasColumns('service_categories', [
            'id',
            'tenant_id',
            'company_id',
            'name',
            'description',
            'parent_id',
            'sort_order',
            'is_active',
            'created_at',
            'updated_at',
        ]));
    }

    #[Test]
    public function service_categories_has_unique_name_per_company_constraint(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        // Create first category
        DB::table('service_categories')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Maintenance',
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt to create duplicate should fail
        $this->expectException(QueryException::class);

        DB::table('service_categories')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Maintenance',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function services_table_exists_with_correct_columns(): void
    {
        $this->assertTrue(Schema::hasTable('services'));

        $this->assertTrue(Schema::hasColumns('services', [
            'id',
            'tenant_id',
            'company_id',
            'code',
            'name',
            'description',
            'category_id',
            'pricing_type',
            'base_price',
            'currency',
            'default_duration_minutes',
            'hourly_rate',
            'tax_rate',
            'is_active',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }

    #[Test]
    public function services_has_unique_code_per_company_constraint(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        // Create first service
        DB::table('services')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'OIL-CHANGE',
            'name' => 'Oil Change Service',
            'pricing_type' => 'flat_rate',
            'base_price' => '45.00',
            'currency' => 'TND',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt to create duplicate should fail
        $this->expectException(QueryException::class);

        DB::table('services')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'OIL-CHANGE',
            'name' => 'Another Oil Change',
            'pricing_type' => 'flat_rate',
            'base_price' => '50.00',
            'currency' => 'TND',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function services_can_reference_category(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $categoryId = Str::uuid()->toString();

        // Create category first
        DB::table('service_categories')->insert([
            'id' => $categoryId,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Maintenance',
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create service with category reference
        $serviceId = Str::uuid()->toString();
        DB::table('services')->insert([
            'id' => $serviceId,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'OIL-CHANGE',
            'name' => 'Oil Change Service',
            'category_id' => $categoryId,
            'pricing_type' => 'flat_rate',
            'base_price' => '45.00',
            'currency' => 'TND',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = DB::table('services')
            ->where('id', $serviceId)
            ->first();

        $this->assertEquals($categoryId, $service->category_id);
    }

    #[Test]
    public function service_categories_support_parent_child_hierarchy(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $parentId = Str::uuid()->toString();
        $childId = Str::uuid()->toString();

        // Create parent category
        DB::table('service_categories')->insert([
            'id' => $parentId,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Maintenance',
            'parent_id' => null,
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create child category
        DB::table('service_categories')->insert([
            'id' => $childId,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Oil & Filter',
            'parent_id' => $parentId,
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $child = DB::table('service_categories')
            ->where('id', $childId)
            ->first();

        $this->assertEquals($parentId, $child->parent_id);
    }
}
