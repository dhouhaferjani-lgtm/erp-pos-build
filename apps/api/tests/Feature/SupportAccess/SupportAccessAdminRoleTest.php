<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SupportAccessAdminRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_create_grants_and_sessions(): void
    {
        $this->actingAs($this->actor('support_approver'), 'sanctum-admin')
            ->postJson('/api/v1/admin/impersonation/requests', [])
            ->assertForbidden();

        $this->actingAs($this->actor('super_admin'), 'sanctum-admin')
            ->postJson('/api/v1/admin/impersonation/requests', [])
            ->assertUnprocessable();
    }

    public function test_only_support_approver_can_approve_four_eyes_decisions(): void
    {
        $grantId = Str::uuid()->toString();

        $this->actingAs($this->actor('super_admin'), 'sanctum-admin')
            ->postJson("/api/v1/admin/impersonation/requests/{$grantId}/approve")
            ->assertForbidden();

        $this->actingAs($this->actor('support_approver'), 'sanctum-admin')
            ->postJson("/api/v1/admin/impersonation/requests/{$grantId}/approve")
            ->assertNotFound();
    }

    private function actor(string $role): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => $role,
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
