<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $company->id,
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
