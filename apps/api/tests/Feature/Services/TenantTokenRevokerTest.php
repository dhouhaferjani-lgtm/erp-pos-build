<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\TenantTokenRevoker;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TenantTokenRevoker — the revocation logic extracted out of TenantObserver so
 * the SAME deletion runs from every tenant-teardown path, including the two
 * (deprovision + failed-registration rollback) that bypass Eloquent model
 * events. These tests pin the revoker's behaviour directly, in shared-DB compat
 * mode (the default test topology).
 */
final class TenantTokenRevokerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_revokes_every_central_token_for_the_tenants_users(): void
    {
        $tenant = Tenant::factory()->create();

        $userA = User::factory()->create(['tenant_id' => $tenant->id, 'status' => UserStatus::Active]);
        $userB = User::factory()->create(['tenant_id' => $tenant->id, 'status' => UserStatus::Active]);

        $userA->createToken('a-1');
        $userA->createToken('a-2');
        $userB->createToken('b-1');

        $this->assertSame(3, DB::table('personal_access_tokens')->count());

        app(TenantTokenRevoker::class)->revokeTenantTokens($tenant);

        $this->assertSame(
            0,
            DB::table('personal_access_tokens')->count(),
            'Every token for the tenant users must be revoked.',
        );
    }

    public function test_leaves_other_tenants_tokens_untouched(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'status' => UserStatus::Active]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'status' => UserStatus::Active]);

        $userA->createToken('a');
        $userB->createToken('b');

        app(TenantTokenRevoker::class)->revokeTenantTokens($tenantA);

        $this->assertSame(
            0,
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $userA->id)
                ->where('tokenable_type', User::class)
                ->count(),
            'Tenant A tokens must be revoked.',
        );
        $this->assertSame(
            1,
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $userB->id)
                ->where('tokenable_type', User::class)
                ->count(),
            'Tenant B tokens must be untouched.',
        );
    }

    public function test_is_a_safe_no_op_when_the_tenant_has_no_users_or_tokens(): void
    {
        $tenant = Tenant::factory()->create();

        // Must not throw and must not touch unrelated tokens.
        $other = Tenant::factory()->create();
        $otherUser = User::factory()->create(['tenant_id' => $other->id, 'status' => UserStatus::Active]);
        $otherUser->createToken('keep');

        app(TenantTokenRevoker::class)->revokeTenantTokens($tenant);

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }
}
