<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\SuperAdmin;
use App\Modules\Billing\Application\Services\PlanEnforcementService;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTenantDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_detail_stats_come_from_the_tenant_database(): void
    {
        $superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Super Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        User::factory()->count(2)->create(['tenant_id' => $tenant->id]);
        User::factory()->create(['tenant_id' => $other->id]);
        Company::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($superAdmin, 'sanctum-admin')
            ->getJson("/api/v1/admin/tenants/{$tenant->id}");

        $response->assertOk()
            ->assertJsonPath('data.stats.users_count', 2)
            ->assertJsonPath('data.stats.companies_count', 1)
            ->assertJsonPath('data.stats.locations_count', 0)
            ->assertJsonPath('data.stats_available', true);
    }

    public function test_plan_summary_failure_degrades_gracefully_to_null(): void
    {
        // In the shared-schema test environment we cannot take down the tenant
        // database to force a run() failure, so we use a partial mock of
        // PlanEnforcementService instead — this is acceptable here because
        // the goal is to exercise the controller's catch branch, not the
        // service's internals.
        $superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Super Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $tenant = Tenant::factory()->create();

        // PlanEnforcementService is not final (intentionally — see its class
        // docblock) so we can bind a subclass stub via the container to
        // simulate getPlanSummary throwing without needing Mockery proxying.
        // This is acceptable in tests because we're exercising the controller's
        // catch branch, not the service internals; the shared-schema test env
        // cannot take a tenant DB offline to cause a real run() failure.
        $stub = new class extends PlanEnforcementService
        {
            public function getPlanSummary(Tenant $tenant): array
            {
                throw new \RuntimeException('tenant db gone');
            }
        };
        $this->instance(PlanEnforcementService::class, $stub);

        // The stats run() succeeds in the test env (DB is reachable), so the
        // stats block sets stats_available=true. The plan-summary catch then
        // forces stats_available to false. Net result: 200, plan_summary null,
        // stats_available false.
        $response = $this->actingAs($superAdmin, 'sanctum-admin')
            ->getJson("/api/v1/admin/tenants/{$tenant->id}");

        $response->assertOk()
            ->assertJsonPath('data.plan_summary', null)
            ->assertJsonPath('data.stats_available', false);
    }
}
