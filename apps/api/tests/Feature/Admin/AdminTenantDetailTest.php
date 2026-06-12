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
}
