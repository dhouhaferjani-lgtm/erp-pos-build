<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\SuperAdmin;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTenantPlanUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_usage_counts_are_scoped_to_the_target_tenant(): void
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
        User::factory()->count(5)->create(['tenant_id' => $other->id]);
        Company::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($superAdmin, 'sanctum-admin')
            ->getJson("/api/v1/admin/tenants/{$tenant->id}/plan-usage");

        $response->assertOk()
            ->assertJsonPath('data.usage.users.current', 2)
            ->assertJsonPath('data.usage.companies.current', 1);
        // With no active subscription the trial plan has no INCLUDED_USERS key,
        // so includedUsers defaults to 0 and extra_users equals the user count.
        // The critical assertion is that the OTHER tenant's 5 users are NOT counted
        // (i.e. users.current == 2, not 7). extra_users reflects the delta from 0.
        $response->assertJsonPath('data.overage.extra_users', 2);
    }
}
