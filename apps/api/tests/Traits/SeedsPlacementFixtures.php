<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared fixtures for location-placement-hierarchy tests: tenant + company +
 * location(s) + products + a permissioned user, mirroring the setup pattern
 * used by the original ZoneManagementTest.
 */
trait SeedsPlacementFixtures
{
    protected Tenant $tenant;

    protected Company $company;

    protected function seedTenantAndCompany(): void
    {
        $suffix = Str::lower(Str::random(6));

        $this->tenant = Tenant::create([
            'name' => 'Placement Test Tenant',
            'slug' => 'placement-test-tenant-'.$suffix,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Placement Test Company',
            'legal_name' => 'Placement Test Company LLC',
            'tax_id' => 'TAX-PLC-'.Str::upper($suffix),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function seedPermissionedUser(array $permissions = ['inventory.view', 'inventory.adjust']): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Placement Test User',
            'email' => 'placement-user-'.Str::lower(Str::random(6)).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        return $user;
    }

    protected function seedLocationForCompany(string $code = 'WH-PLC-01', string $name = 'Placement Warehouse'): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $name,
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    protected function seedProductForCompany(string $sku, string $name = 'Placement Widget'): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $name,
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);
    }
}
