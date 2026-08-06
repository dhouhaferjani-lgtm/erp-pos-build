<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * W-X (2026-08-05): HTTP-level, role-based coverage that admin and
 * accountant — via the actual per-tenant seeded roles, not an ad hoc
 * `givePermissionTo` call — can create a tax configuration, and viewer
 * cannot. Complements TaxConfigurationManagementTest (which pins the
 * ad hoc permission-grant path) and
 * RolesAndPermissionsTaxConfigManageGrantTest (which pins the seeder's
 * Role::hasPermissionTo grants without going through HTTP).
 */
final class TaxConfigManageSeededRoleGrantHttpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'WX Tax Config Tenant',
            'slug' => 'wx-tax-config-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'WX Tax Config Company',
            'legal_name' => 'WX Tax Config Company LLC',
            'tax_id' => 'WXTAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'WX '.$role,
            'email' => $role.'-wx@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => $role,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function payload(): array
    {
        return [
            'tax_type' => 'FIXED_AMOUNT',
            'name' => 'Timbre Fiscal - Seeded Role',
            'code' => 'STAMP_SEEDED_ROLE',
            'fixed_amount' => '0.100',
            'applies_to' => 'DOCUMENT_TOTAL',
            'applicable_document_types' => ['FISCAL_RECEIPT'],
            'is_active' => true,
        ];
    }

    public function test_admin_and_accountant_can_create_tax_configuration(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->withHeaders(['X-Company-ID' => $this->company->id])
            ->postJson('/api/v1/taxation/configurations', array_merge($this->payload(), ['code' => 'STAMP_ADMIN']))
            ->assertCreated();

        $this->actingAs($this->userWithRole('accountant'))
            ->withHeaders(['X-Company-ID' => $this->company->id])
            ->postJson('/api/v1/taxation/configurations', array_merge($this->payload(), ['code' => 'STAMP_ACCOUNTANT']))
            ->assertCreated();
    }

    public function test_viewer_and_manager_cannot_create_tax_configuration(): void
    {
        $this->actingAs($this->userWithRole('viewer'))
            ->withHeaders(['X-Company-ID' => $this->company->id])
            ->postJson('/api/v1/taxation/configurations', $this->payload())
            ->assertForbidden();

        $this->actingAs($this->userWithRole('manager'))
            ->withHeaders(['X-Company-ID' => $this->company->id])
            ->postJson('/api/v1/taxation/configurations', $this->payload())
            ->assertForbidden();
    }
}
