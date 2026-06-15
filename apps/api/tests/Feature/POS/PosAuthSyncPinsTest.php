<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PosAuthSyncPinsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');

        Sanctum::actingAs($this->user);
    }

    public function test_sync_pins_upserts_for_self(): void
    {
        $hash = Hash::make('4321');

        $response = $this->postJson('/api/v1/pos/auth/sync-pins', [
            'updates' => [
                ['user_id' => $this->user->id, 'pin_hash' => $hash],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.synced', 1);
        $this->user->refresh();
        $this->assertNotNull($this->user->pos_pin);
        $this->assertTrue(Hash::check('4321', $this->user->pos_pin));
    }

    public function test_sync_pins_is_idempotent(): void
    {
        $hash = Hash::make('5678');
        $payload = ['updates' => [['user_id' => $this->user->id, 'pin_hash' => $hash]]];

        $this->postJson('/api/v1/pos/auth/sync-pins', $payload)->assertOk();
        $response = $this->postJson('/api/v1/pos/auth/sync-pins', $payload);

        $response->assertOk();
        $response->assertJsonPath('data.synced', 1);
        $response->assertJsonPath('data.skipped', 0);
    }

    public function test_sync_pins_rejects_cross_tenant_user(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->postJson('/api/v1/pos/auth/sync-pins', [
            'updates' => [
                ['user_id' => $otherUser->id, 'pin_hash' => Hash::make('9999')],
            ],
        ]);

        $response->assertStatus(422);
    }

    // FU-2b — a target must be an ACTIVE member of the CURRENT company. A
    // same-tenant user who is not a member of this company must be rejected,
    // narrowing the blast radius from "any tenant user" to "active members of
    // this company".
    public function test_sync_pins_rejects_a_same_tenant_non_company_member(): void
    {
        $outsider = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->postJson('/api/v1/pos/auth/sync-pins', [
            'updates' => [
                ['user_id' => $outsider->id, 'pin_hash' => Hash::make('2468')],
            ],
        ]);

        $response->assertStatus(422);
        $outsider->refresh();
        $this->assertNull($outsider->pos_pin);
    }

    public function test_sync_pins_rejects_a_suspended_company_member(): void
    {
        $suspended = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $suspended->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
            'status' => MembershipStatus::Suspended->value,
        ]);

        $response = $this->postJson('/api/v1/pos/auth/sync-pins', [
            'updates' => [
                ['user_id' => $suspended->id, 'pin_hash' => Hash::make('1357')],
            ],
        ]);

        $response->assertStatus(422);
        $suspended->refresh();
        $this->assertNull($suspended->pos_pin);
    }

    public function test_sync_pins_rejects_a_pending_company_member(): void
    {
        $pending = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $pending->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
            'status' => MembershipStatus::Pending->value,
        ]);

        $response = $this->postJson('/api/v1/pos/auth/sync-pins', [
            'updates' => [
                ['user_id' => $pending->id, 'pin_hash' => Hash::make('1212')],
            ],
        ]);

        $response->assertStatus(422);
        $pending->refresh();
        $this->assertNull($pending->pos_pin);
    }

    // The legit multi-operator offline flow: another operator set up their own
    // PIN on this shared terminal while offline; the batch is pushed under
    // whoever is authenticated at sync time. An ACTIVE company member must still
    // be accepted (do NOT over-restrict to self-only).
    public function test_sync_pins_allows_another_active_company_member(): void
    {
        $colleague = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $colleague->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
            'status' => MembershipStatus::Active->value,
        ]);

        $response = $this->postJson('/api/v1/pos/auth/sync-pins', [
            'updates' => [
                ['user_id' => $colleague->id, 'pin_hash' => Hash::make('8642')],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.synced', 1);
        $colleague->refresh();
        $this->assertTrue(Hash::check('8642', $colleague->pos_pin));
    }

    public function test_sync_pins_requires_permission(): void
    {
        $this->user->revokePermissionTo('pos.operate_terminal');

        $response = $this->postJson('/api/v1/pos/auth/sync-pins', [
            'updates' => [
                ['user_id' => $this->user->id, 'pin_hash' => Hash::make('1111')],
            ],
        ]);

        $response->assertStatus(403);
    }
}
