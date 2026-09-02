<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Uom;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The canonical RolesAndPermissionsSeeder (run on tenant provisioning) must grant
 * uom.view to the read roles and units.manage only to operators allowed to
 * create explicit company unit-text mappings.
 */
final class UomPermissionSeedingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Uom Perm Tenant',
            'slug' => 'uom-perm-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Uom Perm Company',
            'legal_name' => 'Uom Perm Company LLC',
            'tax_id' => 'UOMPERM123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    private function makeUserWithRole(string $role): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Uom Perm '.$role,
            'email' => $role.'-uom-perm@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole($role);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::from($role),
        ]);

        return $user;
    }

    #[Test]
    public function seeded_cashier_role_can_read_units(): void
    {
        $cashier = $this->makeUserWithRole('cashier');

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/v1/uom/units')
            ->assertOk();
    }

    #[Test]
    public function seeded_manager_role_can_read_units(): void
    {
        $manager = $this->makeUserWithRole('manager');

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/uom/units')
            ->assertOk();
    }

    #[Test]
    public function seeded_admin_and_manager_roles_can_manage_unit_text_mappings(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $manager = $this->makeUserWithRole('manager');
        $cashier = $this->makeUserWithRole('cashier');

        self::assertTrue($admin->hasPermissionTo('units.manage'));
        self::assertTrue($manager->hasPermissionTo('units.manage'));
        self::assertFalse($cashier->hasPermissionTo('units.manage'));
    }
}
