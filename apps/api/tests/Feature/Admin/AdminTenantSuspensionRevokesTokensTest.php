<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\SuperAdmin;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTenantSuspensionRevokesTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspending_a_tenant_revokes_its_users_tokens(): void
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
        $otherTenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $bystander = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $user->createToken('t');
        $bystander->createToken('t');

        $this->actingAs($superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/tenants/{$tenant->id}/suspend", ['reason' => 'billing'])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $bystander->tokens()->count());
    }
}
