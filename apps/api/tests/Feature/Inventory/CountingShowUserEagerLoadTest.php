<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingAssignment;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression: the counting user relations must resolve against the UUID-keyed
 * Identity user model. With the integer-keyed scaffold model, eager loading
 * count1User/createdBy compiled `users.id IN (0)` and Postgres rejected the
 * uuid = integer comparison, 500ing the detail endpoint for any counting with
 * an assigned counter.
 */
class CountingShowUserEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $counterUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant SEL',
            'slug' => 'test-tenant-sel',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company SEL',
            'legal_name' => 'Test Company SEL LLC',
            'tax_id' => 'TAXSEL',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin SEL',
            'email' => 'admin-sel@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');
        $this->adminUser->givePermissionTo(['inventory.view', 'inventory.adjust']);

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->counterUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counter SEL',
            'email' => 'counter-sel@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->counterUser->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_show_returns_200_with_counter_user_when_count_1_user_assigned(): void
    {
        $counting = InventoryCounting::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->adminUser->id,
            'status' => CountingStatus::Draft,
            'scope_type' => CountingScopeType::Product,
            'scope_filters' => ['product_ids' => []],
            'execution_mode' => CountingExecutionMode::Parallel,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => true,
            'count_1_user_id' => $this->counterUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/inventory/countings/{$counting->id}");

        $response->assertStatus(200);
        $this->assertSame('Counter SEL', $response->json('data.count_1_user.name'));
        $this->assertSame('Admin SEL', $response->json('data.created_by.name'));
    }

    public function test_show_emits_assignments_with_user_and_progress(): void
    {
        $counting = InventoryCounting::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->adminUser->id,
            'status' => CountingStatus::Count1InProgress,
            'scope_type' => CountingScopeType::Product,
            'scope_filters' => ['product_ids' => []],
            'execution_mode' => CountingExecutionMode::Parallel,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => true,
            'count_1_user_id' => $this->counterUser->id,
        ]);

        InventoryCountingAssignment::create([
            'counting_id' => $counting->id,
            'user_id' => $this->counterUser->id,
            'count_number' => 1,
            'assigned_at' => now(),
            'total_items' => 4,
            'counted_items' => 1,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/inventory/countings/{$counting->id}");

        $response->assertStatus(200);
        $this->assertSame('Counter SEL', $response->json('data.assignments.0.user.name'));
        $this->assertSame(1, $response->json('data.assignments.0.count_number'));
        $this->assertSame(4, $response->json('data.assignments.0.total_items'));
        $this->assertSame(1, $response->json('data.assignments.0.counted_items'));
        $this->assertSame(25, $response->json('data.assignments.0.progress_percentage'));
    }
}
