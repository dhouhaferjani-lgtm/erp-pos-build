<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\SuperAdmin;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private SuperAdmin $superAdmin;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Super Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
    }

    public function test_users_index_fans_out_across_tenants_and_attaches_tenant_info(): void
    {
        User::factory()->create(['tenant_id' => $this->tenantA->id, 'name' => 'Alice A']);
        User::factory()->create(['tenant_id' => $this->tenantB->id, 'name' => 'Bob B']);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson('/api/v1/admin/users');

        $response->assertOk();
        $rows = $response->json('data.data');
        $this->assertCount(2, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Alice A', $names);
        $this->assertContains('Bob B', $names);
        $tenantNames = array_map(static fn (array $r): string => $r['tenant']['name'], $rows);
        $this->assertContains('Tenant A', $tenantNames);
    }

    public function test_users_index_filters_by_email_verified_inside_each_tenant(): void
    {
        User::factory()->create(['tenant_id' => $this->tenantA->id, 'email_verified_at' => null]);
        User::factory()->create(['tenant_id' => $this->tenantB->id]); // factory default: verified

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson('/api/v1/admin/users?email_verified=false');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_show_user_requires_tenant_id_and_resolves_inside_that_tenant(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenantA->id]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("/api/v1/admin/users/{$user->id}")
            ->assertStatus(422);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("/api/v1/admin/users/{$user->id}?tenant_id={$this->tenantA->id}")
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("/api/v1/admin/users/{$user->id}?tenant_id={$this->tenantB->id}")
            ->assertNotFound();
    }

    public function test_verify_email_requires_tenant_id_updates_user_and_writes_audit_log(): void
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'email_verified_at' => null,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/users/{$user->id}/verify-email", ['notes' => 'checked'])
            ->assertStatus(422);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/users/{$user->id}/verify-email", [
                'tenant_id' => $this->tenantA->id,
                'notes' => 'checked',
            ]);

        $response->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'verify_user_email',
            'entity_id' => $user->id,
        ]);
    }

    public function test_verify_email_rejects_already_verified(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenantA->id]); // verified by default

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/users/{$user->id}/verify-email", [
                'tenant_id' => $this->tenantA->id,
            ])
            ->assertStatus(400);
    }
}
