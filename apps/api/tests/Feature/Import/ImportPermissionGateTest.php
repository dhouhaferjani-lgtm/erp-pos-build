<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ImportPermissionGateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-import-permissions',
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
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = $this->makeUser('admin@example.com', 'admin');
        $this->admin->assignRole('admin');
        $this->viewer = $this->makeUser('viewer@example.com', 'viewer');

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_import_routes_require_imports_manage_permission(): void
    {
        $this->actingAs($this->viewer, 'sanctum')
            ->getJson('/api/v1/imports')
            ->assertForbidden();

        $this->actingAs($this->viewer, 'sanctum')
            ->postJson('/api/v1/imports', [])
            ->assertForbidden();

        $this->actingAs($this->viewer, 'sanctum')
            ->getJson('/api/v1/migration-wizard/order')
            ->assertForbidden();
    }

    public function test_admin_can_access_import_routes(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/imports')
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/migration-wizard/order')
            ->assertOk();
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $role,
            'email' => $email,
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => $role,
        ]);

        return $user;
    }
}
