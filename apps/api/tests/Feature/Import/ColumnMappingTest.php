<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Services\ImportService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ColumnMappingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private ImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        /** @var ImportService $importService */
        $importService = app(ImportService::class);
        $this->importService = $importService;
    }

    public function test_apply_column_mapping_renames_row_keys(): void
    {
        $rows = [
            ['Code' => 'SKU-001', 'Price' => '9.99', 'Product Name' => 'Brake Pad'],
            ['Code' => 'SKU-002', 'Price' => '19.99', 'Product Name' => 'Oil Filter'],
        ];

        $mapping = [
            'Code' => 'sku',
            'Price' => 'sale_price',
            'Product Name' => 'name',
        ];

        $result = $this->importService->applyColumnMapping($rows, $mapping);

        $this->assertCount(2, $result);

        $this->assertArrayHasKey('sku', $result[0]);
        $this->assertArrayHasKey('sale_price', $result[0]);
        $this->assertArrayHasKey('name', $result[0]);

        $this->assertSame('SKU-001', $result[0]['sku']);
        $this->assertSame('9.99', $result[0]['sale_price']);
        $this->assertSame('Brake Pad', $result[0]['name']);

        $this->assertSame('SKU-002', $result[1]['sku']);
        $this->assertSame('19.99', $result[1]['sale_price']);
        $this->assertSame('Oil Filter', $result[1]['name']);
    }

    public function test_apply_column_mapping_drops_unmapped_columns(): void
    {
        $rows = [
            ['Code' => 'SKU-001', 'Price' => '9.99', 'Extra Column' => 'should be dropped', 'Another Extra' => 'also dropped'],
        ];

        $mapping = [
            'Code' => 'sku',
            'Price' => 'sale_price',
        ];

        $result = $this->importService->applyColumnMapping($rows, $mapping);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('sku', $result[0]);
        $this->assertArrayHasKey('sale_price', $result[0]);
        $this->assertArrayNotHasKey('Extra Column', $result[0]);
        $this->assertArrayNotHasKey('Another Extra', $result[0]);
        $this->assertArrayNotHasKey('Code', $result[0]);
        $this->assertArrayNotHasKey('Price', $result[0]);
        $this->assertCount(2, $result[0]);
    }

    public function test_null_mapping_returns_rows_unchanged(): void
    {
        $rows = [
            ['name' => 'Brake Pad', 'sku' => 'SKU-001', 'sale_price' => '9.99'],
            ['name' => 'Oil Filter', 'sku' => 'SKU-002', 'sale_price' => '19.99'],
        ];

        $result = $this->importService->applyColumnMapping($rows, null);

        $this->assertSame($rows, $result);
    }
}
