<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Models\SuperAdmin;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TenantSensitivityManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_change_tenant_sensitivity_with_a_central_audit_record(): void
    {
        $admin = $this->superAdmin();
        $tenant = Tenant::factory()->create(['is_sensitive' => false]);

        $this->actingAs($admin, 'sanctum-admin')
            ->patchJson("/api/v1/admin/impersonation/tenants/{$tenant->id}/sensitivity", [
                'is_sensitive' => true,
                'reason' => 'Regulated payroll and national identifiers',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_sensitive', true);

        self::assertTrue((bool) $tenant->fresh()?->is_sensitive);
        $this->assertDatabaseHas('admin_audit_logs', [
            'super_admin_id' => $admin->id,
            'tenant_id' => $tenant->id,
            'action' => 'tenant_sensitivity_changed',
            'entity_type' => 'tenant',
            'entity_id' => $tenant->id,
            'notes' => 'Regulated payroll and national identifiers',
        ]);
    }

    public function test_tenant_sensitivity_change_requires_admin_auth_and_a_reason(): void
    {
        $tenant = Tenant::factory()->create(['is_sensitive' => false]);

        $this->patchJson("/api/v1/admin/impersonation/tenants/{$tenant->id}/sensitivity", [
            'is_sensitive' => true,
            'reason' => 'Regulated tenant',
        ])->assertUnauthorized();

        $this->actingAs($this->superAdmin(), 'sanctum-admin')
            ->patchJson("/api/v1/admin/impersonation/tenants/{$tenant->id}/sensitivity", [
                'is_sensitive' => true,
            ])
            ->assertUnprocessable();

        self::assertFalse((bool) $tenant->fresh()?->is_sensitive);
    }

    private function superAdmin(): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => 'Security Administrator',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
