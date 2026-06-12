<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\SuperAdmin;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\TenantFleetStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stancl\Tenancy\Facades\GlobalCache;
use Tests\TestCase;

class AdminDashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    private SuperAdmin $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        GlobalCache::forget(TenantFleetStatsService::CACHE_KEY);

        $this->superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Super Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    public function test_dashboard_aggregates_user_and_company_counts_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        User::factory()->count(2)->create(['tenant_id' => $tenantA->id]);
        User::factory()->create(['tenant_id' => $tenantB->id]);
        Company::factory()->create(['tenant_id' => $tenantA->id]);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson('/api/v1/admin/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.total_users', 3)
            ->assertJsonPath('data.total_companies', 1)
            ->assertJsonPath('data.total_tenants', 2);
    }

    public function test_fleet_stats_are_cached_in_the_tenancy_neutral_global_cache(): void
    {
        Tenant::factory()->create();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        $this->assertTrue(GlobalCache::has(TenantFleetStatsService::CACHE_KEY));
    }
}
